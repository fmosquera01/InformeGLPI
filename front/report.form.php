<?php
declare(strict_types=1);

use GlpiPlugin\Informeglopi\ReportService;
use GlpiPlugin\Informeglopi\ReportTab;

include('../../../inc/includes.php');
Session::checkLoginUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Html::displayErrorAndDie(__('La creación debe realizarse desde la pestaña del activo.', 'informeglpi'));
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
    $reportId = ReportService::createDraft($itemtype, $itemsId);
    $tabUrl = $item->getFormURLWithID($itemsId) . '&forcetab=' . rawurlencode(ReportTab::class . '$1');
    Session::addMessageAfterRedirect(__('Informe creado. Complete la información técnica en esta misma ficha.', 'informeglpi'), true, INFO);
    Html::redirect($tabUrl . '&report_id=' . $reportId);
    exit;
} catch (Throwable $e) {
    Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    $tabUrl = $item->getFormURLWithID($itemsId) . '&forcetab=' . rawurlencode(ReportTab::class . '$1');
    Html::redirect($tabUrl);
    exit;
}
