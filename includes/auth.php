<?php
// Load the central settings before the session so redirects and session
// cookies behave consistently on root, auth, and nested routes.
require_once __DIR__.'/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function current_user(): ?array { return $_SESSION['user'] ?? null; }

/** The restricted entry workspace belongs to this one designated account only. */
function is_records_entry_account(?array $user = null): bool {
    $user = $user ?? current_user();
    return strtolower(trim((string)($user['email'] ?? ''))) === 'records.entry@greatsolomonmpservices.com';
}


/** Incident reports are available to the two operational roles used by this app.
 * The dedicated Records Entry account remains restricted to its existing workspace.
 */
function can_access_incident_reports(?array $user = null): bool {
    $user = $user ?? current_user();
    if (!$user || is_records_entry_account($user)) return false;
    return in_array((string)($user['role'] ?? ''), ['Administrator', 'Staff'], true);
}

function require_incident_report_access(): void {
    require_login();
    if (!can_access_incident_reports()) {
        http_response_code(403);
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Access Denied</title></head><body style="font-family:Inter,Arial,sans-serif;padding:40px;color:#243247"><h1>Access Denied</h1><p>Incident reporting is available to authorized administrators and staff.</p><p><a href="'.htmlspecialchars(app_url('/dashboard.php'),ENT_QUOTES,'UTF-8').'">Return to dashboard</a></p></body></html>';
        exit;
    }
}

function app_base_path(): string {
    // Match CT44's deployment-aware addressing: support a configured mount
    // prefix and reverse-proxy prefixes, then derive the root from SCRIPT_NAME.
    $configured = trim((string)(getenv('APP_BASE_PATH') ?: getenv('GSMS_APP_BASE_PATH')));
    if ($configured !== '') {
        $prefix = '/'.trim(str_replace('\\', '/', $configured), '/');
        return $prefix === '/' ? '' : $prefix;
    }

    $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? ''));
    if ($forwarded !== '') {
        $prefix = '/'.trim(str_replace('\\', '/', $forwarded), '/');
        return $prefix === '/' ? '' : $prefix;
    }

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === '') return '';
    if ($script[0] !== '/') $script = '/'.ltrim($script, '/');

    // Nested application routes share the prefix before these directories.
    foreach (['/modules/', '/auth/', '/includes/', '/services/'] as $marker) {
        $position = strpos($script, $marker);
        if ($position !== false) {
            return rtrim(substr($script, 0, $position), '/');
        }
    }

    // Root-level scripts use their containing directory as the install prefix.
    $directory = str_replace('\\', '/', dirname($script));
    return ($directory === '/' || $directory === '.' || $directory === '\\')
        ? ''
        : '/'.trim($directory, '/');
}

function app_url(string $path = ''): string {
    $base = rtrim(app_base_path(), '/');
    return $base.'/'.ltrim($path, '/');
}

function require_login(): void {
    if (!current_user()) {
        header('Location: '.app_url('/auth/login.php'));
        exit;
    }
    // Enforce the same 5-minute inactivity limit server-side. Login/OTP pages
    // never call require_login(), so they are excluded from this timeout.
    $limit = 5 * 60;
    $last = (int)($_SESSION['last_activity'] ?? time());
    if ((time() - $last) >= $limit) {
        logout_user();
        header('Location: '.app_url('/auth/login.php?reason=inactivity'));
        exit;
    }
    $_SESSION['last_activity'] = time();

    // The Records Entry account is limited to its own dashboard and the three permitted record-entry tasks.
    $u = current_user();
    if (is_records_entry_account($u)) {
        $script = str_replace('\\\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $allowed = [
            '/modules/health_safety/add_health_record.php',
            '/modules/asset_equipment/register_asset.php',
            '/modules/legal_compliance/manage_employee_documents.php',
            '/modules/legal_compliance/view_document.php',
            '/records_entry_dashboard.php',
            '/auth/logout.php',
        ];
        $isDocumentSave = str_ends_with($script, '/modules/legal_compliance/index.php')
            && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')
            && (($_POST['action'] ?? '') === 'update_employee_document');
        $ok = false;
        foreach ($allowed as $path) {
            if (str_ends_with($script, $path)) { $ok = true; break; }
        }
        if (!$ok && !$isDocumentSave) {
            header('Location: '.app_url('/records_entry_dashboard.php'));
            exit;
        }
    }
}


function require_records_entry_account(): void {
    require_login();
    if (!is_records_entry_account()) {
        http_response_code(403);
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Access Denied</title><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="font-family:Inter,Arial,sans-serif;padding:40px;color:#243247"><h1>Access Denied</h1><p>This dedicated data-entry page is available only to the authorized Records Entry account.</p><p><a href="'.htmlspecialchars(app_url('/dashboard.php'),ENT_QUOTES,'UTF-8').'">Return to dashboard</a></p></body></html>';
        exit;
    }
}


function require_admin(): void {
    require_login();
    $u=current_user();
    if (($u['role'] ?? '') !== 'Administrator') {
        http_response_code(403);
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Access Denied</title></head><body style="font-family:Arial,sans-serif;padding:40px"><h1>Access Denied</h1><p>This area is available to administrators only.</p><p><a href="'.htmlspecialchars(app_url('/dashboard.php'),ENT_QUOTES,'UTF-8').'">Return to dashboard</a></p></body></html>';
        exit;
    }
}

function login_user(array $user): void {
    session_regenerate_id(true);
    $_SESSION['last_activity'] = time();
    $_SESSION['user']=[
        'id'=>(int)$user['id'],
        'name'=>$user['name'],
        'email'=>$user['email'],
        'role'=>$user['role']
    ];
}

function logout_user(): void {
    $_SESSION=[];
    if (ini_get('session.use_cookies')) {
        $p=session_get_cookie_params();
        setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);
    }
    session_destroy();
}
