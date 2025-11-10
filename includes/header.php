<?php require_once __DIR__ . '/helper.php'; require_once __DIR__ . '/auth.php'; require_once __DIR__ . '/lang.php';
// current active year for small admin controls in the header
$header_active_year = function_exists('get_active_year') ? get_active_year() : (int)date('Y');
// Treat the calendar year as the "current" active year for display and quick navigation
$calendar_year = (int)date('Y');
?>
<!doctype html>
<html lang="<?= current_lang() ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(t('site_title')) ?></title>
<?php $cssPath = __DIR__ . '/../assets/css/style.css'; $cssVer = @filemtime($cssPath) ?: time(); ?>
<link rel="stylesheet" href="/movie-club-app/assets/css/style.css?v=<?= $cssVer ?>">
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
    top: 0; left: 0; right: 0;
    z-index: 1000;
    display: grid;
    grid-template-columns: 1fr auto 1fr; /* left and right take equal space; center stays truly centered */
    align-items: center;
    padding: .75rem 2rem;
    border-bottom: 1px solid #222;
    width: 100%;
    box-sizing: border-box;
    column-gap: 1.5rem;
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
    gap: 0.75rem;
    justify-content: flex-end; /* sits in right grid column */
    justify-self: end;
  }
  .header-logo { justify-self: start; }
  .header-center { display: flex; justify-content: center; align-items: center; }
  .global-search { display:flex; align-items:center; gap:.5rem; }
  .global-search input[type=text]{width:280px;max-width:100%;padding:.55rem .75rem;border:1px solid #333;border-radius:.4rem;background:#181818;color:#eee;font-size:.9rem;}
  .global-search button{background:#f6c90e;color:#000;border:none;padding:.55rem .9rem;border-radius:.4rem;font-weight:600;cursor:pointer;font-size:.85rem;}
  .global-search button:hover{background:#ffde50;}
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
  main { padding-top: 82px; }
</style>
</head>
<body>
<?php $show_search = !in_array(basename($_SERVER['PHP_SELF']), ['login.php','register.php']); ?>
<header>
  <!-- Global header -->
  <div class="header-logo">
    <img src="/movie-club-app/assets/img/logo.png" alt="<?= e(t('site_title')) ?>" onerror="this.onerror=null;this.src='/movie-club-app/assets/img/no-poster.svg';">
  </div>
  <?php if ($show_search): ?>
    <div class="header-center">
      <form class="global-search" method="get" action="/movie-club-app/index.php">
        <input type="text" name="search" placeholder="<?= e(t('search_movies')) ?>" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" aria-label="<?= e(t('search_movies')) ?>">
        <button type="submit"><?= e(t('search')) ?></button>
      </form>
    </div>
  <?php endif; ?>
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
      // track whether we print any left-side links to decide separator before language
      $printed_links = false;
      $current_script = basename($_SERVER['PHP_SELF']);
      $is_auth_page = in_array($current_script, ['login.php','register.php']);
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
      |
      <div style="position:relative; display:inline-block;">
        <button id="competitionsBtn" class="lang-button" style="background:transparent;border:none;padding:.5rem 1rem;cursor:pointer;">All competitions ▾</button>
        <div id="competitionsMenu" class="dropdown-menu" style="right:auto;left:0;">
          <a class="dropdown-item" href="/movie-club-app/stats.php?sheet=votes&year=<?= $calendar_year ?>">All competitions</a>
          <?php
            // Build list of competition years from both `competitions` table AND distinct years seen in `votes`.
            // This ensures the dropdown shows every year created on the site (either explicitly created or inferred from votes).
            $competitions = [];
            // from competitions table (if exists)
            $hasCompetitionsTable = $mysqli->query("SHOW TABLES LIKE 'competitions'")->fetch_all(MYSQLI_NUM);
            if ($hasCompetitionsTable) {
              $rows = $mysqli->query("SELECT year FROM competitions")->fetch_all(MYSQLI_ASSOC);
              foreach ($rows as $r) { $competitions[] = (int)$r['year']; }
            }
            // from votes table: if competition_year column exists, use it (falling back to created_at year when null),
            // otherwise fall back to YEAR(created_at). This preserves historical years even after creating a competitions table.
            $hasVotesYearCol = $mysqli->query("SHOW COLUMNS FROM votes LIKE 'competition_year'")->fetch_all(MYSQLI_ASSOC);
            if ($hasVotesYearCol) {
              // use COALESCE(competition_year, YEAR(created_at)) to capture votes that may not have competition_year set
              $rows2 = $mysqli->query("SELECT DISTINCT COALESCE(competition_year, YEAR(created_at)) AS y FROM votes WHERE (competition_year IS NOT NULL OR created_at IS NOT NULL)")->fetch_all(MYSQLI_ASSOC);
              foreach ($rows2 as $r) { $competitions[] = (int)$r['y']; }
            } else {
              // no competition_year column: infer years from created_at
              $rows2 = $mysqli->query("SELECT DISTINCT YEAR(created_at) AS y FROM votes WHERE created_at IS NOT NULL")->fetch_all(MYSQLI_ASSOC);
              foreach ($rows2 as $r) { $competitions[] = (int)$r['y']; }
            }
            // always include the active year (from settings) to avoid hiding it
            // For display purposes, show the calendar/current year as active (per user request)
            $act = $calendar_year;
            if ($act) { $competitions[] = $act; }
            // normalize: unique, numeric, sort descending
            $competitions = array_map('intval', array_values(array_unique($competitions)));
            rsort($competitions, SORT_NUMERIC);
          ?>
          <?php foreach ($competitions as $cy): ?>
            <div style="display:flex;gap:.5rem;align-items:center;padding:.15rem 0.6rem;">
              <!-- Primary action: navigate to stats for the selected year -->
              <a class="dropdown-item" href="/movie-club-app/stats.php?year=<?= (int)$cy ?>" style="flex:1;text-decoration:none;"><?= (int)$cy ?><?= $cy === $act ? ' (active)' : '' ?></a>
              <?php if (function_exists('is_admin') && is_admin()): ?>
                <!-- Admin controls: quick set-active, rename, delete -->
                <form method="post" action="/movie-club-app/admin_competitions.php" style="margin:0;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="set_active">
                  <input type="hidden" name="year" value="<?= (int)$cy ?>">
                  <button type="submit" class="dropdown-item" title="Set active year" style="background:none;border:none;color:#ffd700;margin-left:.25rem;">⭐</button>
                </form>
                <button type="button" class="dropdown-item" style="background:none;border:none;color:#9fd3ff;margin-left:.25rem;" onclick="renameCompetition(<?= (int)$cy ?>)">✏️</button>
                <form method="post" action="/movie-club-app/admin_competitions.php" style="margin:0;display:inline-block;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="year" value="<?= (int)$cy ?>">
                  <button type="submit" class="dropdown-item" title="Delete year" style="background:none;border:none;color:#ffb4b4;margin-left:.25rem;">🗑</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if (function_exists('is_admin') && is_admin()): ?>
            <div class="dropdown-divider"></div>
            <form method="post" action="/movie-club-app/admin_competitions.php" style="margin:0;padding:.25rem 0.6rem;display:flex;gap:.5rem;align-items:center;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="create">
              <button type="submit" class="dropdown-item" style="background:none;border:none;color:inherit;text-align:left;width:100%;">🎉 Create & set next year</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
      | <a href="/movie-club-app/index.php"> <?= e(t('home')) ?></a>
      <?php $printed_links = true; ?>
    <?php else: ?>
      <?php if (!$is_auth_page): ?>
        <a href="/movie-club-app/register.php"><?= e(t('register')) ?></a> |
        <a href="/movie-club-app/login.php"><?= e(t('login')) ?></a>
        | <a href="/movie-club-app/index.php"> <?= e(t('home')) ?></a>
        <?php $printed_links = true; ?>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($printed_links): ?>|
    <?php endif; ?>
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

  // Competitions dropdown toggle (All competitions menu)
  const competitionsBtn = document.getElementById('competitionsBtn');
  const competitionsMenu = document.getElementById('competitionsMenu');
  if (competitionsBtn && competitionsMenu) {
    competitionsBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      competitionsMenu.classList.toggle('show');
      if (userDropdown) userDropdown.classList.remove('show');
      if (langDropdown) langDropdown.classList.remove('show');
    });
    document.addEventListener('click', function(e) {
      if (!competitionsMenu.contains(e.target) && e.target !== competitionsBtn) {
        competitionsMenu.classList.remove('show');
      }
    });
  }

  // rename handler: prompt and submit hidden form
  function renameCompetition(oldYear) {
    const n = prompt('Enter new year for ' + oldYear + ':', oldYear+1);
    if (!n) return;
    const newYear = parseInt(n);
    if (!newYear || newYear < 1900 || newYear > 3000) { alert('Invalid year'); return; }
    if (!confirm('Rename ' + oldYear + ' to ' + newYear + '?')) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/movie-club-app/admin_competitions.php';
    form.style.display = 'none';
    const csrf = document.createElement('input'); csrf.type='hidden'; csrf.name='csrf_token'; csrf.value='<?= $_SESSION['csrf_token'] ?? '' ?>'; form.appendChild(csrf);
    const a = document.createElement('input'); a.type='hidden'; a.name='action'; a.value='rename'; form.appendChild(a);
    const b = document.createElement('input'); b.type='hidden'; b.name='year'; b.value=oldYear; form.appendChild(b);
    const c = document.createElement('input'); c.type='hidden'; c.name='new_year'; c.value=newYear; form.appendChild(c);
    document.body.appendChild(form);
    form.submit();
  }
</script>
<main>
