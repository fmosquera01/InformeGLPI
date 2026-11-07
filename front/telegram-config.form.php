<?php
declare(strict_types=1);

use GlpiPlugin\Informeglopi\TelegramNotification;

include('../../../inc/includes.php');
Session::checkLoginUser();

if (!Session::haveRight('config', UPDATE)) {
    Html::displayRightError();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Html::displayErrorAndDie(__('Método no permitido.', 'informeglpi'));
}

// GLPI 11 valida automáticamente el token CSRF en CheckCsrfListener.
// No repetir Session::checkCSRF() aquí: el token ya fue consumido por el listener.

$action = (string) ($_POST['action'] ?? 'save');
$config = Config::getConfigurationValues('plugin:informeglpi');
$tokenInput = trim((string) ($_POST['telegram_bot_token'] ?? ''));
$chatId = '';

try {
    if ($action === 'test') {
        TelegramNotification::sendTestMessage($tokenInput, $chatId);
        Session::addMessageAfterRedirect(__('Mensaje de prueba enviado correctamente a Telegram.', 'informeglpi'), true, INFO);
    } elseif ($action === 'save') {
        if ($chatId !== '' && !preg_match('/^-?[0-9]+$/', $chatId)) {
            throw new RuntimeException(__('El Chat ID debe contener solamente números.', 'informeglpi'));
        }

        $baseUrl = trim((string) ($_POST['telegram_glpi_base_url'] ?? ''));
        if ($baseUrl !== '') {
            $parts = parse_url($baseUrl);
            if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
                || trim((string) ($parts['host'] ?? '')) === '') {
                throw new RuntimeException(__('Ingrese una URL base válida de GLPI (http o https).', 'informeglpi'));
            }
            $baseUrl = rtrim($baseUrl, '/');
        }

        $morning = trim((string) ($_POST['telegram_morning_time'] ?? '07:00'));
        $evening = trim((string) ($_POST['telegram_evening_time'] ?? '17:00'));
        foreach ([$morning, $evening] as $time) {
            if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
                throw new RuntimeException(__('Las horas deben tener formato HH:MM.', 'informeglpi'));
            }
        }

        $encryptedToken = (string) ($config['telegram_bot_token_encrypted'] ?? '');
        if ($tokenInput !== '') {
            $encryptedToken = TelegramNotification::encryptToken($tokenInput);
        }

        $values = [
            'telegram_enabled' => (int) ($_POST['telegram_enabled'] ?? 0) === 1 ? 1 : 0,
            'telegram_bot_token_encrypted' => $encryptedToken,
            'telegram_glpi_base_url' => $baseUrl,
            'telegram_notify_immediate' => isset($_POST['telegram_notify_immediate']) ? 1 : 0,
            'telegram_notify_morning' => isset($_POST['telegram_notify_morning']) ? 1 : 0,
            'telegram_notify_evening' => isset($_POST['telegram_notify_evening']) ? 1 : 0,
            'telegram_morning_time' => $morning,
            'telegram_evening_time' => $evening,
            'telegram_timezone' => 'America/Guayaquil',
        ];

        if ((int) $values['telegram_enabled'] === 1 && $encryptedToken === '') {
            throw new RuntimeException(__('Para activar Telegram debe configurar el token del bot.', 'informeglpi'));
        }
        if ((int) $values['telegram_enabled'] === 1 && $baseUrl === '') {
            throw new RuntimeException(__('Configure la URL base de GLPI para que los mensajes incluyan enlaces al informe y al PDF.', 'informeglpi'));
        }

        Config::setConfigurationValues('plugin:informeglpi', $values);
        Session::addMessageAfterRedirect(__('Configuración de Telegram guardada correctamente.', 'informeglpi'), true, INFO);
    } else {
        throw new RuntimeException(__('Acción no válida.', 'informeglpi'));
    }
} catch (Throwable $e) {
    Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
}

global $CFG_GLPI;
$rootDoc = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/');
Html::redirect($rootDoc . '/front/config.form.php?forcetab=' . rawurlencode(GlpiPlugin\Informeglopi\TelegramConfig::class . '$1'));
exit;
