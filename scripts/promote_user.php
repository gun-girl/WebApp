<?php
// Promote a user to admin by email or id.
require_once __DIR__ . '/../config.php';

function usage() {
    echo "Usage (CLI): php scripts/promote_user.php --email=user@example.com\n";
    echo "       or:    php scripts/promote_user.php --id=123\n";
    echo "Usage (HTTP): /scripts/promote_user.php?email=user@example.com (not recommended on production)\n";
}

// Parse CLI args
$email = '';
$id = 0;
if (PHP_SAPI === 'cli') {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--email=')) { $email = substr($arg, 8); }
        if (str_starts_with($arg, '--id=')) { $id = (int)substr($arg, 5); }
    }
} else {
    $email = $_GET['email'] ?? '';
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
}

if (!$email && !$id) {
    usage();
    exit(1);
}

try {
    // Ensure role column exists
    $check = $mysqli->query("SHOW COLUMNS FROM users LIKE 'role'");
    if (!$check || $check->num_rows === 0) {
        throw new Exception("The 'role' column does not exist. Run scripts/add_role_column.php first.");
    }

    if ($email) {
        $stmt = $mysqli->prepare("UPDATE users SET role='admin' WHERE email=?");
        $stmt->bind_param('s', $email);
    } else {
        $stmt = $mysqli->prepare("UPDATE users SET role='admin' WHERE id=?");
        $stmt->bind_param('i', $id);
    }

    $stmt->execute();
    if ($stmt->affected_rows > 0) {
        echo "User promoted to admin.\n";
    } else {
        echo "No user updated; check the provided identifier.\n";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error: ' . $e->getMessage();
    exit(1);
}
