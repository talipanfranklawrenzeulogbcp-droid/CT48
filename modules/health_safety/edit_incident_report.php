<?php
require_once __DIR__.'/../../includes/helpers.php';
require_once __DIR__.'/../../includes/service_client.php';
require_incident_report_access();
$svc=service('health');
$id=(int)($_GET['id']??$_POST['id']??0);
$incident=$svc->incident($id);
if(!$incident){ flash('error','Incident report not found.'); redirect('/modules/health_safety/index.php#incident-reports'); }
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_valid($_POST['csrf_token']??null)){ flash('error','Your session security token expired. Please try again.'); redirect('/modules/health_safety/edit_incident_report.php?id='.$id); }
    try { $message=$svc->handle('update_incident',$_POST,current_user()); flash('success',$message); redirect('/modules/health_safety/view_incident_report.php?id='.$id); }
    catch(Throwable $e){ flash('error','Unable to update incident report: '.$e->getMessage()); $incident=array_merge($incident,$_POST); }
}
$val=static fn(string $key): string=>e($incident[$key]??'');
$selected=static fn(string $key,string $option): string=>((string)($incident[$key]??'')===$option?' selected':'');
$storedFiles=json_decode((string)($incident['attachment_paths']??'[]'),true);
if(!is_array($storedFiles))$storedFiles=[];
$storedCount=count(array_filter($storedFiles,'is_string'));
page_header('Edit Incident Report','health'); show_flash(); ?>
<div class="gw-breadcrumb"><a href="<?=e(url('/modules/health_safety/index.php#incident-reports'))?>">Health, Safety &amp; Welfare</a><span>/</span><a href="<?=e(url('/modules/health_safety/view_incident_report.php?id='.$id))?>">Incident #<?=e((string)$id)?></a><span>/</span><strong>Edit Report</strong></div>
<section class="gw-hero"><div><div class="eyebrow">HEALTH &amp; SAFETY · REPORT #<?=e((string)$id)?></div><h1>Edit Incident Report</h1><p>Update the incident record. Existing supporting files are retained; newly uploaded evidence is added to the report.</p></div></section>
<section class="record-form">
  <div class="gw-panel-head"><h2>Incident details</h2><span>Required fields are marked *</span></div>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>">
    <div class="form-grid">
      <div class="full"><label>Incident title / short summary *</label><input name="title" required maxlength="180" value="<?=$val('title')?>"></div>
      <div><label>Incident date *</label><input type="date" name="incident_date" required value="<?=$val('incident_date')?>"></div>
      <div><label>Incident time</label><input type="time" name="incident_time" value="<?=e(substr((string)($incident['incident_time']??''),0,5))?>"></div>
      <div><label>Incident type *</label><select name="incident_type" required><?php foreach(['Injury','Near Miss','Illness','Property Damage','Environmental','Unsafe Condition','Fire','Other'] as $option): ?><option<?=$selected('incident_type',$option)?>><?=e($option)?></option><?php endforeach; ?></select></div>
      <div><label>Severity *</label><select name="severity" required><?php foreach(['Low','Medium','High','Critical'] as $option): ?><option<?=$selected('severity',$option)?>><?=e($option)?></option><?php endforeach; ?></select></div>
      <div class="full"><label>Location / worksite *</label><input name="location" required maxlength="180" value="<?=$val('location')?>" placeholder="Building, department, project site or specific area"></div>
      <div><label>Involved person / employee name *</label><input name="employee_name" required maxlength="120" value="<?=$val('employee_name')?>"></div>
      <div><label>Employee ID / reference</label><input name="employee_id" maxlength="80" value="<?=$val('employee_id')?>"></div>
      <div><label>Supervisor / person in charge</label><input name="supervisor_name" maxlength="120" value="<?=$val('supervisor_name')?>"></div>
      <div><label>Report status *</label><select name="status" required><?php foreach(['Open','Under Investigation','Closed'] as $option): ?><option<?=$selected('status',$option)?>><?=e($option)?></option><?php endforeach; ?></select></div>
      <div class="full"><label>Incident description *</label><textarea name="description" required rows="4"><?=$val('description')?></textarea></div>
      <div class="full"><label>Injury / illness details</label><textarea name="injury_details" rows="3"><?=$val('injury_details')?></textarea></div>
      <div><label>Body part affected</label><input name="body_part_affected" maxlength="180" value="<?=$val('body_part_affected')?>"></div>
      <div><label>Lost time / work impact</label><input name="lost_time" maxlength="80" value="<?=$val('lost_time')?>"></div>
      <div class="full"><label>Witnesses</label><textarea name="witnesses" rows="2"><?=$val('witnesses')?></textarea></div>
      <div class="full"><label>Immediate action taken</label><textarea name="immediate_action" rows="3"><?=$val('immediate_action')?></textarea></div>
      <div class="full"><label>Preliminary / root cause</label><textarea name="root_cause" rows="3"><?=$val('root_cause')?></textarea></div>
      <div class="full"><label>Corrective and preventive actions</label><textarea name="corrective_action" rows="3"><?=$val('corrective_action')?></textarea></div>
      <div><label>Reported by</label><input name="reported_by" maxlength="120" value="<?=$val('reported_by')?>"></div>
      <div class="full"><label>Additional supporting evidence</label><input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf" <?=($storedCount>=5?'disabled':'')?>><small><?=$storedCount?> existing file(s); <?=max(0,5-$storedCount)?> additional file(s) available. Maximum five files total; each new file must be 8 MB or smaller. Accepted formats: JPG, PNG, WEBP and PDF.</small></div>
    </div>
    <div class="record-actions"><button class="gw-btn primary" type="submit">Save Changes</button><a class="gw-btn secondary" href="<?=e(url('/modules/health_safety/view_incident_report.php?id='.$id))?>">Cancel</a></div>
  </form>
</section>
<?php page_footer(); ?>
