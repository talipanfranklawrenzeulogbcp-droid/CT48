<?php
require_once __DIR__.'/../../includes/helpers.php';
require_once __DIR__.'/../../includes/service_client.php';
require_incident_report_access();
$svc=service('health');
$formData = [];
if($_SERVER['REQUEST_METHOD']==='POST'){
    $formData = $_POST;
    if (!csrf_valid($_POST['csrf_token'] ?? null)) { flash('error','Your session security token expired. Please try again.'); redirect('/modules/health_safety/add_incident_report.php'); }
    try {
        $message=$svc->handle((string)($_POST['action']??''),$_POST,current_user());
        flash('success',$message);
        redirect('/modules/health_safety/index.php#incident-reports');
    } catch(Throwable $e) {
        // Keep the entered values visible so a validation or upload error does not
        // force staff to re-enter the complete report.
        flash('error','Unable to save incident report: '.$e->getMessage());
    }
}
$value = static fn(string $key, string $fallback=''): string => e((string)($formData[$key] ?? $fallback));
$selected = static fn(string $key, string $option, string $fallback=''): string => ((string)($formData[$key] ?? $fallback) === $option ? ' selected' : '');
page_header('New Incident Report','health'); show_flash(); ?>
<div class="gw-breadcrumb"><a href="<?=e(url('/modules/health_safety/index.php'))?>">Health, Safety &amp; Welfare</a><span>/</span><strong>New Incident Report</strong></div>
<section class="gw-hero"><div><div class="eyebrow">HEALTH &amp; SAFETY</div><h1>New Incident Report</h1><p>Record what happened, people involved, immediate response, follow-up actions and supporting evidence.</p></div></section>
<section class="record-form">
  <div class="gw-panel-head"><h2>Incident details</h2><span>Required fields are marked *</span></div>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <input type="hidden" name="action" value="add_incident">
    <div class="form-grid">
      <div class="full"><label>Incident title / short summary *</label><input name="title" required maxlength="180" value="<?=$value('title')?>" placeholder="Briefly describe the incident"></div>
      <div><label>Incident date *</label><input type="date" name="incident_date" required value="<?=$value('incident_date', date('Y-m-d'))?>"></div>
      <div><label>Incident time</label><input type="time" name="incident_time" value="<?=$value('incident_time')?>"></div>
      <div><label>Incident type *</label><select name="incident_type" required><option<?= $selected('incident_type','Injury','Injury') ?>>Injury</option><option<?= $selected('incident_type','Near Miss','Injury') ?>>Near Miss</option><option<?= $selected('incident_type','Illness','Injury') ?>>Illness</option><option<?= $selected('incident_type','Property Damage','Injury') ?>>Property Damage</option><option<?= $selected('incident_type','Environmental','Injury') ?>>Environmental</option><option<?= $selected('incident_type','Unsafe Condition','Injury') ?>>Unsafe Condition</option><option<?= $selected('incident_type','Fire','Injury') ?>>Fire</option><option<?= $selected('incident_type','Other','Injury') ?>>Other</option></select></div>
      <div><label>Severity *</label><select name="severity" required><option<?= $selected('severity','Low','Medium') ?>>Low</option><option<?= $selected('severity','Medium','Medium') ?>>Medium</option><option<?= $selected('severity','High','Medium') ?>>High</option><option<?= $selected('severity','Critical','Medium') ?>>Critical</option></select></div>
      <div class="full"><label>Location / worksite *</label><input name="location" required maxlength="180" value="<?=$value('location')?>" placeholder="Building, department, project site or specific area"></div>
      <div><label>Involved person / employee name *</label><input name="employee_name" required maxlength="120" value="<?=$value('employee_name')?>"></div>
      <div><label>Employee ID / reference</label><input name="employee_id" maxlength="80" value="<?=$value('employee_id')?>"></div>
      <div><label>Supervisor / person in charge</label><input name="supervisor_name" maxlength="120" value="<?=$value('supervisor_name')?>"></div>
      <div><label>Report status *</label><select name="status"><option<?= $selected('status','Open','Open') ?>>Open</option><option<?= $selected('status','Under Investigation','Open') ?>>Under Investigation</option><option<?= $selected('status','Closed','Open') ?>>Closed</option></select></div>
      <div class="full"><label>Incident description *</label><textarea name="description" required rows="4" placeholder="Describe the sequence of events, conditions and what was observed."><?= $value('description') ?></textarea></div>
      <div class="full"><label>Injury / illness details</label><textarea name="injury_details" rows="3" placeholder="Describe injury or symptoms, treatment provided, or indicate not applicable."><?= $value('injury_details') ?></textarea></div>
      <div><label>Body part affected</label><input name="body_part_affected" maxlength="180" value="<?=$value('body_part_affected')?>" placeholder="If applicable"></div>
      <div><label>Lost time / work impact</label><input name="lost_time" maxlength="80" value="<?=$value('lost_time')?>" placeholder="e.g. None, 2 days, pending assessment"></div>
      <div class="full"><label>Witnesses</label><textarea name="witnesses" rows="2" placeholder="Names and contact/reference details where appropriate"><?= $value('witnesses') ?></textarea></div>
      <div class="full"><label>Immediate action taken</label><textarea name="immediate_action" rows="3" placeholder="First aid, area isolation, notification, emergency response, etc."><?= $value('immediate_action') ?></textarea></div>
      <div class="full"><label>Preliminary / root cause</label><textarea name="root_cause" rows="3" placeholder="Known or suspected contributing factors; avoid unsupported conclusions."><?= $value('root_cause') ?></textarea></div>
      <div class="full"><label>Corrective and preventive actions</label><textarea name="corrective_action" rows="3" placeholder="Action, responsible person and target date if known"><?= $value('corrective_action') ?></textarea></div>
      <div><label>Reported by</label><input name="reported_by" maxlength="120" value="<?=$value('reported_by', (string)(current_user()['name']??''))?>"></div>
      <div class="full"><label>Supporting evidence (up to 5 files, 8 MB each)</label><input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf"><small>Accepted formats: JPG, PNG, WEBP and PDF. Files are saved in server storage using generated filenames.</small></div>
    </div>
    <div class="record-actions"><button class="gw-btn primary" type="submit">Save Incident Report</button><a class="gw-btn secondary" href="<?=e(url('/modules/health_safety/index.php#incident-reports'))?>">Cancel</a></div>
  </form>
</section>
<?php page_footer(); ?>
