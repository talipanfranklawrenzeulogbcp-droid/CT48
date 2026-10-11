<?php
declare(strict_types=1);
require_once __DIR__.'/helpers.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); header('Allow: POST'); echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit;
}
if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(419); echo json_encode(['ok'=>false,'error'=>'Your session token expired. Refresh the page and try again.']); exit;
}
$u = current_user();
$id = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['action'] ?? 'read');
try {
    if ($id <= 0) throw new RuntimeException('Invalid notification.');
    $pdo = db();
    if (($u['role'] ?? '') === 'Administrator' && $action === 'delete') {
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare("UPDATE admin_notifications SET deleted_at=NOW() WHERE id=? AND type='feedback' AND deleted_at IS NULL");
            $q->execute([$id]);
            $changed = $q->rowCount();
            if ($changed > 0) {
                $replies = $pdo->prepare("UPDATE admin_notifications SET deleted_at=NOW() WHERE type='feedback_reply' AND reply_to_id=? AND deleted_at IS NULL");
                $replies->execute([$id]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        if ($changed < 1) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Feedback was not found or was already removed.']); exit; }
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action !== 'read') {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Action not permitted.']); exit;
    }

    if (($u['role'] ?? '') === 'Administrator') {
        // Administrators share the feedback inbox, but transfer notifications are private or broadcast.
        $visibleSql = "(type='feedback' OR (type='data_transfer' AND (user_id=? OR user_id IS NULL)))";
        $params = [$id, (int)($u['id'] ?? 0)];
    } elseif (($u['role'] ?? '') === 'Staff') {
        $visibleSql = "((user_id=? AND type IN ('feedback_reply','data_transfer','file_release')) OR (user_id IS NULL AND type='data_transfer'))";
        $params = [$id, (int)($u['id'] ?? 0)];
    } else {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Action not permitted.']); exit;
    }

    $update = $pdo->prepare("UPDATE admin_notifications SET is_read=1 WHERE id=? AND deleted_at IS NULL AND $visibleSql");
    $update->execute($params);
    if ($update->rowCount() < 1) {
        // Updating an already-read row is idempotent, not an error.
        $check = $pdo->prepare("SELECT id FROM admin_notifications WHERE id=? AND deleted_at IS NULL AND $visibleSql LIMIT 1");
        $check->execute($params);
        if (!$check->fetchColumn()) {
            http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Notification was not found or is not available to this account.']); exit;
        }
    }
    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    error_log('Notification action failed: '.$e->getMessage());
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Unable to update the notification.']);
}
