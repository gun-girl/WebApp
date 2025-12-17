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

/**
 * Determine if a movie/series is in competition for the active year.
 * Rule: if the parsed release year equals the active competition year.
 */
function is_in_competition(array $movie, ?int $activeYear = null): bool
{
    if ($activeYear === null) {
        $activeYear = get_active_year();
    }
    $yRaw = $movie['year'] ?? null;
    if ($yRaw === null || $yRaw === '') return false;
    if (is_numeric($yRaw)) {
        $y = (int)$yRaw;
    } else {
        if (preg_match('/(\d{4})/', (string)$yRaw, $m)) {
            $y = (int)$m[1];
        } else {
            return false;
        }
    }
    return $y === (int)$activeYear;
}

/**
 * Return the i18n key for a compact badge label representing competition status.
 */
function competition_badge_key(array $movie, ?int $activeYear = null): string
{
    return is_in_competition($movie, $activeYear) ? 'badge_in_competition' : 'badge_out_of_competition';
}

/** Read a setting value from the settings table (or return default). */
function get_setting(string $key, $default = null)
{
    global $mysqli;
    if (!isset($mysqli) || !$mysqli) return $default;
    try {
        $stmt = $mysqli->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            if ($res !== null && array_key_exists('setting_value', $res)) {
                return $res['setting_value'];
            }
        }
    } catch (Exception $e) {
        // ignore
    }
    return $default;
}

/** Upsert a setting value into the settings table. */
function set_setting(string $key, $value): bool
{
    global $mysqli;
    if (!isset($mysqli) || !$mysqli) return false;
    try {
        $stmt = $mysqli->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        if ($stmt) {
            $stmt->bind_param('ss', $key, $value);
            return $stmt->execute();
        }
    } catch (Exception $e) {
        // ignore
    }
    return false;
}