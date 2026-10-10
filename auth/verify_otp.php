<?php
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/mailer.php';

if (current_user()) redirect('/dashboard.php');
$pending=$_SESSION['pending_otp_user']??null;
if (!$pending) redirect('/auth/login.php');

$error='';
$success='';
$localOtpCode=$_SESSION['local_otp_code']??null;
if ((time() - (int)($_SESSION['pending_otp_created'] ?? 0)) > OTP_EXPIRY_MINUTES * 60) { unset($_SESSION['pending_otp_user'], $_SESSION['pending_otp_created'], $_SESSION['local_otp_code']); redirect('/auth/login.php?reason=otp_expired'); }
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) { $error='Your session token expired. Refresh the page and try again.'; } else {
    try {
        $action=$_POST['action']??'verify';

        if ($action==='resend') {
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
                $success='A new verification code has been sent to your email.';
            } catch(Throwable $mailError) {
                if (gsms_local_otp_fallback_enabled()) {
                    $_SESSION['local_otp_code']=$otp;
                    $localOtpCode=$otp;
                    $success='Gmail SMTP is unavailable on this localhost machine. For local testing, use the OTP shown below.';
                } else {
                    db()->prepare('DELETE FROM otp_requests WHERE user_id=?')->execute([(int)$pending['id']]);
                    $error='We could not send the verification code. Gmail SMTP requires DNS resolution and outbound TCP 587 access on the server.';
                }
            }
        } else {
            $code=preg_replace('/\D/','',$_POST['otp']??'');
            $stmt=db()->prepare('SELECT id,otp_hash,expires_at,attempts FROM otp_requests WHERE user_id=? ORDER BY id DESC LIMIT 1');
            $stmt->execute([(int)$pending['id']]);
            $row=$stmt->fetch();

            if (!$row) {
                $error='Your verification code is no longer available. Please sign in again.';
            } elseif (strtotime($row['expires_at']) < time()) {
                $error='Your verification code has expired. Please sign in again.';
                db()->prepare('DELETE FROM otp_requests WHERE id=?')->execute([(int)$row['id']]);
            } elseif ((int)$row['attempts'] >= OTP_MAX_ATTEMPTS) {
                $error='Too many incorrect attempts. Please sign in again.';
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
                redirect('/dashboard.php');
            }
        }
    } catch(Throwable $e) {
        error_log('CT4 OTP verification failed: '.$e->getMessage());
        $error='Unable to process the verification request. Please try again.';
    }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Verify OTP — Great Solomon Manpower Services Inc.</title>
<link rel="stylesheet" href="<?=e(url('/style.css'))?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Material+Symbols+Outlined:FILL@0..1&display=swap" rel="stylesheet">
</head>
<body class="auth-body">
<div class="login-page">
  <div class="login-card auth-card otp-card">
    <div class="brand-mark auth-brand">
      <div class="brand-logo-white auth-logo-wrap"><img src="<?=e(url('/assets/logo2.svg'))?>" alt="Great Solomon Manpower Services Inc. logo" class="brand-logo-image"></div>
      <div class="auth-brand-copy"><strong>Great Solomon Manpower Services Inc.</strong><div class="small-muted">Core Transaction 4</div></div>
    </div>
    <div class="auth-heading">
      <span class="material-symbols-outlined">shield_lock</span>
      <h1>Verify Your Identity</h1>
      <p>We sent a 6-digit verification code to <strong><?=e($pending['email'])?></strong>.</p>
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
      <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="verify">
      <div class="field otp-field">
        <label for="otp">One-Time Password</label>
        <input id="otp" class="otp-input" type="text" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" oninput="this.value=this.value.replace(/\D/g,'').slice(0,6)" onkeydown="return /[0-9]|Backspace|Delete|ArrowLeft|ArrowRight|Tab|Home|End/.test(event.key) || event.ctrlKey || event.metaKey" autocomplete="one-time-code" placeholder="000000" required autofocus>
      </div>
      <button class="gw-btn primary auth-submit" type="submit"><span class="material-symbols-outlined">verified</span> Verify &amp; Continue</button>
    </form>
    <form method="post" class="resend-form">
      <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="resend">
      <button type="submit" class="auth-link">Resend verification code</button>
    </form>
    <a class="auth-link secondary" href="<?=e(url('/auth/login.php'))?>">Use a different account</a>
    <div class="auth-security-note"><span class="material-symbols-outlined">schedule</span> The code expires in <?=OTP_EXPIRY_MINUTES?> minutes</div>
  </div>
</div>
<script src="<?=e(url('/app.js'))?>"></script>
<script>
(function(){
  const otp=document.getElementById('otp');
  if(!otp)return;
  otp.addEventListener('input',function(){ this.value=this.value.replace(/\D/g,'').slice(0,6); });
  otp.addEventListener('paste',function(){ setTimeout(()=>{ this.value=this.value.replace(/\D/g,'').slice(0,6); },0); });
})();
</script>
</body>
</html>
