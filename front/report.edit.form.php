<?php
declare(strict_types=1);

use GlpiPlugin\Informeglopi\Report;
use GlpiPlugin\Informeglopi\ReportService;
use GlpiPlugin\Informeglopi\ReportTab;

include('../../../inc/includes.php');
Session::checkLoginUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Html::displayErrorAndDie(__('La edición debe realizarse desde la pestaña del activo.', 'informeglpi'));
}
$reportId = (int) ($_POST['id'] ?? 0);
$report = new Report();
if ($reportId < 1 || !$report->getFromDB($reportId)) {
    Html::displayNotFoundError();
}
$itemtype = (string) ($report->fields['itemtype'] ?? '');
$itemsId = (int) ($report->fields['items_id'] ?? 0);
$item = class_exists($itemtype) ? new $itemtype() : null;
if (!$item || !$item->getFromDB($itemsId)) {
    Html::displayNotFoundError();
}
try {
    ReportService::updateDraft($reportId, $_POST);
    ReportService::addEvidence($reportId, $_FILES['evidence_files'] ?? []);
    Session::addMessageAfterRedirect(__('Informe guardado correctamente.', 'informeglpi'), true, INFO);
} catch (Throwable $e) {
    Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
}
$tabUrl = $item->getFormURLWithID($itemsId) . '&forcetab=' . rawurlencode(ReportTab::class . '$1');
Html::redirect($tabUrl . '&report_view=drafts&report_id=' . $reportId);
exit;
