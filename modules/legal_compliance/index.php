<?php
require_once __DIR__.'/../../includes/helpers.php';
require_once __DIR__.'/../../includes/service_client.php';
require_login();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && (($_POST['action'] ?? '') === 'update_employee_document')
    && !is_records_entry_account()) {
    http_response_code(403);
    exit('Access denied: employee document uploads are restricted to the authorized Records Entry account.');
}
$svc=service('legal');
if($_SERVER['REQUEST_METHOD']==='POST'){
    // PHP empties both $_POST and $_FILES when the request body exceeds post_max_size.
    // Detect that condition so oversized uploads receive a useful message instead of a CSRF error.
    $contentLength=(int)($_SERVER['CONTENT_LENGTH']??0);
    $postMaxRaw=trim((string)ini_get('post_max_size'));
    $postMaxBytes=0;
    if(preg_match('/^(\d+(?:\.\d+)?)\s*([KMG]?)B?$/i',$postMaxRaw,$postMaxMatch)){
        $postMaxBytes=(int)((float)$postMaxMatch[1]*match(strtoupper($postMaxMatch[2])){'K'=>1024,'M'=>1048576,'G'=>1073741824,default=>1});
    }
    if($contentLength>0 && $postMaxBytes>0 && $contentLength>$postMaxBytes && empty($_POST) && empty($_FILES)){
        flash('error','The upload request is larger than the server accepts. Documents must be 10 MB or smaller. Please choose a smaller file; if this keeps happening, the hosting request-size limit must be increased.');
        redirect('/modules/legal_compliance/index.php#compliance-report');
    }
    if (!csrf_valid($_POST['csrf_token'] ?? null)) { http_response_code(419); exit('Security token expired. Please refresh and try again.'); }
    try { $message=$svc->handle((string)($_POST['action']??''),$_POST,current_user(),$_FILES); flash('success',$message); }
    catch(Throwable $e){ error_log('Legal & Compliance action failed: '.$e->getMessage()); flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Unable to complete this action. Please try again or contact the system administrator.'); }
    $returnTo=(string)($_POST['return_to']??'/modules/legal_compliance/index.php'); if(!str_starts_with($returnTo,'/modules/legal_compliance/')) $returnTo='/modules/legal_compliance/index.php'; redirect($returnTo);
}
$u=current_user();
$reportDate=(string)($_GET['report_date']??'');
$showAllReports=isset($_GET['report_all']) && $_GET['report_all']==='1';
$obs=$svc->complianceReports($reportDate,$showAllReports?null:5);
$reportTotal=$svc->complianceReportCount($reportDate);
$documents=$svc->employeeDocumentRecords();
// Deduplicate repeated checklist entries in this view only; underlying records remain untouched.
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
$auditDate=(string)($_GET['audit_date']??'');
$showAllAudit=isset($_GET['audit_all']) && $_GET['audit_all']==='1';
$staffAdminAudit=(($u['role']??'')==='Administrator') ? service('audit')->latestStaffAdmin($auditDate, $showAllAudit ? null : 5) : [];
page_header('Legal & Compliance','legal');show_flash(); ?>
<div class="gw-breadcrumb"><span>Great Solomon Manpower Services Inc.</span><span>/</span><strong>Legal &amp; Compliance</strong></div>
<section class="gw-hero"><div><div class="eyebrow">MODULE 2</div><h1>Legal &amp; Compliance</h1><p>Record and review compliance reports.</p></div></section>
<section class="gw-quick-actions"><a href="#compliance-report"><span class="material-symbols-outlined">description</span> Add Compliance Report</a><a href="#compliance-reports"><span class="material-symbols-outlined">list_alt</span> Compliance Reports</a><a href="#employee-document-records"><span class="material-symbols-outlined">folder_managed</span> Employee Document Records</a></section>
<section class="gw-stats"><div class="gw-stat"><span class="gw-stat-label">Compliance Reports</span><div class="gw-stat-value"><?=$reportTotal?></div><div class="gw-stat-meta">Total matching reports · Showing <?= $showAllReports ? 'all matching' : 'latest 5' ?></div></div></section>
<section class="record-form" id="compliance-report"><div class="gw-panel-head"><h2>Add Compliance Report</h2><span>Database-backed</span></div><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="add_report"><div class="form-grid"><div><label>Name</label><input name="report_name" required></div><div><label>Role</label><input name="report_role" required></div><div><label>Contact No.</label><input name="contact_no" required></div><div class="full"><label>Compliance</label><textarea name="compliance_note" required placeholder="Write the compliance concern/complaint like a note..."></textarea></div><div class="full"><label for="report_document">Upload Supporting Document (optional)</label><input id="report_document" name="report_document" type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,application/pdf,image/jpeg,image/png,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"><small class="muted">Allowed: PDF, JPG, PNG, DOC, DOCX • Maximum 10 MB</small></div></div><div class="record-actions"><button class="gw-btn primary">Save Compliance Report</button></div></form></section>
<section class="gw-panel" id="compliance-reports"><div class="gw-panel-head"><div><h2>Compliance Reports</h2><span><?= $showAllReports ? 'Showing all matching reports' : 'Showing the latest 5 reports' ?><?= $reportDate ? ' for '.e($reportDate) : '' ?></span></div><span class="material-symbols-outlined">manage_search</span></div>
<form method="get" class="date-filter" style="padding:0 18px 14px;justify-content:flex-end"><span class="dashboard-date-filter-label"><span class="material-symbols-outlined">filter_alt</span>Report date</span><input type="date" name="report_date" value="<?=e($reportDate)?>"><button class="gw-btn secondary" type="submit">Filter</button><?php if($reportDate):?><a class="gw-btn secondary" href="<?=e(url('/modules/legal_compliance/index.php'))?>#compliance-reports">Clear</a><?php endif;?><?php if($showAllReports):?><a class="gw-btn secondary" href="<?=e(url('/modules/legal_compliance/index.php'.($reportDate?'?report_date='.rawurlencode($reportDate):'')))?>#compliance-reports">Show latest 5</a><?php else:?><a class="gw-btn primary" href="<?=e(url('/modules/legal_compliance/index.php?report_all=1'.($reportDate?'&report_date='.rawurlencode($reportDate):'')))?>#compliance-reports">See all compliance reports</a><?php endif;?></form>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>Role</th><th>Contact No.</th><th>Date &amp; Time</th><th>Compliance</th><th>Supporting Document</th><th></th></tr></thead><tbody><?php foreach($obs as $r):?><tr><td><?=e($r['report_name']??$r['title'])?></td><td><?=e($r['report_role']??$r['owner'])?></td><td><?=e($r['contact_no']??'')?></td><td><?=e($r['reported_at']??$r['created_at'])?></td><td><?=nl2br(e($r['compliance_note']??''))?></td><td><?php if(!empty($r['report_document_path'])):?><a class="gw-btn secondary" target="_blank" rel="noopener" href="<?=e(url('/modules/legal_compliance/view_compliance_report_document.php?id='.(int)$r['id']))?>"><span class="material-symbols-outlined" style="font-size:16px;vertical-align:middle">visibility</span> View Document</a><br><small><?=e($r['report_document_name']??'Attached document')?></small><?php else:?><span class="muted">No attachment</span><?php endif;?></td><td><?php if(($u['role']??'')==='Administrator'): ?><form method="post" onsubmit="return confirm('Delete this compliance report? It will be moved to Archive.')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_report"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="gw-btn btn-danger">Delete</button></form><?php else: ?><span class="muted">View only</span><?php endif; ?></td></tr><?php endforeach;if(!$obs):?><tr><td colspan="7" class="empty">No compliance reports recorded.</td></tr><?php endif;?></tbody></table></div></section>

<section class="gw-panel" id="employee-document-records" style="margin-top:20px"><div class="gw-panel-head"><div><h2>Employee Document Records</h2><span>Automatically linked to Health Records • <?=count($documents)?> checklist items</span></div><span class="material-symbols-outlined">folder_managed</span></div>
<p style="padding:0 18px;color:var(--muted,#667085)">Recruitment document checklist. Each document type has its own column. Upload a PDF, JPG, PNG, DOC or DOCX file (maximum 10 MB), then use View Document to open the stored file. Requirements vary by destination, employer and job category. <?php if(is_records_entry_account()): ?><a class="gw-btn primary" style="display:inline-flex;margin:8px 0" href="<?=e(url('/modules/legal_compliance/manage_employee_documents.php'))?>"><span class="material-symbols-outlined">upload_file</span> Open Document Upload Center</a><?php endif; ?></p>
<?php $documentTypes=['Medical Certificate','Fit-to-Work Certificate','Drug Test Result','Chest X-ray Result','Complete Blood Count (CBC)','Urinalysis Result','Hepatitis B Screening (if required)','Valid Passport / Government ID','Updated Resume / CV','Employment Contract / Job Offer','Work Visa / Work Permit (destination-dependent)','Training / Skills Certificates (if required)','Pre-Departure Orientation Certificate (if applicable)','Police / NBI Clearance (if required)'];
$employeeGroups=[]; foreach($documents as $d){$key=(string)($d['health_record_id']??'none').'|'.(string)$d['employee_name']; if(!isset($employeeGroups[$key])) $employeeGroups[$key]=['employee_name'=>$d['employee_name'],'health_record_id'=>$d['health_record_id'],'checkup_date'=>$d['checkup_date']??null,'documents'=>[]]; $employeeGroups[$key]['documents'][$d['document_name']]=$d;}
?>
<div class="table-wrap" style="overflow-x:auto"><table class="data-table employee-document-matrix"><thead><tr><th style="min-width:180px">Employee</th><th style="min-width:125px">Linked Checkup</th><?php foreach($documentTypes as $type):?><th style="min-width:210px"><?=e($type)?></th><?php endforeach;?></tr></thead><tbody>
<?php foreach($employeeGroups as $group):?><tr><td><strong><?=e($group['employee_name'])?></strong><br><small>Health record #<?=e($group['health_record_id']??'—')?></small></td><td><?=e($group['checkup_date']??'No linked checkup')?></td>
<?php foreach($documentTypes as $type): $d=$group['documents'][$type]??null; ?><td><?php if(!$d):?><span class="muted">Not available</span><?php else:?><div style="display:grid;gap:8px;min-width:190px"><strong><?=e($d['status'])?></strong><?php if(!empty($d['uploaded_file_name'])):?><small title="<?=e($d['uploaded_file_name'])?>"><?=e($d['uploaded_file_name'])?></small><a class="gw-btn secondary" target="_blank" rel="noopener" href="<?=e(url('/modules/legal_compliance/view_document.php?id='.(int)$d['id']))?>"><span class="material-symbols-outlined" style="font-size:16px;vertical-align:middle">visibility</span> View Document</a><?php else:?><small class="muted">No document uploaded</small><?php endif;?>
<?php if(is_records_entry_account()): ?><a class="gw-btn secondary" href="<?=e(url('/modules/legal_compliance/manage_employee_documents.php?record='.(int)$d['id']))?>"><span class="material-symbols-outlined" style="font-size:16px;vertical-align:middle">upload_file</span> Manage Document</a><?php endif; ?></div><?php endif;?></td><?php endforeach;?></tr><?php endforeach;?>
<?php if(!$employeeGroups):?><tr><td colspan="<?=count($documentTypes)+2?>" class="empty">No health records exist yet. Add an employee health record to generate a document checklist automatically.</td></tr><?php endif;?></tbody></table></div></section>

<?php if (($u['role'] ?? '') === 'Administrator'): ?>
<section class="gw-panel" id="staff-admin-audit-trail" style="margin-top:20px"><div class="gw-panel-head"><div><h2>Staff &amp; Admin Audit Trail</h2><span><?= $showAllAudit ? 'Showing all matching audit records' : 'Showing the latest 5 audit records' ?><?= $auditDate ? ' for '.e($auditDate) : '' ?></span></div><span class="material-symbols-outlined">manage_search</span></div>
<form method="get" class="date-filter" style="padding:0 18px 14px;justify-content:flex-end"><span class="dashboard-date-filter-label"><span class="material-symbols-outlined">filter_alt</span>Audit date</span><input type="date" name="audit_date" value="<?=e($auditDate)?>"><button class="gw-btn secondary">Filter</button><?php if($auditDate):?><a class="gw-btn secondary" href="<?=e(url('/modules/legal_compliance/index.php'))?>#staff-admin-audit-trail">Clear</a><?php endif;?><?php if($showAllAudit):?><a class="gw-btn secondary" href="<?=e(url('/modules/legal_compliance/index.php'.($auditDate?'?audit_date='.rawurlencode($auditDate):'')))?>#staff-admin-audit-trail">Show latest 5</a><?php else:?><a class="gw-btn primary" href="<?=e(url('/modules/legal_compliance/index.php?audit_all=1'.($auditDate?'&audit_date='.rawurlencode($auditDate):'')))?>#staff-admin-audit-trail">See all audit trails</a><?php endif;?></form>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>Gmail</th><th>Role</th><th>Module</th><th>Action</th><th>Details</th><th>Date &amp; Time</th></tr></thead><tbody><?php foreach($staffAdminAudit as $a): ?><tr><td><?=e($a['name'])?></td><td><?=e($a['email'])?></td><td><?=e($a['role'])?></td><td><?=e($a['module'])?></td><td><?=e($a['action'])?></td><td><?=e($a['details'])?></td><td><?=e($a['created_at'])?></td></tr><?php endforeach; ?><?php if(!$staffAdminAudit): ?><tr><td colspan="7" class="empty">No staff or administrator audit records match the selected filter.</td></tr><?php endif; ?></tbody></table></div></section>
<?php endif; ?>
<?php page_footer(); ?>
