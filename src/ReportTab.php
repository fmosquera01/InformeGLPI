<?php
declare(strict_types=1);

namespace GlpiPlugin\Informeglopi;

use CommonGLPI;

/**
 * History tab shared by all native GLPI assets and Asset Definitions.
 */
final class ReportTab extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return __('Informes técnicos', 'informeglopi');
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
            echo "<div class='alert alert-warning'>" . htmlspecialchars(__('No tiene permisos para consultar informes técnicos.', 'informeglopi'), ENT_QUOTES, 'UTF-8') . '</div>';
            return true;
        }

        $itemtype = $item::class;
        $itemsId = (int) $item->getID();
        $reports = self::loadReports($itemtype, $itemsId);

        echo "<div class='card mb-3'><div class='card-header d-flex justify-content-between align-items-center'>";
        echo '<strong>' . htmlspecialchars(__('Historial de informes técnicos', 'informeglopi'), ENT_QUOTES, 'UTF-8') . '</strong>';

        if (\Session::haveRight(Report::$rightname, CREATE)) {
            $createUrl = '/plugins/informeglopi/front/report.form.php?itemtype=' . rawurlencode($itemtype) . '&items_id=' . $itemsId;
            echo "<a class='btn btn-primary btn-sm' href='" . htmlspecialchars($createUrl, ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars(__('Nuevo informe', 'informeglopi'), ENT_QUOTES, 'UTF-8') . '</a>';
        }
        echo '</div><div class="card-body">';

        if ($reports === []) {
            echo "<div class='alert alert-info mb-0'>" . htmlspecialchars(__('Este activo todavía no tiene informes técnicos.', 'informeglopi'), ENT_QUOTES, 'UTF-8') . '</div>';
        } else {
            echo "<div class='table-responsive'><table class='table table-hover table-sm'>";
            echo '<thead><tr>';
            foreach ([__('Número', 'informeglopi'), __('Fecha', 'informeglopi'), __('Estado', 'informeglopi'), __('Técnico', 'informeglopi'), __('Acciones', 'informeglopi')] as $heading) {
                echo '<th>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</th>';
            }
            echo '</tr></thead><tbody>';

            foreach ($reports as $report) {
                $id = (int) $report['id'];
                $viewUrl = '/plugins/informeglopi/front/report.php?id=' . $id;
                echo '<tr>';
                echo '<td>' . htmlspecialchars((string) $report['report_number'], ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . htmlspecialchars((string) $report['date_creation'], ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . htmlspecialchars((string) $report['status'], ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . htmlspecialchars((string) ($report['creator_name'] ?? '—'), ENT_QUOTES, 'UTF-8') . '</td>';
                echo "<td><a class='btn btn-sm btn-outline-primary' href='" . htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars(__('Visualizar', 'informeglopi'), ENT_QUOTES, 'UTF-8') . '</a></td>';
                echo '</tr>';
            }

            echo '</tbody></table></div>';
        }

        echo '</div></div>';
        return true;
    }

    /** @return array<int, array<string, mixed>> */
    private static function loadReports(string $itemtype, int $itemsId): array
    {
        global $DB;

        $result = [];
        foreach ($DB->request([
            'SELECT' => [
                'id', 'report_number', 'status', 'date_creation',
                'users_id', 'entities_id', 'is_recursive',
            ],
            'FROM' => 'glpi_plugin_informeglopi_reports',
            'WHERE' => [
                'itemtype' => $itemtype,
                'items_id' => $itemsId,
            ],
            'ORDER' => ['id DESC'],
        ]) as $row) {
            if (!\Session::haveAccessToEntity(
                (int) ($row['entities_id'] ?? -1),
                (bool) ($row['is_recursive'] ?? false)
            )) {
                continue;
            }

            $row['creator_name'] = '—';
            $userId = (int) ($row['users_id'] ?? 0);
            if ($userId > 0) {
                foreach ($DB->request([
                    'SELECT' => ['name'],
                    'FROM' => 'glpi_users',
                    'WHERE' => ['id' => $userId],
                    'LIMIT' => 1,
                ]) as $user) {
                    $row['creator_name'] = (string) ($user['name'] ?? '—');
                    break;
                }
            }
            $result[] = $row;
        }

        return $result;
    }
}
