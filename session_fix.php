<?php
// Session Fix for EBTGL Accounting System
session_start();

// Set session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');

// Regenerate session ID to prevent fixation
if (!isset($_SESSION['created'])) {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
} elseif (time() - $_SESSION['created'] > 1800) {
    // Regenerate session ID every 30 minutes
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}

// Function to validate admin session
function validateAdminSession() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

// Function to set admin session
function setAdminSession($admin_id, $admin_username) {
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id'] = $admin_id;
    $_SESSION['admin_username'] = $admin_username;
    $_SESSION['login_time'] = time();
    $_SESSION['login_ip'] = $_SERVER['REMOTE_ADDR'];
}

// Function to destroy admin session
function destroyAdminSession() {
    $_SESSION = array();
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
}
?>