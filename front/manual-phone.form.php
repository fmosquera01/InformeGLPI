<?php
declare(strict_types=1);

use GlpiPlugin\Informeglopi\ReportService;
use GlpiPlugin\Informeglopi\ReportWorkflow;

include('../../../inc/includes.php');
Session::checkLoginUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || (string) ($_POST['manual_cellphone'] ?? '') !== '1') {
    Html::displayErrorAndDie(__('Solicitud no válida para crear un informe de celular.', 'informeglpi'));
}

try {
    // The item type and item ID are fixed server-side; the request cannot choose another asset type.
    $reportId = ReportService::createDraft('ManualCellphone', 0, $_POST);
    ReportService::updateDraft($reportId, $_POST);
    ReportService::addEvidence($reportId, $_FILES['evidence_files'] ?? []);
    if (($_POST['submit_action'] ?? '') === 'sign_technical') {
        ReportWorkflow::signTechnical($reportId, (string) ($_POST['technical_pin'] ?? ''));
        Session::addMessageAfterRedirect(__('Informe firmado y enviado al supervisor.', 'informeglpi'), true, INFO);
    } else {
        Session::addMessageAfterRedirect(__('Borrador de celular guardado correctamente.', 'informeglpi'), true, INFO);
    }
    Html::redirect('/plugins/informeglpi/front/report.edit.form.php?id=' . $reportId);
} catch (Throwable $e) {
    Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    if (isset($reportId) && $reportId > 0) {
        Html::redirect('/plugins/informeglpi/front/report.edit.form.php?id=' . $reportId);
    }
    Html::redirect('/plugins/informeglpi/front/manual-phone.php');
}
exit;
