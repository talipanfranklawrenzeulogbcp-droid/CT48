<?php
require_once __DIR__.'/../../includes/helpers.php';
require_once __DIR__.'/../../includes/service_client.php';
require_incident_report_access();
$svc=service('health');
$id=(int)($_GET['id']??0);
$report=$svc->incident($id);
if(!$report){ http_response_code(404); page_header('Incident Not Found','health'); ?><div class="gw-breadcrumb"><a href="<?=e(url('/modules/health_safety/index.php#incident-reports'))?>">Health, Safety &amp; Welfare</a><span>/</span><strong>Incident Not Found</strong></div><section class="gw-panel" style="padding:24px"><h2>Incident report not found</h2><p>The report may have been archived or the reference is invalid.</p><a class="gw-btn secondary" href="<?=e(url('/modules/health_safety/index.php#incident-reports'))?>">Back to incident reports</a></section><?php page_footer(); exit; }
$files=json_decode((string)($report['attachment_paths']??'[]'),true); if(!is_array($files))$files=[];
$fields=[
 'Incident date'=>($report['incident_date']??'—'), 'Incident time'=>($report['incident_time']??'—'),
 'Location / worksite'=>($report['location']??'—'), 'Incident type'=>($report['incident_type']??'—'),
 'Severity'=>($report['severity']??'—'), 'Status'=>($report['status']??'—'),
 'Involved employee'=>($report['employee_name']??'—'), 'Employee ID'=>($report['employee_id']??'—'),
 'Supervisor'=>($report['supervisor_name']??'—'), 'Reported by'=>($report['reported_by']??'—'),
 'Injury / illness details'=>($report['injury_details']??'—'), 'Body part affected'=>($report['body_part_affected']??'—'),
 'Lost time / work impact'=>($report['lost_time']??'—'), 'Witnesses'=>($report['witnesses']??'—'),
 'Immediate action taken'=>($report['immediate_action']??'—'), 'Preliminary / root cause'=>($report['root_cause']??'—'),
 'Corrective and preventive actions'=>($report['corrective_action']??'—')
];
page_header('Incident Report #'.$id,'health'); show_flash(); ?>
<div class="gw-breadcrumb"><a href="<?=e(url('/modules/health_safety/index.php#incident-reports'))?>">Health, Safety &amp; Welfare</a><span>/</span><strong>Incident Report #<?=e((string)$id)?></strong></div>
<section class="gw-hero"><div><div class="eyebrow">HEALTH &amp; SAFETY · REPORT #<?=e((string)$id)?></div><h1><?=e($report['title'])?></h1><p>Incident report details, response actions and uploaded evidence.</p></div><div class="gw-hero-actions"><a class="gw-btn primary" href="<?=e(url('/modules/health_safety/edit_incident_report.php?id='.$id))?>"><span class="material-symbols-outlined">edit</span> Edit Report</a><a class="gw-btn secondary" href="<?=e(url('/modules/health_safety/index.php#incident-reports'))?>">Back to Reports</a></div></section>
<section class="gw-panel" style="margin-top:20px"><div class="gw-panel-head"><div><h2>Report summary</h2><span>Submitted <?=e($report['created_at']??'')?></span></div><span class="material-symbols-outlined">health_and_safety</span></div><div class="incident-detail-grid"><div class="incident-detail-item incident-detail-wide"><span class="incident-detail-label">Incident description</span><div><?=nl2br(e($report['description']??'—'))?></div></div><?php foreach($fields as $label=>$value): ?><div class="incident-detail-item"><span class="incident-detail-label"><?=e($label)?></span><div><?=nl2br(e($value!==''?$value:'—'))?></div></div><?php endforeach; ?></div></section>
<section class="gw-panel" style="margin-top:20px"><div class="gw-panel-head"><div><h2>Supporting evidence</h2><span>Files stored on the server and linked to this report</span></div><span class="material-symbols-outlined">attach_file</span></div><div style="padding:18px"><?php $shown=0; foreach($files as $fileIndex=>$file): if(is_string($file) && preg_match('#^storage/health_safety/incidents/[a-f0-9]{32}\.(jpg|png|webp|pdf)$#',$file)): $shown++; ?><p><a href="<?=e(url('/modules/health_safety/incident_attachment.php?report_id='.$id.'&file='.(int)$fileIndex))?>" target="_blank" rel="noopener">View or open evidence file <?=e((string)$shown)?></a></p><?php endif; endforeach; if(!$shown): ?><p>No supporting files were attached to this report.</p><?php endif; ?></div></section>
<?php page_footer(); ?>
