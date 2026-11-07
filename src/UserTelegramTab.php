<?php
declare(strict_types=1);

namespace GlpiPlugin\Informeglopi;

use CommonGLPI;

/** Per-user Telegram destination, editable by the user or GLPI administrators. */
final class UserTelegramTab extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return __('Telegram', 'informeglpi');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof \User) {
            return '';
        }
        $targetId = (int) $item->getID();
        if ($targetId !== (int) \Session::getLoginUserID() && !\Session::haveRight('config', UPDATE)) {
            return '';
        }
        return self::createTabEntry(self::getTypeName());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof \User) {
            return true;
        }
        $targetId = (int) $item->getID();
        if ($targetId !== (int) \Session::getLoginUserID() && !\Session::haveRight('config', UPDATE)) {
            return true;
        }
        global $DB;
        $chatId = '';
        foreach ($DB->request([
            'SELECT' => ['chat_id'],
            'FROM' => 'glpi_plugin_informeglopi_user_telegram',
            'WHERE' => ['users_id' => $targetId],
            'LIMIT' => 1,
        ]) as $row) {
            $chatId = (string) ($row['chat_id'] ?? '');
        }
        $escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $root = rtrim((string) ($GLOBALS['CFG_GLPI']['root_doc'] ?? ''), '/');
        echo "<div class='card mb-3' style='max-width:760px'><div class='card-header'><strong>" . $escape(__('Notificaciones personales de Telegram', 'informeglpi')) . "</strong></div><div class='card-body'>";
        echo "<p>" . $escape(__('Registre su Chat ID para recibir avisos de los informes técnicos que le correspondan. Si lo deja vacío, simplemente no recibirá mensajes; esto no afecta el flujo de GLPI.', 'informeglpi')) . "</p>";
        echo "<form method='post' action='" . $escape($root . "/plugins/informeglpi/front/user-telegram.form.php") . "'>";
        echo \Html::hidden('_glpi_csrf_token', ['value' => \Session::getNewCSRFToken(true)]);
        echo "<input type='hidden' name='users_id' value='" . $targetId . "'>";
        echo "<div class='mb-3'><label class='form-label' for='ig_telegram_chat_id'>" . $escape(__('Chat ID personal', 'informeglpi')) . "</label>";
        echo "<input class='form-control' style='max-width:360px' type='text' id='ig_telegram_chat_id' name='chat_id' value='" . $escape($chatId) . "' placeholder='Ej. 123456789'>";
        echo "<small class='form-text text-muted'>" . $escape(__('Inicie una conversación con el bot y registre aquí el Chat ID que le corresponde.', 'informeglpi')) . "</small></div>";
        echo "<button class='btn btn-primary' type='submit'>" . $escape(__('Guardar Chat ID', 'informeglpi')) . "</button> ";
        if ($chatId !== '') {
            echo "<button class='btn btn-outline-secondary' type='submit' name='clear' value='1'>" . $escape(__('Eliminar Chat ID', 'informeglpi')) . "</button>";
        }
        echo \Html::closeForm(false);
        echo "</div></div>";
        return true;
    }
}
