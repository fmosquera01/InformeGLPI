<?php
declare(strict_types=1);

use GlpiPlugin\Informeglopi\Report;

include('../../../inc/includes.php');

Session::checkLoginUser();

if (!Session::haveRight(Report::$rightname, READ)) {
    Html::displayRightError();
}

$reportId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($reportId < 1) {
    Html::displayErrorAndDie(__('ID de informe no válido.', 'informeglpi'));
}

$report = new Report();
if (!$report->getFromDB($reportId)) {
    Html::displayNotFoundError();
}

if (!Session::haveAccessToEntity(
    (int) ($report->fields['entities_id'] ?? -1),
    (bool) ($report->fields['is_recursive'] ?? false)
)) {
    Html::displayRightError();
}

$snapshot = json_decode((string) ($report->fields['asset_snapshot'] ?? '{}'), true);
if (!is_array($snapshot)) {
    $snapshot = [];
}
$assetFields = is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [];
$currentUserId = (int) Session::getLoginUserID();
$canEditDraft = ($report->fields['status'] ?? '') === 'draft'
    && (
        $currentUserId === (int) ($report->fields['users_id'] ?? 0)
        || Session::haveRight(Report::$rightname, UPDATE)
    );

Html::header(__('Informe técnico', 'informeglpi'), $_SERVER['PHP_SELF'], 'assets', 'computer');

$e = static fn($value): string => htmlspecialchars((string) ($value ?? '—'), ENT_QUOTES, 'UTF-8');

echo "<div class='card mb-3'><div class='card-header d-flex justify-content-between align-items-center'><h3 class='card-title mb-0'>" . $e($report->fields['report_number'] ?? '') . "</h3>";
if ($canEditDraft) {
    $editUrl = '/plugins/informeglpi/front/report.edit.form.php?id=' . $reportId;
    echo "<a class='btn btn-primary btn-sm' href='" . $e($editUrl) . "'>" . $e(__('Editar borrador', 'informeglpi')) . '</a>';
}
echo '</div><div class="card-body">';
echo "<div class='row mb-3'>";
echo "<div class='col-md-3'><strong>" . $e(__('Estado', 'informeglpi')) . ":</strong> " . $e($report->fields['status'] ?? '') . "</div>";
echo "<div class='col-md-3'><strong>" . $e(__('Fecha de creación', 'informeglpi')) . ":</strong> " . $e($report->fields['date_creation'] ?? '') . "</div>";
echo "<div class='col-md-3'><strong>" . $e(__('Tipo de activo', 'informeglpi')) . ":</strong> " . $e($snapshot['itemtype'] ?? '') . "</div>";
$assetIdLabel = (($report->fields['itemtype'] ?? '') === 'ManualCellphone') ? __('No registrado en GLPI', 'informeglpi') : (string) ($snapshot['items_id'] ?? '');
echo "<div class='col-md-3'><strong>" . $e(__('ID del activo', 'informeglpi')) . ":</strong> " . $e($assetIdLabel) . "</div>";
echo "</div>";
echo "<div class='alert alert-info'>" . $e(__('Este informe conserva los datos del activo capturados al momento de crear el borrador. La edición del contenido y las firmas se incorporarán en la siguiente etapa.', 'informeglpi')) . "</div>";
echo "<h4>" . $e(__('Datos del activo (snapshot)', 'informeglpi')) . "</h4>";
echo "<div class='table-responsive'><table class='table table-sm table-striped'><tbody>";
if (($report->fields['itemtype'] ?? '') === 'ManualCellphone') {
    foreach ($assetFields as $key => $value) {
        if (is_array($value) || is_object($value)) {
            continue;
        }
        echo "<tr><th style='width:30%'>" . $e($key) . "</th><td>" . nl2br($e($value)) . "</td></tr>";
    }
} else {
    $assetDescription = trim((string) ($assetFields['name'] ?? '') . ((string) ($assetFields['comment'] ?? '') !== '' ? "\n" . $assetFields['comment'] : ''));
    $purchaseDate = trim((string) ($assetFields['date_buy'] ?? ''));
    $purchaseDateDisplay = ($purchaseDate !== '' && !in_array($purchaseDate, ['0000-00-00', '0000-00-00 00:00:00'], true))
        ? Html::convDate($purchaseDate)
        : '—';
    $assetDescription .= "\n" . __('FECHA DE COMPRA:', 'informeglpi') . ' ' . $purchaseDateDisplay;
    if (($report->fields['book_value'] ?? '') !== '' && $report->fields['book_value'] !== null) {
        $assetDescription .= "\n" . __('VALOR EN LIBROS (USD):', 'informeglpi') . ' $' . number_format((float) $report->fields['book_value'], 2, '.', ',');
    }
    echo "<tr><th style='width:30%'>" . $e(__('DESCRIPCIÓN DEL ACTIVO', 'informeglpi')) . "</th><td>" . nl2br($e($assetDescription)) . "</td></tr>";
    foreach ($assetFields as $key => $value) {
        if (is_array($value) || is_object($value) || in_array($key, ['name', 'comment', 'date_buy'], true)) {
            continue;
        }
        echo "<tr><th style='width:30%'>" . $e($key) . "</th><td>" . nl2br($e($value)) . "</td></tr>";
    }
}
echo "</tbody></table></div>";
$damageLabels = [
    'property_damage' => __('Daño a la propiedad', 'informeglpi'),
    'wear' => __('Desgaste', 'informeglpi'),
];
$damageType = (string) ($report->fields['damage_type'] ?? '');
echo "<h4>" . $e(__('Información técnica del informe', 'informeglpi')) . "</h4>";
echo "<dl class='row'>";
echo "<dt class='col-sm-3'>" . $e(__('Clasificación', 'informeglpi')) . "</dt><dd class='col-sm-9'>" . $e($damageLabels[$damageType] ?? '—') . "</dd>";
if (($report->fields['itemtype'] ?? '') === 'ManualCellphone') {
    echo "<dt class='col-sm-3'>" . $e(__('Valor en libros', 'informeglpi')) . "</dt><dd class='col-sm-9'>" . $e($report->fields['book_value'] ?? '—') . "</dd>";
}

echo "</dl>";
foreach ([
    'background' => __('Antecedentes', 'informeglpi'),
    'diagnosis' => __('Diagnóstico técnico', 'informeglpi'),
    'cause' => __('Causa del daño o desgaste', 'informeglpi'),
    'activities' => __('Actividades y observaciones realizadas', 'informeglpi'),
    'recommendations' => __('Recomendaciones', 'informeglpi'),
    'observations' => __('Observaciones adicionales', 'informeglpi'),
] as $field => $label) {
    $value = trim((string) ($report->fields[$field] ?? ''));
    if ($value !== '') {
        echo "<div class='mb-3'><strong>" . $e($label) . "</strong><div class='border rounded p-2'>" . nl2br($e($value)) . "</div></div>";
    }
}

$itemtype = (string) ($report->fields['itemtype'] ?? '');
$itemsId = (int) ($report->fields['items_id'] ?? 0);
if ($itemtype !== '' && $itemsId > 0) {
    $assetUrl = '/front/' . strtolower($itemtype) . '.form.php?id=' . $itemsId;
    echo "<a class='btn btn-secondary' href='" . $e($assetUrl) . "'>" . $e(__('Volver al activo', 'informeglpi')) . "</a>";
}
echo "</div></div>";

Html::footer();
