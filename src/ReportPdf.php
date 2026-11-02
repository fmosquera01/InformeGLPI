<?php
declare(strict_types=1);

namespace GlpiPlugin\Informeglopi;

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
            $pdf = new \TCPDF('P', 'pt', 'LETTER', true, 'UTF-8', false);
            $pdf->SetCreator('GLPI - Informe Técnico');
            $pdf->SetAuthor('Songa - Infraestructura IT');
            $pdf->SetTitle((string) $fields['report_number']);
            $pdf->SetMargins(36, 30, 33);
            $pdf->SetAutoPageBreak(false);
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->AddPage();
            self::drawInstitutionalPdf($pdf, $fields, $assetFields, $snapshot, $evidences, $signatures, $tempFiles);
            if ($pdf->getNumPages() !== 1) {
                throw new RuntimeException(__('El contenido excede una página Letter. Reduzca la extensión del texto o la cantidad de fotografías y vuelva a generar.', 'informeglpi'));
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
            \Html::displayNotFoundError();
        }
        $f = $report->fields;
        if ((string) $f['status'] !== 'finalized'
            || !Session::haveRight(Report::$rightname, READ)
            || !Session::haveAccessToEntity((int) $f['entities_id'], (bool) $f['is_recursive'])) {
            \Html::displayRightError();
        }
        $path = self::privatePath((string) ($f['pdf_path'] ?? ''));
        $hash = hash_file('sha256', $path);
        if (!is_string($hash) || !hash_equals((string) $f['pdf_sha256'], $hash)) {
            \Html::displayErrorAndDie(__('El PDF no supera la verificación de integridad.', 'informeglpi'));
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
        $value = static fn(array $source, array $keys): string => trim((string) (array_reduce(
            $keys,
            static fn($found, $key) => $found !== null && $found !== '' ? $found : ($source[$key] ?? null),
            null
        ) ?? ''));
        $recipients = json_decode((string) ($report['recipients_json'] ?? '[]'), true);
        $recipientNames = [];
        foreach (is_array($recipients) ? $recipients : [] as $id) {
            $recipientNames[] = getUserName((int) $id);
        }
        $date = (string) ($report['report_date'] ?? '');
        if ($date !== '') {
            $timestamp = strtotime($date);
            if ($timestamp !== false) {
                $months = ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];
                $date = date('d', $timestamp) . ' – ' . $months[(int) date('n', $timestamp) - 1] . ' - ' . date('Y', $timestamp);
            }
        }

        $itemType = (string) ($snapshot['itemtype'] ?? $report['itemtype'] ?? '');
        $assetType = $itemType === 'ManualCellphone'
            ? 'CELULAR'
            : ($value($asset, ['computertypes_id', 'phonestypes_id', 'monitortypes_id', 'TIPO']) ?: strtoupper(str_replace(['Glpi\\Asset\\', '_'], ['', ' '], $itemType)));
        if ($assetType !== '' && ctype_digit($assetType) && (int) $assetType > 0) {
            foreach (['ComputerType', 'PhoneType', 'MonitorType'] as $class) {
                if (class_exists($class)) {
                    $typeName = (string) \Dropdown::getDropdownName($class::getTable(), (int) $assetType);
                    if ($typeName !== '') { $assetType = $typeName; break; }
                }
            }
        }
        $assetName = $value($asset, ['name', 'DESCRIPCIÓN DEL ACTIVO']) ?: (string) ($snapshot['asset_name'] ?? '');
        $description = $value($asset, ['comment', 'description', 'DESCRIPCIÓN DEL ACTIVO']);
        if ($description === '') {
            $description = $assetName;
        }
        $purchaseDate = $value($asset, ['date_buy', 'FECHA DE COMPRA']);
        if ($purchaseDate !== '') {
            $timestamp = strtotime($purchaseDate);
            if ($timestamp !== false) {
                $purchaseDate = date('d/m/Y', $timestamp);
            }
        }
        $bookValue = $report['book_value'] ?? null;
        $bookValueText = $bookValue !== null && $bookValue !== ''
            ? '$ ' . number_format((float) $bookValue, 2, '.', ',')
            : '—';

        $field = static function (string $label, string $content, int $labelWidth = 34) use ($esc): string {
            return '<tr><td width="' . $labelWidth . '%" class="field-label"><b>' . $esc($label) . '</b></td><td>' . nl2br($esc($content !== '' ? $content : '—')) . '</td></tr>';
        };
        $assetRows = '';
        $assetRows .= $field('CÓDIGO DE ACTIVO:', $value($asset, ['otherserial', 'asset_code', 'CÓDIGO DE ACTIVO']));
        $assetRows .= $field('TIPO', $assetType);
        $descriptionBlock = 'FECHA DE COMPRA: ' . ($purchaseDate !== '' ? $purchaseDate : '—')
            . "\nVALOR EN LIBRO: " . $bookValueText
            . "\n" . $description;
        $components = is_array($snapshot['components'] ?? null) ? $snapshot['components'] : [];
        if ($itemType === 'Computer') {
            foreach (['processors' => 'Procesador', 'memories' => 'RAM', 'hard_drives' => 'DISCO DURO'] as $key => $label) {
                $componentLines = [];
                foreach (($components[$key] ?? []) as $component) {
                    $line = trim((string) ($component['device']['report_label'] ?? ''));
                    if ($line !== '') {
                        $componentLines[] = $label . ': ' . $line;
                    }
                }
                if ($componentLines) {
                    $descriptionBlock .= "\n" . implode("\n", $componentLines);
                }
            }
        }
        $assetRows .= $field('DESCRIPCIÓN DEL ACTIVO:', $descriptionBlock);
        $manufacturer = $value($asset, ['manufacturers_id', 'MARCA']);
        if ($manufacturer !== '' && ctype_digit($manufacturer) && (int) $manufacturer > 0) {
            $manufacturer = (string) \Dropdown::getDropdownName(\Manufacturer::getTable(), (int) $manufacturer);
        }
        $model = $value($asset, ['computermodels_id', 'phonemodels_id', 'monitormodels_id', 'model', 'MODELO']);
        if ($model !== '' && ctype_digit($model)) {
            foreach (['ComputerModel', 'PhoneModel', 'MonitorModel'] as $class) {
                if (class_exists($class)) {
                    $table = $class::getTable();
                    $modelName = (string) \Dropdown::getDropdownName($table, (int) $model);
                    if ($modelName !== '') { $model = $modelName; break; }
                }
            }
        }
        $assetRows .= $field('MARCA:', $manufacturer);
        $assetRows .= $field('MODELO:', $model);
        $assetRows .= $field('SERIE:', $value($asset, ['serial', 'imei', 'SERIE']));
        $userId = (int) ($asset['users_id'] ?? 0);
        $assetRows .= $field('USUARIO RESPONSABLE:', $userId > 0 ? getUserName($userId) : $value($asset, ['responsible', 'USUARIO RESPONSABLE']));
        $location = $value($asset, ['locations_id', 'area', 'ÁREA DONDE SE ENCONTRABA EL EQUIPO']);
        if ($location !== '' && ctype_digit($location) && (int) $location > 0) {
            $location = (string) \Dropdown::getDropdownName(\Location::getTable(), (int) $location);
        }
        $assetRows .= $field('ÁREA DONDE SE ENCONTRABA EL EQUIPO:', $location);

        $technicalRows = '';
        foreach (['background' => 'ANTECEDENTES:', 'diagnosis' => 'DIAGNÓSTICO', 'cause' => 'CAUSA:'] as $key => $label) {
            $technicalRows .= $field($label, trim((string) ($report[$key] ?? '')), 25);
        }
        $damage = (string) ($report['damage_type'] ?? '');
        $damageText = $damage === 'wear' ? 'Desgaste -X-     Mal uso ---' : ($damage === 'property_damage' ? 'Desgaste ---     Mal uso -X-' : 'Desgaste ---     Mal uso ---');
        $technicalRows .= $field('TIPO DE DAÑO:', $damageText, 25);
        $technicalRows .= $field('RECOMENDACIONES:', trim((string) ($report['recommendations'] ?? '')), 25);

        $photoHtml = '';
        $perRow = min(4, count($evidences));
        foreach ($evidences as $index => $evidence) {
            $path = self::compressedImage($evidence['path'], $index, $tempFiles);
            $photoHtml .= '<td width="' . (int) floor(100 / max(1, $perRow)) . '%" align="center" valign="middle"><img src="' . $esc($path) . '" height="23mm"><br><font size="6">' . $esc($evidence['caption']) . '</font></td>';
            if (($index + 1) % $perRow === 0 && $index + 1 < count($evidences)) {
                $photoHtml .= '</tr><tr>';
            }
        }
        $photoHtml = '<table cellpadding="2" cellspacing="1" border="0"><tr>' . $photoHtml . '</tr></table>';

        $signatureHtml = '';
        foreach (['technical' => 'Elaborador por:', 'supervisor' => 'Supervisado por:'] as $type => $label) {
            $signature = $signatures[$type];
            $signatureHtml .= '<td width="50%" align="center" valign="bottom"><b>' . $esc($label) . '</b><br><img src="' . $esc($signature['path']) . '" height="13mm"><br><b>' . $esc($signature['user']) . '</b><br><font size="6">' . $esc($type === 'technical' ? 'Técnico Infraestructura Sistemas' : 'Jefe de Infraestructura Sistemas') . '</font></td>';
        }

        $preparedBy = getUserName((int) ($report['prepared_by_users_id'] ?? 0));
        $reviewer = getUserName((int) ($report['reviewer_users_id'] ?? 0));
        $number = $esc($report['report_number'] ?? '');
        $subject = $esc($report['subject'] ?? 'Informe técnico del estado actual de ítems de Propiedad, Planta y Equipo.');
        $header = '<table cellpadding="1" cellspacing="0" border="0"><tr><td width="72%" align="center"><font size="11"><b>INFORME TÉCNICO DE ÍTEMS DE<br>PROPIEDAD, PLANTA Y EQUIPOS</b></font></td><td width="28%" align="right"><b>' . $number . '</b></td></tr></table>';
        $metadata = '<table cellpadding="2" cellspacing="0" border="0">'
            . $field('Fecha:', $date, 15)
            . $field('Para:', implode(' – ', $recipientNames), 15)
            . $field('De:', $preparedBy . ' – ' . $reviewer, 15)
            . $field('Asunto:', $subject, 15)
            . '</table>';

        return '<style>
            table{font-size:7.2pt;border-collapse:collapse}
            td{border:0;padding:1.2pt 2pt;vertical-align:top}
            .field-label{font-weight:bold}
            .section-title{font-weight:bold;text-align:center;background-color:#eef1f4;border-bottom:0.6pt solid #7b8794;padding:4pt}
            .report-heading{font-size:11pt;font-weight:bold}
        </style>'
            . $header . $metadata
            . '<br><table cellpadding="3" cellspacing="0" width="100%"><tr><td class="section-title" align="center">INFORMACIÓN DEL ACTIVO</td></tr></table>'
            . '<table cellpadding="2" cellspacing="0" width="100%">' . $assetRows . '</table>'
            . '<br><table cellpadding="3" cellspacing="0" width="100%"><tr><td class="section-title" align="center">INFORMACIÓN TÉCNICA DEL ACTIVO</td></tr></table>'
            . '<table cellpadding="2" cellspacing="0" width="100%">' . $technicalRows . '</table>'
            . '<br><table cellpadding="2" cellspacing="0" border="0" width="100%"><tr><td align="center"><b>FOTOS DEL EQUIPO:</b></td></tr></table>'
            . $photoHtml
            . '<br><table cellpadding="3" cellspacing="0" border="0" width="100%"><tr><td colspan="2" align="left">Atentamente,</td></tr><tr>' . $signatureHtml . '</tr></table>';
    }

    /**
     * Dibuja el formato institucional directamente con TCPDF.
     * Las medidas están en puntos y corresponden a papel Letter, como el
     * formato de referencia compartido por el usuario.
     */
    private static function drawInstitutionalPdf(\TCPDF $pdf, array $report, array $asset, array $snapshot, array $evidences, array $signatures, array &$tempFiles): void
    {
        $blue = [0, 112, 192];
        $headerBlue = [68, 114, 196];
        $rowBlue = [217, 226, 243];
        $borderBlue = [142, 170, 219];
        $x = 36.0;
        $width = 543.0;
        $bottom = 742.0;
        $regular = 'helvetica';
        $bold = 'helvetica';
        $pdf->SetDrawColor(...$borderBlue);
        $pdf->SetLineWidth(0.5);
        $pdf->SetTextColor(0, 0, 0);

        $recipients = json_decode((string) ($report['recipients_json'] ?? '[]'), true);
        $recipientNames = [];
        foreach (is_array($recipients) ? $recipients : [] as $id) {
            $recipientNames[] = getUserName((int) $id);
        }
        $preparedBy = getUserName((int) ($report['prepared_by_users_id'] ?? 0));
        $reviewer = getUserName((int) ($report['reviewer_users_id'] ?? 0));
        $date = (string) ($report['report_date'] ?? '');
        $ts = $date !== '' ? strtotime($date) : false;
        if ($ts !== false) {
            $months = ['ENERO','FEBRERO','MARZO','ABRIL','MAYO','JUNIO','JULIO','AGOSTO','SEPTIEMBRE','OCTUBRE','NOVIEMBRE','DICIEMBRE'];
            $date = date('d', $ts) . ' – ' . $months[(int) date('n', $ts) - 1] . ' - ' . date('Y', $ts);
        }

        // Título y correlativo.
        $pdf->SetTextColor(...$blue);
        $pdf->SetFont($bold, 'B', 19);
        $pdf->SetXY($x, 33);
        $pdf->MultiCell($width, 24, "INFORME TÉCNICO DE ÍTEMS DE PROPIEDAD,\nPLANTA Y EQUIPOS", 0, 'C', false, 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont($bold, 'B', 12);
        $pdf->SetXY($x, 95);
        $pdf->Cell($width, 16, (string) ($report['report_number'] ?? ''), 0, 1, 'R');
        $y = 116.0;

        // Metadatos sin cuadrícula.
        $metadata = [
            ['Fecha:', $date],
            ['Para:', implode(' – ', $recipientNames)],
            ['De:', $preparedBy],
            ['Asunto:', (string) ($report['subject'] ?? 'Informe técnico del estado actual de ítems de Propiedad, Planta y Equipo.')],
        ];
        foreach ($metadata as [$label, $value]) {
            $pdf->SetFont($bold, 'B', 8.5);
            $pdf->SetXY(56.8, $y);
            $pdf->Cell(64, 11, $label, 0, 0, 'L');
            $pdf->SetFont($label === 'Asunto:' ? $regular : $bold, $label === 'Asunto:' ? '' : 'B', 8.5);
            $pdf->SetXY(120, $y);
            $h = max(11.0, $pdf->getStringHeight(440, (string) $value));
            $pdf->MultiCell(440, $h, (string) $value, 0, 'L', false, 1);
            $y += $h;
        }
        $y += 5;

        $section = static function (string $title, float $at) use ($pdf, $x, $width, $headerBlue, $borderBlue): float {
            $pdf->SetFillColor(...$headerBlue);
            $pdf->SetDrawColor(...$borderBlue);
            $pdf->SetLineWidth(0.5);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->SetXY($x, $at);
            $pdf->Cell($width, 18, $title, 1, 1, 'C', true);
            return $at + 18;
        };

        $row = static function (string $label, string $value, float $at, float $labelWidth, bool $filled) use ($pdf, $x, $width, $rowBlue, $borderBlue): float {
            $valueWidth = $width - $labelWidth;
            $pdf->SetFont('helvetica', '', 8.5);
            $value = trim($value) !== '' ? $value : '—';
            $h = max(13.0, $pdf->getStringHeight($valueWidth - 8, $value) + 4);
            $pdf->SetDrawColor(...$borderBlue);
            $pdf->SetLineWidth(0.5);
            $pdf->SetFillColor(...($filled ? $rowBlue : [255, 255, 255]));
            $pdf->Rect($x, $at, $labelWidth, $h, 'DF');
            $pdf->Rect($x + $labelWidth, $at, $valueWidth, $h, 'DF');
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('helvetica', 'B', 8.2);
            $pdf->MultiCell($labelWidth - 5, $h, $label, 0, 'L', false, 0, $x + 3, $at, true, 0, false, true, $h, 'M');
            $pdf->SetFont('helvetica', '', 8.2);
            $pdf->MultiCell($valueWidth - 6, $h, $value, 0, 'L', false, 0, $x + $labelWidth + 3, $at, true, 0, false, true, $h, 'M');
            return $at + $h;
        };

        // Tipo de activo y datos del snapshot.
        $itemType = (string) ($snapshot['itemtype'] ?? $report['itemtype'] ?? '');
        $get = static function (array $source, array $keys): string {
            foreach ($keys as $key) {
                if (isset($source[$key]) && $source[$key] !== '') return trim((string) $source[$key]);
            }
            return '';
        };
        $assetType = $itemType === 'ManualCellphone' ? 'CELULAR' : $get($asset, ['computertypes_id','phonestypes_id','monitortypes_id','TIPO']);
        if ($assetType !== '' && ctype_digit($assetType)) {
            foreach (['ComputerType','PhoneType','MonitorType'] as $class) {
                if (class_exists($class)) {
                    $name = (string) \Dropdown::getDropdownName($class::getTable(), (int) $assetType);
                    if ($name !== '') { $assetType = $name; break; }
                }
            }
        }
        if ($assetType === '') $assetType = strtoupper(str_replace(['Glpi\\Asset\\', '_'], ['', ' '], $itemType));

        $purchaseDate = $get($asset, ['date_buy','FECHA DE COMPRA']);
        $purchaseTs = $purchaseDate !== '' ? strtotime($purchaseDate) : false;
        if ($purchaseTs !== false) $purchaseDate = date('d/m/Y', $purchaseTs);
        $bookValue = $report['book_value'] ?? null;
        $bookValueText = $bookValue !== null && $bookValue !== '' ? '$ ' . number_format((float) $bookValue, 2, '.', ',') : '—';
        $description = $get($asset, ['comment','description','DESCRIPCIÓN DEL ACTIVO']);
        if ($description === '') $description = $get($asset, ['name']) ?: (string) ($snapshot['asset_name'] ?? '');
        $descriptionLines = ['FECHA DE COMPRA: ' . ($purchaseDate !== '' ? $purchaseDate : '—'), 'VALOR EN LIBRO: ' . $bookValueText];
        if ($description !== '') $descriptionLines[] = $description;
        $components = is_array($snapshot['components'] ?? null) ? $snapshot['components'] : [];
        if ($itemType === 'Computer') {
            foreach (['processors' => 'Procesador', 'memories' => 'RAM', 'hard_drives' => 'DISCO DURO'] as $key => $caption) {
                foreach (($components[$key] ?? []) as $component) {
                    $line = trim((string) ($component['device']['report_label'] ?? ''));
                    if ($line !== '') $descriptionLines[] = $caption . ': ' . $line;
                }
            }
        }

        $y = $section('INFORMACIÓN DEL ACTIVO', $y + 3);
        $y = $row('CÓDIGO DE ACTIVO:', $get($asset, ['otherserial','asset_code','CÓDIGO DE ACTIVO']), $y, 296, true);
        $y = $row('TIPO:', $assetType, $y, 296, false);
        $y = $row('DESCRIPCIÓN DEL ACTIVO:', implode("\n", $descriptionLines), $y, 296, true);

        $manufacturer = $get($asset, ['manufacturers_id','MARCA']);
        if ($manufacturer !== '' && ctype_digit($manufacturer)) $manufacturer = (string) \Dropdown::getDropdownName(\Manufacturer::getTable(), (int) $manufacturer);
        $model = $get($asset, ['computermodels_id','phonemodels_id','monitormodels_id','model','MODELO']);
        if ($model !== '' && ctype_digit($model)) {
            foreach (['ComputerModel','PhoneModel','MonitorModel'] as $class) {
                if (class_exists($class)) {
                    $name = (string) \Dropdown::getDropdownName($class::getTable(), (int) $model);
                    if ($name !== '') { $model = $name; break; }
                }
            }
        }
        $y = $row('MARCA:', $manufacturer, $y, 296, false);
        $y = $row('MODELO:', $model, $y, 296, true);
        $y = $row('SERIE:', $get($asset, ['serial','imei','SERIE']), $y, 296, false);
        $responsibleId = (int) ($asset['users_id'] ?? 0);
        $responsible = $responsibleId > 0 ? getUserName($responsibleId) : $get($asset, ['responsible','USUARIO RESPONSABLE']);
        $y = $row('USUARIO RESPONSABLE:', $responsible, $y, 296, true);
        $location = $get($asset, ['locations_id','area','ÁREA DONDE SE ENCONTRABA EL EQUIPO']);
        if ($location !== '' && ctype_digit($location)) $location = (string) \Dropdown::getDropdownName(\Location::getTable(), (int) $location);
        $y = $row('ÁREA DONDE SE ENCONTRABA EL EQUIPO:', $location, $y, 296, false);

        $y = $section('INFORMACIÓN TÉCNICA DEL ACTIVO', $y + 7);
        foreach (['background' => 'ANTECEDENTES:', 'diagnosis' => 'DIAGNÓSTICO:', 'cause' => 'CAUSA:'] as $key => $label) {
            $y = $row($label, trim((string) ($report[$key] ?? '')), $y, 236, (($y % 2) < 1));
        }
        $damage = (string) ($report['damage_type'] ?? '');
        $damageText = $damage === 'wear' ? 'Desgaste  -X-       Daño a la propiedad  ---' : ($damage === 'property_damage' ? 'Desgaste  ---       Daño a la propiedad  -X-' : 'Desgaste  ---       Daño a la propiedad  ---');
        $y = $row('TIPO DE DAÑO:', $damageText, $y, 236, false);
        $y = $row('RECOMENDACIONES:', trim((string) ($report['recommendations'] ?? '')), $y, 236, true);

        // Reservar espacio para las firmas; las fotos se distribuyen en una
        // cuadrícula de hasta tres columnas, manteniendo la proporción.
        $photos = [];
        foreach ($evidences as $i => $evidence) {
            $photos[] = ['path' => self::compressedImage($evidence['path'], $i, $tempFiles), 'caption' => (string) ($evidence['caption'] ?? '')];
        }
        $y += 8;
        $photoTitleH = 15.0;
        $signatureReserve = 112.0;
        $availablePhotoHeight = $bottom - $y - $photoTitleH - $signatureReserve;
        if ($availablePhotoHeight < 48) {
            throw new RuntimeException(__('El contenido del informe no deja espacio suficiente para fotografías y firmas en una sola página Letter. Reduzca la extensión de los textos.', 'informeglpi'));
        }
        $cols = max(1, min(3, count($photos)));
        $photoRows = (int) ceil(count($photos) / $cols);
        $cellW = ($width - 12) / $cols;
        $cellH = min(105.0, ($availablePhotoHeight - 8) / max(1, $photoRows));
        $imageH = max(25.0, $cellH - 15);
        if ($photoRows * $cellH > $availablePhotoHeight) {
            throw new RuntimeException(__('La cantidad de fotografías excede el espacio disponible para mantener el informe en una sola página Letter.', 'informeglpi'));
        }

        $pdf->SetDrawColor(...$borderBlue);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetFont($bold, 'B', 8.5);
        $pdf->SetXY($x, $y);
        $pdf->Cell($width, $photoTitleH, 'FOTOS DEL EQUIPO:', 1, 1, 'C', true);
        $y += $photoTitleH;
        $pdf->SetFillColor(...$rowBlue);
        $pdf->Rect($x, $y, $width, $photoRows * $cellH, 'DF');
        foreach ($photos as $i => $photo) {
            $col = $i % $cols;
            $r = intdiv($i, $cols);
            $cx = $x + 6 + $col * $cellW;
            $cy = $y + $r * $cellH + 4;
            $pdf->Image($photo['path'], $cx, $cy, $cellW - 12, $imageH, '', '', '', true, 150, '', false, false, 0, 'CM', false, false);
            if ($photo['caption'] !== '') {
                $pdf->SetFont($regular, '', 6.5);
                $pdf->SetXY($cx, $cy + $imageH);
                $pdf->MultiCell($cellW - 12, 10, $photo['caption'], 0, 'C', false, 0);
            }
        }
        $y += $photoRows * $cellH + 8;

        // Firmas originales verificadas, con sus usuarios reales.
        $pdf->SetFont($regular, '', 9.5);
        $pdf->SetXY(85, $y);
        $pdf->Cell(200, 12, 'Atentamente,', 0, 1, 'L');
        $y += 17;
        $pdf->SetFont($regular, '', 8.5);
        $pdf->SetXY(85, $y);
        $pdf->Cell(200, 12, 'Elaborador por:', 0, 0, 'L');
        $pdf->SetXY(380, $y);
        $pdf->Cell(180, 12, 'Supervisado por:', 0, 0, 'R');
        $lineY = $y + 55;
        foreach (['technical' => [90.0, 140.0, 'Técnico Infraestructura Sistemas'], 'supervisor' => [380.0, 180.0, 'Jefe de Infraestructura Sistemas']] as $type => [$sx, $sw, $role]) {
            $sig = $signatures[$type];
            $lineW = 140.0;
            $lx = $type === 'supervisor' ? $sx + $sw - $lineW : $sx;
            if (!empty($sig['path']) && is_file($sig['path'])) {
                $pdf->Image($sig['path'], $lx, $y + 13, $lineW, 38, '', '', '', true, 150, '', false, false, 0, 'CB', false, false);
            }
            $pdf->SetDrawColor(0, 0, 0);
            $pdf->Line($lx, $lineY, $lx + $lineW, $lineY);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont($bold, 'B', 8);
            $pdf->SetXY($lx - 20, $lineY + 2);
            $pdf->Cell($lineW + 40, 10, (string) $sig['user'], 0, 0, 'C');
            $pdf->SetFont($regular, '', 7.5);
            $pdf->SetXY($lx - 20, $lineY + 12);
            $pdf->Cell($lineW + 40, 10, $role, 0, 0, 'C');
        }
        if ($lineY + 25 > $bottom) {
            throw new RuntimeException(__('Las firmas exceden el espacio de la página Letter. Reduzca el contenido del informe.', 'informeglpi'));
        }
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
