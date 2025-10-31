<?php require_once __DIR__ . '/helper.php'; require_once __DIR__ . '/auth.php'; ?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>IL DIVANO D’ORO</title>
<link rel="stylesheet" href="/movie-club-app/assets/css/styles.css">
</head><body class="container">
<header>
  <h1>IL DIVANO D’ORO</h1>
  <nav>
    <?php if (current_user()): ?>
      Hello, <?= e(current_user()['username']) ?> |
      <a href="/movie-club-app/logout.php">Logout</a> |
      <a href="/movie-club-app/stats.php">Results</a>
    <?php else: ?>
      <a href="/movie-club-app/register.php">Register</a> |
      <a href="/movie-club-app/login.php">Login</a>
    <?php endif; ?>
    <a href="/movie-club-app/index.php" class="btn">🏠 Home</a>
  </nav>
</header>
<main>
