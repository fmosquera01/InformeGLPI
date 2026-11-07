<?php
declare(strict_types=1);

namespace GlpiPlugin\Informeglopi;

use CommonDBTM;
use CronTask;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * Standalone Telegram integration for Informe Técnico.
 *
 * Settings are stored in GLPI's native configuration context. The bot token is
 * encrypted with sodium and its key is stored in GLPI_VAR_DIR, outside the DB.
 */
final class TelegramNotification extends CommonDBTM
{
    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_informeglopi_reports';
    }

    public static function getTypeName($nb = 0): string
    {
        return __('Notificaciones Telegram', 'informeglpi');
    }

    public static function cronInfo($name): array
    {
        if ($name === 'reminders') {
            return ['description' => __('Enviar recordatorios Telegram de informes pendientes de firma administrativa', 'informeglpi')];
        }

        return [];
    }

    public static function cronReminders($task = null): int
    {
        $config = self::getConfig();
        if ((int) ($config['telegram_enabled'] ?? 0) !== 1 || self::getToken($config) === '') {
            return 0;
        }

        $timezoneName = (string) ($config['telegram_timezone'] ?? 'America/Guayaquil');
        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (Throwable $e) {
            $timezone = new DateTimeZone('America/Guayaquil');
        }
        $now = new DateTimeImmutable('now', $timezone);
        $today = $now->format('Y-m-d');
        $currentTime = $now->format('H:i');
        $sent = 0;

        foreach ([
            'morning' => (string) ($config['telegram_morning_time'] ?? '07:00'),
            'evening' => (string) ($config['telegram_evening_time'] ?? '17:00'),
        ] as $period => $scheduledTime) {
            $enabledKey = $period === 'morning' ? 'telegram_notify_morning' : 'telegram_notify_evening';
            $sentKey = $period === 'morning' ? 'telegram_last_morning_date' : 'telegram_last_evening_date';
            $attemptKey = $period === 'morning' ? 'telegram_last_morning_attempt' : 'telegram_last_evening_attempt';
            if ((int) ($config[$enabledKey] ?? 0) !== 1
                || !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $scheduledTime)
                || $currentTime < $scheduledTime
                || (string) ($config[$sentKey] ?? '') === $today) {
                continue;
            }
            $lastAttempt = trim((string) ($config[$attemptKey] ?? ''));
            if ($lastAttempt !== '' && (int) $lastAttempt > ($now->getTimestamp() - 900)) {
                continue;
            }
            \Config::setConfigurationValues('plugin:informeglpi', [$attemptKey => (string) $now->getTimestamp()]);
            $reviewers = [];
            global $DB;
            foreach ($DB->request([
                'SELECT' => ['reviewer_users_id'],
                'FROM' => 'glpi_plugin_informeglopi_reports',
                'WHERE' => ['status' => 'pending_supervisor_signature'],
            ]) as $row) {
                $reviewerId = (int) ($row['reviewer_users_id'] ?? 0);
                if ($reviewerId > 0 && self::getUserChatId($reviewerId) !== '') {
                    $reviewers[$reviewerId] = true;
                }
            }
            $failed = false;
            foreach (array_keys($reviewers) as $reviewerId) {
                $pending = self::getPendingReports((int) $reviewerId);
                if ($pending === []) {
                    continue;
                }
                try {
                    self::sendMessage(self::getToken($config), self::getUserChatId((int) $reviewerId), self::buildDigestMessage($pending, $period, $config));
                    $sent++;
                } catch (Throwable $e) {
                    \Toolbox::logInFile('informeglopi', 'Telegram: fallo en recordatorio ' . $period . ' para usuario #' . $reviewerId . ': ' . $e->getMessage());
                }
            }
            \Config::setConfigurationValues('plugin:informeglpi', [$sentKey => $today, $attemptKey => '']);
            $config[$sentKey] = $today;
            $config[$attemptKey] = '';
        }
        return $sent;
    }

    public static function notifyTechnicalSignature(int $reportId): void
    {
        $config = self::getConfig();
        if ((int) ($config['telegram_enabled'] ?? 0) !== 1
            || (int) ($config['telegram_notify_immediate'] ?? 0) !== 1) {
            return;
        }
        global $DB;
        $reviewerId = 0;
        foreach ($DB->request([
            'SELECT' => ['reviewer_users_id'],
            'FROM' => 'glpi_plugin_informeglopi_reports',
            'WHERE' => ['id' => $reportId],
            'LIMIT' => 1,
        ]) as $row) {
            $reviewerId = (int) ($row['reviewer_users_id'] ?? 0);
        }
        if ($reviewerId < 1) {
            return;
        }
        $pending = self::getPendingReports($reviewerId, $reportId);
        if ($pending === []) {
            return;
        }
        self::sendToUser($reviewerId, self::buildDigestMessage($pending, 'immediate', $config), $config);
    }

    /** Notify the report creator after the final PDF has been stored successfully. */
    public static function notifyReportFinalized(int $reportId): void
    {
        global $DB;
        $row = self::getReportActors($reportId);
        if ($row === null) {
            return;
        }
        $number = (string) $row['report_number'];
        $url = self::reportPdfUrl($reportId);
        $message = '<b>Songa | Informe Técnico</b>' . "\n"
            . '✅ <b>Informe aprobado y PDF generado</b' . "\n"
            . 'Informe: <b>' . self::escape($number) . '</b>' . "\n"
            . 'El documento final ya está disponible para su descarga.' . "\n"
            . ($url !== '' ? '<a href="' . self::escape($url) . '">Abrir informe y descargar PDF</a>' : '');
        self::sendToUser((int) $row['users_id'], $message);
    }

    /** Notify the creator and assigned technical signer when the supervisor returns the report. */
    public static function notifyReportReturned(int $reportId, string $comment): void
    {
        $row = self::getReportActors($reportId);
        if ($row === null) {
            return;
        }
        $url = self::reportUrl($reportId);
        $message = '<b>Songa | Informe Técnico</b>' . "\n"
            . '↩️ <b>Informe devuelto para corrección</b>' . "\n"
            . 'Informe: <b>' . self::escape((string) $row['report_number']) . '</b>' . "\n"
            . '<b>Motivo indicado por el supervisor:</b>' . "\n"
            . self::escape($comment) . "\n"
            . ($url !== '' ? '<a href="' . self::escape($url) . '">Abrir informe para corregir</a>' : '');
        $recipients = array_unique([(int) $row['users_id'], (int) $row['prepared_by_users_id']]);
        foreach ($recipients as $userId) {
            self::sendToUser($userId, $message);
        }
    }

    private static function getReportActors(int $reportId): ?array
    {
        global $DB;
        foreach ($DB->request([
            'SELECT' => ['id', 'report_number', 'users_id', 'prepared_by_users_id', 'reviewer_users_id'],
            'FROM' => 'glpi_plugin_informeglopi_reports',
            'WHERE' => ['id' => $reportId],
            'LIMIT' => 1,
        ]) as $row) {
            return $row;
        }
        return null;
    }

    private static function getUserChatId(int $userId): string
    {
        global $DB;
        if ($userId < 1 || !$DB->tableExists('glpi_plugin_informeglopi_user_telegram')) {
            return '';
        }
        foreach ($DB->request([
            'SELECT' => ['chat_id'],
            'FROM' => 'glpi_plugin_informeglopi_user_telegram',
            'WHERE' => ['users_id' => $userId],
            'LIMIT' => 1,
        ]) as $row) {
            $chatId = trim((string) ($row['chat_id'] ?? ''));
            return preg_match('/^-?[0-9]+$/', $chatId) ? $chatId : '';
        }
        return '';
    }

    private static function sendToUser(int $userId, string $message, ?array $config = null): void
    {
        $config ??= self::getConfig();
        if ((int) ($config['telegram_enabled'] ?? 0) !== 1) {
            return;
        }
        $chatId = self::getUserChatId($userId);
        $token = self::getToken($config);
        // Missing personal Chat ID is an intentional no-op, never a workflow error.
        if ($chatId === '' || $token === '') {
            return;
        }
        try {
            self::sendMessage($token, $chatId, $message);
        } catch (Throwable $e) {
            \Toolbox::logInFile('informeglopi', 'Telegram: no se pudo notificar al usuario GLPI #' . $userId . ': ' . $e->getMessage());
        }
    }

    private static function reportUrl(int $reportId): string
    {
        $config = self::getConfig();
        $base = rtrim(trim((string) ($config['telegram_glpi_base_url'] ?? '')), '/');
        return $base !== '' ? $base . '/plugins/informeglpi/front/report.edit.form.php?id=' . $reportId : '';
    }

    private static function reportPdfUrl(int $reportId): string
    {
        $config = self::getConfig();
        $base = rtrim(trim((string) ($config['telegram_glpi_base_url'] ?? '')), '/');
        return $base !== '' ? $base . '/plugins/informeglpi/front/report.pdf.php?id=' . $reportId : '';
    }

    public static function sendTestMessage(string $plainToken, string $chatId): void
    {
        $config = self::getConfig();
        $token = trim($plainToken) !== '' ? trim($plainToken) : self::getToken($config);
        if ($token === '') {
            throw new RuntimeException(__('Configure primero el token del bot de Telegram.', 'informeglpi'));
        }
        self::sendMessage(
            $token,
            trim($chatId),
            '<b>Songa | Informe Técnico</b>' . "\n" . 'La conexión con Telegram funciona correctamente.'
        );
    }

    public static function encryptToken(string $plainToken): string
    {
        $plainToken = trim($plainToken);
        if ($plainToken === '') {
            return '';
        }
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new RuntimeException(__('La extensión Sodium de PHP es necesaria para cifrar el token de Telegram.', 'informeglpi'));
        }

        $key = self::getEncryptionKey(true);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plainToken, $nonce, $key);
        return base64_encode($nonce . $cipher);
    }

    public static function getToken(array $config): string
    {
        $encoded = trim((string) ($config['telegram_bot_token_encrypted'] ?? ''));
        if ($encoded === '' || !function_exists('sodium_crypto_secretbox_open')) {
            return '';
        }

        try {
            $packed = base64_decode($encoded, true);
            if ($packed === false || strlen($packed) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                return '';
            }
            $nonce = substr($packed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($packed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::getEncryptionKey(false));
            return is_string($plain) ? $plain : '';
        } catch (Throwable $e) {
            \Toolbox::logInFile('informeglopi', 'Telegram: no se pudo descifrar el token guardado.');
            return '';
        }
    }

    private static function getConfig(): array
    {
        return \Config::getConfigurationValues('plugin:informeglpi');
    }

    private static function getEncryptionKey(bool $create): string
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new RuntimeException(__('La extensión Sodium de PHP no está disponible.', 'informeglpi'));
        }

        $directory = rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . '_plugins'
            . DIRECTORY_SEPARATOR . 'informeglpi';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException(__('No se pudo crear el directorio privado de configuración Telegram.', 'informeglpi'));
        }

        $path = $directory . DIRECTORY_SEPARATOR . 'telegram.key';
        if (!is_file($path) && $create) {
            $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
            $handle = @fopen($path, 'x');
            if ($handle !== false) {
                fwrite($handle, $key);
                fclose($handle);
                @chmod($path, 0600);
            }
        }

        $key = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException(__('No se encuentra la clave privada de cifrado Telegram. Vuelva a guardar el token.', 'informeglpi'));
        }

        return $key;
    }

    private static function getPendingReports(int $reviewerId, int $reportId = 0): array
    {
        global $DB;

        if ($reviewerId < 1) {
            return [];
        }

        $where = [
            'status' => 'pending_supervisor_signature',
            'reviewer_users_id' => $reviewerId,
        ];
        if ($reportId > 0) {
            $where['id'] = $reportId;
        }

        $reports = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'report_number', 'subject', 'asset_snapshot', 'prepared_by_users_id'],
            'FROM' => 'glpi_plugin_informeglopi_reports',
            'WHERE' => $where,
            'ORDER' => ['date_creation ASC'],
        ]) as $row) {
            $user = new \User();
            $technician = '';
            if ($user->getFromDB((int) $row['prepared_by_users_id'])) {
                $technician = trim((string) ($user->fields['firstname'] ?? '') . ' ' . (string) ($user->fields['realname'] ?? ''));
                if ($technician === '') {
                    $technician = (string) ($user->fields['name'] ?? '');
                }
            }

            $snapshot = json_decode((string) ($row['asset_snapshot'] ?? ''), true);
            if (!is_array($snapshot)) {
                $snapshot = [];
            }
            $fields = is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [];
            $assetName = trim((string) ($snapshot['asset_name'] ?? ''));
            $assetCode = trim((string) ($fields['otherserial'] ?? $fields['inventory_number'] ?? ''));
            if ($assetName === '') {
                $assetName = trim((string) ($row['subject'] ?? 'Activo GLPI'));
            }

            $reports[] = [
                'id' => (int) $row['id'],
                'number' => (string) $row['report_number'],
                'subject' => (string) ($row['subject'] ?? ''),
                'asset_name' => $assetName,
                'asset_code' => $assetCode,
                'technician' => $technician,
            ];
        }

        return $reports;
    }

    private static function buildDigestMessage(array $reports, string $period, array $config): string
    {
        $baseUrl = rtrim(trim((string) ($config['telegram_glpi_base_url'] ?? '')), '/');
        $morningTime = (string) ($config['telegram_morning_time'] ?? '07:00');
        $eveningTime = (string) ($config['telegram_evening_time'] ?? '17:00');
        $title = $period === 'immediate'
            ? '📋 <b>Informe técnico listo para tu firma</b>'
            : ($period === 'morning'
                ? '🌅 <b>Recordatorio de informes pendientes (' . self::escape($morningTime) . ')</b>'
                : '🌇 <b>Recordatorio de informes pendientes (' . self::escape($eveningTime) . ')</b>');

        $lines = [
            '<b>Songa | Informe Técnico</b>',
            $title,
            'Pendientes: <b>' . count($reports) . '</b>',
            '',
        ];

        $shown = 0;
        foreach ($reports as $report) {
            if ($shown >= 10) {
                break;
            }
            $shown++;
            $lines[] = '<b>' . self::escape((string) $report['number']) . '</b>';
            $lines[] = 'Activo: ' . self::escape((string) $report['asset_name'])
                . ((string) $report['asset_code'] !== '' ? ' (' . self::escape((string) $report['asset_code']) . ')' : '');
            if ((string) $report['technician'] !== '') {
                $lines[] = 'Técnico: ' . self::escape((string) $report['technician']);
            }
            if ($baseUrl !== '') {
                $url = $baseUrl . '/plugins/informeglpi/front/report.edit.form.php?id=' . (int) $report['id'];
                $lines[] = '<a href="' . self::escape($url) . '">Abrir informe en GLPI</a>';
            }
            $lines[] = '';
        }

        if (count($reports) > $shown) {
            $lines[] = 'Y ' . (count($reports) - $shown) . ' informe(s) adicional(es).';
        }

        return implode("\n", $lines);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function sendMessage(string $token, string $chatId, string $message): void
    {
        if (trim($token) === '' || !preg_match('/^-?[0-9]+$/', trim($chatId))) {
            throw new RuntimeException(__('El token o el Chat ID de Telegram no son válidos.', 'informeglpi'));
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException(__('La extensión cURL de PHP es necesaria para conectar con Telegram.', 'informeglpi'));
        }

        $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
        $payload = json_encode([
            'chat_id' => trim($chatId),
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if (!is_string($response) || $status < 200 || $status >= 300) {
            throw new RuntimeException(__('Telegram no respondió correctamente.', 'informeglpi') . ($error !== '' ? ' ' . $error : ' HTTP ' . $status));
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            $description = is_array($decoded) ? (string) ($decoded['description'] ?? '') : '';
            throw new RuntimeException(__('Telegram rechazó el mensaje.', 'informeglpi') . ($description !== '' ? ' ' . $description : ''));
        }
    }
}
