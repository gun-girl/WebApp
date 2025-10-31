<?php
require_once __DIR__.'/../config.php';
$cols = $mysqli->query("SHOW COLUMNS FROM vote_details")->fetch_all(MYSQLI_ASSOC);
print_r($cols);
