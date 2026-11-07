<?php
declare(strict_types=1);

namespace GlpiPlugin\\Informeglopi;

use CommonDBTM;
use CronTask;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Produces report notifications through the central FML Telegram queue.
 *
 * This class contains report workflow rules only. Bot credentials, Telegram
 * destinations, transport, retries and delivery history belong to FML Telegram.
 */
final class TelegramNotification extends CommonDBTM
{
    private const SOURCE = 'informeglpi';

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_informeglopi_reports';
    }

    public static function getTypeName($nb = 0): string
    {
        return __('Recordatorios de informes pendientes', 'informeglpi');
    }

    public static function cronInfo($name): array
    {
        if ($name === 'reminders') {
            return ['description' => __('Encolar recordatorios de informes pendientes para FML Telegram', 'informeglpi')];
        }

        return [];
    }

    /**
     * Enqueue one daily digest per assigned reviewer at the configured times.
     * The actual delivery is performed by FML Telegram's own worker.
     */
    public static function cronReminders($task = null): int
    {
        if (!class_exists(\\GlpiPlugin\\Telegram\\NotificationService::class)) {
            return 0;
        }
        $config = \\Config::getConfigurationValues('plugin:informeglpi');
        try {
            $timezone = new DateTimeZone('America/Guayaquil');
        } catch (Throwable $e) {
            $timezone = new DateTimeZone('UTC');
        }

        $now = new DateTimeImmutable('now', $timezone);
        $today = $now->format('Y-m-d');
        $currentTime = $now->format('H:i');
        $queued = 0;

        foreach ([
            'morning' => (string) ($config['telegram_morning_time'] ?? '07:00'),
            'evening' => (string) ($config['telegram_evening_time'] ?? '17:00'),
        ] as $period => $scheduledTime) {
            $sentKey = $period === 'morning' ? 'telegram_last_morning_date' : 'telegram_last_evening_date';
            $attemptKey = $period === 'morning' ? 'telegram_last_morning_attempt' : 'telegram_last_evening_attempt';

            if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $scheduledTime)
                || $currentTime < $scheduledTime
                || (string) ($config[$sentKey] ?? '') === $today) {
                continue;
            }

            $lastAttempt = trim((string) ($config[$attemptKey] ?? ''));
            if ($lastAttempt !== '' && (int) $lastAttempt > ($now->getTimestamp() - 900)) {
                continue;
            }

            \\Config::setConfigurationValues('plugin:informeglpi', [
                $attemptKey => (string) $now->getTimestamp(),
            ]);

            $reviewers = [];
            global $DB;
            foreach ($DB->request([
                'SELECT' => ['reviewer_users_id'],
                'FROM' => 'glpi_plugin_informeglopi_reports',
                'WHERE' => ['status' => 'pending_supervisor_signature'],
            ]) as $row) {
                $reviewerId = (int) ($row['reviewer_users_id'] ?? 0);
                if ($reviewerId > 0) {
                    $reviewers[$reviewerId] = true;
                }
            }

            foreach (array_keys($reviewers) as $reviewerId) {
                $reviewerId = (int) $reviewerId;
                $pending = self::getPendingReports($reviewerId);
                if ($pending === []) {
                    continue;
                }

                $subject = $period === 'morning'
                    ? 'Recordatorio de informes pendientes - mañana'
                    : 'Recordatorio de informes pendientes - tarde';
                $message = self::buildDigestMessage($pending, $period, $config);
                $result = self::enqueueToUser(
                    $reviewerId,
                    'report.pending_signature.reminder',
                    $subject,
                    $message,
                    'informeglpi:reminder:' . $period . ':' . $today . ':' . $reviewerId
                );
                $queued += (int) ($result['queued'] ?? 0);
            }

            // Mark this period after enqueue attempts. FML Telegram retains and
            // retries accepted messages independently of this reminder scheduler.
            \\Config::setConfigurationValues('plugin:informeglpi', [
                $sentKey => $today,
                $attemptKey => '',
            ]);
            $config[$sentKey] = $today;
            $config[$attemptKey] = '';
        }

        if ($task instanceof CronTask) {
            $task->addVolume($queued);
        }

        return $queued;
    }

    /** Notify the assigned reviewer after the technical signature is committed. */
    public static function notifyTechnicalSignature(int $reportId): void
    {
        global $DB;
        foreach ($DB->request([
            'SELECT' => ['reviewer_users_id', 'report_number'],
            'FROM' => 'glpi_plugin_informeglopi_reports',
            'WHERE' => ['id' => $reportId],
            'LIMIT' => 1,
        ]) as $row) {
            $reviewerId = (int) ($row['reviewer_users_id'] ?? 0);
            if ($reviewerId < 1) {
                return;
            }

            $pending = self::getPendingReports($reviewerId, $reportId);
            if ($pending === []) {
                return;
            }

            self::enqueueToUser(
                $reviewerId,
                'report.pending_signature',
                'Informe pendiente de revisión: ' . (string) $row['report_number'],
                self::buildDigestMessage($pending, 'immediate', self::getConfig()),
                'informeglpi:report.pending_signature:' . $reportId
            );
            return;
        }
    }

    /** Notify the report creator after the final PDF has been stored successfully. */
    public static function notifyReportFinalized(int $reportId): void
    {
        $row = self::getReportActors($reportId);
        if ($row === null) {
            return;
        }

        $message = "Songa | Informe Técnico\n"
            . "Informe aprobado y PDF generado.\n"
            . 'Informe: ' . (string) $row['report_number'] . "\n"
            . "El documento final está disponible para su descarga.\n"
            . self::reportPdfUrl($reportId);

        self::enqueueToUser(
            (int) $row['users_id'],
            'report.finalized',
            'Informe aprobado: ' . (string) $row['report_number'],
            $message,
            'informeglpi:report.finalized:' . $reportId
        );
    }

    /** Notify the creator and technical signer when the supervisor returns the report. */
    public static function notifyReportReturned(int $reportId, string $comment): void
    {
        $row = self::getReportActors($reportId);
        if ($row === null) {
            return;
        }

        $message = "Songa | Informe Técnico\n"
            . "Informe devuelto para corrección.\n"
            . 'Informe: ' . (string) $row['report_number'] . "\n"
            . "Motivo indicado por el supervisor:\n"
            . trim($comment) . "\n"
            . "Abrir informe para corregir:\n"
            . self::reportUrl($reportId);

        $recipients = array_unique([
            (int) $row['users_id'],
            (int) $row['prepared_by_users_id'],
        ]);
        foreach ($recipients as $userId) {
            self::enqueueToUser(
                (int) $userId,
                'report.returned',
                'Informe devuelto para corrección: ' . (string) $row['report_number'],
                $message,
                'informeglpi:report.returned:' . $reportId . ':' . (string) ($row['date_mod'] ?? '')
            );
        }
    }

    /**
     * Submit a notification to FML Telegram. A missing plugin, destination or
     * queue error is deliberately non-blocking for the report workflow.
     *
     * @return array{queued?:int, skipped?:int, errors?:array}
     */
    private static function enqueueToUser(
        int $userId,
        string $event,
        string $subject,
        string $message,
        string $deduplicationKey
    ): array {
        $serviceClass = \\GlpiPlugin\\Telegram\\NotificationService::class;
        if ($userId < 1 || !class_exists($serviceClass)
            || !is_callable([$serviceClass, 'enqueue'])
            || !is_callable([$serviceClass, 'getApiVersion'])) {
            return ['queued' => 0, 'skipped' => 0, 'errors' => []];
        }

        try {
            if (version_compare($serviceClass::getApiVersion(), '1.0', '<')) {
                return ['queued' => 0, 'skipped' => 0, 'errors' => []];
            }
            return $serviceClass::enqueue([
                'source' => self::SOURCE,
                'event' => $event,
                'subject' => mb_substr($subject, 0, 180, 'UTF-8'),
                'message' => mb_substr($message, 0, 3900, 'UTF-8'),
                'recipients' => ['user:' . $userId],
                'deduplication_key' => mb_substr($deduplicationKey, 0, 191, 'UTF-8'),
            ]);
        } catch (Throwable $exception) {
            try {
                \\Toolbox::logInFile(
                    'informeglopi',
                    'FML Telegram: no se pudo encolar notificación para usuario GLPI #' . $userId
                );
            } catch (Throwable $loggingException) {
                // Notification and logging failures must not affect report processing.
            }
            return ['queued' => 0, 'skipped' => 0, 'errors' => ['Unable to enqueue notification.']];
        }
    }

    private static function getReportActors(int $reportId): ?array
    {
        global $DB;
        foreach ($DB->request([
            'SELECT' => ['id', 'report_number', 'users_id', 'prepared_by_users_id', 'reviewer_users_id', 'date_mod'],
            'FROM' => 'glpi_plugin_informeglopi_reports',
            'WHERE' => ['id' => $reportId],
            'LIMIT' => 1,
        ]) as $row) {
            return $row;
        }
        return null;
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
            $user = new \\User();
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
        $morningTime = (string) ($config['telegram_morning_time'] ?? '07:00');
        $eveningTime = (string) ($config['telegram_evening_time'] ?? '17:00');
        $title = $period === 'immediate'
            ? 'Informe técnico listo para tu firma'
            : ($period === 'morning'
                ? 'Recordatorio de informes pendientes (' . $morningTime . ')'
                : 'Recordatorio de informes pendientes (' . $eveningTime . ')');

        $lines = [
            'Songa | Informe Técnico',
            $title,
            'Pendientes: ' . count($reports),
            '',
        ];

        $shown = 0;
        foreach ($reports as $report) {
            if ($shown >= 10) {
                break;
            }
            $shown++;
            $lines[] = 'Informe: ' . (string) $report['number'];
            $lines[] = 'Activo: ' . (string) $report['asset_name']
                . ((string) $report['asset_code'] !== '' ? ' (' . (string) $report['asset_code'] . ')' : '');
            if ((string) $report['technician'] !== '') {
                $lines[] = 'Técnico: ' . (string) $report['technician'];
            }
            $lines[] = 'Abrir en GLPI: ' . self::reportUrl((int) $report['id']);
            $lines[] = '';
        }

        if (count($reports) > $shown) {
            $lines[] = 'Y ' . (count($reports) - $shown) . ' informe(s) adicional(es).';
        }

        return implode("\n", $lines);
    }

    private static function getConfig(): array
    {
        return \\Config::getConfigurationValues('plugin:informeglpi');
    }

    private static function getBaseUrl(): string
    {
        try {
            $url = trim((string) \\Toolbox::getSystemURL());
            if ($url !== '') {
                return rtrim($url, '/');
            }
        } catch (Throwable $exception) {
            // Fall back to the URL saved by the former standalone integration.
        }

        $config = self::getConfig();
        return rtrim(trim((string) ($config['telegram_glpi_base_url'] ?? '')), '/');
    }

    private static function reportUrl(int $reportId): string
    {
        $base = self::getBaseUrl();
        return $base !== '' ? $base . '/plugins/informeglpi/front/report.edit.form.php?id=' . $reportId : '';
    }

    private static function reportPdfUrl(int $reportId): string
    {
        $base = self::getBaseUrl();
        return $base !== '' ? $base . '/plugins/informeglpi/front/report.pdf.php?id=' . $reportId : '';
    }
}
