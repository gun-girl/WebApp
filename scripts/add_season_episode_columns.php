<?php
require_once __DIR__ . '/../config.php';

$sql = "ALTER TABLE vote_details
  ADD COLUMN season_number SMALLINT NULL AFTER category,
  ADD COLUMN episode_number SMALLINT NULL AFTER season_number";

if ($mysqli->query($sql) === TRUE) {
  echo "Columns season_number and episode_number added to vote_details";
} else {
  echo "Migration error: " . $mysqli->error;
}
