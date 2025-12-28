<?php
// Create admin table and add admins
require_once __DIR__ . '/../config.php';

try {
    // Create admin table
    $sql = "CREATE TABLE IF NOT EXISTS admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if ($mysqli->query($sql)) {
        echo "✓ Admin table created/verified.\n";
    } else {
        throw new Exception("Failed to create admin table: " . $mysqli->error);
    }

    // Add the two admin emails
    $admins = ['frencis.di@gmail.com', 'umberto.marino81@gmail.com'];
    
    foreach ($admins as $email) {
        $stmt = $mysqli->prepare("INSERT IGNORE INTO admins (email) VALUES (?)");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            echo "✓ Added admin: {$email}\n";
        } else {
            echo "- Already exists or error: {$email}\n";
        }
    }
    
    // Verify the admins
    echo "\nCurrent admins:\n";
    $result = $mysqli->query("SELECT email, created_at FROM admins ORDER BY created_at DESC");
    while ($row = $result->fetch_assoc()) {
        echo "  - {$row['email']} (added: {$row['created_at']})\n";
    }
    
    echo "\n✅ Admin table setup completed!\n";
    
} catch (Throwable $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}
?>
