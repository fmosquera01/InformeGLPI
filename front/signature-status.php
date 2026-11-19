<?php
declare(strict_types=1);

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$userId = (int) Session::getLoginUserID();
if ($userId < 1) {
    http_response_code(403);
    echo json_encode(['pending' => false]);
    exit;
}

if (!empty($_SESSION['informeglpi_signature_prompt_seen_v101'])) {
    echo json_encode(['pending' => false]);
    exit;
}

$table = 'glpi_plugin_informeglopi_user_signatures';
if (!$DB->tableExists($table)) {
    echo json_encode(['pending' => false]);
    exit;
}

$configured = false;
foreach ($DB->request([
    'SELECT' => ['id'],
    'FROM' => $table,
    'WHERE' => ['users_id' => $userId],
    'LIMIT' => 1,
]) as $signature) {
    $configured = true;
    break;
}

// Mark this session before returning, so page reloads/navigation never repeat the prompt.
$_SESSION['informeglpi_signature_prompt_seen_v101'] = true;

echo json_encode(['pending' => !$configured], JSON_UNESCAPED_SLASHES);
