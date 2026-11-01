<?php
declare(strict_types=1);

use GlpiPlugin\Informeglopi\Report;

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!Session::haveRight(Report::$rightname, READ)) {
    Html::displayRightError();
}

$evidenceId = (int) ($_GET['id'] ?? 0);
if ($evidenceId < 1) {
    Html::displayNotFoundError();
}

global $DB;
$evidence = null;
foreach ($DB->request([
    'SELECT' => ['id', 'reports_id', 'mime_type', 'file_path', 'original_name'],
    'FROM' => 'glpi_plugin_informeglopi_evidences',
    'WHERE' => ['id' => $evidenceId],
    'LIMIT' => 1,
]) as $row) {
    $evidence = $row;
    break;
}
if (!$evidence) {
    Html::displayNotFoundError();
}

$report = new Report();
if (!$report->getFromDB((int) $evidence['reports_id'])) {
    Html::displayNotFoundError();
}
if (!Session::haveAccessToEntity(
    (int) ($report->fields['entities_id'] ?? -1),
    (bool) ($report->fields['is_recursive'] ?? false)
)) {
    Html::displayRightError();
}

$base = realpath(rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '_plugins' . DIRECTORY_SEPARATOR . 'informeglpi' . DIRECTORY_SEPARATOR . 'evidences');
$path = realpath(rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string) $evidence['file_path'], DIRECTORY_SEPARATOR));
if (!$base || !$path || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
    Html::displayNotFoundError();
}

$allowed = ['image/jpeg', 'image/png', 'image/webp'];
$mime = (string) $evidence['mime_type'];
if (!in_array($mime, $allowed, true)) {
    Html::displayNotFoundError();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="evidence-' . $evidenceId . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($path);
exit;
