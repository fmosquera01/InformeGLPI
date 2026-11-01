<?php
declare(strict_types=1);

use GlpiPlugin\\Informeglopi\\ReportTab;

include('../../../inc/includes.php');
Session::checkLoginUser();

Html::header(__('Nuevo informe de celular no registrado', 'informeglpi'), $_SERVER['PHP_SELF'], 'plugins');
ReportTab::renderManualPhonePage();
Html::footer();
