<?php
declare(strict_types=1);

namespace GlpiPlugin\Informeglopi;

use CommonGLPI;

/** Informe Técnico tab. Creation, editing and history stay inside the asset form. */
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
        global $CFG_GLPI;

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
        $tabUrl = $assetUrl . '&forcetab=' . rawurlencode(self::class);
        $reports = self::loadReports($itemtype, $itemsId);

        echo "<div class='card mb-3'><div class='card-header d-flex justify-content-between align-items-center'>";
        echo '<strong>' . self::e(__('Informes técnicos del activo', 'informeglpi')) . '</strong>';
        if (\Session::haveRight(Report::$rightname, CREATE)) {
            echo "<form method='post' action='" . self::e($CFG_GLPI['root_doc'] . '/plugins/informeglpi/front/report.form.php') . "' class='m-0'>";
            echo Html::hidden('_glpi_csrf_token', ['value' => \Session::getNewCSRFToken()]);
            echo Html::hidden('itemtype', ['value' => $itemtype]);
            echo Html::hidden('items_id', ['value' => $itemsId]);
            echo "<button type='submit' class='btn btn-primary btn-sm' onclick=\"this.disabled=true;this.form.submit();\">" . self::e(__('Crear informe', 'informeglpi')) . '</button>';
            echo '</form>';
        }
        echo '</div><div class="card-body">';

        $selectedReportId = (int) ($_GET['report_id'] ?? 0);
        if ($selectedReportId > 0) {
            self::renderSelectedReport($selectedReportId, $itemtype, $itemsId, $tabUrl);
        }

        if ($reports === []) {
            echo "<div class='alert alert-info mb-0'>" . self::e(__('Este activo todavía no tiene informes técnicos.', 'informeglpi')) . '</div>';
        } else {
            echo "<div class='table-responsive'><table class='table table-hover table-sm'>";
            echo '<thead><tr>';
            foreach ([__('Número', 'informeglpi'), __('Fecha', 'informeglpi'), __('Estado', 'informeglpi'), __('Creado por', 'informeglpi'), __('Acción', 'informeglpi')] as $heading) {
                echo '<th>' . self::e($heading) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($reports as $row) {
                $id = (int) $row['id'];
                $openUrl = $tabUrl . '&report_id=' . $id;
                echo '<tr><td>' . self::e($row['report_number']) . '</td>';
                echo '<td>' . self::e($row['date_creation']) . '</td>';
                echo '<td>' . self::e(self::statusLabel((string) $row['status'])) . '</td>';
                echo '<td>' . self::e($row['creator_name'] ?? '—') . '</td>';
                echo "<td><a class='btn btn-sm btn-outline-primary' href='" . self::e($CFG_GLPI['root_doc'] . $openUrl) . "'>" . self::e(__('Abrir', 'informeglpi')) . '</a></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></div>';
        return true;
    }

    private static function renderSelectedReport(int $reportId, string $itemtype, int $itemsId, string $tabUrl): void
    {
        global $CFG_GLPI;
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
        echo "<div class='alert alert-info'>" . self::e(__('Los datos identificativos se recuperan de GLPI y no se pueden modificar desde este formulario. Complete únicamente la información técnica.', 'informeglpi')) . '</div>';
        echo '<h5>' . self::e(__('INFORMACIÓN DEL ACTIVO', 'informeglpi')) . '</h5>';
        $autoFields = [
            'name' => __('Código / nombre del activo', 'informeglpi'),
            'buy_date' => __('Fecha de compra', 'informeglpi'),
            'serial' => __('Serie', 'informeglpi'),
            'otherserial' => __('Activo fijo', 'informeglpi'),
            'comment' => __('Descripción', 'informeglpi'),
        ];
        echo "<div class='table-responsive'><table class='table table-sm table-bordered'><tbody>";
        echo '<tr><th style="width:28%">' . self::e(__('Tipo', 'informeglpi')) . '</th><td>' . self::e($itemtype) . '</td></tr>';
        foreach ($autoFields as $key => $label) {
            if (!array_key_exists($key, $assetFields) || is_array($assetFields[$key])) {
                continue;
            }
            echo '<tr><th>' . self::e($label) . '</th><td>' . nl2br(self::e($assetFields[$key])) . '</td></tr>';
        }
        echo '</tbody></table></div>';

        echo '<h5 class="mt-4">' . self::e(__('INFORMACIÓN TÉCNICA DEL ACTIVO', 'informeglpi')) . '</h5>';
        if ($isEditable) {
            $action = $CFG_GLPI['root_doc'] . '/plugins/informeglpi/front/report.edit.form.php';
            echo "<form method='post' action='" . self::e($action) . "'>";
            echo Html::hidden('_glpi_csrf_token', ['value' => \Session::getNewCSRFToken()]);
            echo Html::hidden('id', ['value' => $reportId]);
            $fields = [
                'book_value' => __('Valor en libros (USD)', 'informeglpi'),
                'background' => __('ANTECEDENTES', 'informeglpi'),
                'diagnosis' => __('DIAGNÓSTICO', 'informeglpi'),
                'cause' => __('CAUSA', 'informeglpi'),
                'activities' => __('ACTIVIDADES / OBSERVACIONES', 'informeglpi'),
                'recommendations' => __('RECOMENDACIONES', 'informeglpi'),
            ];
            foreach ($fields as $field => $label) {
                echo "<div class='mb-3'><label class='form-label' for='field_" . self::e($field) . "'>" . self::e($label) . '</label>';
                if ($field === 'book_value') {
                    echo "<input class='form-control' type='number' min='0' step='0.01' name='book_value' id='field_book_value' value='" . self::e($report->fields['book_value'] ?? '') . "'></div>";
                } else {
                    echo "<textarea class='form-control' rows='3' id='field_" . self::e($field) . "' name='" . self::e($field) . "'>" . self::e($report->fields[$field] ?? '') . '</textarea></div>';
                }
            }
            $damage = (string) ($report->fields['damage_type'] ?? '');
            echo '<fieldset class="mb-3"><legend class="fs-6">' . self::e(__('TIPO DE DAÑO', 'informeglpi')) . '</legend>';
            foreach (['wear' => __('Desgaste', 'informeglpi'), 'property_damage' => __('Daño a la propiedad / mal uso', 'informeglpi')] as $value => $label) {
                echo "<div class='form-check form-check-inline'><input class='form-check-input' type='radio' name='damage_type' id='damage_" . self::e($value) . "' value='" . self::e($value) . "' " . ($damage === $value ? 'checked' : '') . '>';
                echo "<label class='form-check-label' for='damage_" . self::e($value) . "'>" . self::e($label) . '</label></div>';
            }
            echo '</fieldset>';
            echo "<button type='submit' class='btn btn-primary'>" . self::e(__('Guardar informe', 'informeglpi')) . '</button> ';
            echo "<a class='btn btn-secondary' href='" . self::e($CFG_GLPI['root_doc'] . $tabUrl) . "'>" . self::e(__('Cerrar formulario', 'informeglpi')) . '</a>';
            echo '</form>';
        } else {
            echo "<div class='alert alert-secondary'>" . self::e(__('Este informe ya no está en edición. La descarga PDF estará disponible cuando haya sido firmado y finalizado.', 'informeglpi')) . '</div>';
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

    private static function loadReports(string $itemtype, int $itemsId): array
    {
        global $DB;
        $result = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'report_number', 'status', 'date_creation', 'users_id', 'entities_id', 'is_recursive'],
            'FROM' => 'glpi_plugin_informeglopi_reports',
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $itemsId],
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
