<?php
declare(strict_types=1);

namespace GlpiPlugin\Informeglopi;

use CommonGLPI;
use Dropdown;
use Html;
use Session;

/**
 * Telegram configuration tab under GLPI Setup > General.
 * The bot token is never rendered back into the form.
 */
final class TelegramConfig extends \Config
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof \Config) {
            return self::createTabEntry(__('Telegram', 'informeglpi'));
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof \Config) {
            self::showTelegramConfig();
        }

        return true;
    }

    public static function showTelegramConfig(): void
    {
        global $CFG_GLPI;

        if (!Session::haveRight('config', UPDATE)) {
            echo "<div class='alert alert-warning'>" . htmlspecialchars(__('No tiene permisos para configurar las notificaciones Telegram.', 'informeglpi'), ENT_QUOTES, 'UTF-8') . "</div>";
            return;
        }

        $config = \Config::getConfigurationValues('plugin:informeglpi');
        $hasToken = trim((string) ($config['telegram_bot_token_encrypted'] ?? '')) !== '';
        $rootDoc = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/');
        $action = $rootDoc . '/plugins/informeglpi/front/telegram-config.form.php';
        $escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        echo "<div class='spaced' style='max-width:900px'>";
        echo "<h3>" . $escape(__('Notificaciones de informes por Telegram', 'informeglpi')) . "</h3>";
        echo "<p class='text-muted'>" . $escape(__('Reciba un aviso cuando el técnico firme un informe y recordatorios de los informes que esperan su firma.', 'informeglpi')) . "</p>";
        echo "<form method='post' action='" . $escape($action) . "'>";
        // Use a dedicated token for this form to avoid collisions with other GLPI forms on the Config page.
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken(true)]);

        echo "<div class='form-field mb-3'>";
        echo "<label class='form-label' for='telegram_enabled'>" . $escape(__('Activar módulo Telegram', 'informeglpi')) . "</label>";
        echo "<select class='form-select' id='telegram_enabled' name='telegram_enabled'>";
        foreach ([1 => __('Sí', 'informeglpi'), 0 => __('No', 'informeglpi')] as $value => $label) {
            $selected = (int) ($config['telegram_enabled'] ?? 0) === $value ? ' selected' : '';
            echo "<option value='{$value}'{$selected}>" . $escape($label) . "</option>";
        }
        echo "</select></div>";

        echo "<div class='form-field mb-3'>";
        echo "<label class='form-label' for='telegram_bot_token'>" . $escape(__('Token del bot (BotFather)', 'informeglpi')) . "</label>";
        echo "<input class='form-control' type='password' id='telegram_bot_token' name='telegram_bot_token' value='' autocomplete='new-password' placeholder='" . $escape($hasToken ? __('Token guardado; deje vacío para conservarlo', 'informeglpi') : __('Pegue aquí el token del bot', 'informeglpi')) . "'>";
        echo "<small class='form-text text-muted'>" . $escape($hasToken ? __('Hay un token cifrado guardado. Nunca se muestra nuevamente.', 'informeglpi') : __('El token se cifra en el servidor y no se almacena en texto plano en la base de datos.', 'informeglpi')) . "</small>";
        echo "</div>";

        echo "<div class='form-field mb-3'>";
        echo "<label class='form-label' for='telegram_chat_id'>" . $escape(__('Chat ID personal de Telegram', 'informeglpi')) . "</label>";
        echo "<input class='form-control' type='text' id='telegram_chat_id' name='telegram_chat_id' value='" . $escape($config['telegram_chat_id'] ?? '') . "' placeholder='Ej. 123456789'>";
        echo "</div>";

        echo "<div class='form-field mb-3'>";
        echo "<label class='form-label'>" . $escape(__('Usuario GLPI responsable de firmar', 'informeglpi')) . "</label>";
        Dropdown::show('User', [
            'name' => 'telegram_reviewer_users_id',
            'value' => (int) ($config['telegram_reviewer_users_id'] ?? 0),
            'display_emptychoice' => true,
        ]);
        echo "<small class='form-text text-muted'>" . $escape(__('Solo se notificarán informes cuyo responsable de revisión sea este usuario.', 'informeglpi')) . "</small>";
        echo "</div>";

        echo "<div class='form-field mb-3'>";
        echo "<label class='form-label' for='telegram_glpi_base_url'>" . $escape(__('URL base de GLPI (ambiente de pruebas)', 'informeglpi')) . "</label>";
        echo "<input class='form-control' type='url' id='telegram_glpi_base_url' name='telegram_glpi_base_url' value='" . $escape($config['telegram_glpi_base_url'] ?? '') . "' placeholder='http://glpi.local'>";
        echo "</div>";

        echo "<hr><h4>" . $escape(__('Notificaciones', 'informeglpi')) . "</h4>";
        $checks = [
            'telegram_notify_immediate' => __('Aviso inmediato cuando firma el técnico', 'informeglpi'),
            'telegram_notify_morning' => __('Recordatorio diario de la mañana', 'informeglpi'),
            'telegram_notify_evening' => __('Recordatorio diario de la tarde', 'informeglpi'),
        ];
        foreach ($checks as $name => $label) {
            $checked = (int) ($config[$name] ?? 0) === 1 ? ' checked' : '';
            echo "<div class='form-check mb-2'><input class='form-check-input' type='checkbox' id='{$name}' name='{$name}' value='1'{$checked}>";
            echo "<label class='form-check-label' for='{$name}'>" . $escape($label) . "</label></div>";
        }

        echo "<div class='row g-3 mt-1'>";
        foreach ([
            'telegram_morning_time' => __('Hora del primer recordatorio', 'informeglpi'),
            'telegram_evening_time' => __('Hora del segundo recordatorio', 'informeglpi'),
        ] as $name => $label) {
            $value = $escape($config[$name] ?? ($name === 'telegram_morning_time' ? '07:00' : '17:00'));
            echo "<div class='col-md-6'><label class='form-label' for='{$name}'>" . $escape($label) . "</label>";
            echo "<input class='form-control' type='time' id='{$name}' name='{$name}' value='{$value}' required></div>";
        }
        echo "</div>";
        echo "<div class='form-text mt-2'>" . $escape(__('Se ejecuta todos los días. Zona horaria: America/Guayaquil.', 'informeglpi')) . "</div>";

        echo "<div class='d-flex flex-wrap gap-2 mt-4'>";
        echo "<button class='btn btn-primary' type='submit' name='action' value='save'><i class='ti ti-device-floppy'></i> " . $escape(__('Guardar configuración', 'informeglpi')) . "</button>";
        echo "<button class='btn btn-outline-secondary' type='submit' name='action' value='test'><i class='ti ti-send'></i> " . $escape(__('Enviar mensaje de prueba', 'informeglpi')) . "</button>";
        echo "</div>";
        echo "</form>";
        echo "</div>";
    }
}
