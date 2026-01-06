<?php
// auth.php

// CRITICAL: Disable caching for all pages that include auth
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}

require_once __DIR__ . '/../config.php'; // uses your DB connection
require_once __DIR__ . '/helper.php';

// Start session with secure configuration
if (session_status() !== PHP_SESSION_ACTIVE) {
    // Configure secure session settings
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_lifetime', '0'); // Session cookie only
    
    session_start();
    
    // Debug logging for session issues
    error_log("[AUTH] Session started. ID: " . session_id() . ", Has user: " . (isset($_SESSION['user']) ? 'yes' : 'no'));
    
    // If session has user data but no explicit login marker, clear it (prevent contamination)
    if (isset($_SESSION['user']) && !isset($_SESSION['login_timestamp'])) {
        error_log("[AUTH] Clearing contaminated session without login timestamp");
        unset($_SESSION['user']);
    }
}

function current_user(): ?array {
    // Validate session integrity
    if (isset($_SESSION['user'])) {
        // Verify user still exists in database
        global $mysqli;
        $userId = $_SESSION['user']['id'] ?? null;
        if ($userId) {
            $stmt = $mysqli->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 0) {
                // User deleted or invalid session - clear it
                unset($_SESSION['user']);
                return null;
            }
        }
    }
    return $_SESSION['user'] ?? null;
}

function login_user(array $user): void {
    $_SESSION['user'] = [
        'id' => $user['id'],
        'username' => $user['username'],
        'email' => $user['email'] ?? '',
        'role' => $user['role'] ?? 'user',
    ];
    $_SESSION['login_timestamp'] = time();
    error_log("[AUTH] User logged in: " . $user['username'] . " (ID: " . $user['id'] . ")");
}

function logout_user(): void { 
    error_log("[AUTH] User logged out: " . ($_SESSION['user']['username'] ?? 'unknown'));
    session_destroy(); 
}

function require_login(): void {
    $user = current_user();
    if (!$user) {
        error_log("[AUTH] No valid user, redirecting to login from: " . $_SERVER['REQUEST_URI']);
        redirect(ADDRESS.'/login.php');
    } else {
        error_log("[AUTH] User authenticated: " . $user['username']);
    }
}

function is_admin(): bool {
    $u = current_user();
    if (!$u) return false;
    
    // Check if user's email is in the admins table
    global $mysqli;
    try {
        // Check if admins table exists
        $tableCheck = $mysqli->query("SHOW TABLES LIKE 'admins'");
        if ($tableCheck && $tableCheck->num_rows > 0) {
            $stmt = $mysqli->prepare("SELECT id FROM admins WHERE LOWER(email) = LOWER(?)");
            $stmt->bind_param('s', $u['email']);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                return true;
            }
        }
    } catch (Throwable $e) {
        // Fallback to role column if admins table query fails
    }
    
    // Fallback to role column if it exists
    return ($u['role'] ?? 'user') === 'admin';
}

function require_admin(): void {
    require_login();
    if (!is_admin()) { redirect(ADDRESS.'/index.php'); }
}
function csrf_field() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        session_write_close();
    }
    return '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';
}

if (!function_exists('redirect')) {
    function redirect($url) {
        header('Location: ' . $url);
        exit;
    }
}