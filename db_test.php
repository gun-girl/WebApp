<?php
require 'config.php';
$r = $mysqli->query("SELECT 1");
echo $r ? "DB OK" : "DB FAIL: ".$mysqli->error;
