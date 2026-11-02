<?php
declare(strict_types=1);

use GlpiPlugin\Informeglopi\Report;

include('../../../inc/includes.php');
Session::checkLoginUser();

Html::header(__('Informe Técnico', 'informeglpi'), $_SERVER['PHP_SELF'], 'plugins');

$pendingCount = 0;
$myPendingCount = 0;
$canCreate = Session::haveRight(Report::$rightname, CREATE) && Session::haveRight(Report::$rightname, READ);
if ($canCreate) {
    global $DB;
    $myUserId = (int) Session::getLoginUserID();
    foreach ($DB->request(['SELECT'=>['id','entities_id','is_recursive'],'FROM'=>'glpi_plugin_informeglopi_reports','WHERE'=>['prepared_by_users_id'=>$myUserId,'status'=>['draft','returned_for_correction','pending_supervisor_signature']]]) as $mine) {
        if (Session::haveAccessToEntity((int)$mine['entities_id'], (bool)$mine['is_recursive'])) $myPendingCount++;
    }
}
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
.ig-home{max-width:1120px;margin:1.25rem auto}
.ig-home-intro{color:var(--tblr-secondary,#667085);margin-bottom:1.15rem}
.ig-home-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem}
.ig-home-card{display:flex;flex-direction:column;min-height:176px;border:1px solid var(--tblr-border-color,#dce1e7);border-radius:10px;background:var(--tblr-bg-surface,#fff);padding:1rem 1.1rem;box-shadow:0 1px 4px rgba(0,0,0,.035);transition:box-shadow .15s,border-color .15s}
.ig-home-card-top{display:flex;align-items:center;gap:.65rem;min-height:36px}
.ig-home-icon{width:34px;height:34px;flex:0 0 34px;display:inline-flex;align-items:center;justify-content:center;border-radius:9px;background:var(--tblr-bg-surface-secondary,#f1f4f9);color:var(--tblr-primary,#206bc4);font-size:1.15rem;font-weight:700}
.ig-home-card h3{font-size:1rem;font-weight:600;margin:0;line-height:1.25}
.ig-home-count{margin-left:auto;min-width:28px;height:28px;padding:0 .45rem;display:inline-flex;align-items:center;justify-content:center;border-radius:16px;background:#f59f00;color:#fff;font-weight:700;font-size:.9rem}
.ig-home-card p{color:var(--tblr-secondary,#667085);font-size:.9rem;line-height:1.4;flex:1;margin:.75rem 0 1rem}
.ig-home-action{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;align-self:flex-start;text-decoration:none;font-weight:600;border-radius:6px;padding:.42rem .75rem;font-size:.875rem;color:#fff;background:var(--tblr-primary,#206bc4);border:1px solid var(--tblr-primary,#206bc4)}
.ig-home-action:hover,.ig-home-action:focus{color:#fff;background:var(--tblr-primary-dark,#1b5aa6);border-color:var(--tblr-primary-dark,#1b5aa6);text-decoration:none}
.ig-home-card:hover{border-color:var(--tblr-primary,#206bc4);box-shadow:0 4px 14px rgba(0,0,0,.07)}
.ig-home-unavailable{align-self:flex-start;font-size:.82rem;color:var(--tblr-secondary,#667085);padding:.42rem 0}
@media(max-width:576px){.ig-home-grid{grid-template-columns:1fr}.ig-home-card{min-height:0}}
</style>
<div class="container-fluid ig-home">
<h2 class="h3 mb-1"><?= htmlspecialchars(__('Informe Técnico','informeglpi'),ENT_QUOTES,'UTF-8') ?></h2>
<p class="ig-home-intro"><?= htmlspecialchars(__('Seleccione la opción que desea utilizar.','informeglpi'),ENT_QUOTES,'UTF-8') ?></p>
<div class="ig-home-grid">
<section class="ig-home-card">
<div class="ig-home-card-top"><span class="ig-home-icon" aria-hidden="true">＋</span></div>
<h3><?= htmlspecialchars(__('Crear informe para celular','informeglpi'),ENT_QUOTES,'UTF-8') ?></h3>
<p><?= htmlspecialchars(__('Registre un informe técnico para un teléfono celular que todavía no se encuentra registrado como activo en GLPI.','informeglpi'),ENT_QUOTES,'UTF-8') ?></p>
<a class="ig-home-action" role="button" href="/plugins/informeglpi/front/manual-phone.php"><?= htmlspecialchars(__('Crear informe','informeglpi'),ENT_QUOTES,'UTF-8') ?> <span aria-hidden="true">→</span></a>
</section>
<?php if($canCreate): ?>
<section class="ig-home-card">
<div class="ig-home-card-top"><span class="ig-home-icon" aria-hidden="true">▤</span><span class="ig-home-count"><?= $myPendingCount ?></span></div>
<h3><?= htmlspecialchars(__('Mis informes técnicos','informeglpi'),ENT_QUOTES,'UTF-8') ?></h3>
<p><?= htmlspecialchars(__('Consulte sus borradores y dé seguimiento a los informes que ya envió al supervisor para revisión y firma.','informeglpi'),ENT_QUOTES,'UTF-8') ?></p>
<a class="ig-home-action" href="/plugins/informeglpi/front/report-mine.php"><?= htmlspecialchars(__('Ver mis informes','informeglpi'),ENT_QUOTES,'UTF-8') ?> <span aria-hidden="true">→</span></a>
</section>
<?php endif; ?>
<section class="ig-home-card">
<div class="ig-home-card-top"><span class="ig-home-icon" aria-hidden="true">☷</span><?php if($canReview): ?><span class="ig-home-count" title="<?= htmlspecialchars(__('Informes pendientes de revisión','informeglpi'),ENT_QUOTES,'UTF-8') ?>"><?= $pendingCount ?></span><?php endif; ?></div>
<h3><?= htmlspecialchars(__('Bandeja de entrada','informeglpi'),ENT_QUOTES,'UTF-8') ?></h3>
<p><?= htmlspecialchars(__('Consulte los informes enviados por los técnicos que esperan su revisión y firma.','informeglpi'),ENT_QUOTES,'UTF-8') ?></p>
<?php if($canReview): ?><a class="ig-home-action" href="/plugins/informeglpi/front/report-inbox.php"><?= htmlspecialchars(__('Ver bandeja de entrada','informeglpi'),ENT_QUOTES,'UTF-8') ?> <span aria-hidden="true">→</span></a><?php else: ?><span class="ig-home-unavailable"><?= htmlspecialchars(__('Opción disponible para supervisores autorizados.','informeglpi'),ENT_QUOTES,'UTF-8') ?></span><?php endif; ?>
</section>
<section class="ig-home-card">
<div class="ig-home-card-top"><span class="ig-home-icon" aria-hidden="true">✓</span></div>
<h3><?= htmlspecialchars(__('Informes finalizados','informeglpi'),ENT_QUOTES,'UTF-8') ?></h3>
<p><?= htmlspecialchars(__('Consulte los informes que completaron el proceso de revisión y firma.','informeglpi'),ENT_QUOTES,'UTF-8') ?></p>
<a class="ig-home-action" href="/plugins/informeglpi/front/report-finalized.php"><?= htmlspecialchars(__('Ver informes finalizados','informeglpi'),ENT_QUOTES,'UTF-8') ?> <span aria-hidden="true">→</span></a>
</section>
</div></div>
<?php Html::footer(); ?>
