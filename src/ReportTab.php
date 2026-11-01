<?php
declare(strict_types=1);

namespace GlpiPlugin\Informeglopi;

use CommonGLPI;

/** Informe Técnico: three views kept inside the asset tab. */
final class ReportTab extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return __('Informes técnicos', 'informeglpi');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($withtemplate || !AssetTypeRegistry::isSupported($item::class)) {
            return '';
        }
        return self::getTypeName();
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!AssetTypeRegistry::isSupported($item::class)) {
            return true;
        }
        if (!\Session::haveRight(Report::$rightname, READ)) {
            echo "<div class='alert alert-warning'>" . self::e(__('No tiene permisos para consultar informes técnicos.', 'informeglpi')) . '</div>';
            return true;
        }

        $itemtype = $item::class;
        $itemsId = (int) $item->getID();
        $assetUrl = $item->getFormURLWithID($itemsId);
        $tabUrl = $assetUrl . '&forcetab=' . rawurlencode(self::class . '$1');
        $view = (string) ($_GET['report_view'] ?? 'new');
        if (!in_array($view, ['new', 'drafts', 'approved'], true)) {
            $view = 'new';
        }

        self::renderStyles();
        echo "<div class='card mb-3 ig-report-shell'><div class='card-header'><strong>" . self::e(__('Informes técnicos', 'informeglpi')) . '</strong></div><div class="card-body">';

        echo "<div class='ig-report-tabs'>";
        foreach (['new', 'drafts', 'approved'] as $tabKey) {
            echo "<input type='radio' name='ig_report_view' id='ig-tab-" . self::e($tabKey) . "' " . ($view === $tabKey ? 'checked' : '') . " style='position:absolute;opacity:0;pointer-events:none'>";
        }
        echo "<ul class='nav nav-tabs mb-3' role='tablist'>";
        self::navLink('new', __('Nuevo', 'informeglpi'));
        self::navLink('drafts', __('Pendientes de revisión', 'informeglpi'));
        self::navLink('approved', __('Finalizados', 'informeglpi'));
        echo '</ul>';

        $reportId = (int) ($_GET['report_id'] ?? 0);
        echo "<div class='ig-report-panels'>";
        echo "<div id='ig-panel-new' class='ig-report-panel'>";
        if (\Session::haveRight(Report::$rightname, CREATE)) {
            self::renderNewForm($itemtype, $itemsId);
        } else {
            echo "<div class='alert alert-warning mb-0'>" . self::e(__('No tiene permisos para crear informes técnicos.', 'informeglpi')) . '</div>';
        }
        echo '</div>';
        echo "<div id='ig-panel-drafts' class='ig-report-panel'>";
        self::renderReportList($itemtype, $itemsId, $tabUrl, ['draft', 'pending_technical_signature', 'pending_supervisor_signature']);
        echo '</div>';
        echo "<div id='ig-panel-approved' class='ig-report-panel'>";
        self::renderReportList($itemtype, $itemsId, $tabUrl, ['finalized']);
        echo '</div></div>';

        echo '</div></div>';
        return true;
    }

    private static function navLink(string $key, string $label): void
    {
        echo "<li class='nav-item' role='presentation'><label class='nav-link' for='ig-tab-" . self::e($key) . "' role='tab'>" . self::e($label) . '</label></li>';
    }

    private static function renderNewForm(string $itemtype, int $itemsId): void
    {
        if (!class_exists($itemtype)) {
            return;
        }
        $item = new $itemtype();
        if (!$item->getFromDB($itemsId)) {
            echo "<div class='alert alert-danger'>" . self::e(__('No se pudo cargar el activo.', 'informeglpi')) . '</div>';
            return;
        }

        $snapshot = AssetSnapshotBuilder::build($item);
        $fields = is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [];
        $components = is_array($snapshot['components'] ?? null) ? $snapshot['components'] : [];


        echo "<div class='ig-report-form'>";
        self::renderDocumentHeader(ReportService::previewNextReportNumber());
        echo '<div class="ig-section-title">' . self::e(__('INFORMACIÓN DEL ACTIVO', 'informeglpi')) . '</div>';
        echo "<div class='ig-asset-table'>";
        $typeField = strtolower((new \ReflectionClass($item))->getShortName()) . 'types_id';
        $modelField = strtolower((new \ReflectionClass($item))->getShortName()) . 'models_id';
        $typeClass = (new \ReflectionClass($item))->getShortName() . 'Type';
        $modelClass = (new \ReflectionClass($item))->getShortName() . 'Model';
        $typeValue = isset($fields[$typeField]) && class_exists($typeClass)
            ? \Dropdown::getDropdownName($typeClass::getTable(), (int) $fields[$typeField])
            : '';
        $modelValue = isset($fields[$modelField]) && class_exists($modelClass)
            ? \Dropdown::getDropdownName($modelClass::getTable(), (int) $fields[$modelField])
            : '';
        $manufacturerValue = !empty($fields['manufacturers_id'])
            ? \Dropdown::getDropdownName(\Manufacturer::getTable(), (int) $fields['manufacturers_id'])
            : '';
        $userValue = !empty($fields['users_id']) ? getUserName((int) $fields['users_id']) : '';
        $locationValue = !empty($fields['locations_id'])
            ? \Dropdown::getDropdownName(\Location::getTable(), (int) $fields['locations_id'])
            : '';
        $description = trim((string) ($fields['name'] ?? '') . ((string) ($fields['comment'] ?? '') !== '' ? "\n" . $fields['comment'] : ''));
        if (!empty($fields['date_buy'])) {
            $description .= "\n" . __('FECHA DE COMPRA:', 'informeglpi') . ' ' . \Html::convDate($fields['date_buy']);
        }
        $assetRows = [
            __('CÓDIGO DE ACTIVO:', 'informeglpi') => $fields['otherserial'] ?? '',
            __('TIPO:', 'informeglpi') => $typeValue,
            __('DESCRIPCIÓN DEL ACTIVO:', 'informeglpi') => $description,
            __('MARCA:', 'informeglpi') => $manufacturerValue,
            __('MODELO:', 'informeglpi') => $modelValue,
            __('SERIE:', 'informeglpi') => $fields['serial'] ?? '',
            __('USUARIO RESPONSABLE:', 'informeglpi') => $userValue,
            __('ÁREA DONDE SE ENCONTRABA EL EQUIPO:', 'informeglpi') => $locationValue,
        ];
        foreach ($assetRows as $label => $value) {
            if (is_array($value) || is_object($value) || trim((string) $value) === '' || (string) $value === '0') {
                continue;
            }
            echo "<div class='ig-row'><div class='ig-label'>" . self::e($label) . "</div><div class='ig-value'>" . nl2br(self::e($value)) . '</div></div>';
        }
        if ($itemtype === 'Computer') {
            foreach ([
                'processors' => __('PROCESADOR:', 'informeglpi'),
                'memories' => __('RAM:', 'informeglpi'),
                'hard_drives' => __('DISCO DURO:', 'informeglpi'),
                'network_cards' => __('TARJETAS DE RED:', 'informeglpi'),
            ] as $key => $label) {
                $entries = $components[$key] ?? [];
                $lines = [];
                foreach ($entries as $entry) {
                    $device = $entry['device'] ?? [];
                    $description = [];
                    foreach ($device as $deviceField => $deviceValue) {
                        if (!is_scalar($deviceValue) || trim((string) $deviceValue) === '' || (string) $deviceValue === '0') {
                            continue;
                        }
                        if (in_array($deviceField, ['id', 'entities_id', 'is_recursive', 'is_deleted', 'is_template', 'date_creation', 'date_mod'], true)
                            || str_ends_with((string) $deviceField, '_id')) {
                            continue;
                        }
                        $description[] = (string) $deviceValue;
                    }
                    if (!empty($device['manufacturers_id'])) {
                        $description[] = \Dropdown::getDropdownName(\Manufacturer::getTable(), (int) $device['manufacturers_id']);
                    }
                    if ($description !== []) {
                        $lines[] = implode(' · ', array_unique($description));
                    }
                }
                if ($lines !== []) {
                    echo "<div class='ig-row'><div class='ig-label'>" . self::e($label) . "</div><div class='ig-value'>" . nl2br(self::e(implode("\n", $lines))) . '</div></div>';
                }
            }
        }
        echo '</div>';
        self::renderFieldsForm('/plugins/informeglpi/front/report.form.php', [
            '_glpi_csrf_token' => \Session::getNewCSRFToken(true),
            'itemtype' => $itemtype,
            'items_id' => $itemsId,
        ], [
            'report_date' => date('Y-m-d'),
            'report_number' => ReportService::previewNextReportNumber(),
            'prepared_by_users_id' => (int) \Session::getLoginUserID(),
            'subject' => 'Informe técnico del estado actual de ítems de Propiedad, Planta y Equipo.',
        ]);
        echo '</div>';
    }

    private static function renderDocumentHeader($number): void
    {
        echo "<header class='ig-document-header'>";
        echo "<div class='ig-document-number'><span>" . self::e(__('NÚMERO', 'informeglpi')) . "</span><strong>" . self::e($number !== '' ? $number : __('Pendiente', 'informeglpi')) . "</strong></div>";
        echo "<h2>" . self::e(__('INFORME TECNICO DE ITEMS DE PROPIEDAD, PLANTA Y EQUIPOS', 'informeglpi')) . "</h2>";
        echo '</header>';
    }

    private static function renderStyles(): void
    {
        echo "<style>
        .ig-report-shell{--ig-yellow:#f4c542;--ig-yellow-dark:#d6a900;color:var(--bs-body-color)}
        .ig-report-shell .card-header{font-weight:700;background:#f5f6f8}
        .ig-report-tabs .ig-report-panel{display:none}
        .ig-report-tabs #ig-tab-new:checked ~ .ig-report-panels #ig-panel-new,
        .ig-report-tabs #ig-tab-drafts:checked ~ .ig-report-panels #ig-panel-drafts,
        .ig-report-tabs #ig-tab-approved:checked ~ .ig-report-panels #ig-panel-approved{display:block}
        .ig-report-tabs ul.nav{display:flex;gap:.45rem;flex-wrap:wrap;border-bottom:1px solid #d8dce2;padding:.15rem .15rem .55rem;margin:0 0 1rem}
        .ig-report-tabs ul label{cursor:pointer;margin:0;border:1px solid #d9dde3;border-radius:.55rem;padding:.55rem 1rem;font-weight:650;color:#596273;background:#fff;transition:all .15s ease}
        .ig-report-tabs #ig-tab-new:checked ~ ul label[for=ig-tab-new],
        .ig-report-tabs #ig-tab-drafts:checked ~ ul label[for=ig-tab-drafts],
        .ig-report-tabs #ig-tab-approved:checked ~ ul label[for=ig-tab-approved]{color:#29230e;background:var(--ig-yellow);border-color:var(--ig-yellow-dark);box-shadow:0 2px 5px rgba(0,0,0,.10)}
        .ig-report-tabs ul label:hover{border-color:var(--ig-yellow-dark);background:#fff8df;color:#29230e}
        .ig-report-form{max-width:1120px;margin:0 auto;padding:1.1rem 1.25rem;background:#fff;border:1px solid #d9dde3;border-radius:7px;box-shadow:0 2px 8px rgba(20,30,45,.045);font-family:Arial,Helvetica,sans-serif;color:#20242a}
        .ig-report-form .ig-document-header{position:relative;padding:1.15rem .75rem 1rem;margin-bottom:1rem;border-bottom:2px solid #343a40}
        .ig-report-form .ig-document-header h2{max-width:76%;margin:.7rem auto 0;text-align:center;font-size:1.12rem;line-height:1.35;font-weight:700;letter-spacing:.025em;color:#20242a}
        .ig-report-form .ig-document-number{display:flex;justify-content:flex-end;align-items:center;gap:.6rem;font-size:.78rem;font-weight:700;color:#4b5563}
        .ig-report-form .ig-document-number strong{font-size:.98rem;color:#20242a;border:1px solid #cfd4dc;background:#f7f8fa;border-radius:4px;padding:.28rem .55rem}
        .ig-report-form .ig-section-title{margin:1.05rem 0 .45rem;padding:.45rem .55rem;text-align:center;font-size:.88rem;letter-spacing:.035em;font-weight:700;color:#252a31;background:#f0f1f3;border:1px solid #d8dce2;border-left:4px solid #d5aa19;border-radius:3px}
        .ig-report-form .ig-row{display:grid;grid-template-columns:minmax(220px,31%) minmax(0,1fr);border-bottom:1px solid #e0e3e8;align-items:stretch}
        .ig-report-form .ig-label{font-size:.8rem;line-height:1.4;font-weight:700;padding:.55rem .65rem;background:#f6f7f9;color:#333b46}
        .ig-report-form .ig-value{padding:.38rem .55rem;min-width:0;font-size:.86rem;line-height:1.5;white-space:normal}
        .ig-report-form .ig-asset-table{border:1px solid #d9dde3;border-bottom:0;border-radius:4px;overflow:hidden}
        .ig-report-form .form-control,.ig-report-form .form-select{min-height:35px;padding:.38rem .55rem;border-color:#cbd1d9;border-radius:4px;font-size:.86rem}
        .ig-report-form textarea.form-control{min-height:76px;line-height:1.45;resize:vertical}
        .ig-report-form .form-control:focus,.ig-report-form .form-select:focus{border-color:#d3a914;box-shadow:0 0 0 .16rem rgba(244,197,66,.2)}
        .ig-report-form .input-group-text{background:#f5f6f8;border-color:#cbd1d9}
        .ig-report-form .btn-primary{background:#e7b92d;border-color:#d5a719;color:#29230e;font-weight:700}
        .ig-report-form .btn-primary:hover{background:#d9aa1e;border-color:#c69a14;color:#201a08}
        .ig-report-form .ig-evidence-area{background:#fafbfc}
        .ig-edit-paper{max-width:1180px;margin:0 auto;background:#fdfdfd}
        .ig-report-shell .table{font-size:.86rem}
        .ig-report-shell .table thead th{background:#f2f3f5;color:#3b414b;font-weight:700;white-space:nowrap}
        .ig-report-shell .btn-warning{background:var(--ig-yellow);border-color:var(--ig-yellow-dark);color:#29230e}
        @media(max-width:700px){.ig-report-form{padding:.7rem}.ig-report-form .ig-document-header h2{max-width:100%;font-size:1rem}.ig-report-form .ig-row{grid-template-columns:1fr}.ig-report-form .ig-label{padding-bottom:.1rem}.ig-report-form .ig-value{padding-top:.2rem}}
        </style>";
    }

    public static function renderEditPage(int $reportId): void
    {
        $report = new Report();
        if ($reportId < 1 || !$report->getFromDB($reportId)) {
            \Html::displayNotFoundError();
        }
        $itemtype = (string) ($report->fields['itemtype'] ?? '');
        $itemsId = (int) ($report->fields['items_id'] ?? 0);
        if (!class_exists($itemtype) || !AssetTypeRegistry::isSupported($itemtype)
            || !\Session::haveRight(Report::$rightname, READ)
            || !\Session::haveAccessToEntity((int) ($report->fields['entities_id'] ?? -1), (bool) ($report->fields['is_recursive'] ?? false))) {
            \Html::displayRightError();
        }
        $item = new $itemtype();
        if (!$item->getFromDB($itemsId) || !$item->can($itemsId, READ)) {
            \Html::displayRightError();
        }
        $userId = (int) \Session::getLoginUserID();
        $isEditable = ($report->fields['status'] ?? '') === 'draft'
            && ($userId === (int) ($report->fields['users_id'] ?? 0) || \Session::haveRight(Report::$rightname, UPDATE));

        self::renderStyles();
        echo "<div class='container-fluid mt-3 ig-report-shell'><div class='d-flex justify-content-between align-items-center mb-3'>";
        echo "<h2 class='h4 mb-0'>" . self::e(__('Editar informe técnico', 'informeglpi')) . "</h2>";
        echo "<a class='btn btn-outline-secondary btn-sm' href='" . self::e($item->getFormURLWithID($itemsId)) . "'>" . self::e(__('Volver al activo', 'informeglpi')) . "</a></div>";
        echo "<div class='ig-edit-paper border rounded p-3'>";
        echo "<div class='ig-report-form'>";
        self::renderDocumentHeader($report->fields['report_number'] ?? '');
        echo "<div class='mb-2 text-muted'>" . self::e($item->getTypeName() . ' · ' . ($item->fields['name'] ?? '')) . "</div>";
        if ($isEditable) {
            self::renderFieldsForm('/plugins/informeglpi/front/report.edit.form.php', [
                '_glpi_csrf_token' => \Session::getNewCSRFToken(true),
                'id' => $reportId,
            ], $report->fields);
        } else {
            echo "<div class='alert alert-warning mb-0'>" . self::e(__('Este informe no está disponible para edición con su usuario o su estado actual.', 'informeglpi')) . "</div>";
        }
        echo '</div></div>';
    }

    private static function renderFieldsForm(string $action, array $hidden, array $values): void
    {
        echo "<form class='ig-report-form' method='post' enctype='multipart/form-data' action='" . self::e($action) . "'>";
        global $DB;
        $users = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'realname', 'firstname'], 'FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1], 'ORDER' => ['realname ASC', 'firstname ASC']]) as $user) {
            $label = trim((string) ($user['firstname'] ?? '') . ' ' . (string) ($user['realname'] ?? ''));
            $users[(int) $user['id']] = $label !== '' ? $label : (string) $user['name'];
        }
        $recipients = [];
        $decoded = json_decode((string) ($values['recipients_json'] ?? '[]'), true);
        if (is_array($decoded)) {
            $recipients = array_map('intval', $decoded);
        }
        $reportDate = (string) ($values['report_date'] ?? date('Y-m-d'));
        $parts = explode('-', $reportDate);
        $months = ['', 'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];
        $dateLabel = count($parts) === 3 ? sprintf('%s – %s - %s', $parts[2], $months[(int) $parts[1]] ?? '', $parts[0]) : $reportDate;
        echo '<div class="ig-section-title">' . self::e(__('ENCABEZADO DEL DOCUMENTO', 'informeglpi')) . '</div>';
        echo "<div class='ig-asset-table'>";
        echo "<div class='ig-row'><div class='ig-label'>" . self::e(__('FECHA:', 'informeglpi')) . "</div><div class='ig-value'><input class='form-control' value='" . self::e($dateLabel) . "' readonly></div></div>";
        echo "<div class='ig-row'><div class='ig-label'>" . self::e(__('PARA:', 'informeglpi')) . "</div><div class='ig-value'>";
        echo \Dropdown::showFromArray('recipients_ids', $users, [
            'multiple' => true,
            'values' => $recipients,
            'display' => false,
            'class' => 'form-select',
            'width' => '100%',
        ]);
        echo "<small class='text-muted'>" . self::e(__('Seleccione uno o varios destinatarios; mínimo uno obligatorio.', 'informeglpi')) . "</small></div></div>";
        $preparedById = (int) ($values['prepared_by_users_id'] ?? \Session::getLoginUserID());
        $preparedByName = $preparedById > 0 ? getUserName($preparedById) : '';
        echo "<div class='ig-row'><div class='ig-label'>" . self::e(__('DE: TÉCNICO QUE ELABORA:', 'informeglpi')) . "</div><div class='ig-value'>" . self::e($preparedByName) . "</div></div>";
        $field = 'reviewer_users_id';
        $label = __('DE: RESPONSABLE QUE REVISA:', 'informeglpi');
        $selected = (int) ($values[$field] ?? 0);
        echo "<div class='ig-row'><div class='ig-label'>" . self::e($label) . "</div><div class='ig-value'>";
        echo \Dropdown::showFromArray($field, $users, [
            'value' => $selected,
            'display' => false,
            'class' => 'form-select',
            'width' => '100%',
            'display_emptychoice' => true,
        ]);
        echo '</div></div>';
        $subject = (string) ($values['subject'] ?? 'Informe técnico del estado actual de ítems de Propiedad, Planta y Equipo.');
        echo "<div class='ig-row'><label class='ig-label' for='ig_subject'>" . self::e(__('ASUNTO:', 'informeglpi')) . "</label><div class='ig-value'><input class='form-control' type='text' id='ig_subject' name='subject' required value='" . self::e($subject) . "'></div></div>";
        echo '</div>';
        echo '<div class="ig-section-title">' . self::e(__('INFORMACIÓN TÉCNICA DEL ACTIVO', 'informeglpi')) . '</div>';
        foreach ($hidden as $name => $value) {
            echo "<input type='hidden' name='" . self::e($name) . "' value='" . self::e($value) . "'>";
        }
        $fields = [
            'book_value' => __('VALOR EN LIBROS (USD):', 'informeglpi'),
            'background' => __('ANTECEDENTES:', 'informeglpi'),
            'diagnosis' => __('DIAGNÓSTICO:', 'informeglpi'),
            'cause' => __('CAUSA:', 'informeglpi'),
            'recommendations' => __('RECOMENDACIONES:', 'informeglpi'),
        ];
        echo "<div class='ig-asset-table'>";
        foreach ($fields as $field => $label) {
            $value = $values[$field] ?? '';
            echo "<div class='ig-row'><label class='ig-label' for='field_" . self::e($field) . "'>" . self::e($label) . '</label><div class="ig-value">';
            if ($field === 'book_value') {
                echo "<div class='input-group input-group-sm'><span class='input-group-text'>$</span><input class='form-control' type='number' min='0' step='0.01' name='book_value' id='field_book_value' value='" . self::e($value) . "'></div>";
            } else {
                echo "<textarea class='form-control' rows='2' id='field_" . self::e($field) . "' name='" . self::e($field) . "'>" . self::e($value) . '</textarea>';
            }
            echo '</div></div>';
        }
        $damage = (string) ($values['damage_type'] ?? 'wear');
        echo "<div class='ig-row'><div class='ig-label'>" . self::e(__('TIPO DE DAÑO:', 'informeglpi')) . '</div><div class="ig-value">';
        foreach (['wear' => __('Desgaste', 'informeglpi'), 'property_damage' => __('Mal uso', 'informeglpi')] as $value => $label) {
            echo "<label class='form-check form-check-inline mb-0'><input class='form-check-input' type='radio' name='damage_type' value='" . self::e($value) . "' " . ($damage === $value ? 'checked' : '') . '><span class="form-check-label">' . self::e($label) . '</span></label>';
        }
        echo '</div></div></div>';
        echo '<div class="ig-section-title">' . self::e(__('FOTOS DEL EQUIPO', 'informeglpi')) . '</div>';
        echo "<div class='ig-asset-table p-2'>";
        echo "<label class='form-label' for='ig_evidence_files'>" . self::e(__('Adjuntar fotografías (JPG, PNG o WEBP; máximo 8 archivos de 8 MB cada uno).', 'informeglpi')) . "</label>";
        echo "<input class='form-control' type='file' id='ig_evidence_files' name='evidence_files[]' accept='image/jpeg,image/png,image/webp' capture='environment' multiple>";
        echo "<div class='form-text'>" . self::e(__('Puede seleccionar varias fotos o tomar una fotografía desde un dispositivo móvil.', 'informeglpi')) . "</div>";
        echo "<div id='ig-evidence-preview' class='d-flex flex-wrap gap-2 mt-2'></div></div>";
        echo "<script>
        (function(){const input=document.getElementById('ig_evidence_files');const preview=document.getElementById('ig-evidence-preview');if(!input||!preview)return;input.addEventListener('change',function(){preview.replaceChildren();Array.from(input.files||[]).forEach(function(file){if(!file.type.startsWith('image/'))return;const figure=document.createElement('div');figure.className='border rounded p-1';figure.style.width='120px';const img=document.createElement('img');img.src=URL.createObjectURL(file);img.alt=file.name;img.style.width='100%';img.style.height='85px';img.style.objectFit='cover';const name=document.createElement('small');name.textContent=file.name;name.style.display='block';name.style.overflowWrap='anywhere';figure.append(img,name);preview.append(figure);});});})();
        </script>";
        echo "<div class='d-flex justify-content-end mt-2'><button type='submit' class='btn btn-primary btn-sm'>" . self::e(__('Guardar borrador', 'informeglpi')) . '</button></div>';
        echo '</form>';
    }

    private static function renderReportList(string $itemtype, int $itemsId, string $tabUrl, array $statuses): void
    {
        $reports = self::loadReports($itemtype, $itemsId, $statuses);
        if ($reports === []) {
            $message = in_array('draft', $statuses, true)
                ? __('No hay borradores ni informes pendientes de firma para este activo.', 'informeglpi')
                : __('No hay informes aprobados o pendientes de firma para este activo.', 'informeglpi');
            echo "<div class='alert alert-info mb-0'>" . self::e($message) . '</div>';
            return;
        }
        echo "<div class='table-responsive'><table class='table table-hover table-sm'>";
        echo '<thead><tr>';
        foreach ([__('Número', 'informeglpi'), __('Fecha', 'informeglpi'), __('Estado', 'informeglpi'), __('Creado por', 'informeglpi'), __('Acción', 'informeglpi')] as $heading) {
            echo '<th>' . self::e($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($reports as $row) {
            $id = (int) $row['id'];
            $view = in_array('draft', $statuses, true) ? 'drafts' : 'approved';
            $openUrl = '/plugins/informeglpi/front/report.edit.form.php?id=' . $id;
            echo '<tr><td>' . self::e($row['report_number']) . '</td>';
            echo '<td>' . self::e($row['date_creation']) . '</td>';
            echo '<td>' . self::e(self::statusLabel((string) $row['status'])) . '</td>';
            echo '<td>' . self::e($row['creator_name'] ?? '—') . '</td>';
            echo "<td><a class='btn btn-sm btn-warning' href='" . self::e($openUrl) . "'>" . self::e(__('Editar / ver', 'informeglpi')) . '</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function renderSelectedReport(int $reportId, string $itemtype, int $itemsId, string $tabUrl): void
    {
        $report = new Report();
        if (!$report->getFromDB($reportId)
            || ($report->fields['itemtype'] ?? '') !== $itemtype
            || (int) ($report->fields['items_id'] ?? 0) !== $itemsId
            || !\Session::haveAccessToEntity((int) ($report->fields['entities_id'] ?? -1), (bool) ($report->fields['is_recursive'] ?? false))
        ) {
            echo "<div class='alert alert-danger'>" . self::e(__('No se encontró el informe para este activo o no tiene acceso.', 'informeglpi')) . '</div>';
            return;
        }

        $currentUserId = (int) \Session::getLoginUserID();
        $isEditable = ($report->fields['status'] ?? '') === 'draft'
            && ($currentUserId === (int) ($report->fields['users_id'] ?? 0) || \Session::haveRight(Report::$rightname, UPDATE));
        $snapshot = json_decode((string) ($report->fields['asset_snapshot'] ?? '{}'), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $assetFields = is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [];

        echo "<section class='border rounded p-3 mt-3'>";
        echo "<div class='d-flex justify-content-between align-items-center mb-3'><h4 class='mb-0'>" . self::e($report->fields['report_number'] ?? '') . '</h4>';
        echo "<span class='badge bg-secondary'>" . self::e(self::statusLabel((string) ($report->fields['status'] ?? ''))) . '</span></div>';
        echo '<h5>' . self::e(__('Datos del activo (snapshot)', 'informeglpi')) . '</h5>';
        echo "<div class='table-responsive'><table class='table table-sm table-bordered'><tbody>";
        foreach ($assetFields as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }
            echo '<tr><th style="width:28%">' . self::e($key) . '</th><td>' . self::e($value) . '</td></tr>';
        }
        echo '</tbody></table></div>';

        global $DB;
        $evidenceRows = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'original_name', 'caption'],
            'FROM' => 'glpi_plugin_informeglopi_evidences',
            'WHERE' => ['reports_id' => $reportId],
            'ORDER' => ['id ASC'],
        ]) as $evidenceRow) {
            $evidenceRows[] = $evidenceRow;
        }
        echo '<h5>' . self::e(__('Fotografías del equipo', 'informeglpi')) . '</h5>';
        if ($evidenceRows === []) {
            echo "<div class='text-muted mb-3'>" . self::e(__('Aún no se han adjuntado fotografías.', 'informeglpi')) . '</div>';
        } else {
            echo "<div class='d-flex flex-wrap gap-3 mb-3'>";
            foreach ($evidenceRows as $evidenceRow) {
                $evidenceUrl = '/plugins/informeglpi/front/evidence.php?id=' . (int) $evidenceRow['id'];
                echo "<a href='" . self::e($evidenceUrl) . "' target='_blank' rel='noopener' class='border rounded p-1' style='width:160px'>";
                echo "<img src='" . self::e($evidenceUrl) . "' alt='" . self::e($evidenceRow['original_name']) . "' style='width:100%;height:115px;object-fit:cover'>";
                echo "<small class='d-block text-break mt-1'>" . self::e($evidenceRow['original_name']) . '</small></a>';
            }
            echo '</div>';
        }

        if ($isEditable) {
            self::renderFieldsForm('/plugins/informeglpi/front/report.edit.form.php', [
                '_glpi_csrf_token' => \Session::getNewCSRFToken(true),
                'id' => $reportId,
            ], $report->fields);
        } else {
            echo "<div class='alert alert-secondary'>" . self::e(__('Este informe ya no está en edición. Su consulta y descarga se habilitarán según el flujo de aprobación y finalización.', 'informeglpi')) . '</div>';
            foreach ([
                'background' => __('Antecedentes', 'informeglpi'),
                'diagnosis' => __('Diagnóstico', 'informeglpi'),
                'cause' => __('Causa', 'informeglpi'),
                'activities' => __('Actividades / observaciones', 'informeglpi'),
                'recommendations' => __('Recomendaciones', 'informeglpi'),
            ] as $field => $label) {
                $value = trim((string) ($report->fields[$field] ?? ''));
                if ($value !== '') {
                    echo "<div class='mb-3'><strong>" . self::e($label) . '</strong><div class="border rounded p-2">' . nl2br(self::e($value)) . '</div></div>';
                }
            }
        }
        echo '</section>';
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => __('En preparación', 'informeglpi'),
            'pending_technical_signature' => __('Pendiente de firma técnica', 'informeglpi'),
            'pending_supervisor_signature' => __('Pendiente de supervisión', 'informeglpi'),
            'finalized' => __('Finalizado', 'informeglpi'),
            default => $status,
        };
    }

    private static function e($value): string
    {
        return htmlspecialchars((string) ($value ?? '—'), ENT_QUOTES, 'UTF-8');
    }

    private static function loadReports(string $itemtype, int $itemsId, array $statuses): array
    {
        global $DB;
        $result = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'report_number', 'status', 'date_creation', 'users_id', 'entities_id', 'is_recursive'],
            'FROM' => 'glpi_plugin_informeglopi_reports',
            'WHERE' => [
                'itemtype' => $itemtype,
                'items_id' => $itemsId,
                'status' => $statuses,
            ],
            'ORDER' => ['id DESC'],
        ]) as $row) {
            if (!\Session::haveAccessToEntity((int) ($row['entities_id'] ?? -1), (bool) ($row['is_recursive'] ?? false))) {
                continue;
            }
            $row['creator_name'] = '—';
            $userId = (int) ($row['users_id'] ?? 0);
            if ($userId > 0) {
                foreach ($DB->request(['SELECT' => ['name'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => $userId], 'LIMIT' => 1]) as $user) {
                    $row['creator_name'] = (string) ($user['name'] ?? '—');
                    break;
                }
            }
            $result[] = $row;
        }
        return $result;
    }
}
