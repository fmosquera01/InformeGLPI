<?php
declare(strict_types=1);

namespace GlpiPlugin\Informeglopi;

use CommonDBTM;

/**
 * Informe Técnico record. Finalization rules are enforced by workflow services.
 */
final class Report extends CommonDBTM
{
    // CommonGLPI declares $rightname without a type; keep the same declaration.
    public static $rightname = 'plugin_informeglopi';

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_informeglopi_reports';
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('Informe técnico', 'Informes técnicos', $nb, 'informeglopi');
    }
}
