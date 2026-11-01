<?php
declare(strict_types=1);

use GlpiPlugin\Informeglopi\AssetTypeRegistry;
use GlpiPlugin\Informeglopi\ReportService;

include('../../../inc/includes.php');

Session::checkLoginUser();

$itemtype = isset($_REQUEST['itemtype']) ? (string) $_REQUEST['itemtype'] : '';
$itemsId = isset($_REQUEST['items_id']) ? (int) $_REQUEST['items_id'] : 0;

if (!Session::haveRight('plugin_informeglopi', CREATE)) {
    Html::displayRightError();
}

$errorMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $createdId = ReportService::createDraft($itemtype, $itemsId);
        Html::redirect($CFG_GLPI['root_doc'] . '/plugins/informeglopi/front/report.php?id=' . $createdId);
        exit;
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

if (!AssetTypeRegistry::isSupported($itemtype) || $itemsId < 1 || !class_exists($itemtype)) {
    Html::displayErrorAndDie(__('Activo no válido para crear un informe técnico.', 'informeglopi'));
}

$item = new $itemtype();
if (!$item->getFromDB($itemsId) || !$item->can($itemsId, READ)) {
    Html::displayRightError();
}

Html::header(__('Nuevo informe técnico', 'informeglopi'), $_SERVER['PHP_SELF'], 'assets', 'computer');

echo "<div class='card'><div class='card-header'><h3 class='card-title'>" . htmlspecialchars(__('Crear informe técnico', 'informeglopi'), ENT_QUOTES, 'UTF-8') . "</h3></div>";
echo "<div class='card-body'>";
if ($errorMessage !== '') {
    echo "<div class='alert alert-danger'>" . htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') . '</div>';
}
echo "<div class='alert alert-info'>" . htmlspecialchars(__('Se creará un nuevo borrador y se guardará una copia de los datos actuales del activo. Los informes anteriores no se modificarán.', 'informeglopi'), ENT_QUOTES, 'UTF-8') . "</div>";
echo "<dl class='row'>";
echo "<dt class='col-sm-3'>" . htmlspecialchars(__('Tipo de activo', 'informeglopi'), ENT_QUOTES, 'UTF-8') . "</dt><dd class='col-sm-9'>" . htmlspecialchars($itemtype, ENT_QUOTES, 'UTF-8') . "</dd>";
echo "<dt class='col-sm-3'>" . htmlspecialchars(__('Activo', 'informeglopi'), ENT_QUOTES, 'UTF-8') . "</dt><dd class='col-sm-9'>" . htmlspecialchars((string) ($item->fields['name'] ?? ''), ENT_QUOTES, 'UTF-8') . "</dd>";
echo "<dt class='col-sm-3'>" . htmlspecialchars(__('Número de serie', 'informeglopi'), ENT_QUOTES, 'UTF-8') . "</dt><dd class='col-sm-9'>" . htmlspecialchars((string) ($item->fields['serial'] ?? '—'), ENT_QUOTES, 'UTF-8') . "</dd>";
echo "<dt class='col-sm-3'>" . htmlspecialchars(__('Activo fijo', 'informeglopi'), ENT_QUOTES, 'UTF-8') . "</dt><dd class='col-sm-9'>" . htmlspecialchars((string) ($item->fields['otherserial'] ?? '—'), ENT_QUOTES, 'UTF-8') . "</dd>";
echo "</dl>";
echo "<form method='post' action=''>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo Html::hidden('itemtype', ['value' => $itemtype]);
echo Html::hidden('items_id', ['value' => $itemsId]);
echo "<button type='submit' class='btn btn-primary'>" . htmlspecialchars(__('Crear borrador', 'informeglopi'), ENT_QUOTES, 'UTF-8') . "</button> ";
echo "<a class='btn btn-secondary' href='" . htmlspecialchars($item->getFormURLWithID($itemsId), ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars(__('Cancelar', 'informeglopi'), ENT_QUOTES, 'UTF-8') . "</a>";
echo "</form></div></div>";

Html::footer();
