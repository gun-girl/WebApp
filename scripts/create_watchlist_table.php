<?php
require_once __DIR__.'/../config.php';

// Create watchlist table
$sql = "CREATE TABLE IF NOT EXISTS `watchlist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `movie_id` int(11) NOT NULL,
  `added_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_movie` (`user_id`, `movie_id`),
  KEY `user_id` (`user_id`),
  KEY `movie_id` (`movie_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`movie_id`) REFERENCES `movies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($mysqli->query($sql)) {
    echo "✓ Watchlist table created successfully!\n";
} else {
    echo "Error creating watchlist table: " . $mysqli->error . "\n";
}

$mysqli->close();
?>
