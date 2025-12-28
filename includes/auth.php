<?php
// auth.php
require_once __DIR__ . '/../config.php'; // uses your DB connection
require_once __DIR__ . '/helper.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function login_user(array $user): void {
    $_SESSION['user'] = [
        'id' => $user['id'],
        'username' => $user['username'],
        'email' => $user['email'] ?? '',
        'role' => $user['role'] ?? 'user',
    ];
}

function logout_user(): void { session_destroy(); }

function require_login(): void {
    if (!current_user()) { redirect(ADDRESS.'/login.php'); }
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