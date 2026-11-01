<?php
declare(strict_types=1);

namespace GlpiPlugin\Informeglopi;

use CommonDBTM;
use Glpi\Asset\Asset;

/**
 * Builds a historical snapshot of an asset and its attached hardware.
 *
 * The relation classes and their table metadata are supplied by GLPI itself;
 * this avoids hard-coding relation table names that can vary by item type.
 */
final class AssetSnapshotBuilder
{
    private const DEVICE_RELATIONS = [
        'processors'    => 'Item_DeviceProcessor',
        'memories'      => 'Item_DeviceMemory',
        'hard_drives'   => 'Item_DeviceHardDrive',
        'network_cards' => 'Item_DeviceNetworkCard',
        'graphic_cards' => 'Item_DeviceGraphicCard',
        'sound_cards'   => 'Item_DeviceSoundCard',
        'controllers'   => 'Item_DeviceControl',
        'batteries'     => 'Item_DeviceBattery',
    ];

    public static function build(CommonDBTM $item): array
    {
        $itemtype = $item::class;
        $itemsId = (int) ($item->fields['id'] ?? 0);
        $fields = self::scalarFields($item->fields ?? []);

        $snapshot = [
            'schema_version' => 2,
            'captured_at'    => date('Y-m-d H:i:s'),
            'itemtype'       => $itemtype,
            'items_id'       => $itemsId,
            'asset_name'     => (string) ($item->fields['name'] ?? ''),
            'fields'         => $fields,
            'components'     => [],
        ];

        if (is_a($item, Asset::class)) {
            $snapshot['asset_definition'] = [
                'system_name' => $item::getDefinition()->fields['system_name'] ?? null,
                'name' => $item::getDefinition()->getTranslatedName(),
            ];
            $customFields = $item->fields['custom_fields'] ?? null;
            if (is_string($customFields) && $customFields !== '') {
                $decoded = json_decode($customFields, true);
                $snapshot['custom_fields'] = is_array($decoded) ? $decoded : [];
            }
            foreach ($item->fields as $key => $value) {
                if (str_starts_with((string) $key, 'custom_') && (is_scalar($value) || $value === null)) {
                    $snapshot['custom_fields'][(string) $key] = $value;
                }
            }
        }

        if ($itemtype === 'Computer') {
            foreach (self::DEVICE_RELATIONS as $key => $relationClass) {
                $snapshot['components'][$key] = self::deviceRelations(
                    $relationClass,
                    $itemtype,
                    $itemsId
                );
            }

            $snapshot['components']['disks'] = self::disks($itemtype, $itemsId);
            $snapshot['components']['operating_systems'] = self::operatingSystems($itemtype, $itemsId);
        }

        return $snapshot;
    }

    private static function scalarFields(array $fields): array
    {
        $result = [];
        foreach ($fields as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $result[(string) $key] = $value;
            }
        }
        return $result;
    }

    private static function deviceRelations(string $relationClass, string $itemtype, int $itemsId): array
    {
        global $DB;

        if (!class_exists($relationClass) || $itemsId < 1) {
            return [];
        }

        $relationTable = $relationClass::getTable();
        $itemtypeField = $relationClass::$itemtype_1 ?? 'itemtype';
        $itemsIdField = $relationClass::$items_id_1 ?? 'items_id';
        $deviceType = $relationClass::$itemtype_2 ?? null;
        $deviceIdField = $relationClass::$items_id_2 ?? null;

        if (!is_string($deviceType) || !is_string($deviceIdField) || !class_exists($deviceType)) {
            return [];
        }

        $records = [];
        foreach ($DB->request([
            'SELECT' => '*',
            'FROM'   => $relationTable,
            'WHERE'  => [
                $itemtypeField => $itemtype,
                $itemsIdField  => $itemsId,
            ],
        ]) as $relation) {
            $deviceId = (int) ($relation[$deviceIdField] ?? 0);
            $device = new $deviceType();
            $deviceFields = [];
            if ($deviceId > 0 && $device->getFromDB($deviceId)) {
                $deviceFields = self::scalarFields($device->fields ?? []);
            }

            $records[] = [
                'relation' => self::scalarFields($relation),
                'device'   => $deviceFields,
            ];
        }

        return $records;
    }

    private static function disks(string $itemtype, int $itemsId): array
    {
        global $DB;

        if (!class_exists('Item_Disk')) {
            return [];
        }

        $records = [];
        foreach ($DB->request([
            'SELECT' => '*',
            'FROM'   => Item_Disk::getTable(),
            'WHERE'  => [
                (Item_Disk::$itemtype ?? 'itemtype') => $itemtype,
                (Item_Disk::$items_id ?? 'items_id') => $itemsId,
            ],
        ]) as $disk) {
            $records[] = self::scalarFields($disk);
        }

        return $records;
    }

    private static function operatingSystems(string $itemtype, int $itemsId): array
    {
        global $DB;

        if (!class_exists('Item_OperatingSystem')) {
            return [];
        }

        $relationClass = 'Item_OperatingSystem';
        $records = [];
        foreach ($DB->request([
            'SELECT' => '*',
            'FROM'   => $relationClass::getTable(),
            'WHERE'  => [
                ($relationClass::$itemtype_2 ?? 'itemtype') => $itemtype,
                ($relationClass::$items_id_2 ?? 'items_id') => $itemsId,
            ],
        ]) as $relation) {
            $osIdField = $relationClass::$items_id_1 ?? 'operatingsystems_id';
            $osId = (int) ($relation[$osIdField] ?? 0);
            $osFields = [];
            if ($osId > 0 && class_exists('OperatingSystem')) {
                $os = new \OperatingSystem();
                if ($os->getFromDB($osId)) {
                    $osFields = self::scalarFields($os->fields ?? []);
                }
            }
            $records[] = [
                'relation' => self::scalarFields($relation),
                'operating_system' => $osFields,
            ];
        }

        return $records;
    }
}
