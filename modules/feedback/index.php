<?php
require_once __DIR__.'/../../includes/helpers.php';
require_login();
$u=current_user();
if (!in_array(($u['role']??''),['Administrator','Staff'],true)) { http_response_code(403); exit('Access denied.'); }
$pdo=db();
$admin=($u['role']??'')==='Administrator';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!csrf_valid($_POST['csrf_token']??null)) { flash('error','Your session expired. Refresh and try again.'); redirect('/modules/feedback/index.php'); }
    $caseId=(int)($_POST['case_id']??0); $action=(string)($_POST['action']??'');
    try {
        $q=$pdo->prepare("SELECT c.*,n.sender_user_id,n.sender_name,n.message,n.created_at AS submitted_at FROM feedback_cases c JOIN admin_notifications n ON n.id=c.notification_id WHERE c.id=? AND n.type='feedback' AND n.deleted_at IS NULL LIMIT 1");
        $q->execute([$caseId]); $case=$q->fetch();
        if (!$case) throw new RuntimeException('Feedback record not found.');
        if (!$admin && (int)$case['sender_user_id']!==(int)$u['id']) throw new RuntimeException('You can only manage your own feedback.');
        if ($action==='update_case' && $admin) {
            $status=(string)($_POST['status']??'New'); $priority=(string)($_POST['priority']??'Medium'); $category=(string)($_POST['category']??'General Suggestion');
            if (!in_array($status,['New','Under Review','In Progress','Resolved','Closed'],true) || !in_array($priority,['Low','Medium','High','Critical'],true) || !in_array($category,['System Bug','Feature Request','Workplace Concern','Security Concern','General Suggestion','Other'],true)) throw new RuntimeException('Choose valid feedback options.');
            $assigned=(int)($_POST['assigned_to']??0); if ($assigned>0) { $a=$pdo->prepare("SELECT id FROM users WHERE id=? AND role='Administrator' AND active=1"); $a->execute([$assigned]); if (!$a->fetchColumn()) throw new RuntimeException('Choose an active administrator.'); } else $assigned=null;
            $due=trim((string)($_POST['due_date']??'')); if ($due!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$due)) throw new RuntimeException('Choose a valid due date.');
            $resolved=in_array($status,['Resolved','Closed'],true)?($case['resolved_at']?:date('Y-m-d H:i:s')):null;
            $pdo->prepare('UPDATE feedback_cases SET status=?,priority=?,category=?,assigned_to=?,due_date=?,resolved_at=? WHERE id=?')->execute([$status,$priority,$category,$assigned,$due?:null,$resolved,$caseId]);
            $pdo->prepare('INSERT INTO feedback_history (feedback_case_id,changed_by,action,details) VALUES (?,?,?,?)')->execute([$caseId,(int)$u['id'],'Updated case','Status: '.$status.'; Priority: '.$priority.'; Category: '.$category.'; Assigned to: '.($assigned?:'Unassigned')]);
            audit('Feedback Management','Update Feedback Case','Case #'.$caseId.' status '.$status);
            flash('success','Feedback case updated.');
        } elseif ($action==='rate' && !$admin) {
            $rating=(int)($_POST['rating']??0); $comment=trim((string)($_POST['rating_comment']??''));
            if ($rating<1||$rating>5) throw new RuntimeException('Choose a rating from 1 to 5.');
            if (!in_array($case['status'],['Resolved','Closed'],true)) throw new RuntimeException('You can rate feedback after it is resolved.');
            $comment=function_exists('mb_substr')?mb_substr($comment,0,1000):substr($comment,0,1000);
            $pdo->prepare('UPDATE feedback_cases SET satisfaction_rating=?,satisfaction_comment=? WHERE id=?')->execute([$rating,$comment,$caseId]);
            $pdo->prepare('INSERT INTO feedback_history (feedback_case_id,changed_by,action,details) VALUES (?,?,?,?)')->execute([$caseId,(int)$u['id'],'Satisfaction rating','Staff rated '.$rating.' out of 5']);
            flash('success','Thank you for rating the resolution.');
        } elseif ($action==='reopen' && !$admin) {
            if (!in_array($case['status'],['Resolved','Closed'],true)) throw new RuntimeException('This feedback is not closed.');
            $pdo->prepare("UPDATE feedback_cases SET status='Under Review',resolved_at=NULL WHERE id=?")->execute([$caseId]);
            $pdo->prepare('INSERT INTO feedback_history (feedback_case_id,changed_by,action,details) VALUES (?,?,?,?)')->execute([$caseId,(int)$u['id'],'Reopened','Staff requested further review']);
            flash('success','Feedback reopened for review.');
        } elseif ($action==='upload' && !$admin) {
            if ((int)$case['sender_user_id']!==(int)$u['id']) throw new RuntimeException('You can only attach files to your own feedback.');
            if (empty($_FILES['attachment']) || $_FILES['attachment']['error']!==UPLOAD_ERR_OK) throw new RuntimeException('Choose a file smaller than 5 MB.');
            $f=$_FILES['attachment']; if ((int)$f['size']>5*1024*1024) throw new RuntimeException('File size must be 5 MB or less.');
            $ext=strtolower(pathinfo((string)$f['name'],PATHINFO_EXTENSION)); $allowed=['png','jpg','jpeg','pdf','txt']; if (!in_array($ext,$allowed,true)) throw new RuntimeException('Allowed file types: PNG, JPG, PDF, TXT.');
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']); $mimeAllowed=['image/png','image/jpeg','application/pdf','text/plain','application/octet-stream']; if (!in_array($mime,$mimeAllowed,true)) throw new RuntimeException('The uploaded file type is not allowed.');
            $dir=dirname(__DIR__,2).'/storage/feedback_attachments'; if (!is_dir($dir)) mkdir($dir,0750,true);
            $stored=bin2hex(random_bytes(20)).'.'.$ext; if (!move_uploaded_file($f['tmp_name'],$dir.'/'.$stored)) throw new RuntimeException('The attachment could not be saved.');
            $pdo->prepare('INSERT INTO feedback_attachments (feedback_case_id,uploaded_by,original_name,stored_name,mime_type,file_size) VALUES (?,?,?,?,?,?)')->execute([$caseId,(int)$u['id'],basename((string)$f['name']),$stored,(string)$mime,(int)$f['size']]);
            $pdo->prepare('INSERT INTO feedback_history (feedback_case_id,changed_by,action,details) VALUES (?,?,?,?)')->execute([$caseId,(int)$u['id'],'Attachment added','Supporting file attached']);
            flash('success','Attachment uploaded securely.');
        } else { throw new RuntimeException('This action is not permitted.'); }
    } catch(Throwable $e) { error_log('CT4 feedback management failed: '.$e->getMessage()); flash('error',$e instanceof RuntimeException?$e->getMessage():'The action could not be completed. Please try again.'); }
    redirect('/modules/feedback/index.php');
}
if ($admin && isset($_GET['export']) && $_GET['export']==='csv') {
    $rows=$pdo->query("SELECT c.id,n.sender_name,n.sender_role,c.category,c.priority,c.status,u.name AS assigned_name,c.due_date,c.satisfaction_rating,c.created_at,c.updated_at,c.resolved_at FROM feedback_cases c JOIN admin_notifications n ON n.id=c.notification_id LEFT JOIN users u ON u.id=c.assigned_to WHERE n.type='feedback' AND n.deleted_at IS NULL ORDER BY c.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="feedback-report-'.date('Y-m-d').'.csv"'); $out=fopen('php://output','w'); fputcsv($out,['Reference','Submitted By','Role','Category','Priority','Status','Assigned Administrator','Due Date','Rating','Created','Updated','Resolved']); foreach($rows as $row) { foreach($row as &$value) { $value=(string)($value??''); if(preg_match('/^[=+@\-\t\r]/',$value)) $value="'".$value; } unset($value); fputcsv($out,$row); } fclose($out); exit;
}
$search=trim((string)($_GET['q']??'')); $status=(string)($_GET['status']??''); $category=(string)($_GET['category']??'');
$where=["n.type='feedback'","n.deleted_at IS NULL"]; $params=[];
if (!$admin) { $where[]='n.sender_user_id=?'; $params[]=(int)$u['id']; }
if ($search!=='') { $where[]='(n.message LIKE ? OR n.sender_name LIKE ? OR CAST(c.id AS CHAR) LIKE ?)'; array_push($params,'%'.$search.'%','%'.$search.'%','%'.$search.'%'); }
if ($admin && in_array($status,['New','Under Review','In Progress','Resolved','Closed'],true)) { $where[]='c.status=?'; $params[]=$status; }
if ($admin && in_array($category,['System Bug','Feature Request','Workplace Concern','Security Concern','General Suggestion','Other'],true)) { $where[]='c.category=?'; $params[]=$category; }
$sql="SELECT c.*,n.sender_name,n.sender_role,n.sender_user_id,n.message,n.is_read,n.created_at AS submitted_at,u.name AS assigned_name,(SELECT COUNT(*) FROM admin_notifications r WHERE r.type='feedback_reply' AND r.reply_to_id=n.id AND r.deleted_at IS NULL) AS reply_count FROM feedback_cases c JOIN admin_notifications n ON n.id=c.notification_id LEFT JOIN users u ON u.id=c.assigned_to WHERE ".implode(' AND ',$where).' ORDER BY FIELD(c.priority,\'Critical\',\'High\',\'Medium\',\'Low\'), c.created_at DESC LIMIT 250';
$stmt=$pdo->prepare($sql); $stmt->execute($params); $cases=$stmt->fetchAll();
$stats=['total'=>0,'new'=>0,'progress'=>0,'resolved'=>0];
if($admin){$s=$pdo->query("SELECT COUNT(*) total,SUM(status='New') new_count,SUM(status IN ('Under Review','In Progress')) progress_count,SUM(status IN ('Resolved','Closed')) resolved_count FROM feedback_cases c JOIN admin_notifications n ON n.id=c.notification_id WHERE n.type='feedback' AND n.deleted_at IS NULL")->fetch(); $stats=['total'=>(int)$s['total'],'new'=>(int)$s['new_count'],'progress'=>(int)$s['progress_count'],'resolved'=>(int)$s['resolved_count']];}
$admins=$admin?$pdo->query("SELECT id,name FROM users WHERE role='Administrator' AND active=1 ORDER BY name")->fetchAll():[];
$history=[];$attachments=[];$replies=[];
foreach($cases as &$case){$hq=$pdo->prepare('SELECT h.*,u.name AS actor_name FROM feedback_history h LEFT JOIN users u ON u.id=h.changed_by WHERE h.feedback_case_id=? ORDER BY h.created_at DESC LIMIT 12');$hq->execute([$case['id']]);$history[$case['id']]=$hq->fetchAll();$aq=$pdo->prepare('SELECT id,original_name,mime_type,file_size,created_at FROM feedback_attachments WHERE feedback_case_id=? ORDER BY created_at DESC');$aq->execute([$case['id']]);$attachments[$case['id']]=$aq->fetchAll();$rq=$pdo->prepare("SELECT sender_name,sender_role,message,created_at FROM admin_notifications WHERE type='feedback_reply' AND reply_to_id=? AND deleted_at IS NULL ORDER BY created_at ASC");$rq->execute([$case['notification_id']]);$replies[$case['id']]=$rq->fetchAll();}
unset($case);
page_header('Feedback Management','feedback'); show_flash();
?>
<div class="gw-breadcrumb"><span class="material-symbols-outlined">home</span><span>Great Solomon Manpower Services Inc.</span><span>/</span><strong>Feedback Management</strong></div>
<section class="gw-hero dashboard-hero"><div><div class="eyebrow">CORE TRANSACTION 4</div><div class="dashboard-title-brand"><div class="brand-logo-white dashboard-logo-wrap"><img src="../../assets/logo2.svg" alt="Great Solomon Manpower Services Inc. logo" class="brand-logo-image"></div><h1>Feedback Management</h1></div><p><?= $admin?'Review staff feedback, assign ownership, track resolution, and monitor service quality.':'Track your submitted feedback, replies, and resolution progress.' ?></p></div></section>
<?php if($admin): ?><section class="gw-stats"><div class="gw-stat"><div class="gw-stat-top"><span class="gw-stat-label">Total Feedback</span><div class="gw-stat-icon"><span class="material-symbols-outlined">forum</span></div></div><div class="gw-stat-value"><?=$stats['total']?></div><div class="gw-stat-meta">All active feedback records</div></div><div class="gw-stat"><div class="gw-stat-top"><span class="gw-stat-label">New</span><div class="gw-stat-icon"><span class="material-symbols-outlined">mark_email_unread</span></div></div><div class="gw-stat-value"><?=$stats['new']?></div><div class="gw-stat-meta warning">Awaiting initial review</div></div><div class="gw-stat"><div class="gw-stat-top"><span class="gw-stat-label">In Progress</span><div class="gw-stat-icon"><span class="material-symbols-outlined">pending_actions</span></div></div><div class="gw-stat-value"><?=$stats['progress']?></div><div class="gw-stat-meta">Under review or active work</div></div><div class="gw-stat"><div class="gw-stat-top"><span class="gw-stat-label">Resolved / Closed</span><div class="gw-stat-icon"><span class="material-symbols-outlined">task_alt</span></div></div><div class="gw-stat-value"><?=$stats['resolved']?></div><div class="gw-stat-meta positive">Completed feedback cases</div></div></section><?php endif; ?>
<section class="gw-panel feedback-management-panel"><div class="gw-panel-head"><div><h2><?=$admin?'Feedback queue':'My feedback history'?></h2><span><?=$admin?'Search and filter staff feedback. Select a case to update its lifecycle.':'Review status, replies, and resolution details for your submissions.'?></span></div><?php if($admin): ?><a class="gw-btn secondary" href="?export=csv"><span class="material-symbols-outlined">download</span> Export CSV</a><?php endif; ?></div>
<form method="get" class="feedback-filter-form"><input type="search" name="q" value="<?=e($search)?>" placeholder="Search feedback, staff, or reference number"><select name="status"><option value="">All statuses</option><?php foreach(['New','Under Review','In Progress','Resolved','Closed'] as $v): ?><option <?= $status===$v?'selected':'' ?>><?=e($v)?></option><?php endforeach; ?></select><select name="category"><option value="">All categories</option><?php foreach(['System Bug','Feature Request','Workplace Concern','Security Concern','General Suggestion','Other'] as $v): ?><option <?= $category===$v?'selected':'' ?>><?=e($v)?></option><?php endforeach; ?></select><button class="gw-btn primary"><span class="material-symbols-outlined">filter_alt</span> Apply</button><a class="gw-btn secondary" href="index.php">Clear</a></form>
<?php if(!$cases): ?><p class="task-empty">No feedback records match these filters.</p><?php endif; ?>
<div class="feedback-case-list">
<?php foreach($cases as $c): ?><article class="feedback-case-card"><div class="feedback-case-top"><div><div class="feedback-reference">FB-<?=str_pad((string)$c['id'],5,'0',STR_PAD_LEFT)?> · <?=e($c['category'])?></div><h3><?=e($c['sender_name']?:'Staff member')?> <span>· <?=e($c['sender_role']?:'Staff')?></span></h3><div class="feedback-case-meta">Submitted <?=e($c['submitted_at'])?> · <?= (int)$c['reply_count'] ?> replies</div></div><div class="feedback-status-stack"><span class="feedback-pill status-<?=strtolower(str_replace(' ','-',e($c['status'])))?>"><?=e($c['status'])?></span><span class="feedback-pill priority-<?=strtolower(e($c['priority']))?>"><?=e($c['priority'])?> priority</span></div></div><div class="feedback-case-message"><?=nl2br(e(preg_replace('/\n\nCategory: .*? \| Priority: .*$/s','',(string)$c['message'])))?></div>
<?php if($admin): ?>
<div class="feedback-admin-actions" aria-label="Feedback actions">
<button type="button" class="gw-btn secondary feedback-view-trigger" aria-expanded="false" data-target="feedback-view-<?=$c['id']?>"><span class="material-symbols-outlined">visibility</span> View details</button>
<button type="button" class="gw-btn primary feedback-reply-trigger" aria-controls="feedback-conversation-<?=$c['id']?>" aria-expanded="false" data-target="feedback-conversation-<?=$c['id']?>"><span class="material-symbols-outlined">reply</span> Reply</button>
</div>
<section class="feedback-detail-panel" id="feedback-view-<?=$c['id']?>" hidden>
<div class="feedback-detail-grid"><div><span class="feedback-detail-label">Reference</span><strong>FB-<?=str_pad((string)$c['id'],5,'0',STR_PAD_LEFT)?></strong></div><div><span class="feedback-detail-label">Submitted by</span><strong><?=e($c['sender_name']?:'Staff member')?> · <?=e($c['sender_role']?:'Staff')?></strong></div><div><span class="feedback-detail-label">Submitted on</span><strong><?=e($c['submitted_at'])?></strong></div><div><span class="feedback-detail-label">Assigned administrator</span><strong><?=e($c['assigned_name']?:'Unassigned')?></strong></div></div>
<div class="feedback-detail-label">Full feedback message</div><div class="feedback-detail-message"><?=nl2br(e(preg_replace('/\n\nCategory: .*? \| Priority: .*$/s','',(string)$c['message'])))?></div>
</section>
<?php endif; ?>
<?php if($admin): ?><form method="post" class="feedback-case-controls"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="case_id" value="<?=$c['id']?>"><input type="hidden" name="action" value="update_case"><label>Status<select name="status"><?php foreach(['New','Under Review','In Progress','Resolved','Closed'] as $v): ?><option <?= $c['status']===$v?'selected':'' ?>><?=e($v)?></option><?php endforeach; ?></select></label><label>Priority<select name="priority"><?php foreach(['Low','Medium','High','Critical'] as $v): ?><option <?= $c['priority']===$v?'selected':'' ?>><?=e($v)?></option><?php endforeach; ?></select></label><label>Category<select name="category"><?php foreach(['System Bug','Feature Request','Workplace Concern','Security Concern','General Suggestion','Other'] as $v): ?><option <?= $c['category']===$v?'selected':'' ?>><?=e($v)?></option><?php endforeach; ?></select></label><label>Assign to<select name="assigned_to"><option value="0">Unassigned</option><?php foreach($admins as $a): ?><option value="<?=$a['id']?>" <?= (int)$c['assigned_to']===(int)$a['id']?'selected':'' ?>><?=e($a['name'])?></option><?php endforeach; ?></select></label><label>Due date<input type="date" name="due_date" value="<?=e($c['due_date']??'')?>"></label><button class="gw-btn primary"><span class="material-symbols-outlined">save</span> Save changes</button></form><?php else: ?><div class="feedback-staff-status"><span>Status: <strong><?=e($c['status'])?></strong></span><span>Priority: <strong><?=e($c['priority'])?></strong></span><?php if($c['assigned_name']): ?><span>Assigned to: <strong><?=e($c['assigned_name'])?></strong></span><?php endif; ?><?php if(in_array($c['status'],['Resolved','Closed'],true)): ?><form method="post" class="feedback-rate-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="case_id" value="<?=$c['id']?>"><input type="hidden" name="action" value="rate"><label>Rate resolution<select name="rating" required><option value="">Choose stars</option><?php for($r=5;$r>=1;$r--): ?><option value="<?=$r?>" <?= (int)$c['satisfaction_rating']===$r?'selected':'' ?>><?=$r?> star<?=$r===1?'':'s'?></option><?php endfor; ?></select></label><input name="rating_comment" maxlength="1000" value="<?=e($c['satisfaction_comment']??'')?>" placeholder="Optional comment"><button class="gw-btn secondary">Save rating</button></form><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="case_id" value="<?=$c['id']?>"><input type="hidden" name="action" value="reopen"><button class="gw-btn secondary">Reopen issue</button></form><?php endif; ?></div><?php if(!in_array($c['status'],['Resolved','Closed'],true)): ?><form method="post" enctype="multipart/form-data" class="feedback-attachment-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="case_id" value="<?=$c['id']?>"><input type="hidden" name="action" value="upload"><label>Attach evidence (PNG, JPG, PDF, TXT; max 5 MB)<input type="file" name="attachment" accept=".png,.jpg,.jpeg,.pdf,.txt" required></label><button class="gw-btn secondary">Upload attachment</button></form><?php endif; ?><?php endif; ?>
<details class="feedback-history feedback-conversation" id="feedback-conversation-<?=$c['id']?>"><summary>Conversation (<?=count($replies[$c['id']]??[])?> replies)</summary><?php foreach($replies[$c['id']]??[] as $reply): ?><div class="feedback-history-row"><strong><?=e($reply['sender_name']?:'Administrator')?> · <?=e($reply['sender_role']?:'')?></strong><span><?=e($reply['created_at'])?></span><p><?=e($reply['message'])?></p></div><?php endforeach; ?><?php if($admin): ?><form method="post" action="<?=e(url('/includes/feedback.php'))?>" class="feedback-inline-reply"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="return_to" value="/modules/feedback/index.php"><input type="hidden" name="action" value="reply"><input type="hidden" name="notification_id" value="<?= (int)$c['notification_id'] ?>"><label>Reply to staff<textarea name="feedback" maxlength="3000" rows="3" required placeholder="Write an update or ask for more information..."></textarea></label><button class="gw-btn secondary">Send reply</button></form><?php endif; ?></details>
<?php if(!empty($attachments[$c['id']])): ?><div class="feedback-attachments"><strong>Attachments</strong><?php foreach($attachments[$c['id']] as $a): ?><a href="attachment.php?id=<?= (int)$a['id'] ?>"><?=e($a['original_name'])?></a><span> · <?=number_format((int)$a['file_size']/1024,1)?> KB</span><?php endforeach; ?></div><?php endif; ?>
<details class="feedback-history"><summary>Activity history (<?=count($history[$c['id']]??[])?>)</summary><?php foreach($history[$c['id']]??[] as $h): ?><div class="feedback-history-row"><strong><?=e($h['action'])?></strong><span><?=e($h['actor_name']?:'System')?> · <?=e($h['created_at'])?></span><p><?=e($h['details']??'')?></p></div><?php endforeach; ?></details>
</article><?php endforeach; ?></div></section>
<?php if($admin): ?><section class="gw-panel" style="margin-top:20px"><div class="gw-panel-head"><div><h2>Feedback insights</h2><span>Current active records</span></div><span class="material-symbols-outlined">query_stats</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Category</th><th>Count</th><th>Average rating</th></tr></thead><tbody><?php $insights=$pdo->query("SELECT c.category,COUNT(*) AS total,ROUND(AVG(c.satisfaction_rating),2) AS avg_rating FROM feedback_cases c JOIN admin_notifications n ON n.id=c.notification_id WHERE n.type='feedback' AND n.deleted_at IS NULL GROUP BY c.category ORDER BY total DESC")->fetchAll(); foreach($insights as $i): ?><tr><td><?=e($i['category'])?></td><td><?=e($i['total'])?></td><td><?=$i['avg_rating']===null?'Not rated':e($i['avg_rating']).' / 5'?></td></tr><?php endforeach; ?><?php if(!$insights): ?><tr><td colspan="3">No feedback analytics available yet.</td></tr><?php endif; ?></tbody></table></div></section><?php endif; ?>
<?php if($admin): ?>
<script>
document.querySelectorAll('.feedback-view-trigger').forEach(function(button){
  button.addEventListener('click',function(){
    var panel=document.getElementById(button.dataset.target);
    if(!panel)return;
    var opening=panel.hidden;
    panel.hidden=!opening;
    button.setAttribute('aria-expanded',opening?'true':'false');
    button.innerHTML=opening?'<span class="material-symbols-outlined">visibility_off</span> Hide details':'<span class="material-symbols-outlined">visibility</span> View details';
    if(opening)panel.scrollIntoView({behavior:'smooth',block:'nearest'});
  });
});
document.querySelectorAll('.feedback-reply-trigger').forEach(function(button){
  button.addEventListener('click',function(){
    var conversation=document.getElementById(button.dataset.target);
    if(!conversation)return;
    conversation.open=true;
    button.setAttribute('aria-expanded','true');
    conversation.scrollIntoView({behavior:'smooth',block:'center'});
    var textarea=conversation.querySelector('textarea[name="feedback"]');
    if(textarea)textarea.focus({preventScroll:true});
  });
});
</script>
<?php endif; ?>
<?php page_footer(); ?>
