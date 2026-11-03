<?php
declare(strict_types=1);

namespace GlpiPlugin\Informeglopi;

use RuntimeException;
use Session;
use Throwable;

/** PIN-authorized, auditable report signature transitions. */
final class ReportWorkflow
{
    public static function signTechnical(int $reportId, string $pin): void
    {
        self::sign($reportId, $pin, 'technical');
    }

    public static function signSupervisor(int $reportId, string $pin): void
    {
        self::sign($reportId, $pin, 'supervisor');
    }

    private static function sign(int $reportId, string $pin, string $type): void
    {
        global $DB;
        if (!preg_match('/^[0-9]{6}$/', $pin)) {
            throw new RuntimeException(__('Ingrese su PIN personal de 6 dígitos.', 'informeglpi'));
        }
        if (!Session::haveRight(Report::$rightname, READ)) {
            throw new RuntimeException(__('No tiene permisos para consultar informes técnicos.', 'informeglpi'));
        }
        $report = new Report();
        if (!$report->getFromDB($reportId)) {
            throw new RuntimeException(__('No se encontró el informe.', 'informeglpi'));
        }
        if (!Session::haveAccessToEntity((int) $report->fields['entities_id'], (bool) $report->fields['is_recursive'])) {
            throw new RuntimeException(__('No tiene acceso a la entidad del informe.', 'informeglpi'));
        }
        $userId = (int) Session::getLoginUserID();
        $status = (string) $report->fields['status'];
        if ($type === 'technical') {
            if ($status !== 'draft' || $userId !== (int) $report->fields['prepared_by_users_id']) {
                throw new RuntimeException(__('Sólo el técnico asignado puede firmar un informe en preparación.', 'informeglpi'));
            }
            $nextStatus = 'pending_supervisor_signature';
        } else {
            if ($status !== 'pending_supervisor_signature'
                || $userId !== (int) $report->fields['reviewer_users_id']
                || !Session::haveRight('plugin_informeglpi_validate', UPDATE)) {
                throw new RuntimeException(__('Sólo el supervisor asignado con permiso de validación puede firmar este informe.', 'informeglpi'));
            }
            $nextStatus = 'approved_pending_pdf';
        }

        if ($type === 'technical' && $DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_plugin_informeglopi_evidences',
            'WHERE' => ['reports_id' => $reportId],
            'LIMIT' => 1,
        ])->count() === 0) {
            throw new RuntimeException(__('Debe adjuntar al menos una fotografía del equipo antes de firmar y enviar el informe.', 'informeglpi'));
        }

        if ($type === 'technical') {
            $missing = ReportService::getMissingFields($reportId);
            if ($missing !== []) {
                throw new RuntimeException(__('No se puede firmar. Complete los siguientes campos antes de continuar:', 'informeglpi') . "\n• " . implode("\n• ", $missing));
            }
            // Capture the current GLPI inventory values at the moment of signature.
            if ((string) ($report->fields['itemtype'] ?? '') !== 'ManualCellphone') {
                $itemtype = (string) $report->fields['itemtype'];
                $item = new $itemtype();
                if (!$item->getFromDB((int) $report->fields['items_id'])) {
                    throw new RuntimeException(__('No se pudo volver a consultar el activo en GLPI.', 'informeglpi'));
                }
                $DB->update('glpi_plugin_informeglopi_reports', [
                    'asset_snapshot' => json_encode(AssetSnapshotBuilder::build($item), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ], ['id' => $reportId, 'status' => 'draft']);
            }
        }

        $signature = self::verifyUserPin($userId, $pin);
        $source = rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $signature['signature_path']);
        $source = self::privateFile($source);
        $binary = file_get_contents($source);
        if ($binary === false || !hash_equals($signature['signature_sha256'], hash('sha256', $binary))) {
            throw new RuntimeException(__('La firma personal no supera la verificación de integridad. Regístrela nuevamente desde su perfil.', 'informeglpi'));
        }

        $baseDir = rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '_plugins' . DIRECTORY_SEPARATOR . 'informeglpi' . DIRECTORY_SEPARATOR . 'signatures' . DIRECTORY_SEPARATOR . 'reports';
        if (!is_dir($baseDir) && !mkdir($baseDir, 0750, true) && !is_dir($baseDir)) {
            throw new RuntimeException(__('No se pudo crear el almacenamiento privado de firmas del informe.', 'informeglpi'));
        }
        $fileName = $reportId . '_' . $type . '_' . bin2hex(random_bytes(12)) . '.png';
        $destination = $baseDir . DIRECTORY_SEPARATOR . $fileName;
        if (file_put_contents($destination, $binary, LOCK_EX) === false) {
            throw new RuntimeException(__('No se pudo conservar la copia histórica de la firma.', 'informeglpi'));
        }
        chmod($destination, 0640);
        $relativePath = '_plugins/informeglpi/signatures/reports/' . $fileName;
        $hash = hash('sha256', $binary);
        $signedAt = date('Y-m-d H:i:s');

        $DB->beginTransaction();
        try {
            $existingId = 0;
            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM' => 'glpi_plugin_informeglopi_signatures',
                'WHERE' => ['reports_id' => $reportId, 'signature_type' => $type],
                'LIMIT' => 1,
            ]) as $row) {
                $existingId = (int) $row['id'];
                break;
            }
            $values = [
                'users_id' => $userId,
                'signature_path' => $relativePath,
                'signature_sha256' => $hash,
                'signed_at' => $signedAt,
            ];
            if ($existingId > 0) {
                $DB->update('glpi_plugin_informeglopi_signatures', $values, ['id' => $existingId]);
            } else {
                $values['reports_id'] = $reportId;
                $values['signature_type'] = $type;
                $DB->insert('glpi_plugin_informeglopi_signatures', $values);
            }
            $DB->update('glpi_plugin_informeglopi_reports', ['status' => $nextStatus], ['id' => $reportId, 'status' => $status]);
            $DB->insert('glpi_plugin_informeglopi_history', [
                'reports_id' => $reportId,
                'action' => $type . '_signed',
                'old_status' => $status,
                'new_status' => $nextStatus,
                'changes_json' => json_encode([
                    'signature_type' => $type,
                    'signature_sha256' => $hash,
                    'signed_at' => $signedAt,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'comment' => $type === 'technical' ? __('Firma técnica autorizada con PIN personal.', 'informeglpi') : __('Aprobación y firma del supervisor autorizada con PIN personal.', 'informeglpi'),
                'users_id' => $userId,
            ]);
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            @unlink($destination);
            throw $e;
        }
    }

    public static function returnForCorrection(int $reportId, string $comment): void
    {
        global $DB;
        $comment = trim($comment);
        if ($comment === '' || mb_strlen($comment) > 2000) {
            throw new RuntimeException(__('Indique un motivo de devolución de hasta 2000 caracteres.', 'informeglpi'));
        }
        $report = new Report();
        if (!$report->getFromDB($reportId)) {
            throw new RuntimeException(__('No se encontró el informe.', 'informeglpi'));
        }
        $userId = (int) Session::getLoginUserID();
        if (!Session::haveAccessToEntity((int) $report->fields['entities_id'], (bool) $report->fields['is_recursive'])) {
            throw new RuntimeException(__('No tiene acceso a la entidad del informe.', 'informeglpi'));
        }
        if ((string) $report->fields['status'] !== 'pending_supervisor_signature'
            || $userId !== (int) $report->fields['reviewer_users_id']
            || !Session::haveRight('plugin_informeglpi_validate', UPDATE)) {
            throw new RuntimeException(__('Sólo el supervisor asignado puede devolver este informe.', 'informeglpi'));
        }
        $DB->beginTransaction();
        try {
            $DB->update('glpi_plugin_informeglopi_reports', ['status' => 'returned_for_correction'], ['id' => $reportId, 'status' => 'pending_supervisor_signature']);
            $DB->insert('glpi_plugin_informeglopi_history', [
                'reports_id' => $reportId,
                'action' => 'returned',
                'old_status' => 'pending_supervisor_signature',
                'new_status' => 'returned_for_correction',
                'changes_json' => null,
                'comment' => $comment,
                'users_id' => $userId,
            ]);
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }

    private static function verifyUserPin(int $userId, string $pin): array
    {
        global $DB;
        $rowFound = null;
        foreach ($DB->request([
            'SELECT' => ['id', 'signature_path', 'signature_sha256', 'pin_hash', 'failed_attempts', 'locked_until'],
            'FROM' => 'glpi_plugin_informeglopi_user_signatures',
            'WHERE' => ['users_id' => $userId],
            'LIMIT' => 1,
        ]) as $row) {
            $rowFound = $row;
            break;
        }
        if (!is_array($rowFound)) {
            throw new RuntimeException(__('El usuario todavía no registra firma y PIN en su perfil GLPI.', 'informeglpi'));
        }
        $lockedUntil = (string) ($rowFound['locked_until'] ?? '');
        if ($lockedUntil !== '' && strtotime($lockedUntil) > time()) {
            throw new RuntimeException(__('Su PIN está bloqueado temporalmente por intentos fallidos. Intente más tarde.', 'informeglpi'));
        }
        if (!password_verify($pin, (string) $rowFound['pin_hash'])) {
            $failures = (int) $rowFound['failed_attempts'] + 1;
            $locked = $failures >= 5;
            $DB->update('glpi_plugin_informeglopi_user_signatures', [
                'failed_attempts' => $locked ? 0 : $failures,
                'locked_until' => $locked ? date('Y-m-d H:i:s', time() + 15 * 60) : null,
            ], ['id' => (int) $rowFound['id']]);
            throw new RuntimeException($locked
                ? __('PIN incorrecto. Por seguridad, el uso de su firma quedó bloqueado durante 15 minutos.', 'informeglpi')
                : __('PIN incorrecto. Verifique e intente nuevamente.', 'informeglpi'));
        }
        $DB->update('glpi_plugin_informeglopi_user_signatures', [
            'failed_attempts' => 0,
            'locked_until' => null,
        ], ['id' => (int) $rowFound['id']]);
        return $rowFound;
    }

    private static function privateFile(string $path): string
    {
        $root = realpath(rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '_plugins' . DIRECTORY_SEPARATOR . 'informeglpi' . DIRECTORY_SEPARATOR . 'signatures' . DIRECTORY_SEPARATOR . 'users');
        $file = realpath($path);
        if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
            throw new RuntimeException(__('No se encontró el archivo privado de firma personal.', 'informeglpi'));
        }
        return $file;
    }
}
