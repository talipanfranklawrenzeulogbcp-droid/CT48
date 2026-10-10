<?php
require_once __DIR__.'/includes/auth.php';
if (current_user()) {
    header('Location: '.app_url('/dashboard.php'));
} else {
    header('Location: '.app_url('/auth/login.php'));
}
exit;
