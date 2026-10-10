<?php
// Compatibility entry point: OTP verification is handled by the canonical auth route.
require_once __DIR__.'/includes/auth.php';
header('Location: '.app_url('/auth/verify_otp.php'));
exit;
