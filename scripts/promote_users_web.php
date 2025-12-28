<?php
// Web-accessible script to promote users to admin
// This can be run through a browser when Apache is running
require_once __DIR__ . '/../config.php';

// Simple security check - can be accessed directly for now
try {
    $check = $mysqli->query("SHOW COLUMNS FROM users LIKE 'role'");
    if (!$check || $check->num_rows === 0) {
        echo "Error: The 'role' column does not exist. Run scripts/add_role_column.php first.\n";
        exit(1);
    }

    $emails = ['frencis.di@gmail.com', 'umberto.marino81@gmail.com'];
    
    foreach ($emails as $email) {
        $stmt = $mysqli->prepare("UPDATE users SET role='admin' WHERE email=?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            echo "✓ User {$email} promoted to admin.\n";
        } else {
            echo "! No user found or already admin: {$email}\n";
        }
    }
    
    echo "\n✅ Admin promotion completed!\n";
} catch (Throwable $e) {
    echo 'Error: ' . $e->getMessage();
    exit(1);
}
?>
