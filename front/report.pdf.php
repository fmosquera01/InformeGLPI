<?php
declare(strict_types=1);

use GlpiPlugin\Informeglopi\ReportPdf;

include('../../../inc/includes.php');
Session::checkLoginUser();
ReportPdf::stream((int) ($_GET['id'] ?? 0));
