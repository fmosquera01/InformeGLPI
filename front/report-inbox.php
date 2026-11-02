<?php
declare(strict_types=1);

use GlpiPlugin\Informeglopi\ReportTab;

include('../../../inc/includes.php');
Session::checkLoginUser();
Html::header(__('Bandeja de entrada', 'informeglpi'), $_SERVER['PHP_SELF'], 'plugins');
ReportTab::renderSupervisorInboxPage();
Html::footer();
