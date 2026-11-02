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
        self::renderReportList($itemtype, $itemsId, $tabUrl, ['draft', 'pending_technical_signature', 'pending_supervisor_signature', 'returned_for_correction', 'approved_pending_pdf']);
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

    public static function renderManualPhonePage(): void
    {
        if (!\Session::haveRight(Report::$rightname, CREATE)) {
            \Html::displayRightError();
        }
        self::renderStyles();
        echo "<div class='container-fluid mt-3 ig-report-shell'><div class='card'><div class='card-header'><strong>" . self::e(__('Nuevo informe de celular no registrado en GLPI', 'informeglpi')) . "</strong></div><div class='card-body'>";
        echo "<div class='alert alert-warning'>" . self::e(__('Esta opción es exclusiva para celulares que todavía no están registrados en GLPI. Si el celular ya existe en el inventario, el informe debe crearse desde su ficha.', 'informeglpi')) . "</div>";
        $assetHtml = self::renderManualPhoneInformation([], true);
        self::renderFieldsForm('/plugins/informeglpi/front/manual-phone.form.php', [
            '_glpi_csrf_token' => \Session::getNewCSRFToken(true),
            'manual_cellphone' => '1',
        ], [
            'report_date' => date('Y-m-d'),
            'report_number' => ReportService::previewNextReportNumber(),
            'prepared_by_users_id' => (int) \Session::getLoginUserID(),
            'subject' => 'Informe técnico del estado actual de ítems de Propiedad, Planta y Equipo.',
            'damage_type' => 'wear',
        ], $assetHtml);
        echo '</div></div></div>';
    }

    private static function renderManualPhoneInformation(array $fields, bool $editable): string
    {
        ob_start();
        echo '<div class="ig-section-title">' . self::e(__('INFORMACIÓN DEL ACTIVO', 'informeglpi')) . '</div>';
        echo "<div class='ig-asset-table'>";
        echo "<div class='ig-row'><div class='ig-label'>" . self::e(__('TIPO:', 'informeglpi')) . "</div><div class='ig-value'><strong>CELULAR</strong></div></div>";
        $rows = [
            'asset_code' => __('CÓDIGO DE ACTIVO:', 'informeglpi'),
            'description' => __('DESCRIPCIÓN DEL ACTIVO:', 'informeglpi'),
            'purchase_date' => __('FECHA DE COMPRA:', 'informeglpi'),
            'manufacturer' => __('MARCA:', 'informeglpi'),
            'model' => __('MODELO:', 'informeglpi'),
            'serial' => __('SERIE:', 'informeglpi'),
            'responsible' => __('USUARIO RESPONSABLE:', 'informeglpi'),
            'area' => __('ÁREA DONDE SE ENCONTRABA EL EQUIPO:', 'informeglpi'),
        ];
        foreach ($rows as $key => $label) {
            $value = (string) ($fields[$key] ?? '');
            echo "<div class='ig-row'><label class='ig-label' for='manual_asset_" . self::e($key) . "'>" . self::e($label) . "</label><div class='ig-value'>";
            if ($editable) {
                $required = in_array($key, ['asset_code', 'description', 'manufacturer', 'model', 'serial', 'responsible', 'area'], true) ? ' required' : '';
                if ($key === 'description') {
                    echo "<textarea class='form-control' rows='2' maxlength='255' id='manual_asset_description' name='manual_asset[description]' placeholder='" . self::e(__('Ejemplo: TELEFONO CELULAR A24', 'informeglpi')) . "'" . $required . ">" . self::e($value) . "</textarea>";
                    echo "<small class='form-text text-muted'>" . self::e(__('Describa el activo de forma clara, por ejemplo: TELEFONO CELULAR A24.', 'informeglpi')) . "</small>";
                } else {
                    $type = $key === 'purchase_date' ? 'date' : 'text';
                    echo "<input class='form-control' type='" . $type . "' id='manual_asset_" . self::e($key) . "' name='manual_asset[" . self::e($key) . "]' value='" . self::e($value) . "'" . $required . ">";
                }
            } else {
                echo nl2br(self::e($value !== '' ? $value : '—'));
            }
            echo '</div></div>';
        }
        echo '</div>';
        return (string) ob_get_clean();
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
        $assetHtml = self::renderAssetInformation(
            $itemtype,
            is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [],
            is_array($snapshot['components'] ?? null) ? $snapshot['components'] : [],
            '',
            true
        );
        self::renderFieldsForm('/plugins/informeglpi/front/report.form.php', [
            '_glpi_csrf_token' => \Session::getNewCSRFToken(true),
            'itemtype' => $itemtype,
            'items_id' => $itemsId,
        ], [
            'report_date' => date('Y-m-d'),
            'report_number' => ReportService::previewNextReportNumber(),
            'prepared_by_users_id' => (int) \Session::getLoginUserID(),
            'subject' => 'Informe técnico del estado actual de ítems de Propiedad, Planta y Equipo.',
        ], $assetHtml, true);
    }

    private static function renderAssetInformation(string $itemtype, array $fields, array $components, string $bookValue = ''): string
    {
        ob_start();

        echo '<div class="ig-section-title">' . self::e(__('INFORMACIÓN DEL ACTIVO', 'informeglpi')) . '</div>';
        echo "<div class='ig-asset-table'>";
        $shortName = (new \ReflectionClass($itemtype))->getShortName();
        $typeField = strtolower($shortName) . 'types_id';
        $modelField = strtolower($shortName) . 'models_id';
        $typeClass = $shortName . 'Type';
        $modelClass = $shortName . 'Model';
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
        $purchaseDate = trim((string) ($fields['date_buy'] ?? ''));
        $purchaseDateDisplay = ($purchaseDate !== '' && !in_array($purchaseDate, ['0000-00-00', '0000-00-00 00:00:00'], true))
            ? \Html::convDate($purchaseDate)
            : '—';
        $comments = trim((string) ($fields['comment'] ?? ''));
        $assetRows = [
            __('DESCRIPCIÓN DEL ACTIVO:', 'informeglpi') => '',
            __('CÓDIGO DE ACTIVO:', 'informeglpi') => $fields['otherserial'] ?? '',
            __('TIPO:', 'informeglpi') => $typeValue,
            __('MARCA:', 'informeglpi') => $manufacturerValue,
            __('MODELO:', 'informeglpi') => $modelValue,
            __('SERIE:', 'informeglpi') => $fields['serial'] ?? '',
            __('USUARIO RESPONSABLE:', 'informeglpi') => $userValue,
            __('ÁREA DONDE SE ENCONTRABA EL EQUIPO:', 'informeglpi') => $locationValue,
        ];
        foreach ($assetRows as $label => $value) {
            if ($label !== __('DESCRIPCIÓN DEL ACTIVO:', 'informeglpi')
                && (is_array($value) || is_object($value) || trim((string) $value) === '' || (string) $value === '0' || in_array(mb_strtolower(trim((string) $value)), ['no asset information', 'n/a', 'not available'], true))) {
                continue;
            }
            echo "<div class='ig-row'><div class='ig-label'>" . self::e($label) . "</div><div class='ig-value'>";
            if ($label === __('DESCRIPCIÓN DEL ACTIVO:', 'informeglpi')) {
                echo "<div><strong>" . self::e(__('FECHA DE COMPRA:', 'informeglpi')) . "</strong> " . self::e($purchaseDateDisplay) . "</div>";
                echo "<div class='mt-1'><label class='fw-bold mb-0' for='field_book_value'>" . self::e(__('VALOR EN LIBROS:', 'informeglpi')) . "</label> <span>$</span> <input class='form-control form-control-sm d-inline-block' style='width:130px' type='number' min='0' step='0.01' name='book_value' id='field_book_value' value='" . self::e($bookValue) . "'></div>";
                if ($comments !== '') {
                    echo "<div class='mt-1'>" . nl2br(self::e($comments)) . "</div>";
                }
                if ($itemtype === 'Computer') {
                    self::renderComputerComponents($components);
                }
            } else {
                echo nl2br(self::e($value));
            }
            echo '</div></div>';
        }
        echo '</div>';
        return (string) ob_get_clean();
    }

    /**
     * Render only the hardware details that meaningfully describe a computer.
     * Network adapters are intentionally excluded: GLPI often exposes generic
     * adapter labels that add noise to the institutional report.
     */
    private static function renderComputerComponents(array $components): void
    {
        $definitions = [
            'processors' => [
                'label' => __('PROCESADOR:', 'informeglpi'),
                'fields' => ['designation', 'name', 'frequency', 'nbcores', 'nbthreads'],
            ],
            'memories' => [
                'label' => __('RAM:', 'informeglpi'),
                'fields' => ['designation', 'name', 'size', 'frequency'],
            ],
            'hard_drives' => [
                'label' => __('DISCO DURO:', 'informeglpi'),
                'fields' => ['designation', 'name', 'capacity', 'interface', 'rpm'],
            ],
        ];

        foreach ($definitions as $key => $definition) {
            $lines = [];
            foreach (($components[$key] ?? []) as $entry) {
                $device = is_array($entry['device'] ?? null) ? $entry['device'] : [];
                $relation = is_array($entry['relation'] ?? null) ? $entry['relation'] : [];
                $parts = [];

                // Prefer the inventory designation; use technical attributes only as fallback.
                $reportLabel = trim((string) ($device['report_label'] ?? ''));
                if ($reportLabel !== '') {
                    $parts[] = $reportLabel;
                } else {
                    foreach (['designation', 'name'] as $preferred) {
                        $value = trim((string) ($device[$preferred] ?? $relation[$preferred] ?? ''));
                        if ($value !== '' && $value !== '0') {
                            $parts[] = $value;
                            break;
                        }
                    }
                }

                if ($parts === []) {
                    foreach (array_slice($definition['fields'], 2) as $field) {
                        $value = trim((string) ($device[$field] ?? $relation[$field] ?? ''));
                        if ($value !== '' && $value !== '0') {
                            $parts[] = $value;
                        }
                    }
                }

                if ($reportLabel === '' && !empty($device['manufacturers_id'])) {
                    $manufacturer = \Dropdown::getDropdownName(\Manufacturer::getTable(), (int) $device['manufacturers_id']);
                    if ($manufacturer !== '' && !in_array($manufacturer, $parts, true)) {
                        $parts[] = $manufacturer;
                    }
                }

                $line = trim(implode(' ', array_unique($parts)));
                if ($line !== '' && !in_array($line, $lines, true)) {
                    $lines[] = $line;
                }
            }

            if ($lines !== []) {
                echo "<div class='ig-component-group mt-1'><strong>" . self::e($definition['label']) . "</strong>";
                foreach ($lines as $line) {
                    echo "<div class='ig-component-line'>" . self::e($line) . '</div>';
                }
                echo '</div>';
            }
        }
    }

    /**
     * Hand Held and Tablet are GLPI Phone types in this installation.
     * Show their useful computing hardware and omit network-card noise.
     */
    private static function renderPhoneComponents(array $components): void
    {
        self::renderHardwareComponentRows($components);
    }

    private static function renderHardwareComponentRows(array $components): void
    {
        $definitions = [
            'processors' => [
                'label' => __('PROCESADOR:', 'informeglpi'),
                'fields' => ['designation', 'name', 'frequency', 'nbcores', 'nbthreads'],
            ],
            'memories' => [
                'label' => __('RAM:', 'informeglpi'),
                'fields' => ['designation', 'name', 'size', 'frequency'],
            ],
            'hard_drives' => [
                'label' => __('ALMACENAMIENTO:', 'informeglpi'),
                'fields' => ['designation', 'name', 'capacity', 'interface', 'rpm'],
            ],
        ];

        foreach ($definitions as $key => $definition) {
            $lines = [];
            foreach (($components[$key] ?? []) as $entry) {
                $device = is_array($entry['device'] ?? null) ? $entry['device'] : [];
                $relation = is_array($entry['relation'] ?? null) ? $entry['relation'] : [];
                $parts = [];
                foreach (['designation', 'name'] as $preferred) {
                    $value = trim((string) ($device[$preferred] ?? ''));
                    if ($value !== '' && $value !== '0') {
                        $parts[] = $value;
                        break;
                    }
                }
                if ($parts === []) {
                    foreach (array_slice($definition['fields'], 2) as $field) {
                        $value = trim((string) ($device[$field] ?? $relation[$field] ?? ''));
                        if ($value !== '' && $value !== '0') {
                            $parts[] = $value;
                        }
                    }
                }
                if (!empty($device['manufacturers_id'])) {
                    $manufacturer = \Dropdown::getDropdownName(\Manufacturer::getTable(), (int) $device['manufacturers_id']);
                    if ($manufacturer !== '' && !in_array($manufacturer, $parts, true)) {
                        $parts[] = $manufacturer;
                    }
                }
                $line = trim(implode(' ', array_unique($parts)));
                if ($line !== '' && !in_array($line, $lines, true)) {
                    $lines[] = $line;
                }
            }
            if ($lines !== []) {
                echo "<div class='ig-row'><div class='ig-label'>" . self::e($definition['label']) . "</div><div class='ig-value'>";
                foreach ($lines as $line) {
                    echo "<div class='ig-component-line'>" . self::e($line) . '</div>';
                }
                echo '</div></div>';
            }
        }
    }

    private static function renderDocumentHeader($number): void
    {
        echo "<header class='ig-document-header'>";
        echo "<div class='ig-document-number'><span>" . self::e(__('NÚMERO', 'informeglpi')) . "</span><strong>" . self::e($number !== '' ? $number : __('Pendiente', 'informeglpi')) . "</strong></div>";
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
        .ig-report-form .ig-evidence-upload{margin-top:.65rem;background:#fafbfc;border:1px dashed #c8ced7;border-radius:5px}
        .ig-report-form .ig-evidence-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(145px,1fr));gap:.7rem;margin:.65rem 0 1rem}
        .ig-report-form .ig-evidence-card{min-width:0;border:1px solid #d9dde3;border-radius:6px;background:#fff;padding:.4rem;box-shadow:0 1px 3px rgba(20,30,45,.05)}
        .ig-report-form .ig-evidence-card img{display:block;width:100%;height:118px;object-fit:cover;border-radius:4px;background:#f2f3f5}
        .ig-report-form .ig-evidence-card small{display:block;margin-top:.35rem;color:#596273;overflow-wrap:anywhere;line-height:1.3}
        .ig-report-form .ig-evidence-empty{padding:.75rem;border:1px dashed #d9dde3;border-radius:5px;color:#687385;background:#fafbfc;font-size:.84rem}
        .ig-report-form .ig-document-header{padding:.75rem .7rem;margin-bottom:.7rem;background:linear-gradient(90deg,#fff 0%,#fafbfc 100%)}
        .ig-report-form .ig-section-title{margin:1rem 0 .4rem;padding:.48rem .65rem;text-align:left;border-left-width:5px}
        .ig-report-form .ig-row{grid-template-columns:minmax(205px,29%) minmax(0,1fr)}
        .ig-report-form .ig-label{display:flex;align-items:center}
        .ig-report-form .ig-component-line{padding:.12rem 0 .12rem .15rem;line-height:1.45}
        .ig-report-form .ig-component-line+ .ig-component-line{border-top:1px dashed #e5e7eb}
        .ig-edit-paper{max-width:1180px;margin:0 auto;background:#fdfdfd}
        .ig-report-shell .table{font-size:.86rem}
        .ig-report-shell .table thead th{background:#f2f3f5;color:#3b414b;font-weight:700;white-space:nowrap}
        .ig-report-shell .btn-warning{background:var(--ig-yellow);border-color:var(--ig-yellow-dark);color:#29230e}
        @media(max-width:700px){.ig-report-form{padding:.7rem}.ig-report-form .ig-row{grid-template-columns:1fr}.ig-report-form .ig-label{padding-bottom:.1rem}.ig-report-form .ig-value{padding-top:.2rem}}
        </style>";
    }

    public static function renderSupervisorInboxPage(): void
    {
        global $DB;
        if (!\Session::haveRight(Report::$rightname, READ)
            || !\Session::haveRight('plugin_informeglpi_validate', UPDATE)) {
            \Html::displayRightError();
        }

        $userId = (int) \Session::getLoginUserID();
        $rows = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'report_number', 'itemtype', 'items_id', 'asset_snapshot', 'date_creation', 'prepared_by_users_id', 'entities_id', 'is_recursive'],
            'FROM' => 'glpi_plugin_informeglopi_reports',
            'WHERE' => ['reviewer_users_id' => $userId, 'status' => 'pending_supervisor_signature'],
            'ORDER' => ['date_creation ASC', 'id ASC'],
        ]) as $row) {
            if (\Session::haveAccessToEntity((int) $row['entities_id'], (bool) $row['is_recursive'])) {
                $rows[] = $row;
            }
        }

        self::renderStyles();
        echo "<div class='container-fluid mt-3 ig-report-shell'><div class='d-flex justify-content-between align-items-center mb-3'><h2 class='h4 mb-0'>" . self::e(__('Bandeja de entrada', 'informeglpi')) . "</h2><a class='btn btn-outline-secondary btn-sm' href='/plugins/informeglpi/front/home.php'>← " . self::e(__('Regresar a Informe Técnico', 'informeglpi')) . "</a></div><div class='card'><div class='card-header d-flex justify-content-between align-items-center'><strong>" . self::e(__('Informes pendientes de firma', 'informeglpi')) . "</strong><span class='badge bg-warning text-dark'>" . count($rows) . "</span></div><div class='card-body'>";
        echo "<p class='text-muted'>" . self::e(__('Informes enviados por los técnicos que esperan su revisión y firma.', 'informeglpi')) . '</p>';
        if ($rows === []) {
            echo "<div class='alert alert-success mb-0'>" . self::e(__('No tiene informes pendientes de revisión.', 'informeglpi')) . '</div>';
        } else {
            echo "<div class='table-responsive'><table class='table table-sm table-hover align-middle'><thead><tr>";
            foreach ([__('Informe', 'informeglpi'), __('Activo', 'informeglpi'), __('Técnico', 'informeglpi'), __('Fecha de envío', 'informeglpi'), __('Acción', 'informeglpi')] as $heading) {
                echo '<th>' . self::e($heading) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $snapshot = json_decode((string) $row['asset_snapshot'], true);
                $snapshot = is_array($snapshot) ? ($snapshot['fields'] ?? []) : [];
                $assetName = (string) ($snapshot['name'] ?? $snapshot['NAME'] ?? $snapshot['NOMBRE'] ?? '');
                $serial = (string) ($snapshot['serial'] ?? $snapshot['SERIAL'] ?? '');
                $inventory = (string) ($snapshot['otherserial'] ?? $snapshot['OTHERSERIAL'] ?? '');
                $assetSummary = trim(implode(' · ', array_filter([$assetName, $serial, $inventory])));
                if ($assetSummary === '') {
                    $assetSummary = (string) $row['itemtype'] . ' #' . (int) $row['items_id'];
                }
                $url = '/plugins/informeglpi/front/report.edit.form.php?id=' . (int) $row['id'];
                echo '<tr><td><strong>' . self::e($row['report_number']) . '</strong></td>';
                echo '<td>' . self::e($assetSummary) . '</td>';
                echo '<td>' . self::e(getUserName((int) $row['prepared_by_users_id'])) . '</td>';
                echo '<td>' . self::e($row['date_creation']) . '</td>';
                echo "<td><a class='btn btn-sm btn-warning' href='" . self::e($url) . "'>" . self::e(__('Revisar informe', 'informeglpi')) . '</a></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></div></div>';
    }

    public static function renderFinalizedReportsPage(): void
    {
        global $DB;
        if (!\Session::haveRight(Report::$rightname, READ)) \Html::displayRightError();
        $rows = [];
        foreach ($DB->request(['SELECT'=>['id','report_number','itemtype','items_id','asset_snapshot','date_mod','date_creation','prepared_by_users_id','entities_id','is_recursive'],'FROM'=>'glpi_plugin_informeglopi_reports','WHERE'=>['status'=>'finalized'],'ORDER'=>['date_mod DESC','id DESC']]) as $row) {
            if (\Session::haveAccessToEntity((int)$row['entities_id'], (bool)$row['is_recursive'])) $rows[]=$row;
        }
        self::renderStyles();
        echo "<div class='container-fluid mt-3 ig-report-shell'><div class='d-flex justify-content-between align-items-center mb-3'><h2 class='h4 mb-0'>" . self::e(__('Informes finalizados','informeglpi')) . "</h2><a class='btn btn-outline-secondary btn-sm' href='/plugins/informeglpi/front/home.php'>← " . self::e(__('Regresar a Informe Técnico','informeglpi')) . "</a></div><div class='card'><div class='card-header d-flex justify-content-between'><strong>" . self::e(__('Historial de informes finalizados','informeglpi')) . "</strong><span class='badge bg-success'>" . count($rows) . "</span></div><div class='card-body'>";
        if (!$rows) echo "<div class='alert alert-info mb-0'>" . self::e(__('Todavía no hay informes finalizados.','informeglpi')) . '</div>';
        else {
            echo "<div class='table-responsive'><table class='table table-sm table-hover align-middle'><thead><tr>";
            foreach ([__('Informe','informeglpi'),__('Activo','informeglpi'),__('Técnico','informeglpi'),__('Fecha','informeglpi'),__('Acción','informeglpi')] as $h) echo '<th>'.self::e($h).'</th>';
            echo '</tr></thead><tbody>';
            foreach ($rows as $r) {
                $s=json_decode((string)$r['asset_snapshot'],true); $a=is_array($s)?($s['fields']??[]):[];
                $name=trim(implode(' · ',array_filter([(string)($a['name']??$a['NAME']??$a['NOMBRE']??''),(string)($a['serial']??$a['SERIAL']??''),(string)($a['otherserial']??$a['OTHERSERIAL']??'')])));
                if($name==='') $name=(string)$r['itemtype'].' #'.(int)$r['items_id'];
                $url='/plugins/informeglpi/front/report.edit.form.php?id='.(int)$r['id'];
                echo '<tr><td><strong>'.self::e($r['report_number']).'</strong></td><td>'.self::e($name).'</td><td>'.self::e(getUserName((int)$r['prepared_by_users_id'])).'</td><td>'.self::e($r['date_mod']?:$r['date_creation'])."</td><td><a class='btn btn-sm btn-outline-primary' href='".self::e($url)."'>".self::e(__('Consultar','informeglpi')).'</a></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></div></div>';
    }

    public static function renderEditPage(int $reportId): void
    {
        $report = new Report();
        if ($reportId < 1 || !$report->getFromDB($reportId)) {
            \Html::displayNotFoundError();
        }
        $itemtype = (string) ($report->fields['itemtype'] ?? '');
        $itemsId = (int) ($report->fields['items_id'] ?? 0);
        $isManualPhone = $itemtype === 'ManualCellphone' && $itemsId === 0;
        if ((!$isManualPhone && (!class_exists($itemtype) || !AssetTypeRegistry::isSupported($itemtype)))
            || !\Session::haveRight(Report::$rightname, READ)
            || !\Session::haveAccessToEntity((int) ($report->fields['entities_id'] ?? -1), (bool) ($report->fields['is_recursive'] ?? false))) {
            \Html::displayRightError();
        }
        $item = null;
        if (!$isManualPhone) {
            $item = new $itemtype();
            if (!$item->getFromDB($itemsId) || !$item->can($itemsId, READ)) {
                \Html::displayRightError();
            }
        }
        $userId = (int) \Session::getLoginUserID();
        $reportStatus = (string) ($report->fields['status'] ?? '');
        $isEditable = in_array($reportStatus, ['draft', 'returned_for_correction'], true)
            && $userId === (int) ($report->fields['prepared_by_users_id'] ?? 0);

        self::renderStyles();
        echo "<div class='container-fluid mt-3 ig-report-shell'><div class='d-flex justify-content-between align-items-center mb-3'>";
        echo "<h2 class='h4 mb-0'>" . self::e(($isEditable ? __('Editar informe técnico', 'informeglpi') : __('Consultar informe técnico', 'informeglpi'))) . "</h2>";
        if ($reportStatus === 'pending_supervisor_signature' && $userId === (int)($report->fields['reviewer_users_id'] ?? 0)) {
            echo "<a class='btn btn-outline-secondary btn-sm' href='/plugins/informeglpi/front/report-inbox.php'>← " . self::e(__('Regresar a la bandeja','informeglpi')) . "</a></div>";
        } elseif ($reportStatus === 'finalized') {
            echo "<a class='btn btn-outline-secondary btn-sm' href='/plugins/informeglpi/front/report-finalized.php'>← " . self::e(__('Regresar a finalizados','informeglpi')) . "</a></div>";
        } elseif ($isManualPhone) {
            echo "<a class='btn btn-outline-secondary btn-sm' href='/plugins/informeglpi/front/manual-phone.php'>" . self::e(__('Volver a celulares no registrados', 'informeglpi')) . "</a></div>";
        } else {
            echo "<a class='btn btn-outline-secondary btn-sm' href='" . self::e($item->getFormURLWithID($itemsId)) . "'>" . self::e(__('Volver al activo', 'informeglpi')) . "</a></div>";
        }
        echo "<div class='ig-edit-paper ig-report-form border rounded p-3'>";
        if (!$isEditable) self::renderDocumentHeader((string)($report->fields['report_number'] ?? ''));
        $snapshot = json_decode((string) ($report->fields['asset_snapshot'] ?? '{}'), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $assetFields = is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [];
        if ($isManualPhone) {
            $manualValues = [
                'asset_code' => $assetFields['CÓDIGO DE ACTIVO'] ?? '',
                'description' => $assetFields['DESCRIPCIÓN DEL ACTIVO'] ?? '',
                'purchase_date' => '',
                'manufacturer' => $assetFields['MARCA'] ?? '',
                'model' => $assetFields['MODELO'] ?? '',
                'serial' => $assetFields['SERIE'] ?? '',
                'responsible' => $assetFields['USUARIO RESPONSABLE'] ?? '',
                'area' => $assetFields['ÁREA DONDE SE ENCONTRABA EL EQUIPO'] ?? '',
            ];
            $purchaseDate = (string) ($assetFields['FECHA DE COMPRA'] ?? '');
            if ($purchaseDate !== '') {
                $parsedDate = \DateTime::createFromFormat('d/m/Y', $purchaseDate);
                $manualValues['purchase_date'] = $parsedDate ? $parsedDate->format('Y-m-d') : '';
            }
            $assetHtml = self::renderManualPhoneInformation($manualValues, true);
        } else {
            $assetHtml = self::renderAssetInformation($itemtype, $assetFields, is_array($snapshot['components'] ?? null) ? $snapshot['components'] : [], (string) ($report->fields['book_value'] ?? ''));
        }
        if ($isEditable) {
            if ($reportStatus === 'returned_for_correction') {
                self::renderReturnNotice($reportId);
            }
            self::renderFieldsForm('/plugins/informeglpi/front/report.edit.form.php', [
                '_glpi_csrf_token' => \Session::getNewCSRFToken(true),
                'id' => $reportId,
            ], $report->fields, $assetHtml, !$isManualPhone);
            // Technical signing is integrated into the same form above.
        } else {
            echo self::renderReadOnlyDocumentMetadata($report);
            echo $assetHtml;
            self::renderReadOnlyDetails($report);
            self::renderWorkflowActions($report);
        }
        echo '</div></div>';
    }

    private static function renderReturnNotice(int $reportId): void
    {
        global $DB;
        foreach ($DB->request([
            'SELECT' => ['comment', 'date_creation', 'users_id'],
            'FROM' => 'glpi_plugin_informeglopi_history',
            'WHERE' => ['reports_id' => $reportId, 'action' => 'returned'],
            'ORDER' => ['date_creation DESC'],
            'LIMIT' => 1,
        ]) as $entry) {
            echo "<div class='alert alert-warning'><strong>" . self::e(__('Observaciones del supervisor', 'informeglpi')) . '</strong><div>' . nl2br(self::e((string) ($entry['comment'] ?? ''))) . '</div><small>' . self::e(getUserName((int) $entry['users_id']) . ' · ' . (string) $entry['date_creation']) . '</small></div>';
            break;
        }
    }

    private static function renderReadOnlyDocumentMetadata(Report $report): string
    {
        ob_start();
        $date=(string)($report->fields['report_date']??'');
        $recipients=json_decode((string)($report->fields['recipients_json']??'[]'),true);
        $names=[]; foreach(is_array($recipients)?$recipients:[] as $id) $names[]=getUserName((int)$id);
        echo "<div class='ig-asset-table mb-3'>";
        foreach ([__('FECHA:','informeglpi')=>($date!==''?\Html::convDate($date):'—'),__('PARA:','informeglpi')=>($names?implode(', ',$names):'—'),__('DE: TÉCNICO QUE ELABORA:','informeglpi')=>getUserName((int)($report->fields['prepared_by_users_id']??0)),__('DE: RESPONSABLE QUE REVISA:','informeglpi')=>getUserName((int)($report->fields['reviewer_users_id']??0)),__('ASUNTO:','informeglpi')=>(string)($report->fields['subject']??'—')] as $label=>$value) echo '<div class="ig-row"><div class="ig-label">'.self::e($label).'</div><div class="ig-value">'.self::e($value).'</div></div>';
        echo '</div>'; return (string)ob_get_clean();
    }

    private static function renderReadOnlyDetails(Report $report): void
    {
        $reportId = (int) $report->getID();
        echo '<div class="ig-section-title">' . self::e(__('INFORMACIÓN TÉCNICA DEL ACTIVO','informeglpi')) . '</div>';
        echo "<div class='ig-asset-table mb-3'>";
        foreach ([
            'background' => __('ANTECEDENTES:', 'informeglpi'),
            'diagnosis' => __('DIAGNÓSTICO:', 'informeglpi'),
            'cause' => __('CAUSA:', 'informeglpi'),
            'recommendations' => __('RECOMENDACIONES:', 'informeglpi'),
        ] as $field => $label) {
            $value = trim((string) ($report->fields[$field] ?? ''));
            echo "<div class='ig-row'><div class='ig-label'>" . self::e($label) . "</div><div class='ig-value'>" . ($value !== '' ? nl2br(self::e($value)) : '—') . '</div></div>';
        }
        echo '</div>';
        echo '<div class="ig-section-title">' . self::e(__('FOTOS DEL EQUIPO','informeglpi')) . '</div>';
        self::renderEvidenceGallery($reportId);
        global $DB;
        $history = [];
        foreach ($DB->request([
            'SELECT' => ['action', 'comment', 'users_id', 'date_creation'],
            'FROM' => 'glpi_plugin_informeglopi_history',
            'WHERE' => ['reports_id' => $reportId],
            'ORDER' => ['date_creation DESC'],
        ]) as $entry) {
            if (in_array((string) ($entry['action'] ?? ''), ['returned', 'technical_signed', 'supervisor_signed'], true)) {
                $history[] = $entry;
            }
        }
        if ($history !== []) {
            echo "<h5 class='mt-3'>" . self::e(__('Historial de revisión', 'informeglpi')) . '</h5><ul class="list-group mb-3">';
            foreach ($history as $entry) {
                $who = getUserName((int) $entry['users_id']);
                echo '<li class="list-group-item"><strong>' . self::e($who) . '</strong> — ' . self::e($entry['date_creation']) . '<div>' . nl2br(self::e((string) ($entry['comment'] ?? ''))) . '</div></li>';
            }
            echo '</ul>';
        }
    }

    private static function renderWorkflowActions(Report $report): void
    {
        $id = (int) $report->getID();
        $userId = (int) \Session::getLoginUserID();
        $status = (string) ($report->fields['status'] ?? '');
        $preparedBy = (int) ($report->fields['prepared_by_users_id'] ?? 0);
        $reviewer = (int) ($report->fields['reviewer_users_id'] ?? 0);
        $actionUrl = '/plugins/informeglpi/front/report.workflow.php';
        $token = \Session::getNewCSRFToken(true);

        if ($status === 'draft' && $userId === $preparedBy) {
            echo "<div class='alert alert-info'>" . self::e(__('Cuando el informe esté completo, firme con su PIN personal para enviarlo al supervisor.', 'informeglpi')) . '</div>';
            self::renderWorkflowForm($actionUrl, $token, $id, 'sign_technical', __('Firmar y enviar a revisión', 'informeglpi'), true);
            return;
        }
        if ($status === 'pending_supervisor_signature' && $userId === $reviewer && \Session::haveRight('plugin_informeglpi_validate', UPDATE)) {
            echo "<div class='alert alert-warning'>" . self::e(__('Revise el contenido y las evidencias. Puede aprobar firmando con su PIN o devolver el informe al técnico.', 'informeglpi')) . '</div>';
            self::renderWorkflowForm($actionUrl, $token, $id, 'sign_supervisor', __('Aprobar y firmar', 'informeglpi'), true);
            self::renderWorkflowForm($actionUrl, $token, $id, 'return', __('Devolver para corrección', 'informeglpi'), false);
            return;
        }
        if ($status === 'approved_pending_pdf') {
            echo "<div class='alert alert-success'>" . self::e(__('El informe cuenta con las dos firmas y está pendiente de generar el PDF institucional.', 'informeglpi')) . '</div>';
            if ($userId === $reviewer && \Session::haveRight('plugin_informeglpi_validate', UPDATE)) {
                self::renderWorkflowForm($actionUrl, $token, $id, 'generate_pdf', __('Generar PDF institucional', 'informeglpi'), false);
            }
            return;
        }
        if ($status === 'finalized' && !empty($report->fields['pdf_path'])) {
            echo "<div class='alert alert-success'>" . self::e(__('El informe está finalizado y el PDF institucional se encuentra disponible.', 'informeglpi')) . "</div>";
            echo "<a class='btn btn-primary btn-sm' target='_blank' rel='noopener' href='/plugins/informeglpi/front/report.pdf.php?id=" . $id . "'>" . self::e(__('Ver / descargar PDF', 'informeglpi')) . "</a>";
            return;
        }
        if ($status === 'returned_for_correction') {
            echo "<div class='alert alert-warning'>" . self::e(__('El informe fue devuelto para corrección. El técnico asignado puede editarlo y volver a firmarlo.', 'informeglpi')) . '</div>';
            return;
        }
        echo "<div class='alert alert-secondary mb-0'>" . self::e(__('Este informe está en revisión o no tiene acciones disponibles para su usuario.', 'informeglpi')) . '</div>';
    }

    private static function renderWorkflowForm(string $actionUrl, string $token, int $reportId, string $action, string $label, bool $withPin): void
    {
        echo "<form method='post' action='" . self::e($actionUrl) . "' class='border rounded p-3 mb-3'>";
        echo "<input type='hidden' name='_glpi_csrf_token' value='" . self::e($token) . "'>";
        echo "<input type='hidden' name='id' value='" . $reportId . "'><input type='hidden' name='workflow_action' value='" . self::e($action) . "'>";
        if ($withPin) {
            echo "<label class='form-label'>" . self::e(__('PIN personal de firma', 'informeglpi')) . "</label><input class='form-control mb-2' style='max-width:240px' type='password' name='pin' inputmode='numeric' pattern='[0-9]{6}' maxlength='6' required autocomplete='off'>";
        } elseif ($action === 'return') {
            echo "<label class='form-label'>" . self::e(__('Motivo de devolución (obligatorio)', 'informeglpi')) . "</label><textarea class='form-control mb-2' name='comment' rows='3' maxlength='2000' required></textarea>";
        }
        echo "<button type='submit' class='btn btn-primary btn-sm'>" . self::e($label) . '</button></form>';
    }

    /**
     * Display evidence already persisted for this report while editing.
     * The original files remain private and are streamed through evidence.php.
     */
    private static function renderEvidenceGallery(int $reportId): void
    {
        global $DB;

        $rows = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'original_name', 'date_creation', 'caption'],
            'FROM' => 'glpi_plugin_informeglopi_evidences',
            'WHERE' => ['reports_id' => $reportId],
            'ORDER' => ['date_creation ASC', 'id ASC'],
        ]) as $row) {
            $rows[] = $row;
        }

        if ($rows === []) {
            echo "<div class='ig-evidence-empty'>" . self::e(__('Todavía no hay fotografías guardadas en este informe.', 'informeglpi')) . '</div>';
            return;
        }

        echo "<div class='ig-evidence-gallery' aria-label='" . self::e(__('Fotografías guardadas', 'informeglpi')) . "'>";
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $url = '/plugins/informeglpi/front/evidence.php?id=' . $id;
            $name = (string) ($row['caption'] ?: $row['original_name'] ?: __('Fotografía', 'informeglpi'));
            echo "<figure class='ig-evidence-card mb-0'>";
            echo "<a href='" . self::e($url) . "' target='_blank' rel='noopener noreferrer'>";
            echo "<img src='" . self::e($url) . "' alt='" . self::e($name) . "' loading='lazy'>";
            echo '</a>';
            echo '<figcaption><small>' . self::e($name) . '</small></figcaption>';
            echo '</figure>';
        }
        echo '</div>';
        echo "<div class='form-text mb-2'>" . self::e(__('Las fotografías guardadas se conservan. Las nuevas imágenes que seleccione se añadirán al informe.', 'informeglpi')) . '</div>';
    }

    private static function renderFieldsForm(string $action, array $hidden, array $values, string $assetHtml = '', bool $bookValueInAsset = false): void
    {
        echo "<form class='ig-report-form' method='post' enctype='multipart/form-data' action='" . self::e($action) . "'>";
        self::renderDocumentHeader($values['report_number'] ?? '');
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
        echo "<div class='ig-row'><label class='ig-label' for='ig_subject'>" . self::e(__('ASUNTO:', 'informeglpi')) . "</label><div class='ig-value'><input class='form-control' type='text' id='ig_subject' name='subject' value='" . self::e($subject) . "'></div></div>";
        echo '</div>';
        echo $assetHtml;
        echo '<div class="ig-section-title">' . self::e(__('INFORMACIÓN TÉCNICA DEL ACTIVO', 'informeglpi')) . '</div>';
        foreach ($hidden as $name => $value) {
            echo "<input type='hidden' name='" . self::e($name) . "' value='" . self::e($value) . "'>";
        }
        $fields = [
            'background' => __('ANTECEDENTES:', 'informeglpi'),
            'diagnosis' => __('DIAGNÓSTICO:', 'informeglpi'),
            'cause' => __('CAUSA:', 'informeglpi'),
            'recommendations' => __('RECOMENDACIONES:', 'informeglpi'),
        ];
        echo "<div class='ig-asset-table'>";
        if (!$bookValueInAsset) {
            $fields = ['book_value' => __('VALOR EN LIBROS (USD):', 'informeglpi')] + $fields;
        }
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
        $reportId = (int) ($values['id'] ?? 0);
        if ($reportId > 0) {
            self::renderEvidenceGallery($reportId);
        }
        echo "<div class='ig-asset-table p-2 ig-evidence-upload'>";
        echo "<label class='form-label' for='ig_evidence_files'>" . self::e(__('Agregar fotografías (JPG, PNG o WEBP; máximo 8 por envío, 8 MB cada una).', 'informeglpi')) . "</label>";
        echo "<input class='form-control' type='file' id='ig_evidence_files' name='evidence_files[]' accept='image/jpeg,image/png,image/webp' capture='environment' multiple>";
        echo "<div class='form-text'>" . self::e(__('Puede seleccionar varias fotos o tomar una fotografía desde un dispositivo móvil.', 'informeglpi')) . "</div>";
        echo "<div id='ig-evidence-preview' class='d-flex flex-wrap gap-2 mt-2'></div></div>";
        echo "<script>
        (function(){const input=document.getElementById('ig_evidence_files');const preview=document.getElementById('ig-evidence-preview');if(!input||!preview)return;input.addEventListener('change',function(){preview.replaceChildren();Array.from(input.files||[]).forEach(function(file){if(!file.type.startsWith('image/'))return;const figure=document.createElement('div');figure.className='border rounded p-1';figure.style.width='120px';const img=document.createElement('img');img.src=URL.createObjectURL(file);img.alt=file.name;img.style.width='100%';img.style.height='85px';img.style.objectFit='cover';const name=document.createElement('small');name.textContent=file.name;name.style.display='block';name.style.overflowWrap='anywhere';figure.append(img,name);preview.append(figure);});});})();
        </script>";
        $isExistingDraft = (int) ($values['id'] ?? 0) > 0;
        echo "<div class='border rounded p-3 mt-3 bg-light'>";
        echo "<label class='form-label fw-bold' for='ig_technical_pin'>" . self::e(__('PIN personal para firmar y enviar al supervisor', 'informeglpi')) . "</label>";
        echo "<input class='form-control mb-2' style='max-width:240px' type='password' inputmode='numeric' pattern='[0-9]{6}' maxlength='6' id='ig_technical_pin' name='technical_pin' autocomplete='off' placeholder='••••••'>";
        echo "<div class='d-flex justify-content-end gap-2 flex-wrap'>";
        echo "<button type='submit' name='submit_action' value='save_draft' class='btn btn-outline-primary btn-sm'>" . self::e(__('Guardar borrador', 'informeglpi')) . "</button>";
        echo "<button type='submit' name='submit_action' value='sign_technical' class='btn btn-primary btn-sm'>" . self::e(__('Firmar y enviar a revisión', 'informeglpi')) . "</button>";
        echo "</div></div>";
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
            && ($currentUserId === (int) ($report->fields['prepared_by_users_id'] ?? 0));
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
            // Technical signing is integrated into the same form above.
        } else {
            echo "<div class='alert alert-secondary'>" . self::e(__('Este informe está en consulta. Sólo el técnico asignado puede editarlo durante la preparación o después de una devolución.', 'informeglpi')) . '</div>';
            self::renderWorkflowActions($report);
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
            'returned_for_correction' => __('Devuelto para corrección', 'informeglpi'),
            'approved_pending_pdf' => __('Aprobado, pendiente de PDF', 'informeglpi'),
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
