<?php
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/mailer.php';

header('Content-Type: text/plain; charset=utf-8');
if (!gsms_is_localhost_request()) {
    http_response_code(403);
    echo "For security, this diagnostic is available only from localhost.\n";
    exit;
}

echo "CT4 OTP diagnostics\n";
echo "------------------\n";
try {
    $pdo = db();
    $pdo->query('SELECT 1')->fetchColumn();
    echo "Database connection: OK\n";
    foreach (['users','otp_requests','login_history'] as $table) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        echo $table.' table: '.((int)$stmt->fetchColumn() > 0 ? 'OK' : 'MISSING')."\n";
    }
} catch (Throwable $e) {
    echo "Database connection: FAILED (".get_class($e).")\n";
    error_log('CT4 OTP diagnostic DB failure: '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine());
}

echo "Gmail configuration: ".(filter_var(MAIL_USERNAME, FILTER_VALIDATE_EMAIL) && trim(MAIL_PASSWORD) !== '' ? 'present' : 'MISSING')."\n";
$errno = 0; $errstr = '';
$socket = @stream_socket_client('tcp://'.MAIL_CONNECT_HOST.':'.MAIL_PORT, $errno, $errstr, max(3, SMTP_CONNECT_TIMEOUT));
if ($socket) {
    echo "Gmail SMTP socket ".MAIL_CONNECT_HOST.":".MAIL_PORT.": reachable\n";
    fclose($socket);
} else {
    echo "Gmail SMTP socket ".MAIL_CONNECT_HOST.":".MAIL_PORT.": FAILED (".$errno.")\n";
    error_log('CT4 OTP diagnostic SMTP socket failure: '.$errstr.' ('.$errno.')');
}
echo "\nThis checks connectivity only; it does not send an OTP email.\n";
