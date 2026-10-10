<?php
require_once __DIR__.'/includes/helpers.php';
require_login();
$pdo=db();
$user=current_user();
$isAdmin=($user['role']??'')==='Administrator';
$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_valid($_POST['csrf_token']??null)){ http_response_code(419); $notice='Your session token expired. Refresh the page and try again.'; }
    else {
        $action=(string)($_POST['action']??'');
        try {
            if($action==='create'){
                $title=trim((string)($_POST['title']??''));
                $description=trim((string)($_POST['description']??''));
                $priority=(string)($_POST['priority']??'Medium');
                $due=trim((string)($_POST['due_date']??''));
                $assigned=(int)($_POST['assigned_to']??0);
                if($title===''||strlen($title)>180) throw new RuntimeException('Enter a task title up to 180 characters.');
                if(!in_array($priority,['Low','Medium','High','Critical'],true)) throw new RuntimeException('Select a valid priority.');
                if($due!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$due)) throw new RuntimeException('Select a valid due date.');
                if(!$isAdmin) $assigned=(int)$user['id'];
                if($assigned>0){$check=$pdo->prepare("SELECT id FROM users WHERE id=? AND active=1");$check->execute([$assigned]);if(!$check->fetchColumn())throw new RuntimeException('The selected assignee is not active.');}else{$assigned=null;}
                $approval=$isAdmin?'Not Required':'Pending';
                $stmt=$pdo->prepare('INSERT INTO workflow_tasks(title,description,priority,approval_status,due_date,assigned_to,created_by) VALUES(?,?,?,?,?,?,?)');
                $stmt->execute([$title,$description,$priority,$approval,$due!==''?$due:null,$assigned,(int)$user['id']]);
                audit('Tasks & Approvals','Create Task',$title); $notice='Task created successfully.';
            } elseif($action==='status'){
                $id=(int)($_POST['id']??0);$status=(string)($_POST['status']??'');
                if(!in_array($status,['Pending','In Progress','Blocked','Completed','Cancelled'],true))throw new RuntimeException('Invalid task status.');
                $q=$pdo->prepare('SELECT * FROM workflow_tasks WHERE id=?');$q->execute([$id]);$task=$q->fetch();
                if(!$task)throw new RuntimeException('Task not found.');
                if(!$isAdmin && (int)$task['assigned_to']!==(int)$user['id'] && (int)$task['created_by']!==(int)$user['id']){http_response_code(403);throw new RuntimeException('You do not have permission to update this task.');}
                if(!$isAdmin && in_array($task['approval_status'],['Pending','Rejected'],true))throw new RuntimeException('This task must be approved by an administrator before its status can be changed.');
                $done=$status==='Completed'?date('Y-m-d H:i:s'):null;
                $pdo->prepare('UPDATE workflow_tasks SET status=?,completed_at=? WHERE id=?')->execute([$status,$done,$id]);
                audit('Tasks & Approvals','Update Task Status','#'.$id.' '.$status);$notice='Task status updated.';
            } elseif($action==='approval' && $isAdmin){
                $id=(int)($_POST['id']??0);$approval=(string)($_POST['approval_status']??'');
                if(!in_array($approval,['Approved','Rejected'],true))throw new RuntimeException('Invalid approval decision.');
                $q=$pdo->prepare('SELECT title FROM workflow_tasks WHERE id=?');$q->execute([$id]);$title=$q->fetchColumn();
                if(!$title)throw new RuntimeException('Task not found.');
                $pdo->prepare("UPDATE workflow_tasks SET approval_status=? WHERE id=? AND approval_status IN ('Pending','Approved','Rejected')")->execute([$approval,$id]);
                audit('Tasks & Approvals',$approval.' Task Request',(string)$title);$notice='Task request '.$approval.'.';
            } elseif($action==='delete' && $isAdmin){
                $id=(int)($_POST['id']??0);$q=$pdo->prepare('SELECT title FROM workflow_tasks WHERE id=?');$q->execute([$id]);$title=$q->fetchColumn();
                if(!$title)throw new RuntimeException('Task not found.');
                $pdo->prepare('DELETE FROM workflow_tasks WHERE id=?')->execute([$id]);audit('Tasks & Approvals','Delete Task',(string)$title);$notice='Task deleted.';
            } else { throw new RuntimeException('Action not permitted.'); }
        } catch(Throwable $e){ if(http_response_code()<400)http_response_code(400);$notice=$e instanceof PDOException?'Unable to save the task. Check the data and try again.':$e->getMessage(); }
    }
}
$users=$isAdmin?$pdo->query("SELECT id,name,email FROM users WHERE active=1 ORDER BY name")->fetchAll():[];
$statusFilter=(string)($_GET['status']??'');
$validStatuses=['Pending','In Progress','Blocked','Completed','Cancelled'];
$where=[];$params=[];
if(!$isAdmin){$where[]='(t.assigned_to=? OR t.created_by=?)';$params[]=(int)$user['id'];$params[]=(int)$user['id'];}
if(in_array($statusFilter,$validStatuses,true)){$where[]='t.status=?';$params[]=$statusFilter;}
$sql='SELECT t.*,a.name AS assignee,c.name AS creator FROM workflow_tasks t LEFT JOIN users a ON a.id=t.assigned_to LEFT JOIN users c ON c.id=t.created_by';
if($where)$sql.=' WHERE '.implode(' AND ',$where);
$sql.=" ORDER BY CASE WHEN t.status='Completed' THEN 1 ELSE 0 END, CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END,t.due_date ASC,t.created_at DESC LIMIT 300";
$stmt=$pdo->prepare($sql);$stmt->execute($params);$tasks=$stmt->fetchAll();
$stats=$pdo->query("SELECT COUNT(*) total,SUM(status='Pending') pending,SUM(status='In Progress') progress,SUM(status='Blocked') blocked,SUM(status='Completed') completed,SUM(due_date<CURDATE() AND status NOT IN ('Completed','Cancelled')) overdue FROM workflow_tasks")->fetch();
page_header('Tasks & Approvals','tasks');show_flash();
?>
<section class="gw-hero"><div><div class="eyebrow">CORE TRANSACTION 4 · WORKFLOW</div><h1>Tasks &amp; Approvals</h1><p>Assign work, monitor deadlines, and track completion across the organization.</p></div><div class="task-hero-mark"><span class="material-symbols-outlined">task_alt</span></div></section>
<?php if($notice!==''): ?><div class="notice <?=str_contains(strtolower($notice),'success')||str_contains(strtolower($notice),'updated')||str_contains(strtolower($notice),'created')?'success':'warning'?>" role="status"><?=e($notice)?></div><?php endif; ?>
<section class="gw-stats task-stats">
<div class="gw-stat"><div class="gw-stat-top"><span class="gw-stat-label">All Tasks</span><div class="gw-stat-icon"><span class="material-symbols-outlined">checklist</span></div></div><div class="gw-stat-value"><?=e($stats['total']??0)?></div></div>
<div class="gw-stat"><div class="gw-stat-top"><span class="gw-stat-label">Pending</span><div class="gw-stat-icon"><span class="material-symbols-outlined">schedule</span></div></div><div class="gw-stat-value"><?=e($stats['pending']??0)?></div></div>
<div class="gw-stat"><div class="gw-stat-top"><span class="gw-stat-label">In Progress</span><div class="gw-stat-icon"><span class="material-symbols-outlined">pending_actions</span></div></div><div class="gw-stat-value"><?=e($stats['progress']??0)?></div></div>
<div class="gw-stat"><div class="gw-stat-top"><span class="gw-stat-label">Overdue</span><div class="gw-stat-icon"><span class="material-symbols-outlined">warning</span></div></div><div class="gw-stat-value task-overdue-value"><?=e($stats['overdue']??0)?></div></div>
</section>
<div class="gw-content-grid task-content-grid">
<section class="gw-panel"><div class="gw-panel-head"><div><h2>Create a task</h2><span>Tasks are recorded with their creator and assignee.</span></div></div>
<form method="post" class="task-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create">
<label>Task title<input name="title" maxlength="180" required placeholder="e.g. Review monthly safety report"></label>
<label>Description<textarea name="description" rows="3" maxlength="5000" placeholder="Instructions, expected result, or notes"></textarea></label>
<div class="task-form-row"><label>Priority<select name="priority"><option>Low</option><option selected>Medium</option><option>High</option><option>Critical</option></select></label><label>Due date<input type="date" name="due_date" min="2000-01-01"></label></div>
<?php if($isAdmin): ?><label>Assign to<select name="assigned_to"><option value="0">Unassigned</option><?php foreach($users as $person): ?><option value="<?=e($person['id'])?>"><?=e($person['name'])?> — <?=e($person['email'])?></option><?php endforeach; ?></select></label><?php else: ?><p class="task-assignment-note">New tasks will be assigned to your account.</p><?php endif; ?>
<button class="gw-btn primary" type="submit"><span class="material-symbols-outlined">add_task</span>Create Task</button></form></section>
<section class="gw-panel"><div class="gw-panel-head"><div><h2>Task board</h2><span><?=count($tasks)?> task(s) shown, up to 300</span></div><form method="get" class="task-filter"><select name="status" aria-label="Filter task status"><option value="">All statuses</option><?php foreach($validStatuses as $status): ?><option value="<?=e($status)?>" <?=$statusFilter===$status?'selected':''?>><?=e($status)?></option><?php endforeach; ?></select><button class="gw-btn secondary" type="submit">Filter</button></form></div>
<div class="task-list"><?php foreach($tasks as $task): $overdue=!empty($task['due_date'])&&$task['due_date']<date('Y-m-d')&&!in_array($task['status'],['Completed','Cancelled'],true); ?><article class="task-item"><div class="task-item-main"><div class="task-title-row"><h3><?=e($task['title'])?></h3><span class="task-pill task-status-<?=strtolower(str_replace(' ','-',$task['status']))?>"><?=e($task['status'])?></span><?php if($task['approval_status']!=='Not Required'): ?><span class="task-pill task-approval-<?=strtolower($task['approval_status'])?>">Approval: <?=e($task['approval_status'])?></span><?php endif; ?></div><?php if($task['description']!==''): ?><p><?=nl2br(e($task['description']))?></p><?php endif; ?><div class="task-meta"><span><b>Priority:</b> <?=e($task['priority'])?></span><span><b>Assigned:</b> <?=e($task['assignee']??'Unassigned')?></span><span><b>Created by:</b> <?=e($task['creator']??'Former user')?></span><?php if($task['due_date']): ?><span class="<?=$overdue?'task-overdue':''?>"><b>Due:</b> <?=e($task['due_date'])?><?=$overdue?' · OVERDUE':''?></span><?php endif; ?></div></div><div class="task-actions"><?php if($isAdmin && $task['approval_status']==='Pending'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="approval"><input type="hidden" name="id" value="<?=e($task['id'])?>"><button class="gw-btn primary" name="approval_status" value="Approved" type="submit">Approve</button><button class="gw-btn danger" name="approval_status" value="Rejected" type="submit">Reject</button></form><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=e($task['id'])?>"><select name="status" aria-label="Update status for <?=e($task['title'])?>"><?php foreach($validStatuses as $status): ?><option value="<?=e($status)?>" <?=$task['status']===$status?'selected':''?>><?=e($status)?></option><?php endforeach; ?></select><button class="gw-btn secondary" type="submit">Update</button></form><?php if($isAdmin): ?><form method="post" onsubmit="return confirm('Delete this task permanently?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=e($task['id'])?>"><button class="gw-btn danger" type="submit">Delete</button></form><?php endif; ?></div></article><?php endforeach; ?><?php if(!$tasks): ?><div class="task-empty"><span class="material-symbols-outlined">task</span><strong>No tasks found</strong><p>Create a task or change the selected filter.</p></div><?php endif; ?></div></section></div>
<?php page_footer(); ?>
