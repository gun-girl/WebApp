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

/**
 * Return the active competition year stored in settings table or current year as fallback.
 */
function get_active_year(): int
{
    global $mysqli;
    // default to 2025 until the calendar reaches 2026, then use the actual current year
    $nowYear = (int)date('Y');
    $default = ($nowYear < 2026) ? 2025 : $nowYear;
    if (!isset($mysqli) || !$mysqli) return $default;
    // attempt to read from settings table
    try {
        $stmt = $mysqli->prepare("SELECT setting_value FROM settings WHERE setting_key = 'active_year' LIMIT 1");
        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            if ($res && is_numeric($res['setting_value'])) {
                return (int)$res['setting_value'];
            }
        }
    } catch (Exception $e) {
        // ignore and fall back
    }
    return $default;
}