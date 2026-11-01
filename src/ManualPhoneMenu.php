<?php
declare(strict_types=1);

namespace GlpiPlugin\Informeglopi;

use CommonGLPI;

/** GLPI Plugins menu entry for manual reports of unregistered cellphones. */
final class ManualPhoneMenu extends CommonGLPI
{
    public static function getMenuName(): string
    {
        return __('Informe Técnico', 'informeglpi');
    }

    public static function getMenuContent(): array
    {
        return [
            'title' => __('Informe Técnico', 'informeglpi'),
            'page'  => '/plugins/informeglpi/front/manual-phone.php',
        ];
    }
}
