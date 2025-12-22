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
    // ensure competitions table exists
    $mysqli->query("CREATE TABLE IF NOT EXISTS competitions (
        year INT PRIMARY KEY,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    if ($action === 'create') {
        // if year not provided, compute next as max(existing) + 1 or active+1
        if (!$year) {
            $row = $mysqli->query("SELECT MAX(year) AS m FROM competitions")->fetch_assoc();
            $max = $row && $row['m'] ? (int)$row['m'] : (int)get_active_year();
            $year = $max + 1;
        }
        $stmt = $mysqli->prepare("INSERT INTO competitions (year) VALUES (?)");
        if ($stmt) {
            $stmt->bind_param('i', $year);
            $stmt->execute();
        }
        // set active
        $stmt2 = $mysqli->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('active_year', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        if ($stmt2) { $val=(string)$year; $stmt2->bind_param('s',$val); $stmt2->execute(); }
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION['flash'] = 'Created competition year ' . $year . ' and set active';

    } elseif ($action === 'set_active' && $year) {
        $stmt = $mysqli->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('active_year', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        if ($stmt) { $val=(string)$year; $stmt->bind_param('s',$val); $stmt->execute(); }
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION['flash'] = 'Active year updated to ' . $year;

    } elseif ($action === 'delete' && $year) {
        // prevent deletion if votes exist for that year
        // first verify the votes table actually has competition_year column
        $hasCol = $mysqli->query("SHOW COLUMNS FROM votes LIKE 'competition_year'")->fetch_all(MYSQLI_ASSOC);
        if ($hasCol) {
            $countRow = $mysqli->query("SELECT COUNT(*) AS c FROM votes WHERE competition_year = " . (int)$year)->fetch_assoc();
            $c = $countRow ? (int)$countRow['c'] : 0;
        } else {
            // no competition_year column => there are no year-scoped votes locally
            $c = 0;
        }
        if ($c > 0) {
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $_SESSION['flash'] = 'Cannot delete year ' . $year . ' because there are ' . $c . ' votes for it.';
        } else {
            $stmt = $mysqli->prepare("DELETE FROM competitions WHERE year = ?");
            if ($stmt) { $stmt->bind_param('i',$year); $stmt->execute(); }
            // if it was active, reset active to current year or previous
            $active = get_active_year();
            if ((int)$active === (int)$year) {
                // pick a fallback: max remaining year or current calendar year
                $row = $mysqli->query("SELECT MAX(year) AS m FROM competitions")->fetch_assoc();
                $fallback = $row && $row['m'] ? (int)$row['m'] : (int)date('Y');
                $stmt2 = $mysqli->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('active_year', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                if ($stmt2) { $val=(string)$fallback; $stmt2->bind_param('s',$val); $stmt2->execute(); }
            }
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $_SESSION['flash'] = 'Deleted competition year ' . $year;
        }
    } elseif ($action === 'rename' && $year) {
        $new = isset($_POST['new_year']) ? (int)$_POST['new_year'] : 0;
        if (!$new) {
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $_SESSION['flash'] = 'Invalid new year';
        } else {
            // only allow rename if no votes for the old year and new year not already present
            // check for competition_year column before counting votes
            $hasCol = $mysqli->query("SHOW COLUMNS FROM votes LIKE 'competition_year'")->fetch_all(MYSQLI_ASSOC);
            if ($hasCol) {
                $countRow = $mysqli->query("SELECT COUNT(*) AS c FROM votes WHERE competition_year = " . (int)$year)->fetch_assoc();
                $c = $countRow ? (int)$countRow['c'] : 0;
            } else {
                $c = 0;
            }
            $exists = $mysqli->query("SELECT 1 FROM competitions WHERE year = " . (int)$new)->fetch_assoc();
            if ($c > 0) {
                if (session_status() !== PHP_SESSION_ACTIVE) session_start();
                $_SESSION['flash'] = 'Cannot rename year ' . $year . ' because there are ' . $c . ' votes for it.';
            } elseif ($exists) {
                if (session_status() !== PHP_SESSION_ACTIVE) session_start();
                $_SESSION['flash'] = 'Target year ' . $new . ' already exists.';
            } else {
                $stmt = $mysqli->prepare("UPDATE competitions SET year = ? WHERE year = ?");
                if ($stmt) { $stmt->bind_param('ii', $new, $year); $stmt->execute(); }
                // if it was active, update active setting
                $active = get_active_year();
                if ((int)$active === (int)$year) {
                    $stmt2 = $mysqli->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('active_year', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    if ($stmt2) { $val=(string)$new; $stmt2->bind_param('s',$val); $stmt2->execute(); }
                }
                if (session_status() !== PHP_SESSION_ACTIVE) session_start();
                $_SESSION['flash'] = 'Renamed ' . $year . ' to ' . $new;
            }
        }
    }
} catch (mysqli_sql_exception $e) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['flash'] = 'DB error: ' . $e->getMessage();
}

$back = $_SERVER['HTTP_REFERER'] ?? ADDRESS.'/stats.php';
redirect($back);

?>
