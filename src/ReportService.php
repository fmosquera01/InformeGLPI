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
        if (!AssetTypeRegistry::isSupported($itemtype) || !class_exists($itemtype)) {
            throw new RuntimeException(__('Tipo de activo no permitido.', 'informeglpi'));
        }

        /** @var CommonDBTM $item */
        $item = new $itemtype();
        if (!$item->getFromDB($itemsId)) {
            throw new RuntimeException(__('No se encontró el activo seleccionado.', 'informeglpi'));
        }
        if (!$item->can($itemsId, READ)) {
            throw new RuntimeException(__('No tiene permisos para consultar este activo.', 'informeglpi'));
        }

        $userId = (int) Session::getLoginUserID();
        if ($userId < 1) {
            throw new RuntimeException(__('No se pudo identificar al usuario de GLPI.', 'informeglpi'));
        }

        $recipients = array_values(array_unique(array_filter(array_map('intval', (array) ($input['recipients_ids'] ?? [])), static fn($id) => $id > 0)));
        if ($recipients === []) {
            throw new RuntimeException(__('Debe seleccionar al menos un destinatario en el campo Para.', 'informeglpi'));
        }
        foreach ($recipients as $recipientId) {
            self::assertActiveUser($recipientId);
        }
        $preparedBy = (int) ($input['prepared_by_users_id'] ?? 0);
        $reviewer = (int) ($input['reviewer_users_id'] ?? 0);
        self::assertActiveUser($preparedBy);
        self::assertActiveUser($reviewer);
        if ($preparedBy === $reviewer) {
            throw new RuntimeException(__('El técnico y el responsable que revisa deben ser usuarios distintos.', 'informeglpi'));
        }
        $subject = trim((string) ($input['subject'] ?? ''));
        if ($subject === '') {
            throw new RuntimeException(__('El asunto es obligatorio.', 'informeglpi'));
        }

        $year = (int) date('Y');
        $entityId = (int) ($item->fields['entities_id'] ?? Session::getActiveEntity());
        $isRecursive = (int) ($item->fields['is_recursive'] ?? 0);

        $snapshot = AssetSnapshotBuilder::build($item);
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
        $isCreator = $userId === (int) ($report->fields['users_id'] ?? 0);
        $canManage = Session::haveRight(Report::$rightname, UPDATE);
        if (!$isCreator && !$canManage) {
            throw new RuntimeException(__('Solo el creador o un usuario con permiso de edición puede modificar este borrador.', 'informeglpi'));
        }
        if (($report->fields['status'] ?? '') !== 'draft') {
            throw new RuntimeException(__('El informe ya no está en borrador y no puede modificarse desde este formulario.', 'informeglpi'));
        }

        $recipients = array_values(array_unique(array_filter(array_map('intval', (array) ($input['recipients_ids'] ?? [])), static fn($id) => $id > 0)));
        if ($recipients === []) {
            throw new RuntimeException(__('Debe seleccionar al menos un destinatario en el campo Para.', 'informeglpi'));
        }
        foreach ($recipients as $recipientId) {
            self::assertActiveUser($recipientId);
        }

        $preparedBy = (int) ($input['prepared_by_users_id'] ?? 0);
        $reviewer = (int) ($input['reviewer_users_id'] ?? 0);
        self::assertActiveUser($preparedBy);
        self::assertActiveUser($reviewer);
        if ($preparedBy === $reviewer) {
            throw new RuntimeException(__('El técnico y el responsable que revisa deben ser usuarios distintos.', 'informeglpi'));
        }
        $subject = trim((string) ($input['subject'] ?? ''));
        if ($subject === '') {
            throw new RuntimeException(__('El asunto es obligatorio.', 'informeglpi'));
        }

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
                    'status' => 'draft',
                ]
            );

            if ($changes !== []) {
                $DB->insert('glpi_plugin_informeglopi_history', [
                    'reports_id' => $reportId,
                    'action' => 'updated',
                    'old_status' => 'draft',
                    'new_status' => 'draft',
                    'changes_json' => json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'comment' => __('Actualización de campos del borrador.', 'informeglpi'),
                    'users_id' => $userId,
                ]);
            }
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }

}
