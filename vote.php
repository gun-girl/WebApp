<?php
require_once __DIR__.'/includes/auth.php'; require_login(); verify_csrf();

$movie_id = (int)($_POST['movie_id'] ?? 0);
$rating   = (int)($_POST['rating'] ?? 0);
if ($movie_id<=0 || $rating<1 || $rating>5) exit('Invalid input');

$user_id = current_user()['id'];

// ensure movie exists (cheap guard)
$exists = $mysqli->prepare("SELECT id FROM movies WHERE id=?");
$exists->bind_param('i',$movie_id); $exists->execute();
if (!$exists->get_result()->fetch_row()) exit('Unknown movie');

$stmt = $mysqli->prepare("
  INSERT INTO votes(user_id,movie_id,rating)
  VALUES(?,?,?)
  ON DUPLICATE KEY UPDATE rating=VALUES(rating), updated_at=CURRENT_TIMESTAMP
");
$stmt->bind_param('iii',$user_id,$movie_id,$rating); $stmt->execute();

redirect('/movie-club-app/index.php');
