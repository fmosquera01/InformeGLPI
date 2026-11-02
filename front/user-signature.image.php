<?php
declare(strict_types=1);

include('../../../inc/includes.php');

Session::checkLoginUser();

global $DB;
$userId = (int) Session::getLoginUserID();
if ($userId < 1) {
    Html::displayRightError();
}

$table = 'glpi_plugin_informeglopi_user_signatures';
if (!$DB->tableExists($table)) {
    Html::displayNotFoundError();
}

$record = null;
foreach ($DB->request([
    'SELECT' => ['signature_path', 'signature_sha256'],
    'FROM' => $table,
    'WHERE' => ['users_id' => $userId],
    'LIMIT' => 1,
]) as $row) {
    $record = $row;
    break;
}
if (!$record) {
    Html::displayNotFoundError();
}

$relativePath = (string) ($record['signature_path'] ?? '');
$fileName = basename($relativePath);
if ($fileName === '' || !preg_match('/^' . preg_quote((string) $userId, '/') . '_[a-f0-9]{32}\.png$/', $fileName)) {
    Html::displayNotFoundError();
}

$baseDir = rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . '_plugins'
    . DIRECTORY_SEPARATOR . 'informeglpi'
    . DIRECTORY_SEPARATOR . 'signatures'
    . DIRECTORY_SEPARATOR . 'users';
$base = realpath($baseDir);
$path = realpath($baseDir . DIRECTORY_SEPARATOR . $fileName);
if (!$base || !$path || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
    Html::displayNotFoundError();
}

$expectedHash = (string) ($record['signature_sha256'] ?? '');
$actualHash = hash_file('sha256', $path);
if ($expectedHash === '' || $actualHash === false || !hash_equals($expectedHash, $actualHash)) {
    Html::displayNotFoundError();
}

header('Content-Type: image/png');
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="personal-signature.png"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
readfile($path);
exit;
