<?php
declare(strict_types=1);

namespace GlpiPlugin\Informeglopi;

use CommonDBTM;
use Glpi\Asset\Asset;
use Glpi\Asset\AssetDefinitionManager;

/**
 * Resolves all reportable GLPI assets: native inventory types and
 * dynamically generated Asset Definitions classes.
 */
final class AssetTypeRegistry
{
    private const NATIVE_ASSETS = [
        'Computer',
        'Monitor',
        'NetworkEquipment',
        'Printer',
        'Phone',
        'Peripheral',
        'Software',
        'Cartridge',
        'Consumable',
        'Rack',
        'Enclosure',
        'PDU',
        'PassiveDCEquipment',
        'Unmanaged',
        'Cable',
    ];

    /** @return string[] */
    public static function getItemtypes(): array
    {
        $itemtypes = [];
        foreach (self::NATIVE_ASSETS as $itemtype) {
            if (class_exists($itemtype) && is_a($itemtype, CommonDBTM::class, true)) {
                $itemtypes[] = $itemtype;
            }
        }

        if (class_exists(AssetDefinitionManager::class)) {
            foreach (AssetDefinitionManager::getInstance()->getDefinitions(true) as $definition) {
                $itemtype = $definition->getAssetClassName();
                if (
                    class_exists($itemtype)
                    && is_a($itemtype, Asset::class, true)
                    && is_a($itemtype, CommonDBTM::class, true)
                ) {
                    $itemtypes[] = $itemtype;
                }
            }
        }

        return array_values(array_unique($itemtypes));
    }

    public static function isSupported(string $itemtype): bool
    {
        return in_array($itemtype, self::getItemtypes(), true);
    }
}
