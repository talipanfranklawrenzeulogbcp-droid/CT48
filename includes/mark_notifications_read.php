<?php
require_once __DIR__.'/helpers.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit; }
if (!csrf_valid($_POST['csrf_token'] ?? null)) { http_response_code(419); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'Your session token expired']); exit; }
$u=current_user();
header('Content-Type: application/json; charset=utf-8');
try {
    if (($u['role'] ?? '') === 'Administrator') {
        $stmt=db()->prepare("UPDATE admin_notifications SET is_read=1 WHERE is_read=0 AND deleted_at IS NULL AND (type='feedback' OR (type='data_transfer' AND (user_id=? OR user_id IS NULL)))");
        $stmt->execute([(int)$u['id']]);
    } elseif (($u['role'] ?? '') === 'Staff') {
        $stmt=db()->prepare("UPDATE admin_notifications SET is_read=1 WHERE is_read=0 AND deleted_at IS NULL AND ((user_id=? AND type IN ('feedback_reply','data_transfer','file_release')) OR (user_id IS NULL AND type='data_transfer'))");
        $stmt->execute([(int)$u['id']]);
    } else {
        http_response_code(403); echo json_encode(['ok'=>false]); exit;
    }
    echo json_encode(['ok'=>true]);
} catch(Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false]);
}
