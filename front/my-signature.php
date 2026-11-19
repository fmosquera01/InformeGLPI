<?php
declare(strict_types=1);

use GlpiPlugin\\Informeglopi\\UserSignatureTab;

include('../../../inc/includes.php');
Session::checkLoginUser();

Html::header(__('Mi firma personal', 'informeglpi'), $_SERVER['PHP_SELF'], 'plugins', 'GlpiPlugin\\\\Informeglopi\\\\ManualPhoneMenu');
echo "<div class='container-fluid'><div class='d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3'><h2>" . htmlspecialchars(__('Mi firma personal', 'informeglpi'), ENT_QUOTES, 'UTF-8') . "</h2><a class='btn btn-outline-secondary' href='/plugins/informeglpi/front/home.php'>" . htmlspecialchars(__('Volver a Informe Técnico', 'informeglpi'), ENT_QUOTES, 'UTF-8') . "</a></div>";
UserSignatureTab::render();
echo "</div>";
Html::footer();
