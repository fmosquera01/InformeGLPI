<?php
declare(strict_types=1);

namespace GlpiPlugin\\Informeglopi;

use RuntimeException;
use Session;
use Throwable;

/**
 * Creates the immutable one-page institutional PDF after both signatures.
 * Original evidence is never modified; temporary resized copies are used only
 * while composing the PDF.
 */
final class ReportPdf
{
    public static function generate(int $reportId): void
    {
        global $DB;
        $report = new Report();
        if (!$report->getFromDB($reportId)) {
            throw new RuntimeException(__('No se encontró el informe.', 'informeglpi'));
        }
        $fields = $report->fields;
        $userId = (int) Session::getLoginUserID();
        if ((string) ($fields['status'] ?? '') !== 'approved_pending_pdf'
            || $userId !== (int) ($fields['reviewer_users_id'] ?? 0)
            || !Session::haveRight('plugin_informeglpi_validate', UPDATE)
            || !Session::haveAccessToEntity((int) $fields['entities_id'], (bool) $fields['is_recursive'])) {
            throw new RuntimeException(__('Sólo el supervisor asignado puede generar el PDF de un informe aprobado.', 'informeglpi'));
        }
        if (!class_exists('TCPDF')) {
            throw new RuntimeException(__('La biblioteca TCPDF no está disponible en esta instalación de GLPI.', 'informeglpi'));
        }

        $signatures = [];
        foreach ($DB->request([
            'SELECT' => ['signature_type', 'signature_path', 'signature_sha256', 'signed_at', 'users_id'],
            'FROM' => 'glpi_plugin_informeglopi_signatures',
            'WHERE' => ['reports_id' => $reportId],
        ]) as $row) {
            $path = self::privatePath((string) $row['signature_path']);
            $bytes = file_get_contents($path);
            if ($bytes === false || !hash_equals((string) $row['signature_sha256'], hash('sha256', $bytes))) {
                throw new RuntimeException(__('Una de las firmas no superó la verificación de integridad.', 'informeglpi'));
            }
            $signatures[(string) $row['signature_type']] = ['path' => $path, 'user' => getUserName((int) $row['users_id']), 'date' => (string) $row['signed_at']];
        }
        if (!isset($signatures['technical'], $signatures['supervisor'])) {
            throw new RuntimeException(__('No se encontraron las dos firmas requeridas.', 'informeglpi'));
        }

        $snapshot = json_decode((string) $fields['asset_snapshot'], true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $assetFields = is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [];
        $evidences = [];
        foreach ($DB->request([
            'SELECT' => ['file_path', 'sha256', 'mime_type', 'caption', 'original_name'],
            'FROM' => 'glpi_plugin_informeglopi_evidences',
            'WHERE' => ['reports_id' => $reportId],
            'ORDER' => ['id ASC'],
        ]) as $row) {
            $path = self::privatePath((string) $row['file_path']);
            $bytes = file_get_contents($path);
            if ($bytes === false || !hash_equals((string) $row['sha256'], hash('sha256', $bytes))) {
                throw new RuntimeException(__('Una fotografía no superó la verificación de integridad.', 'informeglpi'));
            }
            $evidences[] = ['path' => $path, 'caption' => (string) ($row['caption'] ?: $row['original_name'])];
        }
        if (!$evidences) {
            throw new RuntimeException(__('El informe debe conservar al menos una fotografía.', 'informeglpi'));
        }

        $tempFiles = [];
        try {
            $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            $pdf->SetCreator('GLPI - Informe Técnico');
            $pdf->SetAuthor('Songa - Infraestructura IT');
            $pdf->SetTitle((string) $fields['report_number']);
            $pdf->SetMargins(10, 8, 10);
            $pdf->SetAutoPageBreak(false, 8);
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->AddPage();
            $pdf->SetFont('helvetica', '', 7.2);
            $html = self::buildHtml($fields, $assetFields, $snapshot, $evidences, $signatures, $tempFiles);
            $pdf->writeHTML($html, true, false, true, false, '');
            if ($pdf->getNumPages() !== 1) {
                throw new RuntimeException(__('El contenido excede una página A4. Reduzca la extensión del texto o la cantidad de fotografías y vuelva a generar.', 'informeglpi'));
            }

            $base = rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '_plugins' . DIRECTORY_SEPARATOR . 'informeglpi' . DIRECTORY_SEPARATOR . 'reports';
            if (!is_dir($base) && !mkdir($base, 0750, true) && !is_dir($base)) {
                throw new RuntimeException(__('No se pudo crear el almacenamiento privado de informes.', 'informeglpi'));
            }
            $filename = 'informe_' . $reportId . '_' . bin2hex(random_bytes(8)) . '.pdf';
            $absolute = $base . DIRECTORY_SEPARATOR . $filename;
            $pdf->Output($absolute, 'F');
            if (!is_file($absolute) || filesize($absolute) < 1000) {
                throw new RuntimeException(__('TCPDF no generó un archivo PDF válido.', 'informeglpi'));
            }
            chmod($absolute, 0640);
            $hash = hash_file('sha256', $absolute);
            if (!is_string($hash)) {
                @unlink($absolute);
                throw new RuntimeException(__('No se pudo calcular la integridad del PDF.', 'informeglpi'));
            }
            $relative = '_plugins/informeglpi/reports/' . $filename;
            $DB->beginTransaction();
            try {
                $DB->update('glpi_plugin_informeglopi_reports', [
                    'pdf_path' => $relative,
                    'pdf_sha256' => $hash,
                    'finalized_at' => date('Y-m-d H:i:s'),
                    'finalized_users_id' => $userId,
                    'status' => 'finalized',
                ], ['id' => $reportId, 'status' => 'approved_pending_pdf']);
                $DB->insert('glpi_plugin_informeglopi_history', [
                    'reports_id' => $reportId,
                    'action' => 'pdf_generated',
                    'old_status' => 'approved_pending_pdf',
                    'new_status' => 'finalized',
                    'changes_json' => json_encode(['pdf_sha256' => $hash], JSON_THROW_ON_ERROR),
                    'comment' => __('PDF institucional generado y almacenado de forma privada.', 'informeglpi'),
                    'users_id' => $userId,
                ]);
                $DB->commit();
            } catch (Throwable $e) {
                $DB->rollBack();
                @unlink($absolute);
                throw $e;
            }
        } finally {
            foreach ($tempFiles as $tmp) {
                if (is_file($tmp)) @unlink($tmp);
            }
        }
    }

    public static function stream(int $reportId): void
    {
        global $DB;
        $report = new Report();
        if (!$report->getFromDB($reportId)) {
            Html::displayNotFoundError();
        }
        $f = $report->fields;
        if ((string) $f['status'] !== 'finalized'
            || !Session::haveRight(Report::$rightname, READ)
            || !Session::haveAccessToEntity((int) $f['entities_id'], (bool) $f['is_recursive'])) {
            Html::displayRightError();
        }
        $path = self::privatePath((string) ($f['pdf_path'] ?? ''));
        $hash = hash_file('sha256', $path);
        if (!is_string($hash) || !hash_equals((string) $f['pdf_sha256'], $hash)) {
            Html::displayErrorAndDie(__('El PDF no supera la verificación de integridad.', 'informeglpi'));
        }
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $f['report_number']) . '.pdf"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    private static function buildHtml(array $report, array $asset, array $snapshot, array $evidences, array $signatures, array &$tempFiles): string
    {
        $esc = static fn($v): string => htmlspecialchars((string) ($v ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $recipients = json_decode((string) ($report['recipients_json'] ?? '[]'), true);
        $recipientNames = [];
        foreach (is_array($recipients) ? $recipients : [] as $id) $recipientNames[] = getUserName((int) $id);
        $date = (string) ($report['report_date'] ?? '');
        $assetRows = [];
        $labels = [
            'name' => 'ACTIVO', 'otherserial' => 'CÓDIGO DE ACTIVO', 'serial' => 'SERIE',
            'manufacturers_id' => 'FABRICANTE', 'computermodels_id' => 'MODELO',
            'locations_id' => 'UBICACIÓN', 'date_buy' => 'FECHA DE COMPRA',
        ];
        foreach ($labels as $key => $label) {
            $value = $asset[$key] ?? '';
            if ($key === 'manufacturers_id' && (int) $value > 0) $value = Dropdown::getDropdownName(Manufacturer::getTable(), (int) $value);
            if ($key === 'locations_id' && (int) $value > 0) $value = Dropdown::getDropdownName(Location::getTable(), (int) $value);
            if ($key === 'date_buy' && $value) $value = Html::convDate((string) $value);
            if (trim((string) $value) !== '' && $value !== null) $assetRows[] = '<td><b>' . $esc($label) . '</b><br>' . $esc($value) . '</td>';
        }
        $components = is_array($snapshot['components'] ?? null) ? $snapshot['components'] : [];
        foreach (['processors' => 'PROCESADOR', 'memories' => 'RAM', 'hard_drives' => 'DISCO'] as $key => $label) {
            $values = [];
            foreach (($components[$key] ?? []) as $component) {
                $values[] = (string) ($component['device']['report_label'] ?? '');
            }
            if ($values) $assetRows[] = '<td><b>' . $esc($label) . '</b><br>' . $esc(implode(' / ', $values)) . '</td>';
        }
        $assetHtml = '<table cellpadding="3" cellspacing="1" border="0" bgcolor="#d9dee7"><tr>' . implode('', $assetRows) . '</tr></table>';
        $photoHtml = '';
        $max = count($evidences);
        foreach ($evidences as $index => $ev) {
            $path = self::compressedImage($ev['path'], $index, $tempFiles);
            $photoHtml .= '<td width="' . (int) floor(100 / min(4, $max)) . '%" align="center"><img src="' . $esc($path) . '" height="24mm"><br><font size="6">' . $esc($ev['caption']) . '</font></td>';
            if (($index + 1) % 4 === 0 && $index + 1 < $max) $photoHtml .= '</tr><tr>';
        }
        $photoHtml = '<table cellpadding="2" cellspacing="1" border="0"><tr>' . $photoHtml . '</tr></table>';
        $signatureHtml = '';
        foreach (['technical' => 'FIRMA TÉCNICO', 'supervisor' => 'FIRMA SUPERVISOR'] as $type => $label) {
            $signatureHtml .= '<td width="50%" align="center"><img src="' . $esc($signatures[$type]['path']) . '" height="15mm"><br><b>' . $esc($label) . '</b><br>' . $esc($signatures[$type]['user']) . '<br><font size="6">' . $esc($signatures[$type]['date']) . '</font></td>';
        }
        $sections = '';
        foreach (['background' => 'ANTECEDENTES', 'diagnosis' => 'DIAGNÓSTICO', 'cause' => 'CAUSA', 'recommendations' => 'RECOMENDACIONES'] as $key => $label) {
            $value = trim((string) ($report[$key] ?? ''));
            $sections .= '<tr><td width="22%"><b>' . $esc($label) . '</b></td><td>' . ($value !== '' ? nl2br($esc($value)) : '—') . '</td></tr>';
        }
        $title = $esc($report['report_number'] ?? '');
        return '<style>table{font-size:7pt} td{border:0.3pt solid #b8c2cf} .head{font-size:10pt;font-weight:bold}</style>'
            . '<table cellpadding="3" cellspacing="0"><tr><td align="center"><font size="11"><b>INFORME TÉCNICO</b></font><br><b>' . $title . '</b></td></tr></table>'
            . '<table cellpadding="3"><tr><td width="18%"><b>FECHA</b></td><td width="32%">' . $esc($date) . '</td><td width="18%"><b>PARA</b></td><td width="32%">' . $esc(implode(', ', $recipientNames)) . '</td></tr>'
            . '<tr><td><b>TÉCNICO</b></td><td>' . $esc(getUserName((int) $report['prepared_by_users_id'])) . '</td><td><b>SUPERVISOR</b></td><td>' . $esc(getUserName((int) $report['reviewer_users_id'])) . '</td></tr>'
            . '<tr><td><b>ASUNTO</b></td><td colspan="3">' . $esc($report['subject'] ?? '') . '</td></tr></table>'
            . '<br><b>DESCRIPCIÓN DEL ACTIVO</b>' . $assetHtml
            . '<br><b>INFORMACIÓN TÉCNICA</b><table cellpadding="3">' . $sections . '</table>'
            . '<br><b>FOTOGRAFÍAS DEL EQUIPO</b>' . $photoHtml
            . '<br><table cellpadding="4"><tr>' . $signatureHtml . '</tr></table>';
    }

    private static function compressedImage(string $source, int $index, array &$tempFiles): string
    {
        if (!function_exists('imagecreatetruecolor')) return $source;
        $info = @getimagesize($source);
        if (!$info) return $source;
        $mime = (string) ($info['mime'] ?? '');
        $loader = match ($mime) {
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png' => 'imagecreatefrompng',
            'image/webp' => 'imagecreatefromwebp',
            default => null,
        };
        if (!$loader || !function_exists($loader)) return $source;
        $image = @$loader($source);
        if (!$image) return $source;
        $width = imagesx($image); $height = imagesy($image);
        $scale = min(1, 1000 / max($width, $height));
        $newW = max(1, (int) round($width * $scale)); $newH = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($newW, $newH);
        $white = imagecolorallocate($target, 255, 255, 255);
        imagefill($target, 0, 0, $white);
        imagecopyresampled($target, $image, 0, 0, 0, 0, $newW, $newH, $width, $height);
        $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ig_pdf_' . $index . '_' . bin2hex(random_bytes(5)) . '.jpg';
        imagejpeg($target, $tmp, 78);
        imagedestroy($image); imagedestroy($target);
        if (is_file($tmp)) { $tempFiles[] = $tmp; return $tmp; }
        return $source;
    }

    private static function privatePath(string $relative): string
    {
        $base = realpath(rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '_plugins' . DIRECTORY_SEPARATOR . 'informeglpi');
        $path = realpath(rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relative, '/')));
        if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new RuntimeException(__('No se encontró un archivo privado del informe.', 'informeglpi'));
        }
        return $path;
    }
}
