<?php
declare(strict_types=1);

/**
 * Database lifecycle for Informe Técnico.
 *
 * Uninstall intentionally preserves report tables and files. These are
 * controlled institutional records and must not be destroyed by accident.
 */
function plugin_informeglpi_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_INFORMEGLOPI_VERSION);
    $charset = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();

    $tables = [
        'glpi_plugin_informeglopi_sequences' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_informeglopi_sequences` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `year` smallint unsigned NOT NULL,
            `last_number` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity_year` (`year`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",

        'glpi_plugin_informeglopi_reports' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_informeglopi_reports` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `report_number` varchar(40) NOT NULL,
            `itemtype` varchar(100) NOT NULL,
            `items_id` int unsigned NOT NULL,
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            `status` varchar(24) NOT NULL DEFAULT 'draft',
            `damage_type` varchar(24) DEFAULT NULL,
            `asset_snapshot` longtext NOT NULL,
            `book_value` decimal(14,2) DEFAULT NULL,
            `book_value_users_id` int unsigned DEFAULT NULL,
            `book_value_date` datetime DEFAULT NULL,
            `background` longtext DEFAULT NULL,
            `diagnosis` longtext DEFAULT NULL,
            `cause` longtext DEFAULT NULL,
            `activities` longtext DEFAULT NULL,
            `recommendations` longtext DEFAULT NULL,
            `observations` longtext DEFAULT NULL,
            `report_date` date DEFAULT NULL,
            `pdf_path` varchar(500) DEFAULT NULL,
            `pdf_sha256` char(64) DEFAULT NULL,
            `finalized_at` datetime DEFAULT NULL,
            `finalized_users_id` int unsigned DEFAULT NULL,
            `users_id` int unsigned NOT NULL,
            `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity_report_number` (`report_number`),
            KEY `idx_report_asset` (`itemtype`, `items_id`),
            KEY `idx_report_entity_status` (`entities_id`, `status`),
            KEY `idx_report_creator` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",

        'glpi_plugin_informeglopi_signatures' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_informeglopi_signatures` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `reports_id` int unsigned NOT NULL,
            `signature_type` varchar(24) NOT NULL,
            `users_id` int unsigned NOT NULL,
            `signature_path` varchar(500) NOT NULL,
            `signature_sha256` char(64) NOT NULL,
            `signed_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity_report_signature` (`reports_id`, `signature_type`),
            KEY `idx_signature_user_date` (`users_id`, `signed_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",

        'glpi_plugin_informeglopi_evidences' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_informeglopi_evidences` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `reports_id` int unsigned NOT NULL,
            `category` varchar(40) NOT NULL DEFAULT 'general',
            `original_name` varchar(255) NOT NULL,
            `mime_type` varchar(100) NOT NULL,
            `file_path` varchar(500) NOT NULL,
            `sha256` char(64) NOT NULL,
            `caption` varchar(500) DEFAULT NULL,
            `users_id` int unsigned NOT NULL,
            `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_evidence_report` (`reports_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",

        'glpi_plugin_informeglopi_history' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_informeglopi_history` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `reports_id` int unsigned NOT NULL,
            `action` varchar(40) NOT NULL,
            `old_status` varchar(24) DEFAULT NULL,
            `new_status` varchar(24) DEFAULT NULL,
            `changes_json` longtext DEFAULT NULL,
            `comment` text DEFAULT NULL,
            `users_id` int unsigned NOT NULL,
            `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_history_report_date` (`reports_id`, `date_creation`),
            KEY `idx_history_user` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
    ];

    foreach ($tables as $table => $sql) {
        if (!$DB->tableExists($table)) {
            $migration->addPostQuery($sql);
        }
    }

    // Add document header fields without altering existing reports.
    $reportTable = 'glpi_plugin_informeglopi_reports';
    $newFields = [
        'recipients_json' => ['longtext', ['value' => null]],
        'prepared_by_users_id' => ['integer', ['value' => 0]],
        'reviewer_users_id' => ['integer', ['value' => 0]],
        'subject' => ['string', ['value' => 'Informe técnico del estado actual de ítems de Propiedad, Planta y Equipo.']],
    ];
    foreach ($newFields as $field => [$type, $options]) {
        if ($DB->tableExists($reportTable) && !$DB->fieldExists($reportTable, $field)) {
            $migration->addField($reportTable, $field, $type, $options);
        }
    }

    $migration->executeMigration();

    // Register native GLPI profile rights idempotently.
    // ProfileRight::addProfileRights() inserts every row unconditionally in GLPI 11,
    // so calling it again during an upgrade causes duplicate-key errors.
    $rights = [
        'plugin_informeglpi',
        'plugin_informeglpi_validate',
    ];

    $profiles = $DB->request([
        'SELECT' => ['id'],
        'FROM'   => Profile::getTable(),
    ]);

    foreach ($profiles as $profile) {
        $profiles_id = (int) $profile['id'];
        $existingRights = ProfileRight::getProfileRights($profiles_id, $rights);

        foreach ($rights as $rightName) {
            if (!array_key_exists($rightName, $existingRights)) {
                $DB->insert(
                    ProfileRight::getTable(),
                    [
                        'profiles_id' => $profiles_id,
                        'name'        => $rightName,
                    ]
                );
            }
        }
    }

    ProfileRight::cleanAllPossibleRights();

    return true;
}

function plugin_informeglpi_upgrade($previousVersion): bool
{
    // Schema migrations are deliberately idempotent.
    return plugin_informeglpi_install();
}

function plugin_informeglpi_uninstall(): bool
{
    // Do not drop report tables or stored documents during plugin uninstall.
    // A separate, explicitly authorized archival/purge procedure will be
    // required if institutional policy ever permits permanent deletion.
    return true;
}
