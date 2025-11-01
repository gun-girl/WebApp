<?php
// scripts/create_votes_table.php
require_once __DIR__ . '/../config.php';

$sql = <<<SQL
CREATE TABLE IF NOT EXISTS votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    movie_id INT NOT NULL,
    category VARCHAR(50),
    platform VARCHAR(50),
    competition ENUM('In Concorso', 'Fuori Concorso'),
    writing TINYINT,
    direction TINYINT,
    acting_theme TINYINT,
    emotional_involvement TINYINT,
    novelty TINYINT,
    casting_research_artwork TINYINT,
    sound TINYINT,
    adjective VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

if ($mysqli->query($sql)) {
    echo "✅ Table 'votes' created successfully.";
} else {
    echo "❌ Error creating table: " . $mysqli->error;
}
?>

