<?php
// Run this once to add a 'role' column to users if missing.
require_once __DIR__ . '/../config.php';

try {
    $check = $mysqli->query("SHOW COLUMNS FROM users LIKE 'role'");
    if ($check && $check->num_rows > 0) {
        echo "Role column already exists.\n";
        exit(0);
    }

    $sql = "ALTER TABLE `users` ADD COLUMN `role` VARCHAR(20) NOT NULL DEFAULT 'user' AFTER `password_hash`";
    if (!$mysqli->query($sql)) {
        throw new Exception('Failed to add role column: ' . $mysqli->error);
    }
    echo "Added role column with default 'user'.\n";

    // Optional: promote a specific user to admin by email via CLI arg or GET param
    $adminEmail = isset($argv[1]) ? $argv[1] : ($_GET['email'] ?? '');
    if ($adminEmail) {
        $stmt = $mysqli->prepare("UPDATE users SET role='admin' WHERE email=?");
        $stmt->bind_param('s', $adminEmail);
        $stmt->execute();
        echo "Promoted {$adminEmail} to admin (if user exists).\n";
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo 'Migration error: ' . $e->getMessage();
}
