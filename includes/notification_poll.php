<?php
declare(strict_types=1);
require_once __DIR__.'/helpers.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
try {
    $u=current_user();
    if(($u['role']??'')==='Administrator') {
        $q=db()->prepare("SELECT COUNT(*) FROM admin_notifications WHERE is_read=0 AND deleted_at IS NULL AND (type='feedback' OR (type='data_transfer' AND (user_id=? OR user_id IS NULL)))");
        $q->execute([(int)$u['id']]);
    } else {
        $q=db()->prepare("SELECT COUNT(*) FROM admin_notifications WHERE is_read=0 AND deleted_at IS NULL AND ((user_id=? AND type IN ('feedback_reply','data_transfer','file_release')) OR (user_id IS NULL AND type='data_transfer'))");
        $q->execute([(int)$u['id']]);
    }
    echo json_encode(['ok'=>true,'unread'=>(int)$q->fetchColumn()]);
} catch(Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'unread'=>0]);
}
