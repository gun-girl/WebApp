<?php
// start_new_year.php — admin-only endpoint to advance active competition year by 1
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helper.php';

// Must be POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(ADDRESS.'/index.php');
}

require_admin(); // ensures logged-in admin and redirects otherwise
verify_csrf();

// compute next year
$current = function_exists('get_active_year') ? get_active_year() : (int)date('Y');
$next = (int)$current + 1;

// upsert into settings
global $mysqli;
if (!isset($mysqli) || !$mysqli) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['flash'] = 'Database connection not available';
    redirect(ADDRESS.'/settings.php');
}

// Ensure settings table exists. If not, create it (safe, idempotent).
try {
    $createSql = "CREATE TABLE IF NOT EXISTS settings (
      id INT AUTO_INCREMENT PRIMARY KEY,
      setting_key VARCHAR(50) NOT NULL UNIQUE,
      setting_value VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $mysqli->query($createSql);

    $stmt = $mysqli->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('active_year', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    if ($stmt) {
        $val = (string)$next;
        $stmt->bind_param('s', $val);
        $stmt->execute();
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION['flash'] = 'Active year updated to ' . $next;
    } else {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION['flash'] = 'Failed to update active year (DB prepare failed)';
    }
} catch (mysqli_sql_exception $e) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['flash'] = 'Database error: ' . $e->getMessage();
}

// redirect back to referer or settings
$back = $_SERVER['HTTP_REFERER'] ?? ADDRESS.'/settings.php';
redirect($back);

?>