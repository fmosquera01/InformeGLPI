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
            `status` varchar(40) NOT NULL DEFAULT 'draft',
            `damage_type` varchar(24) DEFAULT NULL,
            `asset_snapshot` longtext NOT NULL,
            `book_value` decimal(14,2) DEFAULT NULL,
            `book_value_users_id` int unsigned DEFAULT NULL,
            `book_value_date` timestamp DEFAULT NULL,
            `background` longtext DEFAULT NULL,
            `diagnosis` longtext DEFAULT NULL,
            `cause` longtext DEFAULT NULL,
            `activities` longtext DEFAULT NULL,
            `recommendations` longtext DEFAULT NULL,
            `observations` longtext DEFAULT NULL,
            `report_date` date DEFAULT NULL,
            `recipients_json` longtext DEFAULT NULL,
            `prepared_by_users_id` int unsigned NOT NULL DEFAULT 0,
            `reviewer_users_id` int unsigned NOT NULL DEFAULT 0,
            `subject` varchar(255) DEFAULT NULL,
            `pdf_path` varchar(500) DEFAULT NULL,
            `pdf_sha256` char(64) DEFAULT NULL,
            `finalized_at` timestamp DEFAULT NULL,
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

        'glpi_plugin_informeglopi_user_signatures' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_informeglopi_user_signatures` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `users_id` int unsigned NOT NULL,
            `signature_path` varchar(500) NOT NULL,
            `signature_sha256` char(64) NOT NULL,
            `pin_hash` varchar(255) NOT NULL,
            `failed_attempts` tinyint unsigned NOT NULL DEFAULT 0,
            `locked_until` timestamp DEFAULT NULL,
            `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity_user_signature` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",

        'glpi_plugin_informeglopi_signatures' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_informeglopi_signatures` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `reports_id` int unsigned NOT NULL,
            `signature_type` varchar(24) NOT NULL,
            `users_id` int unsigned NOT NULL,
            `signature_path` varchar(500) NOT NULL,
            `signature_sha256` char(64) NOT NULL,
            `signed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
            `old_status` varchar(40) DEFAULT NULL,
            `new_status` varchar(40) DEFAULT NULL,
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

    // Workflow states such as pending_supervisor_signature require more than 24 characters.
    if ($DB->tableExists($reportTable) && $DB->fieldExists($reportTable, 'status')) {
        $migration->changeField($reportTable, 'status', 'status', "varchar(40) NOT NULL DEFAULT 'draft'");
    }

    // History stores the same workflow state names as the report record.
    $historyTable = 'glpi_plugin_informeglopi_history';
    if ($DB->tableExists($historyTable)) {
        if ($DB->fieldExists($historyTable, 'old_status')) {
            $migration->changeField($historyTable, 'old_status', 'old_status', 'varchar(40) DEFAULT NULL');
        }
        if ($DB->fieldExists($historyTable, 'new_status')) {
            $migration->changeField($historyTable, 'new_status', 'new_status', 'varchar(40) DEFAULT NULL');
        }
    }

    // GLPI 11 discourages DATETIME columns. Migrate existing installations to TIMESTAMP too.
    $timestampFields = [
        'glpi_plugin_informeglopi_reports' => [
            'book_value_date' => 'timestamp DEFAULT NULL',
            'finalized_at' => 'timestamp DEFAULT NULL',
        ],
        'glpi_plugin_informeglopi_user_signatures' => [
            'locked_until' => 'timestamp DEFAULT NULL',
            'date_creation' => 'timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'date_mod' => 'timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ],
        'glpi_plugin_informeglopi_signatures' => [
            'signed_at' => 'timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ],
    ];
    foreach ($timestampFields as $table => $fields) {
        if (!$DB->tableExists($table)) {
            continue;
        }
        foreach ($fields as $field => $definition) {
            if ($DB->fieldExists($table, $field)) {
                $migration->changeField($table, $field, $field, $definition);
            }
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

    // Migrate legacy per-user Chat IDs into FML Telegram destinations when available.
    // The legacy table is retained as a non-active archive; no Chat IDs are discarded.
    if ($DB->tableExists('glpi_plugin_informeglopi_user_telegram')
        && $DB->tableExists('glpi_plugin_telegram_destinations')
        && $DB->fieldExists('glpi_plugin_telegram_destinations', 'users_id')) {
        foreach ($DB->request([
            'SELECT' => ['id', 'users_id', 'chat_id'],
            'FROM' => 'glpi_plugin_informeglopi_user_telegram',
        ]) as $legacyDestination) {
            $userId = (int) ($legacyDestination['users_id'] ?? 0);
            $chatId = trim((string) ($legacyDestination['chat_id'] ?? ''));
            if ($userId < 1 || !preg_match('/^-?[0-9]+$/', $chatId)) {
                continue;
            }

            $alreadyMigrated = false;
            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM' => 'glpi_plugin_telegram_destinations',
                'WHERE' => ['users_id' => $userId, 'chat_id' => $chatId],
                'LIMIT' => 1,
            ]) as $existingDestination) {
                $alreadyMigrated = true;
                break;
            }
            if ($alreadyMigrated) {
                continue;
            }

            $user = new User();
            $displayName = 'GLPI user #' . $userId;
            if ($user->getFromDB($userId)) {
                $displayName = trim((string) ($user->fields['firstname'] ?? '') . ' ' . (string) ($user->fields['realname'] ?? ''));
                if ($displayName === '') {
                    $displayName = (string) ($user->fields['name'] ?? $displayName);
                }
            }

            $legacyId = (int) ($legacyDestination['id'] ?? 0);
            $alias = 'informeglpi_user_' . $userId . '_' . $legacyId;
            $DB->insert('glpi_plugin_telegram_destinations', [
                'name' => mb_substr($displayName . ' - Informe Técnico', 0, 120, 'UTF-8'),
                'alias' => mb_substr($alias, 0, 120, 'UTF-8'),
                'chat_id' => $chatId,
                'users_id' => $userId,
                'chat_type' => 'private',
                'is_active' => 1,
                'date_creation' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // Run once per minute in external mode; GLPI's system cron drives exact daily schedules.
    CronTask::register(
        \GlpiPlugin\Informeglopi\TelegramNotification::class,
        'reminders',
        MINUTE_TIMESTAMP,
        [
            'comment' => 'Envía recordatorios Telegram de informes pendientes de firma administrativa.',
            'mode'    => CronTask::MODE_EXTERNAL,
        ]
    );

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


/**
 * Render the user's personal-signature entry point on GLPI's central home page.
 * Uses the documented display_central hook; no GLPI core files are modified.
 */
function plugin_informeglpi_display_central(): void
{
    global $DB, $CFG_GLPI;

    $userId = (int) Session::getLoginUserID();
    if ($userId < 1 || Session::getCurrentInterface() !== 'central') {
        return;
    }

    $table = 'glpi_plugin_informeglopi_user_signatures';
    $configured = false;
    if ($DB->tableExists($table)) {
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM' => $table,
            'WHERE' => ['users_id' => $userId],
            'LIMIT' => 1,
        ]) as $signature) {
            $configured = true;
            break;
        }
    }

    $rootDoc = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/');
    $signatureUrl = $rootDoc . '/plugins/informeglpi/front/my-signature.php';
    $url = htmlspecialchars($signatureUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $cookieName = 'ig_signature_prompt_dismissed_' . $userId;
    $dismissed = isset($_COOKIE[$cookieName]) && $_COOKIE[$cookieName] === '1';

    echo "<section class='container-fluid mb-3' id='ig-personal-signature-entry'>";
    echo "<div class='card border-primary-subtle'><div class='card-body d-flex flex-wrap align-items-center justify-content-between gap-3'>";
    echo "<div><h3 class='h5 mb-1'>Mi firma personal</h3>";
    if ($configured) {
        echo "<p class='mb-0 text-muted'>Consulte o actualice su firma manuscrita y su PIN personal.</p>";
    } else {
        echo "<p class='mb-0 text-muted'>Registre su firma personal para autorizar documentos institucionales.</p>";
    }
    echo "</div><a class='btn btn-primary' href='{$url}'>";
    echo $configured ? 'Ver o cambiar mi firma' : 'Crear mi firma personal';
    echo "</a></div></div></section>";

    if ($configured || $dismissed) {
        return;
    }

    echo "<div class='modal fade' id='ig-personal-signature-modal' tabindex='-1' aria-labelledby='ig-personal-signature-title' aria-hidden='true'>";
    echo "<div class='modal-dialog modal-dialog-centered'><div class='modal-content'>";
    echo "<div class='modal-header'><h2 class='modal-title fs-5' id='ig-personal-signature-title'>Configure su firma personal</h2></div>";
    echo "<div class='modal-body'><p>Para agilizar la autorización de informes técnicos y actas de entrega, registre su firma manuscrita y cree un PIN personal.</p>";
    echo "<p class='mb-0 text-muted'>La firma y el PIN son personales. No los comparta con otras personas.</p></div>";
    echo "<div class='modal-footer'><button type='button' class='btn btn-outline-secondary' data-bs-dismiss='modal' id='ig-signature-remind-later'>Recordármelo después</button>";
    echo "<a class='btn btn-primary' href='{$url}'>Crear mi firma personal</a></div>";
    echo "</div></div></div>";
    echo "<script>
    (() => {
        const modalElement = document.getElementById('ig-personal-signature-modal');
        if (!modalElement) return;
        const cookie = " . json_encode($cookieName) . ";
        const later = document.getElementById('ig-signature-remind-later');
        later?.addEventListener('click', () => {
            document.cookie = cookie + '=1; Max-Age=28800; Path=/; SameSite=Lax';
        });
        if (window.bootstrap?.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
        }
    })();
    </script>";
}
