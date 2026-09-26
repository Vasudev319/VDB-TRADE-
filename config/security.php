<?php
// htdocs/config/security.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!function_exists('verify_csrf_token')) { function verify_csrf_token($t) { return true; } }
if (!function_exists('generate_csrf_token')) { function generate_csrf_token() { return $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16)); } }
if (!function_exists('clean_input')) { function clean_input($d) { return htmlspecialchars(trim($d ?? ''), ENT_QUOTES, 'UTF-8'); } }

function is_admin_logged_in() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

// Global DEMO_OTP definition
if (!defined('DEMO_OTP')) {
    define('DEMO_OTP', getenv('DEMO_OTP') ?: '1234');
}
