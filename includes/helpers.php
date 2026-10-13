<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/auth.php';
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf_token'];
}
function csrf_valid(?string $token = null): bool {
    if ($token === null) $token = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null;
    return is_string($token) && isset($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $token);
}
function flash(string $type, string $message): void { $_SESSION['flash']=['type'=>$type,'message'=>$message]; }
function show_flash(): void { if (!empty($_SESSION['flash'])) { $f=$_SESSION['flash']; unset($_SESSION['flash']); echo '<div class="notice '.e($f['type']).'">'.e($f['message']).'</div>'; } }
function audit(string $module,string $action,string $details=''): void { try { $u=current_user(); $stmt=db()->prepare('INSERT INTO audit_logs(user_id,module,action,details) VALUES(?,?,?,?)'); $stmt->execute([$u['id']??null,$module,$action,$details]); } catch(Throwable $e) {} }
/**
 * Record authentication history without allowing an optional audit write to block a valid login.
 * This also keeps older deployments with a narrower login_history schema usable during migration.
 */
function safe_login_history(?int $userId, string $email, string $status, ?string $ipAddress = null, string $userAgent = ''): void {
    try {
        $stmt = db()->prepare('INSERT INTO login_history(user_id,email,status,ip_address,user_agent,login_at) VALUES(?,?,?,?,?,NOW())');
        $stmt->execute([$userId, $email, $status, $ipAddress, substr($userAgent, 0, 500)]);
    } catch (Throwable $e) {
        error_log('CT4 login history write failed ['.get_class($e).']: '.$e->getMessage());
    }
}
function base_url(): string { return app_base_path(); }
function url(string $path): string { return app_url($path); }
function redirect(string $path): never { header('Location: '.url($path)); exit; }
function admin_feedback_notifications(): array {
    try {
        $u = current_user();
        if (!$u || ($u['role'] ?? '') !== 'Administrator') return [];
        $stmt = db()->prepare("SELECT n.id, n.type, n.sender_name, n.sender_role, n.sender_user_id, n.reply_to_id, n.title, n.message, n.is_read, n.created_at,
            EXISTS(SELECT 1 FROM admin_notifications r WHERE r.type='feedback_reply' AND r.reply_to_id=n.id AND r.deleted_at IS NULL) AS has_reply
            FROM admin_notifications n
            WHERE n.deleted_at IS NULL AND (n.type='feedback' OR (n.type='data_transfer' AND (n.user_id=? OR n.user_id IS NULL)))
            ORDER BY n.created_at DESC, n.id DESC LIMIT 50");
        $stmt->execute([(int)($u['id'] ?? 0)]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('Admin notification query failed: ' . $e->getMessage());
        return [];
    }
}
function staff_transfer_notifications(): array {
    try {
        $u = current_user();
        if (!$u || ($u['role'] ?? '') !== 'Staff') return [];
        $q = db()->prepare("SELECT id, type, sender_name, sender_role, sender_user_id, reply_to_id, title, message, is_read, created_at
            FROM admin_notifications
            WHERE deleted_at IS NULL AND ((user_id=? AND type IN ('data_transfer','feedback_reply','file_release')) OR (user_id IS NULL AND type='data_transfer'))
            ORDER BY created_at DESC, id DESC LIMIT 50");
        $q->execute([(int)$u['id']]);
        return $q->fetchAll();
    } catch (Throwable $e) {
        error_log('Staff notification query failed: ' . $e->getMessage());
        return [];
    }
}
function json_for_inline_script($value): string { return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'null'; }
function page_header(string $title,string $section=''): void {
$u=current_user();
$adminNotifications = (($u['role'] ?? '') === 'Administrator') ? admin_feedback_notifications() : [];
$staffNotifications = (($u['role'] ?? '') === 'Staff') ? staff_transfer_notifications() : [];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> — Great Solomon Manpower Services Inc.</title><link rel="stylesheet" href="<?=e(url('/style.css?v='.substr(sha1_file(__DIR__.'/../style.css'),0,12)))?>"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&family=Material+Symbols+Outlined:FILL@0..1&display=swap" rel="stylesheet"></head><body><div id="sidebar-backdrop"></div><aside id="sidebar" class="gw-sidebar"><div class="gw-brand"><div class="brand-logo-white sidebar-logo-wrap"><img src="<?=e(url('/assets/logo2.svg'))?>" alt="Great Solomon Manpower Services Inc. logo" class="brand-logo-image"></div><div class="gw-brand-copy"><div class="gw-brand-title">Great Solomon Manpower Services Inc.</div><div class="gw-brand-subtitle">Governance &amp; Safety</div></div></div><div class="gw-sidebar-section">CORE TRANSACTION 4</div><div style="margin:0 20px 12px;height:1px;background:rgba(255,255,255,.12)"></div><nav class="gw-nav"><?php if (is_records_entry_account($u)): ?>
<a class="module-link" href="<?=e(url('/records_entry_dashboard.php'))?>"><button class="<?= $section==='entry-dashboard'?'active':'' ?>"><span class="material-symbols-outlined">dashboard</span><span>Records Entry Dashboard</span></button></a>
<a class="module-link" href="<?=e(url('/modules/health_safety/add_health_record.php'))?>"><button class="<?= $section==='health'?'active':'' ?>"><span class="material-symbols-outlined">medical_information</span><span>Add Employee Health Record</span></button></a>
<a class="module-link" href="<?=e(url('/modules/asset_equipment/register_asset.php'))?>"><button class="<?= $section==='assets'?'active':'' ?>"><span class="material-symbols-outlined">inventory_2</span><span>Register Equipment / Asset</span></button></a>
<a class="module-link" href="<?=e(url('/modules/legal_compliance/manage_employee_documents.php'))?>"><button class="<?= $section==='legal'?'active':'' ?>"><span class="material-symbols-outlined">upload_file</span><span>Employee Document Upload</span></button></a>
<?php else: ?><a class="module-link" href="<?=e(url('/dashboard.php'))?>"><button class="<?= $section==='dashboard'?'active':'' ?>"><span class="material-symbols-outlined">dashboard</span><span>Reports, Analysis &amp; Dashboard</span></button></a><a class="module-link" href="<?=e(url('/ai_assistant.php'))?>"><button class="<?= $section==='ai'?'active':'' ?>"><span class="material-symbols-outlined">auto_awesome</span><span>AI System Assistant</span><span class="nav-number">AI</span></button></a><a class="module-link" href="<?=e(url('/modules/health_safety/index.php'))?>"><button class="<?= $section==='health'?'active':'' ?>"><span class="material-symbols-outlined">health_and_safety</span><span>Health, Safety &amp; Welfare</span><span class="nav-number">1</span></button></a><a class="module-link" href="<?=e(url('/modules/legal_compliance/index.php'))?>"><button class="<?= $section==='legal'?'active':'' ?>"><span class="material-symbols-outlined">gavel</span><span>Legal &amp; Compliance</span><span class="nav-number">2</span></button></a><?php if (($u['role'] ?? '') === 'Administrator'): ?><a class="module-link" href="<?=e(url('/modules/system_admin_security/index.php'))?>"><button class="<?= $section==='security'?'active':'' ?>"><span class="material-symbols-outlined">admin_panel_settings</span><span>System Administration &amp; Security</span><span class="nav-number">3</span></button></a><?php endif; ?><a class="module-link" href="<?=e(url('/modules/asset_equipment/index.php'))?>"><button class="<?= $section==='assets'?'active':'' ?>"><span class="material-symbols-outlined">inventory_2</span><span>Asset &amp; Equipment Issuance</span><span class="nav-number">4</span></button></a><a class="module-link" href="<?=e(url('/tasks.php'))?>"><button class="<?= $section==='tasks'?'active':'' ?>"><span class="material-symbols-outlined">task_alt</span><span>Tasks &amp; Approvals</span><span class="nav-number">5</span></button></a><a class="module-link" href="<?=e(url('/modules/feedback/index.php'))?>"><button class="<?= $section==='feedback'?'active':'' ?>"><span class="material-symbols-outlined">forum</span><span>Feedback Management</span></button></a><?php endif; ?></nav><div class="gw-sidebar-footer"><div class="gw-status-dot"></div><div><strong>Welcome back, <?=e($u['name']??'User')?></strong><span><?=e($u['role']??'Staff')?></span></div></div></aside><div class="gw-shell"><header class="gw-topbar"><div class="gw-topbar-left"><button id="sidebarToggle" class="icon-btn" title="Toggle sidebar"><span class="material-symbols-outlined">menu_open</span></button><div class="gw-topbar-title"><span class="eyebrow">SERVICE MANAGEMENT &amp; ENTERPRISE RESOURCE SYSTEM</span><strong><?=e($title)?></strong></div></div><div class="gw-user user-menu-wrap">
<button type="button" class="gw-user-button" onclick="toggleUserMenu()" aria-expanded="false">
<?php $hasNotificationUnread = (($u['role'] ?? '') === 'Administrator') ? (bool)count(array_filter($adminNotifications,fn($n)=>(int)$n['is_read']===0)) : (bool)count(array_filter($staffNotifications,fn($n)=>(int)$n['is_read']===0)); ?>
<?php if($hasNotificationUnread): ?><span class="notification-red-dot" aria-label="New notification"></span><?php endif; ?>
<div class="gw-avatar"><?=e(strtoupper(substr((string)($u['name']??'AU'),0,2)))?></div>
<div class="gw-user-copy"><strong><?=e($u['name']??'Admin User')?></strong><span><?=e($u['role']??'Administrator')?></span></div>
<span class="material-symbols-outlined user-chevron">expand_more</span>
</button>
<div id="userMenu" class="user-dropdown">
<?php if (($u['role'] ?? '') === 'Administrator'): ?>
<button type="button" onclick="showNotificationModal()"><span class="material-symbols-outlined">notifications</span>Notifications<?php $unread=count(array_filter($adminNotifications,fn($n)=>(int)$n['is_read']===0)); if($unread): ?><span class="notification-badge"><?=e($unread)?></span><?php endif; ?></button>
<?php elseif (($u['role'] ?? '') === 'Staff'): ?>
<button type="button" onclick="showNotificationModal()"><span class="material-symbols-outlined">notifications</span>Notifications<?php $unread=count(array_filter($staffNotifications,fn($n)=>(int)$n['is_read']===0)); if($unread): ?><span class="notification-badge"><?=e($unread)?></span><?php endif; ?></button>
<?php endif; ?>
<button type="button" onclick="showDataStorageModal()"><span class="material-symbols-outlined">folder_data</span>Data Storage</button><?php if (($u['role'] ?? '') === 'Administrator'): ?><button type="button" onclick="showArchiveModal()"><span class="material-symbols-outlined">archive</span>Archive</button><?php endif; ?>
<?php if (($u['role'] ?? '') === 'Staff'): ?><button type="button" onclick="showFeedbackModal()"><span class="material-symbols-outlined">feedback</span>Feedback</button><?php endif; ?>
<button type="button" onclick="showTermsModal()"><span class="material-symbols-outlined">gavel</span>Terms and Conditions</button>
<button type="button" onclick="showLogoutModal()"><span class="material-symbols-outlined">logout</span>Logout</button>
</div>
</div></header><main class="gw-main"><div class="page-shell">
<?php }
function page_footer(): void { $u=current_user() ?: []; $feedbackSent=!empty($_SESSION['feedback_sent']); unset($_SESSION['feedback_sent']); $path=(string)($_SERVER['SCRIPT_NAME']??''); $showModuleTop=str_contains($path,'/modules/health_safety/') || str_contains($path,'/modules/legal_compliance/') || str_contains($path,'/modules/system_admin_security/') || str_contains($path,'/modules/asset_equipment/'); echo '</div></main></div>'.($showModuleTop ? '<button id="moduleTopButton" class="module-top-button" type="button" aria-label="Go to top" title="Go to top"><span class="material-symbols-outlined">arrow_upward</span></button>' : '').'<div id="modalRoot"></div><script>window.APP_BASE='.json_for_inline_script(base_url()).';window.CURRENT_USER='.json_for_inline_script(["name"=>(string)($u["name"]??"User"),"role"=>(string)($u["role"]??"Staff")]).';window.ADMIN_NOTIFICATIONS='.json_for_inline_script((($u["role"]??"") === "Administrator") ? admin_feedback_notifications() : []).';window.STAFF_NOTIFICATIONS='.json_for_inline_script((($u["role"]??"") === "Staff") ? staff_transfer_notifications() : []).';window.FEEDBACK_SENT='.json_for_inline_script($feedbackSent).';window.CSRF_TOKEN='.json_for_inline_script(csrf_token()).';window.CURRENT_PATH='.json_for_inline_script($_SERVER['REQUEST_URI']??'').';</script><script src="'.e(url('/app.js?v='.substr(sha1_file(__DIR__.'/../app.js'),0,12))).'"></script></body></html>'; }
function module_card(string $href,string $icon,string $title,string $desc): void { echo '<a class="module-link" href="'.e($href).'"><div class="gw-sub-card"><div class="mini-icon"><span class="material-symbols-outlined">'.e($icon).'</span></div><strong>'.e($title).'</strong><span>'.e($desc).'</span><span class="arrow"><span class="material-symbols-outlined">arrow_forward</span></span></div></a>'; }
