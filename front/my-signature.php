<?php
declare(strict_types=1);

use GlpiPlugin\\Informeglopi\\UserSignatureTab;

include('../../../inc/includes.php');
Session::checkLoginUser();

$isHelpdesk = Session::getCurrentInterface() === 'helpdesk';
$rootDoc = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/');
if ($isHelpdesk) {
    Html::helpHeader(__('Mi firma personal', 'informeglpi'));
    $backUrl = $rootDoc . '/Helpdesk';
    $backLabel = 'Volver al Helpdesk';
} else {
    Html::header(__('Mi firma personal', 'informeglpi'), $_SERVER['PHP_SELF'], 'plugins');
    $backUrl = $rootDoc . '/front/central.php';
    $backLabel = 'Volver a la página principal';
}

echo "<div class='container-fluid' style='max-width:1100px;margin:1rem auto'>";
echo "<div class='d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3'><h2>Mi firma personal</h2>";
$backUrl = htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8');
echo "<a class='btn btn-outline-secondary' href='{$backUrl}'>{$backLabel}</a></div>";
echo "<div class='alert alert-info' role='note'><h3 class='h5'>Administre su firma personal</h3>";
echo "<p class='mb-2'>Puede registrar su firma o actualizarla desde esta página. Para dibujarla con mayor comodidad, utilice una pantalla táctil o el mouse.</p>";
echo "<p class='mb-0'>Su firma y su PIN son personales. No comparta su cuenta ni su PIN con otras personas.</p></div>";
if (!empty($_GET['welcome'])) {
    echo "<div class='alert alert-warning' role='status'><strong>Aún no tiene una firma personal registrada.</strong> Configure su firma para poder utilizarla cuando autorice actas de entrega e informes técnicos.</div>";
}
UserSignatureTab::render();
echo "</div>";

if ($isHelpdesk) {
    Html::helpFooter();
} else {
    Html::footer();
}
