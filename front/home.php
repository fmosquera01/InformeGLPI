<?php
declare(strict_types=1);

use GlpiPlugin\Informeglopi\Report;

include('../../../inc/includes.php');
Session::checkLoginUser();

Html::header(__('Informe Técnico', 'informeglpi'), $_SERVER['PHP_SELF'], 'plugins');

$pendingCount = 0;
$canReview = Session::haveRight(Report::$rightname, READ)
    && Session::haveRight('plugin_informeglpi_validate', UPDATE);
if ($canReview) {
    global $DB;
    $userId = (int) Session::getLoginUserID();
    foreach ($DB->request([
        'SELECT' => ['id', 'entities_id', 'is_recursive'],
        'FROM'   => 'glpi_plugin_informeglopi_reports',
        'WHERE'  => ['reviewer_users_id' => $userId, 'status' => 'pending_supervisor_signature'],
    ]) as $pending) {
        if (Session::haveAccessToEntity((int) $pending['entities_id'], (bool) $pending['is_recursive'])) {
            $pendingCount++;
        }
    }
}
?>
<style>
.ig-home{max-width:1120px;margin:1.5rem auto}.ig-home-intro{color:var(--tblr-secondary,#667085);margin-bottom:1.5rem}.ig-home-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.25rem}.ig-home-card{display:flex;flex-direction:column;min-height:220px;border:1px solid var(--tblr-border-color,#dce1e7);border-radius:12px;background:var(--tblr-bg-surface,#fff);padding:1.4rem;box-shadow:0 2px 8px rgba(0,0,0,.04);transition:box-shadow .15s,border-color .15s}.ig-home-card-top{display:flex;align-items:center;justify-content:space-between;gap:1rem}.ig-home-icon{width:46px;height:46px;display:flex;align-items:center;justify-content:center;border-radius:10px;background:var(--tblr-bg-surface-secondary,#f1f4f9);color:var(--tblr-primary,#206bc4);font-size:1.4rem}.ig-home-count{min-width:34px;height:34px;padding:0 .55rem;display:inline-flex;align-items:center;justify-content:center;border-radius:18px;background:#f59f00;color:#fff;font-weight:700;font-size:1rem}.ig-home-card h3{font-size:1.1rem;margin:1.1rem 0 .4rem}.ig-home-card p{color:var(--tblr-secondary,#667085);flex:1;margin-bottom:1.2rem}.ig-home-action{display:inline-flex;align-items:center;gap:.4rem;align-self:flex-start;text-decoration:none;font-weight:600}.ig-home-card:hover{border-color:var(--tblr-primary,#206bc4);box-shadow:0 5px 18px rgba(0,0,0,.08)}
</style>
<div class="container-fluid ig-home">
<h2 class="h3 mb-1"><?= htmlspecialchars(__('Informe Técnico','informeglpi'),ENT_QUOTES,'UTF-8') ?></h2>
<p class="ig-home-intro"><?= htmlspecialchars(__('Seleccione la opción que desea utilizar.','informeglpi'),ENT_QUOTES,'UTF-8') ?></p>
<div class="ig-home-grid">
<section class="ig-home-card">
<div class="ig-home-card-top"><span class="ig-home-icon" aria-hidden="true">＋</span></div>
<h3><?= htmlspecialchars(__('Crear informe para celular','informeglpi'),ENT_QUOTES,'UTF-8') ?></h3>
<p><?= htmlspecialchars(__('Registre un informe técnico para un teléfono celular que todavía no se encuentra registrado como activo en GLPI.','informeglpi'),ENT_QUOTES,'UTF-8') ?></p>
<a class="ig-home-action" href="/plugins/informeglpi/front/manual-phone.php"><?= htmlspecialchars(__('Crear informe','informeglpi'),ENT_QUOTES,'UTF-8') ?> <span aria-hidden="true">→</span></a>
</section>
<section class="ig-home-card">
<div class="ig-home-card-top"><span class="ig-home-icon" aria-hidden="true">☷</span><?php if($canReview): ?><span class="ig-home-count" title="<?= htmlspecialchars(__('Informes pendientes de revisión','informeglpi'),ENT_QUOTES,'UTF-8') ?>"><?= $pendingCount ?></span><?php endif; ?></div>
<h3><?= htmlspecialchars(__('Bandeja de entrada','informeglpi'),ENT_QUOTES,'UTF-8') ?></h3>
<p><?= htmlspecialchars(__('Consulte los informes enviados por los técnicos que esperan su revisión y firma.','informeglpi'),ENT_QUOTES,'UTF-8') ?></p>
<?php if($canReview): ?><a class="ig-home-action" href="/plugins/informeglpi/front/report-inbox.php"><?= htmlspecialchars(__('Ver bandeja de entrada','informeglpi'),ENT_QUOTES,'UTF-8') ?> <span aria-hidden="true">→</span></a><?php else: ?><span class="text-muted small"><?= htmlspecialchars(__('Opción disponible para supervisores autorizados.','informeglpi'),ENT_QUOTES,'UTF-8') ?></span><?php endif; ?>
</section>
</div></div>
<?php Html::footer(); ?>
