<?php
declare(strict_types=1);

namespace GlpiPlugin\\Informeglopi;

use CommonGLPI;

/** GLPI menu entry for the supervisor's report review inbox. */
final class ReportInboxMenu extends CommonGLPI
{
    public static function getMenuName(): string
    {
        return __('Bandeja de revisión', 'informeglpi');
    }

    public static function getMenuContent(): array
    {
        return [
            'title' => __('Bandeja de revisión', 'informeglpi'),
            'page'  => '/plugins/informeglpi/front/report-inbox.php',
        ];
    }
}
