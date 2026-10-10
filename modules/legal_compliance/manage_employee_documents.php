<?php
require_once __DIR__.'/../../includes/helpers.php'; require_once __DIR__.'/../../includes/service_client.php'; require_records_entry_account();
$svc=service('legal'); $documents=$svc->employeeDocumentRecords();
$employeeOptions=$svc->documentEmployeeOptions();
// Hide duplicate checklist rows in the interface without deleting database records.
$uniqueDocuments=[];
foreach($documents as $doc){
    $key=(string)($doc['health_record_id']??'none').'|'.mb_strtolower(trim((string)($doc['document_name']??'')));
    if(!isset($uniqueDocuments[$key])
        || (!empty($doc['uploaded_file_name']) && empty($uniqueDocuments[$key]['uploaded_file_name']))
        || ((int)($doc['id']??0)>(int)($uniqueDocuments[$key]['id']??0) && empty($uniqueDocuments[$key]['uploaded_file_name']))) {
        $uniqueDocuments[$key]=$doc;
    }
}
$documents=array_values($uniqueDocuments);
$recordFilter=(int)($_GET['record']??0);
$documentTypes=['Medical Certificate','Fit-to-Work Certificate','Drug Test Result','Chest X-ray Result','Complete Blood Count (CBC)','Urinalysis Result','Hepatitis B Screening (if required)','Valid Passport / Government ID','Updated Resume / CV','Employment Contract / Job Offer','Work Visa / Work Permit (destination-dependent)','Training / Skills Certificates (if required)','Pre-Departure Orientation Certificate (if applicable)','Police / NBI Clearance (if required)'];
$documents=array_values(array_filter($documents,fn($d)=>!$recordFilter || (int)$d['id']===$recordFilter));
page_header('Employee Document Upload Center','legal'); show_flash(); ?>
<div class="gw-breadcrumb"><a href="<?=e(url('/records_entry_dashboard.php'))?>">Records Entry Dashboard</a><span>/</span><strong>Employee Document Upload</strong></div>
<section class="gw-hero"><div><div class="eyebrow">EMPLOYEE DOCUMENTS</div><h1>Employee Document Upload Center</h1><p>Manage recruitment documents from a dedicated page. Updates are saved to the existing employee document records and remain linked to Health Records.</p></div></section>
<div class="gw-quick-actions"><a href="<?=e(url('/records_entry_dashboard.php'))?>"><span class="material-symbols-outlined">home</span> Return to Records Entry Dashboard</a></div>
<section class="gw-panel" style="margin-top:20px"><div class="gw-panel-head"><div><h2>Upload and Update Documents</h2><span>PDF, JPG, PNG, DOC or DOCX • Maximum 10 MB per file</span></div><span class="material-symbols-outlined">upload_file</span></div>
<div class="document-upload-grid">
<?php foreach($documents as $d): ?>
<article class="record-form document-upload-card"><div class="gw-panel-head"><div><h2><?=e($d['document_name'])?></h2><span><?=e($d['employee_name'])?> · Health record #<?=e($d['health_record_id']??'—')?></span></div><span class="gw-status-pill"><?=e($d['status'])?></span></div>
<?php if(!empty($d['uploaded_file_name'])): ?><p class="document-current-file"><span class="material-symbols-outlined">description</span><?=e($d['uploaded_file_name'])?> <a class="gw-btn secondary" target="_blank" rel="noopener" href="<?=e(url('/modules/legal_compliance/view_document.php?id='.(int)$d['id']))?>">View Document</a></p><?php endif; ?>
<form method="post" action="<?=e(url('/modules/legal_compliance/index.php'))?>" enctype="multipart/form-data"><input type="hidden" name="action" value="update_employee_document"><input type="hidden" name="id" value="<?=(int)$d['id']?>"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="return_to" value="/modules/legal_compliance/manage_employee_documents.php<?= $recordFilter ? '?record='.$recordFilter : '' ?>">
<div class="form-grid"><div class="full"><label>Document Owner / Employee</label><select name="health_record_id" required><option value="">Choose employee</option><?php foreach($employeeOptions as $employee): ?><option value="<?= (int)$employee['id']?>" <?=((int)($d['health_record_id']??0)===(int)$employee['id'])?'selected':''?>><?=e($employee['employee_name'])?> — Health record #<?= (int)$employee['id']?></option><?php endforeach; ?></select><small class="small-muted">Choose the employee who owns this document. The existing document record and uploaded file are retained.</small></div><div><label>Status</label><select name="status" required><?php foreach(['Missing','Requested','Received','Verified','Expired','Not Applicable'] as $st): ?><option value="<?=e($st)?>" <?=$d['status']===$st?'selected':''?>><?=e($st)?></option><?php endforeach; ?></select></div><div><label>Issue Date</label><input type="date" name="issue_date" value="<?=e($d['issue_date']??'')?>"></div><div><label>Expiry Date</label><input type="date" name="expiry_date" value="<?=e($d['expiry_date']??'')?>"></div><div class="full"><label>Upload / Replace Document</label><input type="file" name="document_file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"><small class="small-muted">Leave empty to keep the currently uploaded file.</small></div><div class="full"><label>Notes</label><textarea name="document_notes" rows="2" placeholder="Optional notes"><?=e($d['notes']??'')?></textarea></div></div><div class="record-actions"><button class="gw-btn primary" type="submit">Save Document Record</button></div></form></article>
<?php endforeach; if(!$documents): ?><p class="empty">No document checklist items found. Add an employee health record first to generate the checklist.</p><?php endif; ?>
</div></section>
<?php page_footer(); ?>