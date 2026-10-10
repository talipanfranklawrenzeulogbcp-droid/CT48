<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/service_client.php';
require_admin();

$user = current_user();
$svc = service('admin');
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_valid(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)) {
        flash('error', 'Your session security token expired. Refresh the page and try again.');
        redirect('/modules/system_admin_security/index.php');
    }
    try {
        $action = (string)($_POST['action'] ?? '');
        if (!in_array($action, ['add_user', 'edit_user', 'set_user_status'], true)) {
            throw new RuntimeException('Unsupported action.');
        }
        flash('success', $svc->handle($action, $_POST, $user));
    } catch (Throwable $e) {
        error_log('CT4 admin security action failed: '.$e->getMessage());
        flash('error', $e->getMessage());
    }
    redirect('/modules/system_admin_security/index.php');
}

try {
    $stats = $svc->stats();
    $users = $svc->users();
    $events = $svc->events(100);
    $logins = $svc->logins(100);
} catch (Throwable $e) {
    error_log('CT4 admin security page failed: '.$e->getMessage());
    http_response_code(500);
    $stats = ['users'=>0, 'logins'=>0];
    $users = $events = $logins = [];
    $error = 'Unable to load administration data. Check database connectivity and the server error log.';
}

page_header('System Administration & Security', 'security');
show_flash();
?>
<div class="gw-breadcrumb">
  <span class="material-symbols-outlined">home</span>
  <span>Core Transaction 4</span><span>/</span>
  <strong>System Administration &amp; Security</strong>
</div>
<section class="gw-hero">
  <div>
    <div class="eyebrow">MODULE 3</div>
    <h1>System Administration &amp; Security</h1>
    <p>Manage user access, account status, and recent security activity.</p>
  </div>
  <div class="gw-hero-actions">
    <a class="gw-btn secondary" href="<?=e(url('/dashboard.php'))?>"><span class="material-symbols-outlined">arrow_back</span>Back to Dashboard</a>
  </div>
</section>
<?php if ($error !== ''): ?><div class="notice error" role="alert"><?=e($error)?></div><?php endif; ?>
<section class="gw-stats">
  <article class="gw-stat"><div class="gw-stat-top"><div class="gw-stat-label">Active Users</div><div class="gw-stat-icon"><span class="material-symbols-outlined">group</span></div></div><div class="gw-stat-value"><?=e($stats['users'] ?? 0)?></div><div class="gw-stat-meta">Accounts currently enabled</div></article>
  <article class="gw-stat"><div class="gw-stat-top"><div class="gw-stat-label">Successful Logins</div><div class="gw-stat-icon"><span class="material-symbols-outlined">verified_user</span></div></div><div class="gw-stat-value"><?=e($stats['logins'] ?? 0)?></div><div class="gw-stat-meta">Recorded successful sign-ins</div></article>
</section>

<section class="record-form" id="create-user">
  <div class="gw-panel-head"><div><h2>Create User</h2><span>Add an authorized CT4 account</span></div><span class="material-symbols-outlined">person_add</span></div>
  <form method="post" class="form-grid admin-user-form" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <input type="hidden" name="action" value="add_user">
    <div><label for="newUserName">Name</label><input id="newUserName" name="name" required maxlength="160" autocomplete="name"></div>
    <div><label for="newUserEmail">Email</label><input id="newUserEmail" type="email" name="email" required maxlength="254" autocomplete="email"></div>
    <div><label for="newUserPassword">Temporary password</label><input id="newUserPassword" type="password" name="password" required minlength="6" autocomplete="new-password"></div>
    <div><label for="newUserRole">Role</label><select id="newUserRole" name="role"><option value="Staff">Staff</option><option value="Administrator">Administrator</option></select></div>
    <div class="full record-actions"><button class="gw-btn primary" type="submit"><span class="material-symbols-outlined">person_add</span>Create User</button></div>
  </form>
</section>

<section class="gw-panel">
  <div class="gw-panel-head"><div><h2>User Accounts</h2><span><?=count($users)?> accounts</span></div><span class="material-symbols-outlined">manage_accounts</span></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead><tbody>
  <?php foreach ($users as $row): $rowActive = (int)($row['active'] ?? 0) === 1; $rowId = (int)($row['id'] ?? 0); ?>
    <tr>
      <td><strong><?=e($row['name'] ?? '')?></strong></td>
      <td><?=e($row['email'] ?? '')?></td>
      <td><span class="admin-role-pill"><?=e($row['role'] ?? '')?></span></td>
      <td><span class="admin-status-pill <?=$rowActive ? 'is-active' : 'is-inactive'?>"><?=$rowActive ? 'Active' : 'Inactive'?></span></td>
      <td><div class="admin-row-actions">
        <?php if ($rowId !== (int)($user['id'] ?? 0)): ?>
          <button class="gw-btn secondary" type="button" data-edit-user data-id="<?=$rowId?>" data-name="<?=e($row['name'] ?? '')?>" data-email="<?=e($row['email'] ?? '')?>" data-role="<?=e($row['role'] ?? 'Staff')?>"><span class="material-symbols-outlined">edit</span>Edit</button>
          <form method="post" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="set_user_status">
            <input type="hidden" name="id" value="<?=$rowId?>">
            <input type="hidden" name="active" value="<?=$rowActive ? '0' : '1'?>">
            <button class="gw-btn <?=$rowActive ? 'secondary' : 'primary'?>" type="submit"><?=$rowActive ? 'Deactivate' : 'Activate'?></button>
          </form>
        <?php else: ?><span class="small-muted">Current account</span><?php endif; ?>
      </div></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$users): ?><tr><td colspan="5" class="empty">No user records available.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>

<section class="gw-panel">
  <div class="gw-panel-head"><div><h2>Recent Login History</h2><span>Latest <?=count($logins)?> sign-in records · 5 visible at a time</span></div><span class="material-symbols-outlined">history</span></div>
  <div class="table-wrap table-scroll-five"><table class="data-table" data-global-sort="true" aria-label="Recent login history"><thead><tr><th scope="col">User</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col" data-sort-type="date">Time</th></tr></thead><tbody>
    <?php foreach ($logins as $row): ?><tr><td><?=e($row['user_name'] ?? 'Unknown')?></td><td><?=e($row['role'] ?? '')?></td><td><?=e($row['status'] ?? '')?></td><td data-sort-value="<?=e($row['login_at'] ?? '')?>"><?=e($row['login_at'] ?? '')?></td></tr><?php endforeach; ?>
    <?php if (!$logins): ?><tr><td colspan="4" class="empty">No login history available.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>

<section class="gw-panel">
  <div class="gw-panel-head"><div><h2>Recent Audit Events</h2><span>Latest <?=count($events)?> events · 5 visible at a time</span></div><span class="material-symbols-outlined">fact_check</span></div>
  <div class="table-wrap table-scroll-five"><table class="data-table" data-global-sort="true" aria-label="Recent audit events"><thead><tr><th scope="col" data-sort-type="date">Time</th><th scope="col">Module</th><th scope="col">Action</th></tr></thead><tbody>
    <?php foreach ($events as $event): ?><tr><td data-sort-value="<?=e($event['created_at'] ?? '')?>"><?=e($event['created_at'] ?? '')?></td><td><?=e($event['module'] ?? '')?></td><td><?=e($event['action'] ?? '')?></td></tr><?php endforeach; ?>
    <?php if (!$events): ?><tr><td colspan="3" class="empty">No audit events available.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>
<style>
.gw-stats{grid-template-columns:repeat(2,minmax(0,1fr))}
.record-form .admin-user-form{grid-template-columns:repeat(4,minmax(0,1fr));align-items:end}
.admin-user-form label{font-size:11px;font-weight:800;color:var(--gw-muted);display:block;margin-bottom:6px}
.admin-user-form input,.admin-user-form select{width:100%;min-width:0;border:1px solid var(--gw-border);border-radius:10px;padding:10px 11px;font:inherit;font-size:12px;background:var(--bg-surface,#fff);color:var(--gw-text)}
.admin-user-form .record-actions{grid-column:1/-1;margin-top:0}
.admin-row-actions{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.admin-inline-form{display:inline-flex;margin:0}
.admin-role-pill,.admin-status-pill{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-size:10px;font-weight:800;white-space:nowrap}
.admin-role-pill{background:#f3edff;color:#6d28d9}
.admin-status-pill.is-active{background:#e9f9f0;color:#087443}
.admin-status-pill.is-inactive{background:#fff1f2;color:#be123c}
.gw-panel{margin-bottom:20px;min-width:0}
.gw-panel .table-wrap{max-width:100%}
@media(max-width:1000px){.record-form .admin-user-form{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.gw-stats{grid-template-columns:1fr}.record-form .admin-user-form{grid-template-columns:1fr}.record-form .admin-user-form .full{grid-column:auto}.gw-hero{align-items:flex-start;flex-direction:column}.admin-row-actions{align-items:flex-start}}
</style>
<?php page_footer(); ?>
