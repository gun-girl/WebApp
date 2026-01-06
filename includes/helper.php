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
 * Return the NAME of the active competition.
 * Prefers settings.active_competition_id -> competitions.name.
 * Falls back to settings.active_year -> competitions with YEAR(start)=active_year -> name,
 * otherwise returns "Competition <currentYear>".
 */
function get_active_year(): string
{
    global $mysqli;
    $fallbackName = 'Competition ' . date('Y');
    if (!isset($mysqli) || !$mysqli) return $fallbackName;
    try {
        // Prefer active_competition_id lookup
        $stmt = $mysqli->prepare("SELECT setting_value FROM settings WHERE setting_key = 'active_competition_id' LIMIT 1");
        if ($stmt) {
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row && is_numeric($row['setting_value'])) {
                $cid = (int)$row['setting_value'];
                $stmt2 = $mysqli->prepare("SELECT name FROM competitions WHERE id = ? LIMIT 1");
                if ($stmt2) {
                    $stmt2->bind_param('i', $cid);
                    $stmt2->execute();
                    $r2 = $stmt2->get_result()->fetch_assoc();
                    if ($r2 && isset($r2['name']) && $r2['name'] !== '') return (string)$r2['name'];
                }
            }
        }
        // Fallback via active_year to nearest competition name
        $stmtY = $mysqli->prepare("SELECT setting_value FROM settings WHERE setting_key = 'active_year' LIMIT 1");
        if ($stmtY) {
            $stmtY->execute();
            $rowY = $stmtY->get_result()->fetch_assoc();
            if ($rowY && is_numeric($rowY['setting_value'])) {
                $yr = (int)$rowY['setting_value'];
                $stmt3 = $mysqli->prepare("SELECT name FROM competitions WHERE YEAR(start) = ? ORDER BY start DESC LIMIT 1");
                if ($stmt3) {
                    $stmt3->bind_param('i', $yr);
                    $stmt3->execute();
                    $r3 = $stmt3->get_result()->fetch_assoc();
                    if ($r3 && isset($r3['name']) && $r3['name'] !== '') return (string)$r3['name'];
                }
                return 'Competition ' . $yr;
            }
        }
    } catch (Exception $e) {
        // ignore
    }
    return $fallbackName;
}

/**
 * Determine if a movie/series is in competition for the active year.
 * Rule: In competition if release date is within the configured windows:
 * - Window A: 2024-12-19 to 2025-10-31 (inclusive)
 * - Window B: 2025-11-01 to 2026-12-31 (inclusive)
 */
function is_in_competition(array $movie): bool
{
    global $mysqli;
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

    // Read active competition window from DB
    $start = null; $end = null;
    try {
        $cid = get_active_competition_id();
        if ($cid) {
            $stmt = $mysqli->prepare("SELECT start, `end` FROM competitions WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $cid);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                if ($row && !empty($row['start']) && !empty($row['end'])) { $start = $row['start']; $end = $row['end']; }
            }
        }
        // Fallback: latest competition
        if (!$start || !$end) {
            $row2 = $mysqli->query("SELECT start, `end` FROM competitions ORDER BY start DESC LIMIT 1")->fetch_assoc();
            if ($row2 && !empty($row2['start']) && !empty($row2['end'])) { $start = $row2['start']; $end = $row2['end']; }
        }
    } catch (Exception $e) {
        // ignore
    }

    if (!$start || !$end) return false;
    return ($releaseDate >= $start && $releaseDate <= $end);
}

/**
 * Return the i18n key for a compact badge label representing competition status.
 */
function competition_badge_key(array $movie, ?int $activeYear = null): string
{
    return is_in_competition($movie) ? 'badge_in_competition' : 'badge_out_of_competition';
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

/**
 * Return the active competition id from settings or null if not set.
 */
function get_active_competition_id(): ?int
{
    global $mysqli;
    if (!isset($mysqli) || !$mysqli) return null;
    try {
        $stmt = $mysqli->prepare("SELECT setting_value FROM settings WHERE setting_key = 'active_competition_id' LIMIT 1");
        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            if ($res && is_numeric($res['setting_value'])) {
                return (int)$res['setting_value'];
            }
        }
    } catch (Exception $e) {
        // ignore
    }
    return null;
}