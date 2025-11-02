<?php
require_once __DIR__.'/../config.php';

echo "Updating vote_details table schema...\n\n";

try {
    // Add new columns to vote_details if they don't exist
    $columns_to_add = [
        "competition_status VARCHAR(50) DEFAULT NULL",
        "category VARCHAR(50) DEFAULT NULL",
        "where_watched VARCHAR(100) DEFAULT NULL",
        "adjective VARCHAR(100) DEFAULT NULL"
    ];
    
    foreach ($columns_to_add as $column_def) {
        $column_name = explode(' ', $column_def)[0];
        
        // Check if column exists
        $check = $mysqli->query("SHOW COLUMNS FROM vote_details LIKE '$column_name'");
        
        if ($check->num_rows == 0) {
            $mysqli->query("ALTER TABLE vote_details ADD COLUMN $column_def");
            echo "✓ Added column: $column_name\n";
        } else {
            echo "- Column already exists: $column_name\n";
        }
    }
    
    echo "\n✅ Schema update completed successfully!\n";
    
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
}
