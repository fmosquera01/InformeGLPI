<?php
declare(strict_types=1);
/**
 * Informe Técnico - Songa.
 * Internal plugin key and installation directory: informeglopi.
 */
define('PLUGIN_INFORMEGLOPI_VERSION', '0.5.103');
require_once __DIR__ . '/src/Report.php';
require_once __DIR__ . '/src/AssetTypeRegistry.php';
require_once __DIR__ . '/src/Profile.php';
require_once __DIR__ . '/src/ReportTab.php';
require_once __DIR__ . '/src/AssetSnapshotBuilder.php';
require_once __DIR__ . '/src/ReportService.php';
require_once __DIR__ . '/src/ReportWorkflow.php';
require_once __DIR__ . '/src/TelegramNotification.php';
require_once __DIR__ . '/src/ReportPdf.php';
require_once __DIR__ . '/src/ManualPhoneMenu.php';
require_once __DIR__ . '/src/UserSignatureTab.php';
function plugin_init_informeglpi(): void
{
    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS['csrf_compliant']['informeglpi'] = true;
    $PLUGIN_HOOKS['menu_entry']['informeglpi'] = true;
    // Direct entry in GLPI's simplified Helpdesk menu.
    $PLUGIN_HOOKS['helpdesk_menu_entry']['informeglpi'] = '/plugins/informeglpi/front/my-signature.php';
    $PLUGIN_HOOKS['helpdesk_menu_entry_icon']['informeglpi'] = 'fas fa-signature';
    // Load the lightweight prompt assets consistently. The JavaScript itself
    // restricts execution to Helpdesk routes and the endpoint verifies the
    // authenticated user's interface and signature status.
    $PLUGIN_HOOKS['add_javascript']['informeglpi'][] = 'js/signature-prompt.js';
    $PLUGIN_HOOKS['add_css']['informeglpi'][] = 'css/signature-prompt.css';
    $PLUGIN_HOOKS['menu_toadd']['informeglpi'] = ['plugins' => [\GlpiPlugin\Informeglopi\ManualPhoneMenu::class]];
    Plugin::registerClass(\GlpiPlugin\Informeglopi\ManualPhoneMenu::class);
    Plugin::registerClass(\GlpiPlugin\Informeglopi\Report::class);
    Plugin::registerClass(\GlpiPlugin\Informeglopi\Profile::class, [
        'addtabon' => ['Profile'],
    ]);
    Plugin::registerClass(\GlpiPlugin\Informeglopi\UserSignatureTab::class, [
        'addtabon' => ['User'],
    ]);
    Plugin::registerClass(\GlpiPlugin\Informeglopi\ReportTab::class, [
        'addtabon' => \GlpiPlugin\Informeglopi\AssetTypeRegistry::getItemtypes(),
    ]);
}
function plugin_version_informeglpi(): array
{
    return [
        'name'         => 'Informe Técnico',
        'version'      => PLUGIN_INFORMEGLOPI_VERSION,
        'author'       => 'Songa - Infraestructura IT',
        'license'      => 'Proprietary',
        'homepage'     => 'https://github.com/fmosquera01/InformeGlopi',
        'requirements' => [
            'glpi' => [
                'min' => '11.0.0',
                'max' => '11.99.99',
            ],
            'php' => [
                'min' => '8.2.0',
            ],
        ],
    ];
}
function plugin_informeglpi_check_prerequisites(): bool
{
    if (!defined('GLPI_VERSION')) {
        return false;
    }
    return version_compare(GLPI_VERSION, '11.0.0', '>=')
        && version_compare(GLPI_VERSION, '12.0.0', '<');
}
function plugin_informeglpi_check_config($verbose = false): bool
{
    return true;
}
