<?php
declare(strict_types=1);
require_once __DIR__.'/helpers.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit; }
if (!csrf_valid($_POST['csrf_token'] ?? null)) { http_response_code(419); echo json_encode(['ok'=>false,'error'=>'Your session token expired. Refresh the page and try again.']); exit; }
$u=current_user(); $id=(int)($_POST['id']??0); $action=(string)($_POST['action']??'read');
try {
    if($id<=0) throw new RuntimeException('Invalid notification.');
    $pdo=db(); $changed=0;
    if(($u['role']??'')==='Administrator' && $action==='delete') {
        $pdo->beginTransaction();
        try {
            $q=$pdo->prepare("UPDATE admin_notifications SET deleted_at=NOW() WHERE id=? AND type='feedback' AND deleted_at IS NULL");
            $q->execute([$id]); $changed=$q->rowCount();
            if($changed) { $replies=$pdo->prepare("UPDATE admin_notifications SET deleted_at=NOW() WHERE type='feedback_reply' AND reply_to_id=? AND deleted_at IS NULL"); $replies->execute([$id]); }
            $pdo->commit();
        } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    } elseif(($u['role']??'')==='Administrator' && $action==='read') {
        $q=$pdo->prepare("UPDATE admin_notifications SET is_read=1 WHERE id=? AND type IN ('feedback','data_transfer') AND deleted_at IS NULL AND (user_id=? OR type='feedback')");
        $q->execute([$id,(int)$u['id']]); $changed=$q->rowCount();
    } elseif(($u['role']??'')==='Staff' && $action==='read') {
        $q=$pdo->prepare("UPDATE admin_notifications SET is_read=1 WHERE id=? AND user_id=? AND type IN ('feedback_reply','data_transfer','file_release') AND deleted_at IS NULL");
        $q->execute([$id,(int)$u['id']]); $changed=$q->rowCount();
    } else { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Action not permitted.']); exit; }
    if($changed===0) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Notification was not found or is no longer available.']); exit; }
    echo json_encode(['ok'=>true]);
} catch(Throwable $e) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Unable to update notification.']); }
