<?php
// admin_competitions.php — create / set active / delete competitions (admin-only)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(ADDRESS.'/index.php');
}

require_admin();
verify_csrf();

global $mysqli;
if (!isset($mysqli) || !$mysqli) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['flash'] = 'Database connection not available';
    redirect(ADDRESS.'/index.php');
}

$action = $_POST['action'] ?? '';
$year = isset($_POST['year']) ? (int)$_POST['year'] : 0;

try {
    // ensure competitions table exists with date ranges
    $mysqli->query("CREATE TABLE IF NOT EXISTS competitions (
        name VARCHAR(255) NOT NULL,
        start DATE NOT NULL,
        end DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_competitions_start_end (start, end)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $name = trim($_POST['name'] ?? '');
    $startRaw = trim($_POST['start_date'] ?? '');
    $endRaw = trim($_POST['end_date'] ?? '');

    $parseDate = static function (string $value): ?DateTime {
        if (!$value) return null;
        $dt = DateTime::createFromFormat('Y-m-d', $value);
        return ($dt && $dt->format('Y-m-d') === $value) ? $dt : null;
    };

    $startDate = $parseDate($startRaw);
    $endDate = $parseDate($endRaw);
    $yearFromDates = $startDate ? (int)$startDate->format('Y') : 0;

    if ($action === 'create') {
        if (!$startDate || !$endDate) {
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $_SESSION['flash'] = 'Please provide start and end dates (YYYY-MM-DD).';
        } elseif ($startDate > $endDate) {
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $_SESSION['flash'] = 'Start date must be before or equal to end date.';
        } else {
            $year = $yearFromDates ?: (int)date('Y');
            if ($name === '') {
                $name = 'Competition ' . $year;
            }
            $stmt = $mysqli->prepare("INSERT INTO competitions (name, start, end) VALUES (?, ?, ?)");
            if ($stmt) {
                $s = $startDate->format('Y-m-d');
                $e = $endDate->format('Y-m-d');
                $stmt->bind_param('sss', $name, $s, $e);
                $stmt->execute();
                // set active competition id to the newly created
                $newId = $mysqli->insert_id;
                if ($newId) {
                    $stmtId = $mysqli->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('active_competition_id', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    if ($stmtId) { $valId=(string)$newId; $stmtId->bind_param('s',$valId); $stmtId->execute(); }
                }
            }
            // also set active year derived from the start date (legacy support)
            $stmt2 = $mysqli->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('active_year', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            if ($stmt2) { $val=(string)$year; $stmt2->bind_param('s',$val); $stmt2->execute(); }
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $_SESSION['flash'] = 'Created competition "' . $name . '" (' . $startDate->format('Y-m-d') . ' to ' . $endDate->format('Y-m-d') . ') and set active year to ' . $year;
        }

    } elseif ($action === 'set_active') {
        $compId = isset($_POST['comp_id']) ? (int)$_POST['comp_id'] : 0;
        if ($compId) {
            // save active competition id
            $stmt = $mysqli->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('active_competition_id', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            if ($stmt) { $valId=(string)$compId; $stmt->bind_param('s',$valId); $stmt->execute(); }
            // for legacy, also compute its start year and set active_year
            $yrRow = $mysqli->query("SELECT start FROM competitions WHERE id = " . $compId)->fetch_assoc();
            if ($yrRow && !empty($yrRow['start'])) {
                $year = (int)date('Y', strtotime($yrRow['start']));
                $stmt2 = $mysqli->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('active_year', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                if ($stmt2) { $val=(string)$year; $stmt2->bind_param('s',$val); $stmt2->execute(); }
            }
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $_SESSION['flash'] = 'Active competition updated';
        } else {
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $_SESSION['flash'] = 'Invalid competition id';
        }

    } elseif ($action === 'delete') {
        $yearToDelete = $year ?: $yearFromDates;
        // prevent deletion if votes exist for that year (legacy linkage)
        $hasCol = $mysqli->query("SHOW COLUMNS FROM votes LIKE 'competition_year'")->fetch_all(MYSQLI_ASSOC);
        if ($hasCol && $yearToDelete) {
            $countRow = $mysqli->query("SELECT COUNT(*) AS c FROM votes WHERE competition_year = " . (int)$yearToDelete)->fetch_assoc();
            $c = $countRow ? (int)$countRow['c'] : 0;
        } else {
            $c = 0;
        }
        if ($c > 0) {
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $_SESSION['flash'] = 'Cannot delete competition for year ' . $yearToDelete . ' because there are ' . $c . ' votes for it.';
        } else {
            // resolve competition id from provided start_date
            $compIdToDelete = null; $deleteStartStr = null;
            if ($startDate) {
                $deleteStartStr = $startDate->format('Y-m-d');
                $rowComp = $mysqli->query("SELECT id, start FROM competitions WHERE start = '" . $mysqli->real_escape_string($deleteStartStr) . "' LIMIT 1")->fetch_assoc();
                if ($rowComp) { $compIdToDelete = (int)$rowComp['id']; }
            }
            // delete by start date to match new schema
            if ($deleteStartStr) {
                $stmt = $mysqli->prepare("DELETE FROM competitions WHERE start = ? LIMIT 1");
                if ($stmt) { $stmt->bind_param('s',$deleteStartStr); $stmt->execute(); }
            }
            // if it was active, reset active to most recent competition and sync legacy year
            $activeId = function_exists('get_active_competition_id') ? get_active_competition_id() : null;
            if ($compIdToDelete && $activeId && (int)$activeId === (int)$compIdToDelete) {
                $row = $mysqli->query("SELECT id, start FROM competitions ORDER BY start DESC LIMIT 1")->fetch_assoc();
                if ($row) {
                    $newActiveId = (int)$row['id'];
                    $fallbackYear = (int)date('Y', strtotime($row['start']));
                    $stmtId = $mysqli->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('active_competition_id', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    if ($stmtId) { $valId=(string)$newActiveId; $stmtId->bind_param('s',$valId); $stmtId->execute(); }
                    $stmt2 = $mysqli->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('active_year', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    if ($stmt2) { $val=(string)$fallbackYear; $stmt2->bind_param('s',$val); $stmt2->execute(); }
                } else {
                    // No competitions remain, clear active_competition_id
                    $mysqli->query("DELETE FROM settings WHERE setting_key = 'active_competition_id'");
                }
            }
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $_SESSION['flash'] = 'Deleted competition starting ' . ($deleteStartStr ?: 'unknown');
        }
    }
} catch (mysqli_sql_exception $e) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['flash'] = 'DB error: ' . $e->getMessage();
}

$back = $_SERVER['HTTP_REFERER'] ?? ADDRESS.'/stats.php';
redirect($back);

?>
