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
</head>
<body class="<?= isset($body_extra_class) && $body_extra_class ? e($body_extra_class) : '' ?>">
<?php $show_search = !in_array(basename($_SERVER['PHP_SELF']), ['login.php','register.php']); ?>
<header>
  <!-- Global header -->
  <div class="header-logo">
    <img src="/movie-club-app/assets/img/logo.png" alt="<?= e(t('site_title')) ?>" onerror="this.onerror=null;this.src='/movie-club-app/assets/img/no-poster.svg';">
  </div>
  <?php if ($show_search): ?>
    <div class="header-center">
      <form class="global-search" method="get" action="/movie-club-app/index.php">
        <input id="search-field" type="text" name="search" placeholder="<?= e(t('search_movies')) ?>" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" aria-label="<?= e(t('search_movies')) ?>">
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
          <span>
            <?php if (function_exists('is_admin') && is_admin()): ?>
              <svg class="crown-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 19h20v2H2v-2zm2-2h16l-2-9-4 3-2-5-2 5-4-3-2 9z" fill="#ffd700"/></svg>
            <?php endif; ?>
            <?= e($user['username']) ?> 👤
          </span>
          <?php if (function_exists('is_admin') && is_admin()): ?>
            <span class="badge-admin">ADMIN</span>
          <?php endif; ?>
          <span class="dropdown-arrow">▼</span>
        </button>
          <div class="dropdown-menu" id="userDropdown">
          <a href="/movie-club-app/profile.php" class="dropdown-item">👤 <?= e(t('your_profile')) ?></a>
          
          <a href="/movie-club-app/stats.php?mine=1" class="dropdown-item">⭐ <?= e(t('your_ratings')) ?></a>
          <a href="/movie-club-app/profile.php?settings=1" class="dropdown-item">⚙️ <?= e(t('account_settings')) ?></a>
          <div class="dropdown-divider"></div>
          <a href="/movie-club-app/logout.php" class="dropdown-item">🚪 <?= e(t('sign_out')) ?></a>
        </div>
      </div>
      <div class="nav-primary">
      <?php if (function_exists('is_admin') && is_admin()): ?>
      <div class="competitions-dropdown-wrap">
        <button id="competitionsBtn" class="lang-button competitions-btn"><?= e(t('all_competitions')) ?> ▾</button>
        <div id="competitionsMenu" class="dropdown-menu competitions-menu">
          <a class="dropdown-item" href="/movie-club-app/stats.php?sheet=votes&year=<?= $calendar_year ?>"><?= e(t('all_competitions')) ?></a>
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
            <div class="competition-year-row">
              <!-- Primary action: navigate to stats for the selected year -->
              <a class="dropdown-item competition-year-link" href="/movie-club-app/stats.php?year=<?= (int)$cy ?>"><?= (int)$cy ?><?= $cy === $act ? ' (active)' : '' ?></a>
              <?php if (function_exists('is_admin') && is_admin()): ?>
                <!-- Admin controls: quick set-active, rename, delete -->
                <form method="post" action="/movie-club-app/admin_competitions.php" class="admin-form-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="set_active">
                  <input type="hidden" name="year" value="<?= (int)$cy ?>">
                  <button type="submit" class="dropdown-item admin-btn admin-btn-star" title="Set active year">⭐</button>
                </form>
                <button type="button" class="dropdown-item admin-btn admin-btn-edit" onclick="renameCompetition(<?= (int)$cy ?>)">✏️</button>
                <form method="post" action="/movie-club-app/admin_competitions.php" class="admin-form-inline inline-block">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="year" value="<?= (int)$cy ?>">
                  <button type="submit" class="dropdown-item admin-btn admin-btn-delete" title="Delete year">🗑</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if (function_exists('is_admin') && is_admin()): ?>
            <div class="dropdown-divider"></div>
            <form method="post" action="/movie-club-app/admin_competitions.php" class="admin-form-flex">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="create">
              <button type="submit" class="dropdown-item admin-btn-create">🎉 Create & set next year</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      | <a href="/movie-club-app/index.php"> <?= e(t('home')) ?></a>
      </div>

      <!-- Hamburger appears on small screens -->
      <button id="burgerBtn" class="burger-btn" aria-expanded="false" aria-controls="mobileMenu">
        <?php if (function_exists('is_admin') && is_admin()): ?>
          <svg class="burger-crown" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 19h20v2H2v-2zm2-2h16l-2-9-4 3-2-5-2 5-4-3-2 9z" fill="#ffd700"/></svg>
        <?php endif; ?>
        ☰
      </button>
      <div id="mobileMenu" class="mobile-menu" role="menu" aria-label="Main menu">
        <!-- Account section (when logged in) -->
        <?php if (current_user()): ?>
          <a class="mobile-item" href="/movie-club-app/stats.php?mine=1">⭐ <?= e(t('your_ratings')) ?></a>
        <?php else: ?>
          <a class="mobile-item" href="/movie-club-app/register.php"><?= e(t('register')) ?></a>
          <a class="mobile-item" href="/movie-club-app/login.php"><?= e(t('login')) ?></a>
        <?php endif; ?>

        <!-- Collapsible All competitions -->
        <button id="mobileCompetitionsToggle" class="mobile-item" type="button"><?= e(t('all_competitions')) ?> ▾</button>
        <div id="mobileCompetitionsMenu" class="mobile-submenu">
          <a class="mobile-item" href="/movie-club-app/stats.php?sheet=votes&year=<?= $calendar_year ?>"><?= e(t('all_competitions')) ?></a>
          <?php
            $m_competitions = [];
            $m_hasCompetitionsTable = $mysqli->query("SHOW TABLES LIKE 'competitions'")->fetch_all(MYSQLI_NUM);
            if ($m_hasCompetitionsTable) {
              $m_rows = $mysqli->query("SELECT year FROM competitions")->fetch_all(MYSQLI_ASSOC);
              foreach ($m_rows as $r) { $m_competitions[] = (int)$r['year']; }
            }
            $m_hasVotesYearCol = $mysqli->query("SHOW COLUMNS FROM votes LIKE 'competition_year'")->fetch_all(MYSQLI_ASSOC);
            if ($m_hasVotesYearCol) {
              $m_rows2 = $mysqli->query("SELECT DISTINCT COALESCE(competition_year, YEAR(created_at)) AS y FROM votes WHERE (competition_year IS NOT NULL OR created_at IS NOT NULL)")->fetch_all(MYSQLI_ASSOC);
              foreach ($m_rows2 as $r) { $m_competitions[] = (int)$r['y']; }
            } else {
              $m_rows2 = $mysqli->query("SELECT DISTINCT YEAR(created_at) AS y FROM votes WHERE created_at IS NOT NULL")->fetch_all(MYSQLI_ASSOC);
              foreach ($m_rows2 as $r) { $m_competitions[] = (int)$r['y']; }
            }
            $m_act = $calendar_year; if ($m_act) { $m_competitions[] = $m_act; }
            $m_competitions = array_map('intval', array_values(array_unique($m_competitions)));
            rsort($m_competitions, SORT_NUMERIC);
          ?>
          <?php foreach ($m_competitions as $cy): ?>
            <a class="mobile-item" href="/movie-club-app/stats.php?year=<?= (int)$cy ?>"><?= (int)$cy ?><?= $cy === $m_act ? ' (active)' : '' ?></a>
          <?php endforeach; ?>
        </div>

        <?php if (current_user()): ?>
          <a class="mobile-item mobile-signout" href="/movie-club-app/logout.php"><?= e(t('sign_out')) ?></a>
        <?php else: ?>
          <a class="mobile-item" href="/movie-club-app/index.php"><?= e(t('home')) ?></a>
        <?php endif; ?>
      </div>
      <?php $printed_links = true; ?>
    <?php else: ?>
      <?php if (!$is_auth_page): ?>
        <a href="/movie-club-app/register.php"><?= e(t('register')) ?></a> |
        <a href="/movie-club-app/login.php"><?= e(t('login')) ?></a>
        | <a href="/movie-club-app/index.php"> <?= e(t('home')) ?></a>
        <?php $printed_links = true; ?>
      <?php endif; ?>
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

  // Hamburger + mobile menu
  const burgerBtn = document.getElementById('burgerBtn');
  const mobileMenu = document.getElementById('mobileMenu');
  const mobileCompetitionsToggle = document.getElementById('mobileCompetitionsToggle');
  const mobileCompetitionsMenu = document.getElementById('mobileCompetitionsMenu');

  if (burgerBtn && mobileMenu){
    burgerBtn.addEventListener('click', function(e){
      e.stopPropagation();
      const open = mobileMenu.classList.toggle('show');
      burgerBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      // close other menus
      if (userDropdown) userDropdown.classList.remove('show');
      if (langDropdown) langDropdown.classList.remove('show');
      if (competitionsMenu) competitionsMenu.classList.remove('show');
    });
    document.addEventListener('click', function(e){
      if (!mobileMenu.contains(e.target) && e.target !== burgerBtn){
        mobileMenu.classList.remove('show');
        burgerBtn.setAttribute('aria-expanded','false');
      }
    });
  }

  if (mobileCompetitionsToggle && mobileCompetitionsMenu){
    mobileCompetitionsToggle.addEventListener('click', function(e){
      e.stopPropagation();
      mobileCompetitionsMenu.classList.toggle('show');
    });
  }

  // Mobile: Account and Language collapsibles
  const mobileAccountToggle = document.getElementById('mobileAccountToggle');
  const mobileAccountMenu = document.getElementById('mobileAccountMenu');
  if (mobileAccountToggle && mobileAccountMenu){
    mobileAccountToggle.addEventListener('click', function(e){
      e.stopPropagation();
      mobileAccountMenu.classList.toggle('show');
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
