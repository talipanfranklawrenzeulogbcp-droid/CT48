<?php
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/mailer.php';

if (current_user()) redirect(is_records_entry_account(current_user()) ? '/records_entry_dashboard.php' : '/dashboard.php');

$error='';
$success='';
$pending=$_SESSION['pending_otp_user']??null;
$localOtpCode=$_SESSION['local_otp_code']??null;
if ($pending && (time() - (int)($_SESSION['pending_otp_created'] ?? 0)) > OTP_EXPIRY_MINUTES * 60) {
    unset($_SESSION['pending_otp_user'], $_SESSION['pending_otp_created'], $_SESSION['local_otp_code']);
    $pending=null; $localOtpCode=null; $error='Your verification session expired. Please sign in again.';
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $error='Your session token expired. Refresh the page and try again.';
    } else {
    try {
        $action=$_POST['action']??'login';

        if ($action==='verify_otp' && $pending) {
            $code=preg_replace('/\D/','',$_POST['otp']??'');
            $stmt=db()->prepare('SELECT id,otp_hash,expires_at,attempts FROM otp_requests WHERE user_id=? ORDER BY id DESC LIMIT 1');
            $stmt->execute([(int)$pending['id']]);
            $row=$stmt->fetch();

            if (!$row) {
                $error='Your verification code is no longer available. Please sign in again.';
                unset($_SESSION['pending_otp_user'],$_SESSION['pending_otp_created']);
                $pending=null;
            } elseif (strtotime($row['expires_at']) < time()) {
                $error='Your verification code has expired. Please sign in again.';
                db()->prepare('DELETE FROM otp_requests WHERE id=?')->execute([(int)$row['id']]);
                unset($_SESSION['pending_otp_user'], $_SESSION['pending_otp_created'], $_SESSION['local_otp_code']);
                $pending=null; $localOtpCode=null;
            } elseif ((int)$row['attempts'] >= OTP_MAX_ATTEMPTS) {
                $error='Too many incorrect attempts. Please sign in again.';
                db()->prepare('DELETE FROM otp_requests WHERE id=?')->execute([(int)$row['id']]);
                unset($_SESSION['pending_otp_user'], $_SESSION['pending_otp_created'], $_SESSION['local_otp_code']);
                $pending=null; $localOtpCode=null;
            } elseif (!preg_match('/^\d{6}$/',$code) || !password_verify($code,$row['otp_hash'])) {
                db()->prepare('UPDATE otp_requests SET attempts=attempts+1 WHERE id=?')->execute([(int)$row['id']]);
                $error='Incorrect verification code.';
            } else {
                login_user($pending);
                db()->prepare('DELETE FROM otp_requests WHERE user_id=?')->execute([(int)$pending['id']]);
                unset($_SESSION['pending_otp_user'],$_SESSION['pending_otp_created'],$_SESSION['local_otp_code']);
                $localOtpCode=null;

                $history=db()->prepare('INSERT INTO login_history(user_id,email,status,ip_address,user_agent) VALUES(?,?,?,?,?)');
                $history->execute([
                    (int)$pending['id'],$pending['email'],'Success',
                    $_SERVER['REMOTE_ADDR']??'Unknown',
                    substr($_SERVER['HTTP_USER_AGENT']??'',0,500)
                ]);
                audit('System Administration & Security','Two-Step Login','Successful password and OTP verification');
                redirect(is_records_entry_account(current_user()) ? '/records_entry_dashboard.php' : '/dashboard.php');
            }
        } elseif ($action==='resend_otp' && $pending) {
            $otp=(string)random_int(100000,999999);
            $hash=password_hash($otp,PASSWORD_DEFAULT);
            $expires=date('Y-m-d H:i:s',time()+(OTP_EXPIRY_MINUTES*60));
            db()->prepare('DELETE FROM otp_requests WHERE user_id=?')->execute([(int)$pending['id']]);
            db()->prepare('INSERT INTO otp_requests(user_id,otp_hash,expires_at,attempts) VALUES(?,?,?,0)')
                ->execute([(int)$pending['id'],$hash,$expires]);
            try {
                send_otp_email($pending['email'],$pending['name'],$otp);
                unset($_SESSION['local_otp_code']);
                $localOtpCode=null;
                $success='A new 6-digit verification code has been sent to your email.';
            } catch(Throwable $mailError) {
                if (gsms_local_otp_fallback_enabled()) {
                    $_SESSION['local_otp_code']=$otp;
                    $localOtpCode=$otp;
                    $success='Gmail SMTP is unavailable on this localhost machine. For local testing, use the OTP shown below. Real Gmail delivery will resume automatically when SMTP access is available.';
                } else {
                    db()->prepare('DELETE FROM otp_requests WHERE user_id=?')->execute([(int)$pending['id']]);
                    $error='We could not send the verification code. Gmail SMTP requires DNS resolution and outbound TCP 587 access on the server.';
                }
            }
        } elseif ($action==='login') {
            $email=trim((string)($_POST['email']??''));
            $password=(string)($_POST['password']??'');
            if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error='Invalid email or password.';
            } else {
                $ip=(string)($_SERVER['REMOTE_ADDR']??'Unknown');
                $rate=db()->prepare("SELECT COUNT(*) FROM login_history WHERE email=? AND ip_address=? AND status='Failed' AND login_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
                $rate->execute([$email,$ip]);
                if ((int)$rate->fetchColumn() >= 8) {
                    $error='Too many failed attempts. Please wait 15 minutes before trying again.';
                } else {
            $stmt=db()->prepare('SELECT id,name,email,role,password_hash FROM users WHERE email=? AND active=1 LIMIT 1');
            $stmt->execute([$email]);
            $u=$stmt->fetch();

            if ($u && password_verify($password,$u['password_hash'])) {
                // This dedicated entry role is exempt from OTP; all other roles retain normal OTP.
                if (strtolower(trim((string)($u['email'] ?? ''))) === 'records.entry@greatsolomonmpservices.com') {
                    login_user($u);
                    $history=db()->prepare('INSERT INTO login_history(user_id,email,status,ip_address,user_agent) VALUES(?,?,?,?,?)');
                    $history->execute([(int)$u['id'],$u['email'],'Success',$_SERVER['REMOTE_ADDR']??'Unknown',substr($_SERVER['HTTP_USER_AGENT']??'',0,500)]);
                    audit('Records Entry','Password Login','Successful password-only login for restricted entry account');
                    redirect('/records_entry_dashboard.php');
                }
                db()->prepare('DELETE FROM otp_requests WHERE user_id=?')->execute([(int)$u['id']]);

                $otp=(string)random_int(100000,999999);
                $otpHash=password_hash($otp,PASSWORD_DEFAULT);
                $expires=date('Y-m-d H:i:s',time()+(OTP_EXPIRY_MINUTES*60));

                db()->prepare('INSERT INTO otp_requests(user_id,otp_hash,expires_at,attempts) VALUES(?,?,?,0)')
                    ->execute([(int)$u['id'],$otpHash,$expires]);

                try {
                    send_otp_email($u['email'],$u['name'],$otp);
                    unset($_SESSION['local_otp_code']);
                    $_SESSION['pending_otp_user']=[
                        'id'=>(int)$u['id'],'name'=>$u['name'],
                        'email'=>$u['email'],'role'=>$u['role']
                    ];
                    $_SESSION['pending_otp_created']=time();
                    $pending=$_SESSION['pending_otp_user'];
                    $success='Verification code sent. Enter the 6-digit OTP below to continue to the dashboard.';

                    $history=db()->prepare('INSERT INTO login_history(user_id,email,status,ip_address,user_agent) VALUES(?,?,?,?,?)');
                    $history->execute([
                        (int)$u['id'],$email,'OTP Pending',
                        $_SERVER['REMOTE_ADDR']??'Unknown',
                        substr($_SERVER['HTTP_USER_AGENT']??'',0,500)
                    ]);
                } catch(Throwable $mailError) {
                    if (gsms_local_otp_fallback_enabled()) {
                        $_SESSION['local_otp_code']=$otp;
                        $_SESSION['pending_otp_user']=[
                            'id'=>(int)$u['id'],'name'=>$u['name'],
                            'email'=>$u['email'],'role'=>$u['role']
                        ];
                        $_SESSION['pending_otp_created']=time();
                        $pending=$_SESSION['pending_otp_user'];
                        $localOtpCode=$otp;
                        $success='Gmail SMTP is unavailable on this localhost machine. For local testing, use the OTP shown below. Real Gmail delivery will resume automatically when SMTP access is available.';

                        $history=db()->prepare('INSERT INTO login_history(user_id,email,status,ip_address,user_agent) VALUES(?,?,?,?,?)');
                        $history->execute([
                            (int)$u['id'],$email,'OTP Pending',
                            $_SERVER['REMOTE_ADDR']??'Unknown',
                            substr($_SERVER['HTTP_USER_AGENT']??'',0,500)
                        ]);
                    } else {
                        db()->prepare('DELETE FROM otp_requests WHERE user_id=?')->execute([(int)$u['id']]);
                        $error='We could not send the verification code. Gmail SMTP requires DNS resolution and outbound TCP 587 access on the server.';
                    }
                }
            } else {
                $error='Invalid email or password.';
                $history=db()->prepare('INSERT INTO login_history(user_id,email,status,ip_address,user_agent) VALUES(?,?,?,?,?)');
                $history->execute([
                    $u ? (int)$u['id'] : null,$email,'Failed',
                    $_SERVER['REMOTE_ADDR']??'Unknown',
                    substr($_SERVER['HTTP_USER_AGENT']??'',0,500)
                ]);
            }
                }
            }
        }
    } catch(Throwable $e) {
        // Log the actual exception for server-side diagnosis, but never show
        // credentials, SQL parameters, or internal details in the public UI.
        error_log(sprintf(
            'CT4 login request failed [%s] %s at %s:%d',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
        if (defined('APP_DEBUG') && APP_DEBUG) {
            $error = 'Login diagnostic: '.get_class($e).': '.$e->getMessage().' (see server error log for file and line).';
        } else {
            $error='Unable to process the request. Check the database and Gmail settings.';
        }
    }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $pending ? 'Enter OTP' : 'Sign In' ?> — Great Solomon Manpower Services Inc.</title>
<link rel="stylesheet" href="<?=e(url('/style.css'))?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Material+Symbols+Outlined:FILL@0..1&display=swap" rel="stylesheet">
</head>
<body class="auth-body">
<div class="login-page">
  <div class="login-card auth-card <?= $pending ? 'otp-card' : '' ?>">
    <div class="brand-mark auth-brand">
      <div class="brand-logo-white auth-logo-wrap"><img src="<?=e(url('/assets/logo2.svg'))?>" alt="Great Solomon Manpower Services Inc. logo" class="brand-logo-image"></div>
      <div class="auth-brand-copy"><strong>Great Solomon Manpower Services Inc.</strong><div class="small-muted">Core Transaction 4</div></div>
    </div>

    <?php if($pending): ?>
      <div class="auth-heading">
        <span class="material-symbols-outlined">shield_lock</span>
        <h1>Enter OTP</h1>
        <p>Login successful. Enter the <strong>6-digit OTP</strong> sent to <strong><?=e($pending['email'])?></strong> to continue to the dashboard.</p>
      </div>
      <?php if($error):?><div class="notice error auth-error"><?=e($error)?></div><?php endif;?>
      <?php if($success):?><div class="notice success auth-error"><?=e($success)?></div><?php endif;?>
      <?php if($localOtpCode && gsms_local_otp_fallback_enabled()): ?>
        <div class="notice success auth-error" style="text-align:center">
          <strong>LOCALHOST TEST OTP</strong><br>
          <span style="font-size:28px;letter-spacing:6px;font-weight:800"><?=e($localOtpCode)?></span>
        </div>
      <?php endif;?>

      <form method="post" class="auth-form">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="verify_otp">
        <div class="field otp-field">
          <label for="otp">One-Time Password</label>
          <input id="otp" class="otp-input" type="text" name="otp" inputmode="numeric"
                 pattern="\d{6}" maxlength="6" autocomplete="one-time-code"
                 placeholder="000000" required autofocus>
        </div>
        <button class="gw-btn primary auth-submit" type="submit">
          <span class="material-symbols-outlined">verified</span> Verify &amp; Open Dashboard
        </button>
      </form>

      <form method="post" class="resend-form">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="resend_otp">
        <button type="submit" class="auth-link">Resend verification code</button>
      </form>
      <a class="auth-link secondary" href="<?=e(url('/auth/logout.php'))?>">Use a different account</a>
      <div class="auth-security-note">
        <span class="material-symbols-outlined">schedule</span>
        The code expires in <?=OTP_EXPIRY_MINUTES?> minutes
      </div>

    <?php else: ?>
      <div class="auth-heading">
        <span class="material-symbols-outlined">lock</span>
        <h1>Welcome Back</h1>
        <p>Sign in to access Governance, Safety &amp; System Administration.</p>
      </div>
      <?php if($error):?><div class="notice error auth-error"><?=e($error)?></div><?php endif;?>
      <?php if($success):?><div class="notice success auth-error"><?=e($success)?></div><?php endif;?>

      <form method="post" class="auth-form">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="login">
        <div class="field"><label>Email Address</label><input type="email" name="email" autocomplete="username" required value="<?=e($_POST['email']??'')?>"></div>
        <div class="field"><label>Password</label><input type="password" name="password" autocomplete="current-password" required></div>
        <button class="gw-btn primary auth-submit" type="submit">
          <span class="material-symbols-outlined">login</span> Sign In
        </button>
      </form>
      <div class="auth-security-note">
        <span class="material-symbols-outlined">verified_user</span>
        After login, a 6-digit OTP is required before the dashboard opens.
      </div>
    <?php endif; ?>
  </div>
</div>
<script src="<?=e(url('/app.js'))?>"></script>
</body>
</html>
