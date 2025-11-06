<?php require_once __DIR__ . '/helper.php'; require_once __DIR__ . '/auth.php'; require_once __DIR__ . '/lang.php'; ?>
<!doctype html>
<html lang="<?= current_lang() ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(t('site_title')) ?></title>
<link rel="stylesheet" href="/movie-club-app/assests/css/style.css">
<style>
  /* === GLOBAL === */
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'Poppins', system-ui, sans-serif;
    background: radial-gradient(circle at top, #0c0c0c, #000);
    color: #eee;
    min-height: 100vh;
  }
  header {
    background: rgba(0,0,0,0.8);
    backdrop-filter: blur(8px);
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 2rem;
    border-bottom: 1px solid #222;
    width: 100%;
    box-sizing: border-box;
  }
  .header-logo {
    display: flex;
    align-items: center;
    gap: 1rem;
  }
  .header-logo img {
    height: 50px;
    width: auto;
  }
  header h1 {
    font-size: 1.6rem;
    letter-spacing: 1px;
    font-weight: 600;
    color: #f6c90e;
  }
  nav {
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }
  nav a {
    color: #fff;
    text-decoration: none;
    transition: color .2s;
    font-weight: 500;
  }
  nav a:hover { color: #f6c90e; }

  /* === USER DROPDOWN MENU === */
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
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }
  .badge-admin {
    background: #f6c90e;
    color: #000;
    font-size: .75rem;
    padding: .1rem .4rem;
    border-radius: .25rem;
    font-weight: 700;
  }
  .user-button:hover {
    background: rgba(246,201,14,.1);
    color: #f6c90e;
  }
  .dropdown-arrow {
    font-size: 0.7rem;
    transition: transform 0.2s;
  }
  .user-button:hover .dropdown-arrow {
    transform: translateY(2px);
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

  /* === LANGUAGE DROPDOWN === */
  .lang-switcher {
    position: relative;
    display: inline-block;
  }
  .lang-button {
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
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }
  .lang-button:hover {
    background: rgba(246,201,14,.1);
    color: #f6c90e;
  }
  .lang-dropdown {
    display: none;
    position: absolute;
    top: 100%;
    right: 0;
    background: #1a1a1a;
    border: 1px solid #333;
    border-radius: .5rem;
    min-width: 150px;
    box-shadow: 0 8px 25px rgba(0,0,0,.6);
    margin-top: .5rem;
    z-index: 1000;
    overflow: hidden;
  }
  .lang-dropdown.show {
    display: block;
    animation: fadeIn .2s ease;
  }
  .lang-option {
    display: block;
    padding: .8rem 1.2rem;
    color: #ccc;
    text-decoration: none;
    transition: all .2s;
    font-size: .95rem;
  }
  .lang-option:hover {
    background: rgba(246,201,14,.1);
    color: #f6c90e;
    padding-left: 1.5rem;
  }
  .lang-option.active {
    background: rgba(246,201,14,.2);
    color: #f6c90e;
    font-weight: 600;
  }

  .hello-only { color:#fff; font-weight:500; }

  /* Push page content below the fixed header */
  main {
    padding-top: 84px; /* ~50px logo + vertical padding */
  }
</style>
</head>
<body>
<header>
  <!-- Global header: include this file on every page. Remove any other language switchers elsewhere. -->
  <div class="header-logo">
    <img src="/movie-club-app/assests/img/logo.png" alt="<?= e(t('site_title')) ?>">
  </div>
  <nav>
    <?php 
      // Show a standalone "Hello" only on the home page
      $__parts = parse_url($_SERVER['REQUEST_URI']);
      $__path = $__parts['path'] ?? '/';
      $__is_home = ($__path === '/movie-club-app/index.php' || rtrim($__path,'/') === '/movie-club-app');
      if ($__is_home): ?>
        <span class="hello-only"><?= e(t('hello')) ?></span>
    <?php endif; ?>
    <?php 
      if (current_user()): 
        $user = current_user();
    ?>
      <div class="user-menu">
        <button class="user-button" id="userMenuBtn">
          <span><?= e($user['username']) ?> 👤</span>
          <?php if (function_exists('is_admin') && is_admin()): ?>
            <span class="badge-admin">ADMIN</span>
          <?php endif; ?>
          <span class="dropdown-arrow">▼</span>
        </button>
        <div class="dropdown-menu" id="userDropdown">
          <a href="/movie-club-app/profile.php" class="dropdown-item">👤 <?= e(t('your_profile')) ?></a>
          <a href="/movie-club-app/watchlist.php" class="dropdown-item">➕ <?= e(t('watchlist')) ?></a>
          <a href="/movie-club-app/stats.php?mine=1" class="dropdown-item">⭐ <?= e(t('your_ratings')) ?></a>
          <a href="/movie-club-app/profile.php?settings=1" class="dropdown-item">⚙️ <?= e(t('account_settings')) ?></a>
          <div class="dropdown-divider"></div>
          <a href="/movie-club-app/logout.php" class="dropdown-item">🚪 <?= e(t('sign_out')) ?></a>
        </div>
      </div>
      | <a href="/movie-club-app/stats.php"><?= e(t('all_votes')) ?></a>
      | <a href="/movie-club-app/index.php"> <?= e(t('home')) ?></a>
    <?php else: ?>
      <a href="/movie-club-app/register.php"><?= e(t('register')) ?></a> |
      <a href="/movie-club-app/login.php"><?= e(t('login')) ?></a>
      | <a href="/movie-club-app/index.php"> <?= e(t('home')) ?></a>
    <?php endif; ?>
    |
    <?php
      // Language switcher dropdown that preserves all query params except lang
      $query = $_GET;
      $query_en = $query;
      $query_it = $query;
      $query_en['lang'] = 'en';
      $query_it['lang'] = 'it';
      $parts = parse_url($_SERVER['REQUEST_URI']);
      $path = $parts['path'] ?? '/';
      $url_en = $path . '?' . http_build_query($query_en);
      $url_it = $path . '?' . http_build_query($query_it);
      $current_lang = current_lang();
    ?>
    <div class="lang-switcher">
      <button class="lang-button" id="langMenuBtn">
        <span><?= $current_lang === 'it' ? 'IT' : 'EN' ?></span>
        <span class="dropdown-arrow">▼</span>
      </button>
      <div class="lang-dropdown" id="langDropdown">
        <a href="<?= htmlspecialchars($url_en) ?>" class="lang-option <?= $current_lang === 'en' ? 'active' : '' ?>">English</a>
        <a href="<?= htmlspecialchars($url_it) ?>" class="lang-option <?= $current_lang === 'it' ? 'active' : '' ?>">Italiano</a>
      </div>
    </div>
  </nav>
</header>
<script>
  // User dropdown menu toggle
  const userBtn = document.getElementById('userMenuBtn');
  const userDropdown = document.getElementById('userDropdown');
  
  if (userBtn && userDropdown) {
    userBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      userDropdown.classList.toggle('show');
      // Close language dropdown if open
      if (langDropdown) langDropdown.classList.remove('show');
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
      if (!userDropdown.contains(e.target) && e.target !== userBtn) {
        userDropdown.classList.remove('show');
      }
    });
  }

  // Language dropdown menu toggle
  const langBtn = document.getElementById('langMenuBtn');
  const langDropdown = document.getElementById('langDropdown');
  
  if (langBtn && langDropdown) {
    langBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      langDropdown.classList.toggle('show');
      // Close user dropdown if open
      if (userDropdown) userDropdown.classList.remove('show');
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
      if (!langDropdown.contains(e.target) && e.target !== langBtn) {
        langDropdown.classList.remove('show');
      }
    });
  }
</script>
<main>
