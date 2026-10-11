<?php
declare(strict_types=1);
require_once __DIR__.'/helpers.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
try {
    $u = current_user();
    if (($u['role'] ?? '') === 'Administrator') {
        $notifications = admin_feedback_notifications();
        $count = db()->prepare("SELECT COUNT(*) FROM admin_notifications WHERE is_read=0 AND deleted_at IS NULL AND (type='feedback' OR (type='data_transfer' AND (user_id=? OR user_id IS NULL)))");
        $count->execute([(int)($u['id'] ?? 0)]);
    } elseif (($u['role'] ?? '') === 'Staff') {
        $notifications = staff_transfer_notifications();
        $count = db()->prepare("SELECT COUNT(*) FROM admin_notifications WHERE is_read=0 AND deleted_at IS NULL AND ((user_id=? AND type IN ('feedback_reply','data_transfer','file_release')) OR (user_id IS NULL AND type='data_transfer'))");
        $count->execute([(int)($u['id'] ?? 0)]);
    } else {
        $notifications = [];
        $count = null;
    }
    $unread = $count ? (int)$count->fetchColumn() : 0;
    echo json_encode(['ok'=>true, 'unread'=>$unread, 'notifications'=>$notifications], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('Notification polling failed: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'unread'=>0, 'notifications'=>[]]);
}
