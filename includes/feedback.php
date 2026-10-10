<?php
require_once __DIR__.'/helpers.php';
require_login();
if($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); header('Allow: POST'); redirect('/dashboard.php'); }
if (!csrf_valid($_POST['csrf_token'] ?? null)) { flash('error','Your session token expired. Please reopen the form and try again.'); redirect('/dashboard.php'); }
$u=current_user();
$action=(string)($_POST['action']??'send');
// Administrators may respond to employee feedback, but cannot submit feedback themselves.
if (($u['role'] ?? '') === 'Administrator' && $action !== 'reply') {
    http_response_code(403);
    flash('error','Administrator accounts can view and reply to employee feedback, but cannot submit feedback.');
    redirect('/dashboard.php');
}
if (!in_array(($u['role'] ?? ''), ['Administrator','Staff'], true)) {
    http_response_code(403);
    flash('error','Your account is not permitted to use feedback.');
    redirect('/dashboard.php');
}
$name=trim((string)($u['name']??'')); $role=trim((string)($u['role']??'')); $feedback=trim((string)($_POST['feedback']??''));
$feedbackLength=function_exists('mb_strlen') ? mb_strlen($feedback, 'UTF-8') : strlen($feedback);
$category=trim((string)($_POST['category']??'General Suggestion')); $priority=trim((string)($_POST['priority']??'Medium'));
$allowedCategories=['System Bug','Feature Request','Workplace Concern','Security Concern','General Suggestion','Other'];
if(!in_array($category,$allowedCategories,true)) $category='General Suggestion';
if(!in_array($priority,['Low','Medium','High','Critical'],true)) $priority='Medium';
$returnTo=trim((string)($_POST['return_to']??''));
// Accept only a local absolute path; reject protocol-relative URLs and header injection.
if($returnTo==='' || $returnTo[0]!=='/' || str_starts_with($returnTo,'//') || preg_match('/[\r\n]/',$returnTo)){ $returnTo='/dashboard.php'; }
if ($feedbackLength > 3000) { flash('error','Feedback and replies must be 3,000 characters or fewer.'); redirect($returnTo); }

try {
    if($action==='reply'){
        if(($u['role']??'')!=='Administrator') throw new RuntimeException('Only an administrator can reply to feedback.');
        $notificationId=(int)($_POST['notification_id']??0);
        if($notificationId<=0 || $feedback==='') throw new RuntimeException('Please provide a reply.');
        $q=db()->prepare("SELECT id,sender_user_id,sender_name,sender_role FROM admin_notifications WHERE id=? AND type='feedback' AND deleted_at IS NULL LIMIT 1");
        $q->execute([$notificationId]); $original=$q->fetch(PDO::FETCH_ASSOC);
        if(!$original) throw new RuntimeException('The selected feedback could not be found.');
        $recipientId=(int)($original['sender_user_id']??0);
        if($recipientId<=0 && !empty($original['sender_name'])){
            // Legacy feedback may lack sender_user_id; resolve only an unambiguous active account.
            $find=db()->prepare("SELECT id FROM users WHERE name=? AND role='Staff' AND active=1 ORDER BY id LIMIT 2");
            $find->execute([$original['sender_name']]);
            $matches=$find->fetchAll(PDO::FETCH_COLUMN);
            if(count($matches)===1) $recipientId=(int)$matches[0];
        }
        if($recipientId<=0) throw new RuntimeException('The staff account for this feedback could not be found.');
        $senderId=(int)($u['id']??0) ?: null;
        $pdo=db();
        $pdo->beginTransaction();
        try {
            $stmt=$pdo->prepare("INSERT INTO admin_notifications (user_id,type,title,message,sender_name,sender_role,sender_user_id,reply_to_id) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$recipientId,'feedback_reply','Reply to Your Feedback',$feedback,$name,$role,$senderId,$notificationId]);
            $pdo->commit();
        } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        audit('System Administration & Security','Reply to Feedback',($original['sender_name']??'Staff').' — '.$feedback);
        $_SESSION['feedback_sent']=true;
        $_SESSION['feedback_action']='reply';
        flash('success','Your reply was sent to the staff member.');
        redirect($returnTo);
    }

    if($name===''||$role===''||$feedback===''){
        flash('error','Please enter your feedback. Your account details are attached automatically.');
        redirect($returnTo);
    }
    $pdo=db();
    $stmt=$pdo->query("SELECT id FROM users WHERE role='Administrator' AND active=1 ORDER BY id LIMIT 1");
    $adminId=$stmt->fetchColumn();
    if($adminId===false) throw new RuntimeException('No active administrator account is available to receive feedback.');
    $senderId=(int)($u['id']??0) ?: null;
    $insert=$pdo->prepare("INSERT INTO admin_notifications (user_id,type,title,message,sender_name,sender_role,sender_user_id,reply_to_id) VALUES (?,?,?,?,?,?,?,?)");
    $insert->execute([(int)$adminId,'feedback','New User Feedback',$feedback,$name,$role,$senderId,null]);
    $notificationId=(int)$pdo->lastInsertId();
    $case=$pdo->prepare("INSERT INTO feedback_cases (notification_id,category,priority,status) VALUES (?,?,?,'New')");
    $case->execute([$notificationId,$category,$priority]);
    $caseId=(int)$pdo->lastInsertId();
    $history=$pdo->prepare("INSERT INTO feedback_history (feedback_case_id,changed_by,action,details) VALUES (?,?,?,?)");
    $history->execute([$caseId,$senderId,'Submitted','Feedback submitted by '.$name]);
    audit('System Administration & Security','Submit Feedback',$name.' ('.$role.')');
    $_SESSION['feedback_sent']=true;
    $_SESSION['feedback_action']='send';
    flash('success','Your feedback was sent to the administrator.');
} catch(Throwable $e){
    error_log('CT4 feedback submission failed: '.$e->getMessage());
    flash('error','The feedback could not be processed. Please try again or contact the system administrator.');
}
redirect($returnTo);
