<?php
// helper.php
/**
 * Escape any value for HTML output. Accepts null or other types and casts to string.
 */
function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
if (!function_exists('redirect')) {
    function redirect(string $path): void
    {
        header("Location: $path");
        exit;
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        global $errors;
        $posted = $_POST['csrf_token'] ?? '';
        if (empty($posted) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$posted)) {
            $errors[] = 'Invalid CSRF token';
        }
    }
}