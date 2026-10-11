<?php
require_once __DIR__.'/../../includes/helpers.php';
require_once __DIR__.'/../../includes/service_client.php';
require_incident_report_access();
$svc=service('health');
if($_SERVER['REQUEST_METHOD']==='POST'){
    if (!csrf_valid($_POST['csrf_token'] ?? null)) { flash('error','Your session security token expired. Please try again.'); redirect('/modules/health_safety/add_incident_report.php'); }
    try {
        $message=$svc->handle((string)($_POST['action']??''),$_POST,current_user());
        flash('success',$message);
        redirect('/modules/health_safety/index.php#incident-reports');
    } catch(Throwable $e) {
        flash('error','Unable to save incident report: '.$e->getMessage());
    }
}
page_header('New Incident Report','health'); show_flash(); ?>
<div class="gw-breadcrumb"><a href="<?=e(url('/modules/health_safety/index.php'))?>">Health, Safety &amp; Welfare</a><span>/</span><strong>New Incident Report</strong></div>
<section class="gw-hero"><div><div class="eyebrow">HEALTH &amp; SAFETY</div><h1>New Incident Report</h1><p>Record what happened, people involved, immediate response, follow-up actions and supporting evidence.</p></div></section>
<section class="record-form">
  <div class="gw-panel-head"><h2>Incident details</h2><span>Required fields are marked *</span></div>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <input type="hidden" name="action" value="add_incident">
    <div class="form-grid">
      <div class="full"><label>Incident title / short summary *</label><input name="title" required maxlength="180" placeholder="Briefly describe the incident"></div>
      <div><label>Incident date *</label><input type="date" name="incident_date" required value="<?=e(date('Y-m-d'))?>"></div>
      <div><label>Incident time</label><input type="time" name="incident_time"></div>
      <div><label>Incident type *</label><select name="incident_type" required><option>Injury</option><option>Near Miss</option><option>Illness</option><option>Property Damage</option><option>Environmental</option><option>Unsafe Condition</option><option>Fire</option><option>Other</option></select></div>
      <div><label>Severity *</label><select name="severity" required><option>Low</option><option selected>Medium</option><option>High</option><option>Critical</option></select></div>
      <div class="full"><label>Location / worksite *</label><input name="location" required maxlength="180" placeholder="Building, department, project site or specific area"></div>
      <div><label>Involved person / employee name *</label><input name="employee_name" required maxlength="120"></div>
      <div><label>Employee ID / reference</label><input name="employee_id" maxlength="80"></div>
      <div><label>Supervisor / person in charge</label><input name="supervisor_name" maxlength="120"></div>
      <div><label>Report status *</label><select name="status"><option selected>Open</option><option>Under Investigation</option><option>Closed</option></select></div>
      <div class="full"><label>Incident description *</label><textarea name="description" required rows="4" placeholder="Describe the sequence of events, conditions and what was observed."></textarea></div>
      <div class="full"><label>Injury / illness details</label><textarea name="injury_details" rows="3" placeholder="Describe injury or symptoms, treatment provided, or indicate not applicable."></textarea></div>
      <div><label>Body part affected</label><input name="body_part_affected" maxlength="180" placeholder="If applicable"></div>
      <div><label>Lost time / work impact</label><input name="lost_time" maxlength="80" placeholder="e.g. None, 2 days, pending assessment"></div>
      <div class="full"><label>Witnesses</label><textarea name="witnesses" rows="2" placeholder="Names and contact/reference details where appropriate"></textarea></div>
      <div class="full"><label>Immediate action taken</label><textarea name="immediate_action" rows="3" placeholder="First aid, area isolation, notification, emergency response, etc."></textarea></div>
      <div class="full"><label>Preliminary / root cause</label><textarea name="root_cause" rows="3" placeholder="Known or suspected contributing factors; avoid unsupported conclusions."></textarea></div>
      <div class="full"><label>Corrective and preventive actions</label><textarea name="corrective_action" rows="3" placeholder="Action, responsible person and target date if known"></textarea></div>
      <div><label>Reported by</label><input name="reported_by" maxlength="120" value="<?=e((string)(current_user()['name']??''))?>"></div>
      <div class="full"><label>Supporting evidence (up to 5 files, 8 MB each)</label><input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf"><small>Accepted formats: JPG, PNG, WEBP and PDF. Files are saved in server storage using generated filenames.</small></div>
    </div>
    <div class="record-actions"><button class="gw-btn primary" type="submit">Save Incident Report</button><a class="gw-btn secondary" href="<?=e(url('/modules/health_safety/index.php#incident-reports'))?>">Cancel</a></div>
  </form>
</section>
<?php page_footer(); ?>
