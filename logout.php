<?php
// htdocs/logout.php — CUSTOMER SESSION TERMINATION
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Store redirect message
$_SESSION = [];

// Delete session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Redirect to login
header("Location: index.php?logged_out=1");
exit;