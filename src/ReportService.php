<?php
declare(strict_types=1);

namespace GlpiPlugin\Informeglopi;

use CommonDBTM;
use RuntimeException;
use Session;
use Throwable;

final class ReportService
{
    public static function createDraft(string $itemtype, int $itemsId, array $input = []): int
    {
        global $DB;

        if (!Session::haveRight(Report::$rightname, CREATE)) {
            throw new RuntimeException(__('No tiene permisos para crear informes técnicos.', 'informeglpi'));
        }
        $isManualPhone = $itemtype === 'ManualCellphone' && $itemsId === 0;
        if (!$isManualPhone && (!AssetTypeRegistry::isSupported($itemtype) || !class_exists($itemtype))) {
            throw new RuntimeException(__('Tipo de activo no permitido.', 'informeglpi'));
        }

        $item = null;
        if ($isManualPhone) {
            $manual = is_array($input['manual_asset'] ?? null) ? $input['manual_asset'] : [];
            $imei = trim((string) ($manual['serial'] ?? ''));
            if ($imei !== '' && !preg_match('/^[0-9]{14,16}$/', $imei)) {
                throw new RuntimeException(__('La serie debe corresponder al IMEI numérico del celular (14 a 16 dígitos).', 'informeglpi'));
            }
            if ($imei !== '') {
                self::assertManualCellphoneNotRegistered($imei, trim((string) ($manual['asset_code'] ?? '')));
            }
            $entityId = (int) Session::getActiveEntity();
            $isRecursive = 0;
            $snapshot = self::buildManualCellphoneSnapshot($manual);
        } else {
            /** @var CommonDBTM $item */
            $item = new $itemtype();
            if (!$item->getFromDB($itemsId)) {
                throw new RuntimeException(__('No se encontró el activo seleccionado.', 'informeglpi'));
            }
            if (!$item->can($itemsId, READ)) {
                throw new RuntimeException(__('No tiene permisos para consultar este activo.', 'informeglpi'));
            }
            $entityId = (int) ($item->fields['entities_id'] ?? Session::getActiveEntity());
            $isRecursive = (int) ($item->fields['is_recursive'] ?? 0);
            $snapshot = AssetSnapshotBuilder::build($item);
        }

        $userId = (int) Session::getLoginUserID();
        if ($userId < 1) {
            throw new RuntimeException(__('No se pudo identificar al usuario de GLPI.', 'informeglpi'));
        }

        $recipients = array_values(array_unique(array_filter(array_map('intval', (array) ($input['recipients_ids'] ?? [])), static fn($id) => $id > 0)));
        foreach ($recipients as $recipientId) {
            self::assertActiveUser($recipientId);
        }
        $preparedBy = $userId;
        $reviewer = (int) ($input['reviewer_users_id'] ?? 0);
        self::assertActiveUser($preparedBy);
        if ($reviewer > 0) {
            self::assertActiveUser($reviewer);
        }
        if ($reviewer > 0 && $preparedBy === $reviewer) {
            throw new RuntimeException(__('El técnico y el responsable que revisa deben ser usuarios distintos.', 'informeglpi'));
        }
        $subject = trim((string) ($input['subject'] ?? ''));

        $year = (int) date('Y');
        $bookValueRaw = trim((string) ($input['book_value'] ?? ''));
        if ($bookValueRaw !== '' && (!is_numeric($bookValueRaw) || (float) $bookValueRaw < 0)) {
            throw new RuntimeException(__('El valor en libros debe ser un número mayor o igual a cero.', 'informeglpi'));
        }
        $bookValue = $bookValueRaw === '' ? null : number_format((float) $bookValueRaw, 2, '.', '');

        $snapshotJson = json_encode(
            $snapshot,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $DB->beginTransaction();
        try {
            $sequenceTable = 'glpi_plugin_informeglopi_sequences';
            $sequenceExists = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => $sequenceTable,
                'WHERE'  => ['year' => $year],
                'LIMIT'  => 1,
            ])->count() > 0;

            if (!$sequenceExists) {
                try {
                    $DB->insert($sequenceTable, [
                        'year'        => $year,
                        'last_number' => 0,
                    ]);
                } catch (Throwable $insertError) {
                    // A concurrent request may have created this year's sequence first.
                    $sequenceExists = $DB->request([
                        'SELECT' => ['id'],
                        'FROM'   => $sequenceTable,
                        'WHERE'  => ['year' => $year],
                        'LIMIT'  => 1,
                    ])->count() > 0;

                    if (!$sequenceExists) {
                        throw $insertError;
                    }
                }
            }

            // Use GLPI's query builder expression for an atomic increment.
            $DB->update(
                $sequenceTable,
                ['last_number' => new \QueryExpression('last_number + 1')],
                ['year' => $year]
            );

            $sequence = 0;
            foreach ($DB->request([
                'SELECT' => ['last_number'],
                'FROM' => 'glpi_plugin_informeglopi_sequences',
                'WHERE' => ['year' => $year],
                'LIMIT' => 1,
            ]) as $row) {
                $sequence = (int) ($row['last_number'] ?? 0);
                break;
            }
            if ($sequence < 1) {
                throw new RuntimeException(__('No se pudo reservar el número correlativo del informe.', 'informeglpi'));
            }

            $reportNumber = 'ITSIS' . $year . '-' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
            $DB->insert('glpi_plugin_informeglopi_reports', [
                'report_number' => $reportNumber,
                'itemtype' => $itemtype,
                'items_id' => $itemsId,
                'entities_id' => $entityId,
                'is_recursive' => $isRecursive,
                'status' => 'draft',
                'asset_snapshot' => $snapshotJson,
                'report_date' => date('Y-m-d'),
                'recipients_json' => json_encode($recipients, JSON_THROW_ON_ERROR),
                'prepared_by_users_id' => $preparedBy,
                'reviewer_users_id' => $reviewer,
                'subject' => $subject,
                'book_value' => $bookValue,
                'book_value_users_id' => $bookValue !== null ? $userId : null,
                'book_value_date' => $bookValue !== null ? date('Y-m-d H:i:s') : null,
                'users_id' => $userId,
            ]);

            $reportId = 0;
            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM' => 'glpi_plugin_informeglopi_reports',
                'WHERE' => ['report_number' => $reportNumber],
                'LIMIT' => 1,
            ]) as $row) {
                $reportId = (int) ($row['id'] ?? 0);
                break;
            }
            if ($reportId < 1) {
                throw new RuntimeException(__('No se pudo guardar el informe técnico.', 'informeglpi'));
            }

            $DB->insert('glpi_plugin_informeglopi_history', [
                'reports_id' => $reportId,
                'action' => 'created',
                'old_status' => null,
                'new_status' => 'draft',
                'changes_json' => json_encode([
                    'report_number' => $reportNumber,
                    'itemtype' => $itemtype,
                    'items_id' => $itemsId,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'comment' => __('Creación del borrador.', 'informeglpi'),
                'users_id' => $userId,
            ]);

            $DB->commit();
            return $reportId;
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }

    private static function assertManualCellphoneNotRegistered(string $imei, string $assetCode): void
    {
        global $DB;
        if (!$DB->tableExists('glpi_phones')) {
            return;
        }
        $byImei = $DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_phones',
            'WHERE' => ['serial' => $imei],
            'LIMIT' => 1,
        ])->count() > 0;
        $byAssetCode = $DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_phones',
            'WHERE' => ['otherserial' => $assetCode],
            'LIMIT' => 1,
        ])->count() > 0;
        if ($byImei || $byAssetCode) {
            throw new RuntimeException(__('El IMEI o código de activo ya está registrado en GLPI. Debe crear el informe desde la ficha del celular.', 'informeglpi'));
        }
    }

    private static function buildManualCellphoneSnapshot(array $manual): array
    {
        $value = static fn(string $key): string => trim((string) ($manual[$key] ?? ''));
        if (mb_strlen($value('description')) > 255) {
            throw new RuntimeException(__('La descripción del activo no puede superar los 255 caracteres.', 'informeglpi'));
        }
        $purchaseDate = $value('purchase_date');
        if ($purchaseDate !== '') {
            $parsed = \DateTime::createFromFormat('Y-m-d', $purchaseDate);
            if (!$parsed || $parsed->format('Y-m-d') !== $purchaseDate) {
                throw new RuntimeException(__('La fecha de compra no es válida.', 'informeglpi'));
            }
            $purchaseDate = $parsed->format('d/m/Y');
        }
        return [
            'schema_version' => 1,
            'itemtype' => 'CELULAR (NO REGISTRADO EN GLPI)',
            'items_id' => 0,
            'fields' => [
                'CÓDIGO DE ACTIVO' => $value('asset_code'),
                'TIPO' => 'CELULAR',
                'DESCRIPCIÓN DEL ACTIVO' => $value('description'),
                'FECHA DE COMPRA' => $purchaseDate,
                'MARCA' => $value('manufacturer'),
                'MODELO' => $value('model'),
                'SERIE' => $value('serial'),
                'USUARIO RESPONSABLE' => $value('responsible'),
                'ÁREA DONDE SE ENCONTRABA EL EQUIPO' => $value('area'),
            ],
            'components' => [],
        ];
    }

    /** Return a non-reserving preview of the next annual document number. */
    public static function previewNextReportNumber(?int $year = null): string
    {
        global $DB;

        $year = $year ?? (int) date('Y');
        $lastNumber = 0;
        foreach ($DB->request([
            'SELECT' => ['last_number'],
            'FROM' => 'glpi_plugin_informeglopi_sequences',
            'WHERE' => ['year' => $year],
            'LIMIT' => 1,
        ]) as $row) {
            $lastNumber = (int) ($row['last_number'] ?? 0);
            break;
        }

        return 'ITSIS' . $year . '-' . str_pad((string) ($lastNumber + 1), 3, '0', STR_PAD_LEFT);
    }

    private static function assertActiveUser(int $userId): void
    {
        global $DB;
        if ($userId < 1 || $DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_users',
            'WHERE' => ['id' => $userId, 'is_active' => 1],
            'LIMIT' => 1,
        ])->count() === 0) {
            throw new RuntimeException(__('Seleccione usuarios activos y válidos de GLPI para Para y De.', 'informeglpi'));
        }
    }

    public static function updateDraft(int $reportId, array $input): void
    {
        global $DB;

        if (!Session::haveRight(Report::$rightname, READ)) {
            throw new RuntimeException(__('No tiene permisos para editar informes técnicos.', 'informeglpi'));
        }

        $report = new Report();
        if (!$report->getFromDB($reportId)) {
            throw new RuntimeException(__('No se encontró el informe técnico.', 'informeglpi'));
        }
        if (!Session::haveAccessToEntity(
            (int) ($report->fields['entities_id'] ?? -1),
            (bool) ($report->fields['is_recursive'] ?? false)
        )) {
            throw new RuntimeException(__('No tiene acceso a la entidad de este informe.', 'informeglpi'));
        }

        $userId = (int) Session::getLoginUserID();
        $isAssignedPreparer = $userId === (int) ($report->fields['prepared_by_users_id'] ?? 0);
        if (!$isAssignedPreparer) {
            throw new RuntimeException(__('Solo el técnico asignado puede modificar este informe. El supervisor únicamente puede aprobarlo o devolverlo.', 'informeglpi'));
        }
        $currentStatus = (string) ($report->fields['status'] ?? '');
        if (!in_array($currentStatus, ['draft', 'returned_for_correction'], true)) {
            throw new RuntimeException(__('El informe ya no está disponible para edición.', 'informeglpi'));
        }

        $recipients = array_values(array_unique(array_filter(array_map('intval', (array) ($input['recipients_ids'] ?? [])), static fn($id) => $id > 0)));
        foreach ($recipients as $recipientId) {
            self::assertActiveUser($recipientId);
        }

        $preparedBy = (int) ($report->fields['prepared_by_users_id'] ?? 0);
        $reviewer = (int) ($input['reviewer_users_id'] ?? 0);
        self::assertActiveUser($preparedBy);
        if ($reviewer > 0) {
            self::assertActiveUser($reviewer);
        }
        if ($reviewer > 0 && $preparedBy === $reviewer) {
            throw new RuntimeException(__('El técnico y el responsable que revisa deben ser usuarios distintos.', 'informeglpi'));
        }
        $subject = trim((string) ($input['subject'] ?? ''));

        $damageType = (string) ($input['damage_type'] ?? '');
        if (!in_array($damageType, ['', 'property_damage', 'wear'], true)) {
            throw new RuntimeException(__('El tipo de daño seleccionado no es válido.', 'informeglpi'));
        }

        $bookValueRaw = trim((string) ($input['book_value'] ?? ''));
        if ($bookValueRaw !== '' && (!is_numeric($bookValueRaw) || (float) $bookValueRaw < 0)) {
            throw new RuntimeException(__('El valor en libros debe ser un número mayor o igual a cero.', 'informeglpi'));
        }
        $bookValue = $bookValueRaw === '' ? null : number_format((float) $bookValueRaw, 2, '.', '');

        $textFields = ['background', 'diagnosis', 'cause', 'activities', 'recommendations', 'observations'];
        $changes = [];
        $oldBookValue = (string) ($report->fields['book_value'] ?? '');
        $bookValueChanged = (string) ($bookValue ?? '') !== $oldBookValue;
        $updates = [
            'damage_type' => $damageType !== '' ? $damageType : null,
            'book_value' => $bookValue,
            'recipients_json' => json_encode($recipients, JSON_THROW_ON_ERROR),
            'prepared_by_users_id' => $preparedBy,
            'reviewer_users_id' => $reviewer,
            'subject' => $subject,
        ];
        $changes['document_header'] = [
            'recipients_ids' => $recipients,
            'prepared_by_users_id' => $preparedBy,
            'reviewer_users_id' => $reviewer,
            'subject' => $subject,
        ];

        if (($report->fields['itemtype'] ?? '') === 'ManualCellphone' && (int) ($report->fields['items_id'] ?? -1) === 0) {
            $manual = is_array($input['manual_asset'] ?? null) ? $input['manual_asset'] : [];
            $imei = trim((string) ($manual['serial'] ?? ''));
            if ($imei !== '' && !preg_match('/^[0-9]{14,16}$/', $imei)) {
                throw new RuntimeException(__('La serie debe corresponder al IMEI numérico del celular (14 a 16 dígitos).', 'informeglpi'));
            }
            if ($imei !== '') {
                self::assertManualCellphoneNotRegistered($imei, trim((string) ($manual['asset_code'] ?? '')));
            }
            $oldSnapshot = json_decode((string) ($report->fields['asset_snapshot'] ?? '{}'), true);
            $newSnapshot = self::buildManualCellphoneSnapshot($manual);
            $updates['asset_snapshot'] = json_encode($newSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $changes['manual_asset'] = [
                'before' => is_array($oldSnapshot['fields'] ?? null) ? $oldSnapshot['fields'] : [],
                'after' => $newSnapshot['fields'],
            ];
        }
        if ($bookValueChanged) {
            $updates['book_value_users_id'] = $bookValue !== null ? $userId : null;
            $updates['book_value_date'] = $bookValue !== null ? date('Y-m-d H:i:s') : null;
        }

        foreach ($textFields as $field) {
            $value = trim((string) ($input[$field] ?? ''));
            $oldValue = (string) ($report->fields[$field] ?? '');
            $updates[$field] = $value !== '' ? $value : null;
            if ($value !== $oldValue) {
                $changes[$field] = [
                    'before' => $oldValue,
                    'after' => $value,
                ];
            }
        }

        $oldDamage = (string) ($report->fields['damage_type'] ?? '');
        if ($damageType !== $oldDamage) {
            $changes['damage_type'] = ['before' => $oldDamage, 'after' => $damageType];
        }
        if ($bookValueChanged) {
            $changes['book_value'] = ['before' => $oldBookValue, 'after' => (string) ($bookValue ?? '')];
        }

        $DB->beginTransaction();
        try {
            $DB->update(
                'glpi_plugin_informeglopi_reports',
                $updates,
                [
                    'id' => $reportId,
                    'status' => $currentStatus,
                ]
            );
            if ($currentStatus === 'returned_for_correction') {
                $DB->update('glpi_plugin_informeglopi_reports', ['status' => 'draft'], ['id' => $reportId, 'status' => 'returned_for_correction']);
            }

            if ($changes !== [] || $currentStatus === 'returned_for_correction') {
                $DB->insert('glpi_plugin_informeglopi_history', [
                    'reports_id' => $reportId,
                    'action' => 'updated',
                    'old_status' => $currentStatus,
                    'new_status' => 'draft',
                    'changes_json' => json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'comment' => $currentStatus === 'returned_for_correction' ? __('Correcciones guardadas; el informe vuelve a preparación.', 'informeglpi') : __('Actualización de campos del borrador.', 'informeglpi'),
                    'users_id' => $userId,
                ]);
            }
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }


    /**
     * Re-read the live GLPI asset and return every mandatory item still missing.
     * The report never writes to the asset record.
     */
    public static function getMissingFields(int $reportId): array
    {
        global $DB;
        $report = new Report();
        if (!$report->getFromDB($reportId)) {
            throw new RuntimeException(__('No se encontró el informe técnico.', 'informeglpi'));
        }
        $missing = [];
        $requiredReport = [
            'Para (destinatarios)' => !empty(json_decode((string) ($report->fields['recipients_json'] ?? '[]'), true)),
            'Responsable que revisa' => (int) ($report->fields['reviewer_users_id'] ?? 0) > 0,
            'Asunto' => trim((string) ($report->fields['subject'] ?? '')) !== '',
            'Antecedentes' => trim((string) ($report->fields['background'] ?? '')) !== '',
            'Diagnóstico' => trim((string) ($report->fields['diagnosis'] ?? '')) !== '',
            'Causa' => trim((string) ($report->fields['cause'] ?? '')) !== '',
            'Recomendaciones' => trim((string) ($report->fields['recommendations'] ?? '')) !== '',
            'Tipo de daño' => in_array((string) ($report->fields['damage_type'] ?? ''), ['wear', 'property_damage'], true),
            'Valor en libros' => trim((string) ($report->fields['book_value'] ?? '')) !== '',
        ];
        foreach ($requiredReport as $label => $ok) {
            if (!$ok) $missing[] = $label;
        }

        $itemtype = (string) ($report->fields['itemtype'] ?? '');
        $itemsId = (int) ($report->fields['items_id'] ?? 0);
        if ($itemtype === 'ManualCellphone' && $itemsId === 0) {
            $snapshot = json_decode((string) ($report->fields['asset_snapshot'] ?? '{}'), true);
            $f = is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [];
            foreach ([
                'Código de activo' => 'CÓDIGO DE ACTIVO',
                'Descripción del activo' => 'DESCRIPCIÓN DEL ACTIVO',
                'Fecha de compra' => 'FECHA DE COMPRA',
                'Marca' => 'MARCA',
                'Modelo' => 'MODELO',
                'IMEI / serie' => 'SERIE',
                'Usuario responsable' => 'USUARIO RESPONSABLE',
                'Área / ubicación' => 'ÁREA DONDE SE ENCONTRABA EL EQUIPO',
            ] as $label => $key) {
                if (trim((string) ($f[$key] ?? '')) === '') $missing[] = 'Activo: ' . $label;
            }
            $imei = trim((string) ($f['SERIE'] ?? ''));
            if ($imei !== '' && !preg_match('/^[0-9]{14,16}$/', $imei)) $missing[] = 'Activo: IMEI válido (14 a 16 dígitos)';
        } else {
            if (!class_exists($itemtype)) {
                $missing[] = 'Tipo de activo';
            } else {
                $item = new $itemtype();
                if (!$item->getFromDB($itemsId)) {
                    $missing[] = 'Activo registrado en GLPI';
                } else {
                    $f = $item->fields;
                    $short = (new \ReflectionClass($itemtype))->getShortName();
                    $typeField = strtolower($short) . 'types_id';
                    $modelField = strtolower($short) . 'models_id';
                    $checks = [
                        'Código de activo' => trim((string) ($f['otherserial'] ?? '')) !== '',
                        'Tipo de activo' => (int) ($f[$typeField] ?? 0) > 0 || str_contains($itemtype, 'Asset'),
                        'Marca / fabricante' => (int) ($f['manufacturers_id'] ?? 0) > 0,
                        'Modelo' => (int) ($f[$modelField] ?? 0) > 0,
                        'Serie' => trim((string) ($f['serial'] ?? '')) !== '',
                        'Usuario responsable' => (int) ($f['users_id'] ?? 0) > 0,
                        'Área / ubicación' => (int) ($f['locations_id'] ?? 0) > 0,
                    ];
                    foreach ($checks as $label => $ok) if (!$ok) $missing[] = 'Activo: ' . $label;
                    $buyDate = '';
                    if ($DB->tableExists('glpi_infocoms')) {
                        foreach ($DB->request(['SELECT'=>['buy_date'],'FROM'=>'glpi_infocoms','WHERE'=>['itemtype'=>$itemtype,'items_id'=>$itemsId],'LIMIT'=>1]) as $info) {
                            $buyDate = trim((string) ($info['buy_date'] ?? ''));
                            break;
                        }
                    }
                    if ($buyDate === '' || in_array($buyDate, ['0000-00-00','0000-00-00 00:00:00'], true)) $missing[] = 'Activo: Fecha de compra';
                }
            }
        }
        return $missing;
    }

    /**
     * Store evidence photographs for a report. Files are kept below GLPI_VAR_DIR,
     * outside the public web root, and are served only through evidence.php.
     */
    public static function addEvidence(int $reportId, array $files): int
    {
        global $DB;

        $report = new Report();
        if (!$report->getFromDB($reportId)) {
            throw new RuntimeException(__('No se encontró el informe para adjuntar fotografías.', 'informeglpi'));
        }
        if (!in_array((string) ($report->fields['status'] ?? ''), ['draft', 'returned_for_correction'], true)) {
            throw new RuntimeException(__('Solo se pueden adjuntar fotografías mientras el informe está en preparación.', 'informeglpi'));
        }
        $userId = (int) Session::getLoginUserID();
        if ($userId !== (int) ($report->fields['prepared_by_users_id'] ?? 0)) {
            throw new RuntimeException(__('Solo el técnico asignado puede adjuntar fotografías a este informe.', 'informeglpi'));
        }

        if (!isset($files['name']) || !is_array($files['name'])) {
            return 0;
        }
        $count = count(array_filter($files['error'] ?? [], static fn($error) => (int) $error !== UPLOAD_ERR_NO_FILE));
        if ($count > 8) {
            throw new RuntimeException(__('Puede adjuntar un máximo de 8 fotografías por envío.', 'informeglpi'));
        }

        $baseDir = rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '_plugins' . DIRECTORY_SEPARATOR . 'informeglpi' . DIRECTORY_SEPARATOR . 'evidences';
        if (!is_dir($baseDir) && !mkdir($baseDir, 0750, true) && !is_dir($baseDir)) {
            throw new RuntimeException(__('No se pudo crear el directorio privado de fotografías.', 'informeglpi'));
        }

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $saved = 0;

        foreach ($files['name'] as $index => $originalName) {
            $error = (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                throw new RuntimeException(__('Una fotografía no se pudo cargar correctamente (código ' . $error . ').', 'informeglpi'));
            }

            $tmpName = (string) ($files['tmp_name'][$index] ?? '');
            $size = (int) ($files['size'][$index] ?? 0);
            if ($size < 1 || $size > 8 * 1024 * 1024 || !is_uploaded_file($tmpName)) {
                throw new RuntimeException(__('Cada fotografía debe pesar como máximo 8 MB.', 'informeglpi'));
            }

            $mime = (string) $finfo->file($tmpName);
            if (!isset($allowed[$mime]) || @getimagesize($tmpName) === false) {
                throw new RuntimeException(__('Formato de fotografía no permitido. Use JPG, PNG o WEBP.', 'informeglpi'));
            }

            $fileName = $reportId . '_' . bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
            $destination = $baseDir . DIRECTORY_SEPARATOR . $fileName;
            if (!move_uploaded_file($tmpName, $destination)) {
                throw new RuntimeException(__('No se pudo guardar una de las fotografías.', 'informeglpi'));
            }
            chmod($destination, 0640);
            $relativePath = '_plugins/informeglpi/evidences/' . $fileName;

            try {
                $DB->insert('glpi_plugin_informeglopi_evidences', [
                    'reports_id' => $reportId,
                    'category' => 'general',
                    'original_name' => mb_substr(basename((string) $originalName), 0, 255),
                    'mime_type' => $mime,
                    'file_path' => $relativePath,
                    'sha256' => hash_file('sha256', $destination),
                    'caption' => null,
                    'users_id' => $userId,
                ]);
            } catch (Throwable $e) {
                @unlink($destination);
                throw $e;
            }
            $saved++;
        }

        if ($saved > 0) {
            $DB->insert('glpi_plugin_informeglopi_history', [
                'reports_id' => $reportId,
                'action' => 'evidence_added',
                'old_status' => 'draft',
                'new_status' => 'draft',
                'changes_json' => json_encode(['photos_added' => $saved], JSON_THROW_ON_ERROR),
                'comment' => __('Se adjuntaron fotografías al informe.', 'informeglpi'),
                'users_id' => $userId,
            ]);
        }
        return $saved;
    }

}
