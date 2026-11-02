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

        // GLPI stores purchase/accounting information in glpi_infocoms,
        // not in the main asset table (Computer, Monitor, etc.).
        global $DB;
        // GLPI 11 stores the purchase date in glpi_infocoms.buy_date.
        // Keep the snapshot key as date_buy because the report renderer consumes it.
        if ($DB->tableExists('glpi_infocoms') && $DB->fieldExists('glpi_infocoms', 'buy_date')) {
            foreach ($DB->request([
                'SELECT' => ['buy_date'],
                'FROM'   => 'glpi_infocoms',
                'WHERE'  => [
                    'itemtype' => $itemtype,
                    'items_id' => $itemsId,
                ],
                'LIMIT'  => 1,
            ]) as $infocom) {
                $fields['date_buy'] = $infocom['buy_date'] ?? null;
                break;
            }
        }

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
            // Only computers include processor, RAM and storage details in the report.
            foreach (['processors', 'memories', 'hard_drives'] as $key) {
                $snapshot['components'][$key] = self::deviceRelations(
                    self::DEVICE_RELATIONS[$key],
                    $itemtype,
                    $itemsId
                );
            }
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

        // GLPI 11 Item_Devices relations use the common columns itemtype/items_id.
        // Do not rely on the undocumented itemtype_1/items_id_1 property names:
        // they are not defined consistently by all relation classes.
        $relationTable = $relationClass::getTable();
        $deviceType = $relationClass::getDeviceType();
        if (!is_string($deviceType) || !class_exists($deviceType)) {
            return [];
        }

        $deviceIdField = $deviceType::getForeignKeyField();
        if (!is_string($deviceIdField) || $deviceIdField === '') {
            return [];
        }

        $records = [];
        foreach ($DB->request([
            'SELECT' => '*',
            'FROM'   => $relationTable,
            'WHERE'  => [
                'itemtype'   => $itemtype,
                'items_id'   => $itemsId,
                'is_deleted' => 0,
            ],
            'ORDER' => ['id ASC'],
        ]) as $relation) {
            $deviceId = (int) ($relation[$deviceIdField] ?? 0);
            if ($deviceId < 1) {
                continue;
            }

            $device = new $deviceType();
            $deviceFields = [];
            if ($device->getFromDB($deviceId)) {
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

        if (!class_exists(\Item_Disk::class)) {
            return [];
        }

        $records = [];
        foreach ($DB->request([
            'SELECT' => '*',
            'FROM'   => \Item_Disk::getTable(),
            'WHERE'  => [
                (\Item_Disk::$itemtype ?? 'itemtype') => $itemtype,
                (\Item_Disk::$items_id ?? 'items_id') => $itemsId,
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
