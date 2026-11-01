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

        echo "<div class='card mb-3'><div class='card-header'><strong>" . self::e(__('Informes técnicos', 'informeglpi')) . '</strong></div><div class="card-body">';
        echo "<ul class='nav nav-tabs mb-3'>";
        self::navLink($tabUrl, 'new', __('Nuevo', 'informeglpi'), $view);
        self::navLink($tabUrl, 'drafts', __('Borradores', 'informeglpi'), $view);
        self::navLink($tabUrl, 'approved', __('Aprobados', 'informeglpi'), $view);
        echo '</ul>';

        if ($view === 'new') {
            if (\Session::haveRight(Report::$rightname, CREATE)) {
                self::renderNewForm($itemtype, $itemsId);
            } else {
                echo "<div class='alert alert-warning mb-0'>" . self::e(__('No tiene permisos para crear informes técnicos.', 'informeglpi')) . '</div>';
            }
        } elseif ($view === 'drafts') {
            self::renderReportList($itemtype, $itemsId, $tabUrl, ['draft', 'pending_technical_signature', 'pending_supervisor_signature']);
            $reportId = (int) ($_GET['report_id'] ?? 0);
            if ($reportId > 0) {
                self::renderSelectedReport($reportId, $itemtype, $itemsId, $tabUrl);
            }
        } else {
            self::renderReportList($itemtype, $itemsId, $tabUrl, ['finalized']);
            $reportId = (int) ($_GET['report_id'] ?? 0);
            if ($reportId > 0) {
                self::renderSelectedReport($reportId, $itemtype, $itemsId, $tabUrl);
            }
        }

        echo '</div></div>';
        return true;
    }

    private static function navLink(string $tabUrl, string $key, string $label, string $active): void
    {
        $url = $tabUrl . '&report_view=' . rawurlencode($key);
        $class = $active === $key ? 'nav-link active' : 'nav-link';
        echo "<li class='nav-item'><a class='" . $class . "' href='" . self::e($url) . "'>" . self::e($label) . '</a></li>';
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
        $assetFields = is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [];

        echo "<div class='alert alert-info'>" . self::e(__('Complete la información técnica. Los datos del activo se capturan automáticamente al guardar y no se pueden modificar desde este informe.', 'informeglpi')) . '</div>';
        echo '<h5>' . self::e(__('Información del activo', 'informeglpi')) . '</h5>';
        echo "<div class='table-responsive'><table class='table table-sm table-bordered'><tbody>";
        echo '<tr><th style="width:28%">' . self::e(__('Tipo', 'informeglpi')) . '</th><td>' . self::e($itemtype) . '</td></tr>';
        foreach ([
            'name' => __('Código / nombre', 'informeglpi'),
            'serial' => __('Serie', 'informeglpi'),
            'otherserial' => __('Activo fijo', 'informeglpi'),
            'buy_date' => __('Fecha de compra', 'informeglpi'),
            'comment' => __('Descripción', 'informeglpi'),
        ] as $key => $label) {
            if (isset($assetFields[$key]) && !is_array($assetFields[$key])) {
                echo '<tr><th>' . self::e($label) . '</th><td>' . nl2br(self::e($assetFields[$key])) . '</td></tr>';
            }
        }
        echo '</tbody></table></div>';
        self::renderFieldsForm('/plugins/informeglpi/front/report.form.php', [
            '_glpi_csrf_token' => \Session::getNewCSRFToken(true),
            'itemtype' => $itemtype,
            'items_id' => $itemsId,
        ], []);
    }

    private static function renderFieldsForm(string $action, array $hidden, array $values): void
    {
        echo '<h5 class="mt-4">' . self::e(__('Información técnica del informe', 'informeglpi')) . '</h5>';
        echo "<form method='post' action='" . self::e($action) . "'>";
        foreach ($hidden as $name => $value) {
            echo "<input type='hidden' name='" . self::e($name) . "' value='" . self::e($value) . "'>";
        }
        $fields = [
            'book_value' => __('Valor en libros (USD)', 'informeglpi'),
            'background' => __('ANTECEDENTES', 'informeglpi'),
            'diagnosis' => __('DIAGNÓSTICO', 'informeglpi'),
            'cause' => __('CAUSA', 'informeglpi'),
            'activities' => __('ACTIVIDADES / OBSERVACIONES', 'informeglpi'),
            'recommendations' => __('RECOMENDACIONES', 'informeglpi'),
            'observations' => __('OBSERVACIONES ADICIONALES', 'informeglpi'),
        ];
        foreach ($fields as $field => $label) {
            $value = $values[$field] ?? '';
            echo "<div class='mb-3'><label class='form-label' for='field_" . self::e($field) . "'>" . self::e($label) . '</label>';
            if ($field === 'book_value') {
                echo "<input class='form-control' type='number' min='0' step='0.01' name='book_value' id='field_book_value' value='" . self::e($value) . "'></div>";
            } else {
                echo "<textarea class='form-control' rows='3' id='field_" . self::e($field) . "' name='" . self::e($field) . "'>" . self::e($value) . '</textarea></div>';
            }
        }
        $damage = (string) ($values['damage_type'] ?? '');
        echo '<fieldset class="mb-3"><legend class="fs-6">' . self::e(__('TIPO DE DAÑO', 'informeglpi')) . '</legend>';
        foreach (['wear' => __('Desgaste', 'informeglpi'), 'property_damage' => __('Daño a la propiedad / mal uso', 'informeglpi')] as $value => $label) {
            echo "<div class='form-check form-check-inline'><input class='form-check-input' type='radio' name='damage_type' id='damage_" . self::e($value) . "' value='" . self::e($value) . "' " . ($damage === $value ? 'checked' : '') . '>';
            echo "<label class='form-check-label' for='damage_" . self::e($value) . "'>" . self::e($label) . '</label></div>';
        }
        echo '</fieldset>';
        echo "<button type='submit' class='btn btn-primary'>" . self::e(__('Guardar borrador', 'informeglpi')) . '</button>';
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
            $openUrl = $tabUrl . '&report_view=' . $view . '&report_id=' . $id;
            echo '<tr><td>' . self::e($row['report_number']) . '</td>';
            echo '<td>' . self::e($row['date_creation']) . '</td>';
            echo '<td>' . self::e(self::statusLabel((string) $row['status'])) . '</td>';
            echo '<td>' . self::e($row['creator_name'] ?? '—') . '</td>';
            echo "<td><a class='btn btn-sm btn-outline-primary' href='" . self::e($openUrl) . "'>" . self::e(__('Editar / ver', 'informeglpi')) . '</a></td></tr>';
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
