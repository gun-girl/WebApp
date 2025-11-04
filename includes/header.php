<?php require_once __DIR__ . '/helper.php'; require_once __DIR__ . '/auth.php'; require_once __DIR__ . '/lang.php'; ?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(t('site_title')) ?></title>
<!-- Corrected stylesheet path -->
<link rel="stylesheet" href="/movie-club-app/assests/css/style.css">
<style>
  .header-logo {
    display: flex;
    align-items: center;
    gap: 1rem;
  }
  .header-logo img {
    height: 50px;
    width: auto;
  }
</style>
</head><body class="container">
<header>
  <div class="header-logo">
    <img src="/movie-club-app/assests/img/logo.png" alt="<?= e(t('site_title')) ?>">
  </div>
  <nav>
    <?php if (current_user()): ?>
      <?= e(t('hello')) ?>, <?= e(current_user()['username']) ?> |
      <a href="/movie-club-app/logout.php"><?= e(t('logout')) ?></a> |
      <a href="/movie-club-app/stats.php"><?= e(t('results')) ?></a>
    <?php else: ?>
      <a href="/movie-club-app/register.php"><?= e(t('register')) ?></a> |
      <a href="/movie-club-app/login.php"><?= e(t('login')) ?></a>
    <?php endif; ?>
    <a href="/movie-club-app/index.php" class="btn"><?= e(t('home')) ?></a>
    <?php
      // Build language switcher links that preserve all query params except lang
      $query = $_GET;
      $query_en = $query;
      $query_it = $query;
      $query_en['lang'] = 'en';
      $query_it['lang'] = 'it';
      $base_url = $_SERVER['REQUEST_URI'];
      $parts = parse_url($base_url);
      $path = $parts['path'];
      $url_en = $path . '?' . http_build_query($query_en);
      $url_it = $path . '?' . http_build_query($query_it);
    ?>
    <span class="lang-menu">|
      <a href="<?= htmlspecialchars($url_en) ?>"><?= e(t('lang_en')) ?></a>
      &nbsp;|&nbsp;
      <a href="<?= htmlspecialchars($url_it) ?>"><?= e(t('lang_it')) ?></a>
    </span>
  </nav>
</header>
<main>
