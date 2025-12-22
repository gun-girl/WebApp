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
    return $u && (($u['role'] ?? 'user') === 'admin');
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
    }
    return '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';
}

if (!function_exists('redirect')) {
    function redirect($url) {
        header('Location: ' . $url);
        exit;
    }
}