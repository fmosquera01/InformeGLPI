<?php
declare(strict_types=1);

include('../../../inc/includes.php');
Session::checkLoginUser();
if (!Session::haveRight('plugin_informeglpi', CREATE) || !Session::haveRight('plugin_informeglpi', READ)) {
    Html::displayRightError();
}
Html::header(__('Mis informes técnicos', 'informeglpi'), $_SERVER['PHP_SELF'], 'plugins');
global $DB;
$userId = (int) Session::getLoginUserID();
$rows = [];
foreach ($DB->request([
    'SELECT' => ['id','report_number','itemtype','items_id','asset_snapshot','status','date_creation','entities_id','is_recursive'],
    'FROM' => 'glpi_plugin_informeglopi_reports',
    'WHERE' => ['prepared_by_users_id' => $userId, 'status' => ['draft','returned_for_correction','pending_supervisor_signature']],
    'ORDER' => ['date_mod DESC','id DESC'],
]) as $row) {
    if (Session::haveAccessToEntity((int)$row['entities_id'], (bool)$row['is_recursive'])) $rows[] = $row;
}
$labels = [
    'draft' => __('En preparación','informeglpi'),
    'returned_for_correction' => __('Devuelto para corrección','informeglpi'),
    'pending_supervisor_signature' => __('Pendiente de firma del supervisor','informeglpi'),
];
?>
<div class="container-fluid mt-3">
<div class="d-flex justify-content-between align-items-center mb-3">
<h2 class="h4 mb-0"><?= htmlspecialchars(__('Mis informes técnicos','informeglpi'), ENT_QUOTES, 'UTF-8') ?></h2>
<a class="btn btn-outline-secondary btn-sm" href="/plugins/informeglpi/front/home.php"><?= htmlspecialchars(__('Regresar','informeglpi'), ENT_QUOTES, 'UTF-8') ?></a>
</div>
<div class="card"><div class="card-header"><strong><?= htmlspecialchars(__('Borradores y seguimiento de informes enviados','informeglpi'), ENT_QUOTES, 'UTF-8') ?></strong> <span class="badge bg-secondary"><?= count($rows) ?></span></div><div class="card-body">
<?php if (!$rows): ?>
<div class="alert alert-info mb-0"><?= htmlspecialchars(__('Todavía no tiene informes en preparación ni enviados a supervisión.','informeglpi'), ENT_QUOTES, 'UTF-8') ?></div>
<?php else: ?>
<div class="table-responsive"><table class="table table-sm table-hover align-middle"><thead><tr><th><?= htmlspecialchars(__('Informe','informeglpi'),ENT_QUOTES,'UTF-8') ?></th><th><?= htmlspecialchars(__('Activo','informeglpi'),ENT_QUOTES,'UTF-8') ?></th><th><?= htmlspecialchars(__('Fecha','informeglpi'),ENT_QUOTES,'UTF-8') ?></th><th><?= htmlspecialchars(__('Estado','informeglpi'),ENT_QUOTES,'UTF-8') ?></th><th><?= htmlspecialchars(__('Acción','informeglpi'),ENT_QUOTES,'UTF-8') ?></th></tr></thead><tbody>
<?php foreach ($rows as $row):
$snapshot=json_decode((string)$row['asset_snapshot'],true); $fields=is_array($snapshot)?($snapshot['fields']??[]):[];
$asset=trim(implode(' · ',array_filter([(string)($fields['name']??$fields['NAME']??''),(string)($fields['serial']??$fields['SERIAL']??''),(string)($fields['otherserial']??$fields['OTHERSERIAL']??'')])));
if ($asset==='') $asset=(string)$row['itemtype'].' #'.(int)$row['items_id'];
$url='/plugins/informeglpi/front/report.edit.form.php?id='.(int)$row['id'];
?>
<tr><td><strong><?= htmlspecialchars((string)$row['report_number'],ENT_QUOTES,'UTF-8') ?></strong></td><td><?= htmlspecialchars($asset,ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars((string)$row['date_creation'],ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars($labels[(string)$row['status']]??(string)$row['status'],ENT_QUOTES,'UTF-8') ?></td><td><a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($url,ENT_QUOTES,'UTF-8') ?>"><?= htmlspecialchars($row['status']==='pending_supervisor_signature'?__('Consultar seguimiento','informeglpi'):__('Continuar informe','informeglpi'),ENT_QUOTES,'UTF-8') ?></a></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</div></div></div>
<?php Html::footer(); ?>
