<?php
declare(strict_types=1);

use GlpiPlugin\Informeglopi\ReportService;
use GlpiPlugin\Informeglopi\ReportTab;

include('../../../inc/includes.php');
Session::checkLoginUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Html::displayErrorAndDie(__('La creación debe realizarse desde la pestaña Nuevo del activo.', 'informeglpi'));
}

$itemtype = (string) ($_POST['itemtype'] ?? '');
$itemsId = (int) ($_POST['items_id'] ?? 0);
if (!class_exists($itemtype) || $itemsId < 1) {
    Html::displayErrorAndDie(__('Activo no válido para crear un informe técnico.', 'informeglpi'));
}
$item = new $itemtype();
if (!$item->getFromDB($itemsId) || !$item->can($itemsId, READ)) {
    Html::displayRightError();
}

try {
    $reportId = ReportService::createDraft($itemtype, $itemsId, $_POST);
    ReportService::updateDraft($reportId, $_POST);
    Session::addMessageAfterRedirect(__('Borrador guardado correctamente.', 'informeglpi'), true, INFO);
    $tabUrl = $item->getFormURLWithID($itemsId) . '&forcetab=' . rawurlencode(ReportTab::class . '$1');
    Html::redirect($tabUrl . '&report_view=drafts&report_id=' . $reportId);
    exit;
} catch (Throwable $e) {
    Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    $tabUrl = $item->getFormURLWithID($itemsId) . '&forcetab=' . rawurlencode(ReportTab::class . '$1');
    Html::redirect($tabUrl . '&report_view=new');
    exit;
}
