<?php
// Compatibility entry point: keep one canonical login implementation.
require_once __DIR__.'/includes/auth.php';
header('Location: '.app_url('/auth/login.php'));
exit;
