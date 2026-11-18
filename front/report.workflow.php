<?php
declare(strict_types=1);

use GlpiPlugin\Informeglopi\ReportWorkflow;
use GlpiPlugin\Informeglopi\ReportPdf;

include('../../../inc/includes.php');
Session::checkLoginUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Html::displayErrorAndDie(__('Método no permitido.', 'informeglpi'));
}

$reportId = (int) ($_POST['id'] ?? 0);
$action = (string) ($_POST['workflow_action'] ?? '');

try {
    switch ($action) {
        case 'sign_technical':
            ReportWorkflow::signTechnical($reportId, (string) ($_POST['pin'] ?? ''));
            Session::addMessageAfterRedirect(__('Firma técnica registrada. El informe fue enviado al supervisor.', 'informeglpi'), true, INFO);
            break;
        case 'sign_supervisor':
            // Complete the supervisor's approval in one action: sign, generate,
            // finalize and enqueue the existing FML Telegram notification.
            ReportWorkflow::signSupervisor($reportId, (string) ($_POST['pin'] ?? ''));
            ReportPdf::generate($reportId);
            $pdfCreated = true;
            break;
        case 'return':
            ReportWorkflow::returnForCorrection($reportId, (string) ($_POST['comment'] ?? ''));
            Session::addMessageAfterRedirect(__('El informe fue devuelto al técnico para corrección.', 'informeglpi'), true, INFO);
            break;
        case 'generate_pdf':
            // Recovery path only: used if automatic generation previously failed.
            ReportPdf::generate($reportId);
            $pdfCreated = true;
            break;
        default:
            throw new RuntimeException(__('Acción de flujo no válida.', 'informeglpi'));
    }
} catch (Throwable $e) {
    Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
}

Html::redirect('/plugins/informeglpi/front/report.edit.form.php?id=' . $reportId . (!empty($pdfCreated) ? '&pdf_created=1' : ''));
exit;
