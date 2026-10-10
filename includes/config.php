<?php
// =============================================================
// GREAT SOLOMON MANPOWER SERVICES INC. — CORE TRANSACTION 4
// config.php — Centralised runtime configuration.
// All secrets come from environment variables; no hard-coded
// credentials exist in this file (HostForge resilience rule).
// =============================================================

// --- Load .env file (local/dev only; production uses real env vars) ---
function gsms_load_env_file(): void {
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;
    $file = dirname(__DIR__).'/.env';
    if (!is_file($file) || !is_readable($file)) return;
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$name, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, " \"'");
        if ($name !== '' && getenv($name) === false) {
            putenv($name.'='.$value);
        }
    }
}
gsms_load_env_file();

// --- Ephemeral directory bootstrap (HostForge ephemeral containers) ---
// Auto-create write-dependent directories so the app survives cold starts.
(function (): void {
    $base = dirname(__DIR__);
    foreach (['storage/logs', 'storage/exports', 'storage/reports'] as $dir) {
        $path = $base.'/'.$dir;
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
    }
})();

// --- Database: support both individual DB_* vars and DATABASE_URL ---
// HostForge managed MySQL injects the individual DB_* variables.
// External connections may use a single DATABASE_URL string.
(function (): void {
    $url = getenv('DATABASE_URL');
    if ($url && !getenv('GSMS_DB_HOST')) {
        $p = parse_url($url);
        if ($p !== false) {
            if (!empty($p['host']))     putenv('GSMS_DB_HOST='.urldecode($p['host']));
            if (!empty($p['port']))     putenv('GSMS_DB_PORT='.$p['port']);
            if (!empty($p['user']))     putenv('GSMS_DB_USER='.urldecode($p['user']));
            if (!empty($p['pass']))     putenv('GSMS_DB_PASS='.urldecode($p['pass']));
            if (!empty($p['path']))     putenv('GSMS_DB_NAME='.ltrim(urldecode($p['path']), '/'));
        }
    }
    // HostForge managed MySQL also injects DB_HOST / DB_DATABASE etc.
    // Fall back to those if project-prefixed vars are missing.
    // Accept common hosting-provider aliases as well as the project-prefixed
    // variables. DATABASE_URL takes precedence when GSMS_DB_HOST is not set.
    // Normalize common managed-MySQL environment names (HostForge, Railway,
    // Render, Docker Compose, and local .env files). Explicit GSMS_* values
    // always win, followed by DATABASE_URL, then provider aliases.
    $firstEnv = static function(array $names): ?string {
        foreach ($names as $name) {
            $value = getenv($name);
            if ($value !== false && trim((string)$value) !== '') return (string)$value;
        }
        return null;
    };
    $aliases = [
        'GSMS_DB_HOST' => ['DB_HOST','MYSQLHOST','MARIADB_HOST','MYSQL_HOST'],
        'GSMS_DB_PORT' => ['DB_PORT','MYSQLPORT','MARIADB_PORT','MYSQL_PORT'],
        'GSMS_DB_NAME' => ['DB_DATABASE','DB_NAME','MYSQLDATABASE','MARIADB_DATABASE','MYSQL_DATABASE'],
        'GSMS_DB_USER' => ['DB_USERNAME','DB_USER','MYSQLUSER','MARIADB_USER','MYSQL_USER'],
        'GSMS_DB_PASS' => ['DB_PASSWORD','DB_PASS','MYSQLPASSWORD','MARIADB_PASSWORD','MYSQL_PASSWORD'],
    ];
    foreach ($aliases as $target => $names) {
        if (getenv($target) !== false && trim((string)getenv($target)) !== '') continue;
        $value = $firstEnv($names);
        if ($value !== null) putenv($target.'='.$value);
    }
})();

// --- Application Environment & Debugging ---
$gsms_host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$gsms_is_localhost = $gsms_host === 'localhost'
    || str_starts_with($gsms_host, 'localhost:')
    || $gsms_host === '127.0.0.1'
    || str_starts_with($gsms_host, '127.0.0.1:')
    || $gsms_host === '::1'
    || str_starts_with($gsms_host, '[::1]:');

define('APP_ENV', getenv('APP_ENV') ?: (getenv('ENVIRONMENT') ?: ($gsms_is_localhost ? 'local' : 'production')));

define('APP_DEBUG', filter_var(getenv('APP_DEBUG') ?: (APP_ENV !== 'production' ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN));

if (APP_ENV === 'production' && !APP_DEBUG) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

// --- Resolved DB constants ---
define('DB_HOST', getenv('GSMS_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', (int)(getenv('GSMS_DB_PORT') ?: 3306));
define('DB_NAME', getenv('GSMS_DB_NAME') ?: 'great_solomon_ct4');
define('DB_USER', getenv('GSMS_DB_USER') ?: 'root');
define('DB_PASS', getenv('GSMS_DB_PASS') ?: '');

// --- Gmail SMTP ---
define('MAIL_HOST',       getenv('GSMS_MAIL_HOST')       ?: 'smtp.gmail.com');
define('MAIL_PORT',  (int)(getenv('GSMS_MAIL_PORT')      ?: 587));
define('MAIL_CONNECT_HOST', getenv('GSMS_MAIL_CONNECT_HOST') ?: MAIL_HOST);
define('MAIL_TLS_PEER_NAME', getenv('GSMS_MAIL_TLS_PEER_NAME') ?: MAIL_HOST);
define('MAIL_USERNAME',   getenv('GSMS_MAIL_USERNAME')   ?: 'governancesafety21@gmail.com');
define('MAIL_PASSWORD',   getenv('GSMS_MAIL_PASSWORD')   ?: '');
define('MAIL_FROM_EMAIL', getenv('GSMS_MAIL_FROM_EMAIL') ?: 'governancesafety21@gmail.com');
define('MAIL_FROM_NAME',  getenv('GSMS_MAIL_FROM_NAME')  ?: 'Great Solomon Manpower Services Inc. Core Transaction 4');
define('OTP_SENDER_EMAIL',getenv('GSMS_OTP_SENDER_EMAIL')?: 'governancesafety21@gmail.com');
define('SMTP_CONNECT_TIMEOUT', (int)(getenv('GSMS_SMTP_CONNECT_TIMEOUT') ?: 3));
define('OTP_EXPIRY_MINUTES', 10);
define('OTP_MAX_ATTEMPTS',    5);

/**
 * Gmail is the real OTP delivery mechanism. For localhost development only,
 * a controlled fallback keeps the login testable when DNS/SMTP is unavailable.
 * Production never falls back to displaying an OTP.
 */
function gsms_is_localhost_request(): bool {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return $host === 'localhost'
        || str_starts_with($host, 'localhost:')
        || $host === '127.0.0.1'
        || str_starts_with($host, '127.0.0.1:')
        || $host === '::1'
        || str_starts_with($host, '[::1]:');
}

function gsms_local_otp_fallback_enabled(): bool {
    $configured = getenv('GSMS_LOCAL_OTP_FALLBACK');
    if ($configured !== false && $configured !== '') {
        return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
    }
    return gsms_is_localhost_request();
}

// --- Gemini AI ---
// Never expose this to browser JavaScript.
$gsms_gemini_api_key = '';
foreach (['GEMINI_API_KEY', 'GOOGLE_API_KEY', 'GOOGLE_GEMINI_API_KEY', 'GOOGLE_AI_API_KEY', 'GOOGLE_GENERATIVE_AI_API_KEY'] as $gsms_key_name) {
    $gsms_key_value = getenv($gsms_key_name);
    if ($gsms_key_value !== false && trim((string)$gsms_key_value) !== '') {
        $gsms_gemini_api_key = trim((string)$gsms_key_value);
        break;
    }
}
define('GEMINI_API_KEY', $gsms_gemini_api_key);
define('GEMINI_MODEL', trim((string)(getenv('GEMINI_MODEL') ?: 'gemini-3.8-flash')));

// --- HTTPS / Secure-cookie detection (HostForge edge proxy terminates TLS) ---
define('APP_HTTPS', (
    (!empty($_SERVER['HTTPS'])                    && $_SERVER['HTTPS']                    !== 'off') ||
    (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])   && $_SERVER['HTTP_X_FORWARDED_PROTO']   === 'https') ||
    (!empty($_SERVER['HTTP_X_FORWARDED_SSL'])     && $_SERVER['HTTP_X_FORWARDED_SSL']     === 'on') ||
    ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
));

// Baseline browser security headers. Keep CSP configurable because this legacy UI uses inline handlers.
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

// Apply secure session cookie settings on first include.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => APP_HTTPS,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
