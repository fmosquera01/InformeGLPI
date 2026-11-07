<?php
declare(strict_types=1);

include('../../../inc/includes.php');
Session::checkLoginUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Html::displayErrorAndDie(__('Método no permitido.', 'informeglpi'));
}

global $DB;
$targetId = (int) ($_POST['users_id'] ?? 0);
$currentId = (int) Session::getLoginUserID();
if ($targetId < 1 || ($targetId !== $currentId && !Session::haveRight('user', UPDATE))) {
    Html::displayRightError();
}
$chatId = trim((string) ($_POST['chat_id'] ?? ''));
try {
    if (empty($_POST['clear']) && $chatId !== '' && !preg_match('/^-?[0-9]+$/', $chatId)) {
        throw new RuntimeException(__('El Chat ID debe contener solamente números.', 'informeglpi'));
    }
    $table = 'glpi_plugin_informeglopi_user_telegram';
    $existingId = 0;
    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => ['users_id' => $targetId], 'LIMIT' => 1]) as $row) {
        $existingId = (int) $row['id'];
    }
    if (!empty($_POST['clear']) || $chatId === '') {
        if ($existingId > 0) {
            $DB->delete($table, ['id' => $existingId]);
        }
        Session::addMessageAfterRedirect(__('Chat ID eliminado. No se enviarán notificaciones a este usuario.', 'informeglpi'), true, INFO);
    } elseif ($existingId > 0) {
        $DB->update($table, ['chat_id' => $chatId, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $existingId]);
        Session::addMessageAfterRedirect(__('Chat ID de Telegram actualizado correctamente.', 'informeglpi'), true, INFO);
    } else {
        $DB->insert($table, ['users_id' => $targetId, 'chat_id' => $chatId, 'date_creation' => date('Y-m-d H:i:s'), 'date_mod' => date('Y-m-d H:i:s')]);
        Session::addMessageAfterRedirect(__('Chat ID de Telegram registrado correctamente.', 'informeglpi'), true, INFO);
    }
} catch (Throwable $e) {
    Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
}
Html::redirect('/front/user.form.php?id=' . $targetId);
exit;
