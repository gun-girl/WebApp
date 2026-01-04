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
            if (!is_array($errors ?? null)) { $errors = []; }
            $errors[] = 'Invalid CSRF token';
            // Refresh token so the next attempt is guaranteed to have a fresh value
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }
}

/**
 * Return the active competition year stored in settings table or current year as fallback.
 * Competition year starts in November of the previous year.
 * E.g.: 2025 competition runs from November 2024 through October 2025.
 */
function get_active_year(): int
{
    global $mysqli;
    // Calculate default based on current date: competition year starts in November of prior year
    $currentYear = (int)date('Y');
    $currentMonth = (int)date('n');
    // If we're before November, use current year as competition year
    // If we're in November or later, competition year advances to next year
    $defaultYear = ($currentMonth >= 11) ? $currentYear + 1 : $currentYear;
    
    if (!isset($mysqli) || !$mysqli) return $defaultYear;
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
    return $defaultYear;
}

/**
 * Determine if a movie/series is in competition for the active year.
 * Rule: A title is in competition for year Y if it was released from November (Y-1) onwards.
 * E.g., for 2026 competition: released from November 2025 onwards.
 */
function is_in_competition(array $movie, ?int $activeYear = null): bool
{
    if ($activeYear === null) {
        $activeYear = get_active_year();
    }
    
    // Normalize a release date from 'released' (YYYY-MM-DD) or fall back to Jan 1 of year
    $releaseDate = null;
    $released = $movie['released'] ?? null;
    if ($released && preg_match('/^\d{4}-\d{2}-\d{2}$/', $released)) {
        $releaseDate = $released;
    } else {
        $yRaw = $movie['year'] ?? null;
        if ($yRaw && preg_match('/(\d{4})/', (string)$yRaw, $m)) {
            $releaseDate = $m[1] . '-01-01';
        }
    }

    if (!$releaseDate) return false;

    // Competition starts in November of the previous year
    $competitionStart = ($activeYear - 1) . '-11-01';
    
    return ($releaseDate >= $competitionStart);
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