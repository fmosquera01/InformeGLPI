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
            // Asset Definitions keep their shared type/model references in the
            // common assets table. Read them explicitly so they are present in
            // the historical snapshot even if the generated item class omits them.
            if ($itemsId > 0
                && $DB->tableExists('glpi_assets_assets')
                && $DB->fieldExists('glpi_assets_assets', 'assets_assettypes_id')
                && $DB->fieldExists('glpi_assets_assets', 'assets_assetmodels_id')) {
                foreach ($DB->request([
                    'SELECT' => ['assets_assettypes_id', 'assets_assetmodels_id'],
                    'FROM' => 'glpi_assets_assets',
                    'WHERE' => ['id' => $itemsId],
                    'LIMIT' => 1,
                ]) as $assetCommonFields) {
                    $fields['assets_assettypes_id'] = $assetCommonFields['assets_assettypes_id'] ?? 0;
                    $fields['assets_assetmodels_id'] = $assetCommonFields['assets_assetmodels_id'] ?? 0;
                    break;
                }
            }
            $snapshot['fields'] = $fields;
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

        if ($itemtype !== 'Computer' || $itemsId < 1) {
            return [];
        }

        // Read the association rows first, then load each catalog device by ID.
        // This avoids fragile JOIN alias handling across GLPI 11 installations.
        $relationConfig = [
            'Item_DeviceProcessor' => [
                'relation_table' => 'glpi_items_deviceprocessors',
                'device_table' => 'glpi_deviceprocessors',
                'foreign_key' => 'deviceprocessors_id',
            ],
            'Item_DeviceMemory' => [
                'relation_table' => 'glpi_items_devicememories',
                'device_table' => 'glpi_devicememories',
                'foreign_key' => 'devicememories_id',
            ],
            'Item_DeviceHardDrive' => [
                'relation_table' => 'glpi_items_deviceharddrives',
                'device_table' => 'glpi_deviceharddrives',
                'foreign_key' => 'deviceharddrives_id',
            ],
        ];

        if (!isset($relationConfig[$relationClass])) {
            return [];
        }

        $config = $relationConfig[$relationClass];
        $rows = [];

        foreach ($DB->request([
            'SELECT' => '*',
            'FROM' => $config['relation_table'],
            'WHERE' => [
                'items_id' => $itemsId,
                'itemtype' => 'Computer',
            ],
        ]) as $relation) {
            if ($relationClass === 'Item_DeviceHardDrive') {
                if ((int) ($relation['is_deleted'] ?? 0) !== 0
                    || (float) ($relation['capacity'] ?? 0) <= 0) {
                    continue;
                }
            }

            $deviceId = (int) ($relation[$config['foreign_key']] ?? 0);
            if ($deviceId < 1) {
                continue;
            }

            foreach ($DB->request([
                'SELECT' => '*',
                'FROM' => $config['device_table'],
                'WHERE' => ['id' => $deviceId],
                'LIMIT' => 1,
            ]) as $device) {
                $row = self::scalarFields($device);

                if ($relationClass === 'Item_DeviceMemory') {
                    $row['_relation_size'] = $relation['size'] ?? null;
                    $memoryTypeId = (int) ($device['devicememorytypes_id'] ?? 0);
                    $row['_memory_type_name'] = $memoryTypeId > 0
                        ? (string) \Dropdown::getDropdownName('glpi_devicememorytypes', $memoryTypeId)
                        : '';
                } elseif ($relationClass === 'Item_DeviceHardDrive') {
                    $row['_relation_capacity'] = $relation['capacity'] ?? null;
                    $interfaceId = (int) ($device['interfacetypes_id'] ?? 0);
                    $row['_interface_name'] = $interfaceId > 0
                        ? (string) \Dropdown::getDropdownName('glpi_interfacetypes', $interfaceId)
                        : '';

                    $designation = trim((string) ($device['designation'] ?? $device['name'] ?? ''));
                    if ($designation === '') {
                        continue;
                    }
                    if (stripos((string) $row['_interface_name'], 'USB') !== false) {
                        continue;
                    }
                }

                $rows[] = $row;
                break;
            }
        }

        // Varias ranuras pueden apuntar al mismo componente de memoria del catálogo.
        // En ese caso, consolidamos sus capacidades para informar la RAM total,
        // sin mezclar componentes distintos instalados en el mismo equipo.
        if ($relationClass === 'Item_DeviceMemory') {
            $groupedRows = [];
            foreach ($rows as $row) {
                $deviceId = (int) ($row['id'] ?? 0);
                $groupKey = $deviceId > 0
                    ? 'device:' . $deviceId
                    : implode('|', [
                        (string) ($row['designation'] ?? $row['name'] ?? ''),
                        (string) ($row['devicememorytypes_id'] ?? 0),
                        (string) ($row['frequency'] ?? 0),
                        (string) ($row['manufacturers_id'] ?? 0),
                    ]);

                if (!isset($groupedRows[$groupKey])) {
                    $groupedRows[$groupKey] = $row;
                    $groupedRows[$groupKey]['_relation_size'] = 0;
                }
                $groupedRows[$groupKey]['_relation_size'] += (float) ($row['_relation_size'] ?? 0);
            }
            $rows = array_values($groupedRows);
        }

        $records = [];
        foreach ($rows as $row) {
            $deviceFields = self::scalarFields($row);
            $relationFields = [];
            $labelParts = [];

            if ($relationClass === 'Item_DeviceProcessor') {
                $label = trim((string) ($row['designation'] ?? $row['name'] ?? ''));
            } elseif ($relationClass === 'Item_DeviceMemory') {
                $relationFields['size'] = $row['_relation_size'] ?? null;
                $memoryType = trim((string) ($row['_memory_type_name'] ?? ''));
                if ($memoryType !== '') {
                    $labelParts[] = $memoryType;
                }
                if (!empty($row['frequency'])) {
                    $labelParts[] = (string) $row['frequency'] . ' MHz';
                }
                if (!empty($row['manufacturers_id'])) {
                    $manufacturer = (string) \Dropdown::getDropdownName(\Manufacturer::getTable(), (int) $row['manufacturers_id']);
                    if ($manufacturer !== '') {
                        $labelParts[] = $manufacturer;
                    }
                }
                $size = (float) ($row['_relation_size'] ?? 0);
                if ($size > 0) {
                    $labelParts[] = (string) (int) round($size / 1024) . ' GB';
                }
                $label = implode(' ', $labelParts);
                unset($deviceFields['_relation_size'], $deviceFields['_memory_type_name']);
            } else {
                $relationFields['capacity'] = $row['_relation_capacity'] ?? null;
                $interface = trim((string) ($row['_interface_name'] ?? ''));
                $designation = trim((string) ($row['designation'] ?? $row['name'] ?? ''));
                $labelParts[] = $interface !== '' ? $interface : $designation;
                $capacity = (float) ($row['_relation_capacity'] ?? 0);
                if ($capacity > 0) {
                    $labelParts[] = (string) (int) round($capacity / 1024) . ' GB';
                }
                $label = implode(' ', array_filter($labelParts));
                unset($deviceFields['_relation_capacity'], $deviceFields['_interface_name']);
            }

            $deviceFields['report_label'] = trim((string) $label);
            $records[] = ['relation' => $relationFields, 'device' => $deviceFields];
        }

        return $records;
    }

    private static function componentLabel(string $relationClass, array $device, array $relation): string
    {
        $designation = trim((string) ($device['designation'] ?? $device['name'] ?? ''));
        $manufacturer = '';
        if (!empty($device['manufacturers_id'])) {
            $manufacturer = (string) \Dropdown::getDropdownName(
                \Manufacturer::getTable(),
                (int) $device['manufacturers_id']
            );
        }

        if ($relationClass === 'Item_DeviceProcessor') {
            return $designation;
        }

        if ($relationClass === 'Item_DeviceMemory') {
            $parts = [];
            $typeId = (int) ($device['devicememorytypes_id'] ?? 0);
            if ($typeId > 0 && class_exists('DeviceMemoryType')) {
                $parts[] = (string) \Dropdown::getDropdownName(
                    \DeviceMemoryType::getTable(),
                    $typeId
                );
            }
            if (!empty($device['frequency'])) {
                $parts[] = (string) $device['frequency'] . ' MHz';
            }
            if ($manufacturer !== '') {
                $parts[] = $manufacturer;
            }
            $size = (float) ($relation['size'] ?? $device['size'] ?? 0);
            if ($size > 0) {
                $parts[] = (string) (int) round($size / 1024) . ' GB';
            }
            return trim(implode(' ', array_filter($parts)));
        }

        if ($relationClass === 'Item_DeviceHardDrive') {
            $parts = [$designation];
            $capacity = (float) ($relation['capacity'] ?? 0);
            if ($capacity > 0) {
                $parts[] = (string) (int) round($capacity / 1024) . ' GB';
            }
            $interfaceId = (int) ($device['interfacetypes_id'] ?? 0);
            if ($interfaceId > 0) {
                $parts[] = (string) \Dropdown::getDropdownName(
                    'glpi_interfacetypes',
                    $interfaceId
                );
            }
            return trim(implode(' ', array_filter($parts)));
        }

        return $designation;
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
