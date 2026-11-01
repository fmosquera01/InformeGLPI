<?php
declare(strict_types=1);

namespace GlpiPlugin\Informeglopi;

use CommonGLPI;
use Html;
use Session;

/**
 * Native GLPI profile rights for Informe Técnico.
 * No parallel ACL or plugin-specific user-role system is used.
 */
final class Profile extends \Profile
{
    // CommonGLPI declares $rightname without a type; keep the same declaration.
    public static $rightname = 'plugin_informeglpi';

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof \Profile && (int) $item->getField('id') > 0) {
            return self::createTabEntry(__('Informe Técnico', 'informeglpi'));
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof \Profile) {
            $profile = new self();
            $profile->showFormInformeTecnico((int) $item->getID());
        }

        return true;
    }

    private function showFormInformeTecnico(int $profiles_id): void
    {
        $targetProfile = new \Profile();
        if (!$targetProfile->can($profiles_id, UPDATE)) {
            return;
        }

        // Permission to administer profiles is the authority to edit this rights tab.
        // Do not gate this screen on the plugin right being configured already.
        $canEdit = Session::haveRight('profile', UPDATE);
        echo "<div class='spaced'>";

        if ($canEdit) {
            echo "<form method='post' action='" . htmlspecialchars(self::getFormURL(), ENT_QUOTES, 'UTF-8') . "'>";
        }

        $rights = [
            [
                'itemtype' => Report::class,
                'label'    => __('Informes técnicos', 'informeglpi'),
                'field'    => self::$rightname,
            ],
            [
                'itemtype' => Report::class,
                'label'    => __('Aprobar y finalizar informes', 'informeglpi'),
                'field'    => 'plugin_informeglpi_validate',
            ],
        ];

        $this->displayRightsChoiceMatrix($rights, [
            'canedit' => $canEdit,
            'title'   => __('Derechos de Informe Técnico', 'informeglpi'),
        ]);

        if ($canEdit) {
            echo "<div class='text-center'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo '</div>';
            Html::closeForm();
        }

        echo '</div>';
    }
}
