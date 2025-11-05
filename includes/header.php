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
  /* User dropdown menu */
  .user-menu {
    position: relative;
    display: inline-block;
  }
  .user-button {
    background: transparent;
    border: none;
    color: #fff;
    font-weight: 500;
    cursor: pointer;
    padding: .5rem 1rem;
    border-radius: .3rem;
    transition: all .2s;
    font-family: inherit;
    font-size: inherit;
  }
  .user-button:hover {
    background: rgba(246,201,14,.1);
    color: #f6c90e;
  }
  .dropdown-menu {
    display: none;
    position: absolute;
    top: 100%;
    right: 0;
    background: #1a1a1a;
    border: 1px solid #333;
    border-radius: .5rem;
    min-width: 250px;
    box-shadow: 0 8px 25px rgba(0,0,0,.6);
    margin-top: .5rem;
    z-index: 1000;
    overflow: hidden;
  }
  .dropdown-menu.show {
    display: block;
    animation: fadeIn .2s ease;
  }
  @keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
  }
  .dropdown-item {
    display: block;
    padding: .8rem 1.2rem;
    color: #ccc;
    text-decoration: none;
    transition: all .2s;
    border-bottom: 1px solid #282828;
    font-size: .95rem;
  }
  .dropdown-item:hover {
    background: rgba(246,201,14,.1);
    color: #f6c90e;
    padding-left: 1.5rem;
  }
  .dropdown-divider {
    height: 2px;
    background: #333;
    margin: .3rem 0;
  }
</style>
</head><body class="container">
<header>
  <div class="header-logo">
    <img src="/movie-club-app/assests/img/logo.png" alt="<?= e(t('site_title')) ?>">
  </div>
  <nav>
    <?php 
      $is_stats_page = (basename($_SERVER['PHP_SELF']) === 'stats.php');
      if (current_user()): 
        $user = current_user();
    ?>
      <div class="user-menu">
        <button class="user-button" id="userMenuBtn">
          <?= e(t('hello')) ?>, <?= e($user['username']) ?> 👤
        </button>
        <div class="dropdown-menu" id="userDropdown">
          <a href="/movie-club-app/profile.php" class="dropdown-item">👤 <?= e(t('profile')) ?></a>
          <a href="/movie-club-app/stats.php?mine=1" class="dropdown-item">📊 <?= e(t('view_my_votes')) ?></a>
          <a href="/movie-club-app/profile.php" class="dropdown-item">✉️ <?= e(t('email')) ?>: <?= e($user['email']) ?></a>
          <?php if (!$is_stats_page): ?>
            <div class="dropdown-divider"></div>
            <a href="/movie-club-app/logout.php" class="dropdown-item">🚪 <?= e(t('logout')) ?></a>
          <?php endif; ?>
        </div>
      </div>
      <?php if (!$is_stats_page): ?>
        |
        <a href="/movie-club-app/stats.php"><?= e(t('all_votes')) ?></a>
      <?php endif; ?>
    <?php else: ?>
      <a href="/movie-club-app/register.php"><?= e(t('register')) ?></a> |
      <a href="/movie-club-app/login.php"><?= e(t('login')) ?></a>
    <?php endif; ?>
    | <a href="/movie-club-app/index.php" class="btn"><?= e(t('home')) ?></a>
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
<script>
  // User dropdown menu toggle
  const userBtn = document.getElementById('userMenuBtn');
  const dropdown = document.getElementById('userDropdown');
  
  if (userBtn && dropdown) {
    userBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      dropdown.classList.toggle('show');
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
      if (!dropdown.contains(e.target) && e.target !== userBtn) {
        dropdown.classList.remove('show');
      }
    });
  }
</script>
<main>
