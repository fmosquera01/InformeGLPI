<?php
declare(strict_types=1);

use GlpiPlugin\Informeglopi\Report;
use GlpiPlugin\Informeglopi\ReportService;
use GlpiPlugin\Informeglopi\ReportTab;
use GlpiPlugin\Informeglopi\ReportWorkflow;

include('../../../inc/includes.php');
Session::checkLoginUser();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $reportId = (int) ($_GET['id'] ?? 0);
    Html::header(__('Editar informe técnico', 'informeglpi'), $_SERVER['PHP_SELF'], 'assets');
    ReportTab::renderEditPage($reportId);
    Html::footer();
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Html::displayErrorAndDie(__('Método no permitido.', 'informeglpi'));
}
$reportId = (int) ($_POST['id'] ?? 0);
$report = new Report();
if ($reportId < 1 || !$report->getFromDB($reportId)) {
    Html::displayNotFoundError();
}
$itemtype = (string) ($report->fields['itemtype'] ?? '');
$itemsId = (int) ($report->fields['items_id'] ?? 0);
$isManualPhone = $itemtype === 'ManualCellphone' && $itemsId === 0;
$item = null;
if (!$isManualPhone) {
    $item = class_exists($itemtype) ? new $itemtype() : null;
    if (!$item || !$item->getFromDB($itemsId)) {
        Html::displayNotFoundError();
    }
}
try {
    ReportService::updateDraft($reportId, $_POST);
    ReportService::addEvidence($reportId, $_FILES['evidence_files'] ?? []);
    if (($_POST['submit_action'] ?? '') === 'sign_technical') {
        ReportWorkflow::signTechnical($reportId, (string) ($_POST['technical_pin'] ?? ''));
        Session::addMessageAfterRedirect(__('Informe firmado y enviado al supervisor.', 'informeglpi'), true, INFO);
    } else {
        Session::addMessageAfterRedirect(__('Informe guardado correctamente.', 'informeglpi'), true, INFO);
    }
} catch (Throwable $e) {
    Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
}
if ($isManualPhone) {
    Html::redirect('/plugins/informeglpi/front/report.edit.form.php?id=' . $reportId);
} else {
    $tabUrl = $item->getFormURLWithID($itemsId) . '&forcetab=' . rawurlencode(ReportTab::class . '$1');
    Html::redirect('/plugins/informeglpi/front/report.edit.form.php?id=' . $reportId);
}
exit;
