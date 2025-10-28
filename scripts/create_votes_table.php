<?php
require_once __DIR__ . '/../config.php';

$sql = <<<SQL
CREATE TABLE IF NOT EXISTS votes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  movie_id INT NOT NULL,
  rating TINYINT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_user_movie (user_id, movie_id),
  KEY idx_movie (movie_id),
  KEY idx_user (user_id)
);
SQL;

try {
    $mysqli->query($sql);
    echo "votes table created or already exists\n";
} catch (Exception $e) {
    echo "Error creating votes table: ", $e->getMessage(), "\n";
}

