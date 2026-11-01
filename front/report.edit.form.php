<?php
declare(strict_types=1);

use GlpiPlugin\Informeglopi\Report;
use GlpiPlugin\Informeglopi\ReportService;

include('../../../inc/includes.php');

Session::checkLoginUser();

if (!Session::haveRight(Report::$rightname, READ)) {
    Html::displayRightError();
}

$reportId = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;
$report = new Report();
if ($reportId < 1 || !$report->getFromDB($reportId)) {
    Html::displayNotFoundError();
}
if (!Session::haveAccessToEntity(
    (int) ($report->fields['entities_id'] ?? -1),
    (bool) ($report->fields['is_recursive'] ?? false)
)) {
    Html::displayRightError();
}

$userId = (int) Session::getLoginUserID();
$canEdit = $userId === (int) ($report->fields['users_id'] ?? 0)
    || Session::haveRight(Report::$rightname, UPDATE);
if (!$canEdit || ($report->fields['status'] ?? '') !== 'draft') {
    Html::displayRightError();
}

$errorMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        ReportService::updateDraft($reportId, $_POST);
        Session::addMessageAfterRedirect(__('Borrador actualizado correctamente.', 'informeglpi'), true, INFO);
        Html::redirect($CFG_GLPI['root_doc'] . '/plugins/informeglpi/front/report.php?id=' . $reportId);
        exit;
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$e = static fn($value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$damageType = (string) ($report->fields['damage_type'] ?? '');

Html::header(__('Editar borrador', 'informeglpi'), $_SERVER['PHP_SELF'], 'assets', 'computer');

echo "<div class='card'><div class='card-header'><h3 class='card-title'>" . $e($report->fields['report_number'] ?? '') . "</h3></div><div class='card-body'>";
if ($errorMessage !== '') {
    echo "<div class='alert alert-danger'>" . $e($errorMessage) . '</div>';
}
echo "<form method='post' action=''>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo Html::hidden('id', ['value' => $reportId]);

echo "<fieldset class='mb-3'><legend>" . $e(__('Clasificación del daño', 'informeglpi')) . "</legend>";
echo "<div class='form-check'><input class='form-check-input' type='radio' name='damage_type' id='damage_property' value='property_damage' " . ($damageType === 'property_damage' ? 'checked' : '') . "><label class='form-check-label' for='damage_property'>" . $e(__('Daño a la propiedad', 'informeglpi')) . "</label></div>";
echo "<div class='form-check'><input class='form-check-input' type='radio' name='damage_type' id='damage_wear' value='wear' " . ($damageType === 'wear' ? 'checked' : '') . "><label class='form-check-label' for='damage_wear'>" . $e(__('Desgaste', 'informeglpi')) . "</label></div>";
echo "</fieldset>";

echo "<div class='mb-3'><label class='form-label' for='book_value'>" . $e(__('Valor en libros (USD)', 'informeglpi')) . "</label>";
echo "<input class='form-control' type='number' min='0' step='0.01' id='book_value' name='book_value' value='" . $e($report->fields['book_value'] ?? '') . "'></div>";

$labels = [
    'background' => __('Antecedentes', 'informeglpi'),
    'diagnosis' => __('Diagnóstico técnico', 'informeglpi'),
    'cause' => __('Causa del daño o desgaste', 'informeglpi'),
    'activities' => __('Actividades y observaciones realizadas', 'informeglpi'),
    'recommendations' => __('Recomendaciones', 'informeglpi'),
    'observations' => __('Observaciones adicionales', 'informeglpi'),
];
foreach ($labels as $field => $label) {
    echo "<div class='mb-3'><label class='form-label' for='" . $e($field) . "'>" . $e($label) . "</label>";
    echo "<textarea class='form-control' rows='3' id='" . $e($field) . "' name='" . $e($field) . "'>" . $e($report->fields[$field] ?? '') . "</textarea></div>";
}

echo "<button type='submit' class='btn btn-primary'>" . $e(__('Guardar borrador', 'informeglpi')) . "</button> ";
echo "<a class='btn btn-secondary' href='" . $e($CFG_GLPI['root_doc'] . '/plugins/informeglpi/front/report.php?id=' . $reportId) . "'>" . $e(__('Cancelar', 'informeglpi')) . "</a>";
echo "</form></div></div>";

Html::footer();
