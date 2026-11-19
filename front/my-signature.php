<?php
declare(strict_types=1);

use GlpiPlugin\\Informeglopi\\UserSignatureTab;

include('../../../inc/includes.php');
Session::checkLoginUser();

$isHelpdesk = Session::getCurrentInterface() === 'helpdesk';
if ($isHelpdesk) {
    Html::helpHeader(__('Mi firma personal', 'informeglpi'));
} else {
    Html::header(__('Mi firma personal', 'informeglpi'), $_SERVER['PHP_SELF'], 'plugins');
}

echo "<div class='container-fluid' style='max-width:1100px;margin:1rem auto'>";
echo "<div class='d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3'><h2>Mi firma personal</h2>";
echo "<a class='btn btn-outline-secondary' href='/Helpdesk'>Volver al Helpdesk</a></div>";
echo "<div class='alert alert-info' role='note'><h3 class='h5'>¿Cómo registrar su firma?</h3>";
echo "<p class='mb-2'>Para dibujar su firma, se recomienda utilizar una pantalla táctil de teléfono o tableta. Si está usando una computadora sin pantalla táctil, abra GLPI desde el navegador de su celular, inicie sesión con su propia cuenta y entre a <strong>Mi firma personal</strong> desde el menú del Helpdesk.</p>";
echo "<p class='mb-0'>La firma y el PIN son personales. No comparta su cuenta ni su PIN con el técnico ni con otra persona.</p></div>";
if (!empty($_GET['welcome'])) {
    echo "<div class='alert alert-warning' role='status'><strong>Aún no tiene una firma personal registrada.</strong> Puede configurarla ahora. Este registro permitirá utilizar su firma cuando autorice actas de entrega e informes.</div>";
}
UserSignatureTab::render();
echo "</div>";

if ($isHelpdesk) {
    Html::helpFooter();
} else {
    Html::footer();
}
