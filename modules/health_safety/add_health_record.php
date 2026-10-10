<?php
require_once __DIR__.'/../../includes/helpers.php';
require_once __DIR__.'/../../includes/service_client.php';
require_records_entry_account();
$svc=service('health');
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{$message=$svc->handle((string)($_POST['action']??''),$_POST,current_user());flash('success',$message);}
 catch(Throwable $e){flash('error','Unable to save record: '.$e->getMessage());}
 redirect('/modules/health_safety/add_health_record.php');
}
page_header('Add Employee Health Record','health');show_flash(); ?>
<div class="gw-breadcrumb"><a href="<?=e(url('/modules/health_safety/index.php'))?>">Health, Safety &amp; Welfare</a><span>/</span><strong>Add Employee Health Record</strong></div>
<section class="gw-hero"><div><div class="eyebrow">HEALTH RECORDS</div><h1>Add Employee Health Record</h1><p>Enter an employee health assessment in a dedicated form. Saved records remain connected to the existing Health Records table.</p></div></section>
<section class="record-form"><div class="gw-panel-head"><h2>Employee Health Information</h2><span>Database-backed • Restricted health information</span></div><form method="post"><input type="hidden" name="action" value="add_health"><div class="form-grid">
<div><label>Employee Full Name *</label><input name="employee_name" required maxlength="120"></div>
<div><label>Employee ID / Passport No.</label><input name="employee_id" maxlength="80"></div>
<div><label>Checkup Date *</label><input type="date" name="checkup_date" required value="<?=date('Y-m-d')?>"></div>
<div><label>Record Type</label><select name="record_type"><option>Pre-employment Medical</option><option>Annual Medical</option><option>Fit-to-Work Assessment</option><option>Follow-up Checkup</option><option>Incident-related Assessment</option><option>Other</option></select></div>
<div><label>Fitness Status *</label><select name="fitness_status"><option>Pending</option><option>Fit</option><option>Fit with Restrictions</option><option>Unfit</option></select></div>
<div><label>Clinician / Clinic</label><input name="clinician_name" maxlength="120"></div>
<div><label>Height (cm)</label><input type="number" name="height_cm" id="height_cm" min="50" max="250" step="0.1" placeholder="e.g. 170"></div>
<div><label>Weight (kg)</label><input type="number" name="weight_kg" id="weight_kg" min="2" max="400" step="0.1" placeholder="e.g. 65"></div>
<div><label>Body Mass Index (BMI)</label><input type="number" name="bmi" id="bmi" step="0.1" readonly placeholder="Calculated automatically"><small>Calculated from height and weight when both are entered.</small></div>
<div class="full"><label>Allergies</label><textarea name="allergies" placeholder="Known allergies or 'None reported'"></textarea></div>
<div class="full"><label>Medical Conditions / Relevant History</label><textarea name="medical_conditions" placeholder="Relevant conditions and medical history"></textarea></div>
<div class="full"><label>Current Medications</label><textarea name="medications" placeholder="Medication name and relevant instructions"></textarea></div>
<div class="full"><label>Laboratory / Examination Results</label><textarea name="lab_results" placeholder="Record test names, dates and results; attach files through the approved records process"></textarea></div>
<div class="full"><label>Work Restrictions / Recommendations</label><textarea name="restrictions" placeholder="Restrictions, accommodations or follow-up recommendations"></textarea></div>
<div><label>Next Review Date</label><input type="date" name="next_review_date"></div>
<div class="full"><label>General Notes</label><textarea name="notes" placeholder="Assessment summary and non-sensitive operational notes"></textarea></div>
<div class="full"><label>Confidential Clinical Notes</label><textarea name="confidential_notes" placeholder="Only enter information permitted by your organization's health-data policy"></textarea></div>
</div><div class="record-actions"><button class="gw-btn primary" type="submit">Save Health Record</button></div></form><div class="record-actions"><a class="gw-btn secondary" href="<?=e(url('/modules/health_safety/index.php'))?>">Cancel</a></div></section>
<script>
(function(){
 const height=document.getElementById('height_cm'), weight=document.getElementById('weight_kg'), bmi=document.getElementById('bmi');
 function calculateBMI(){
   const h=parseFloat(height.value), w=parseFloat(weight.value);
   bmi.value=(h>0 && w>0) ? (w/Math.pow(h/100,2)).toFixed(1) : '';
 }
 height.addEventListener('input',calculateBMI); weight.addEventListener('input',calculateBMI);
})();
</script>
<?php page_footer(); ?>