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
<meta name="theme-color" content="#000000">
<meta name="description" content="A collaborative movie voting and watchlist application">
<title><?= e(t('site_title')) ?></title>
<link rel="manifest" href="<?= ADDRESS ?>/manifest.json">
<link rel="icon" type="image/png" href="<?= ADDRESS ?>/assets/img/logo.png">
<link rel="apple-touch-icon" href="<?= ADDRESS ?>/assets/img/logo.png">
<?php $cssPath = __DIR__ . '/../assets/css/style.css'; $cssVer = @filemtime($cssPath) ?: time(); ?>
<link rel="stylesheet" href="<?= ADDRESS ?>/assets/css/style.css?v=<?= $cssVer ?>">
</head>
<?php
  $bodyClasses = [];
  if (isset($body_extra_class) && $body_extra_class) { $bodyClasses[] = $body_extra_class; }
  if (function_exists('current_user') && current_user()) { $bodyClasses[] = 'logged-in'; }
?>
<body class="<?= e(implode(' ', $bodyClasses)) ?>">
<?php $show_search = !in_array(basename($_SERVER['PHP_SELF']), ['login.php','register.php']); ?>
<header>
  <!-- Global header -->
  <a class="header-logo" href="<?= ADDRESS ?>/index.php" aria-label="<?= e(t('home')) ?>" style="text-decoration: none;">
    <img src="<?= ADDRESS ?>/assets/img/logo.png" alt="<?= e(t('site_title')) ?>" onerror="this.onerror=null;this.src='<?= ADDRESS ?>/assets/img/no-poster.svg';">
    <span class="logo-text logo-text-desktop">DIVANO D'ORO</span>
  </a>
  <a class="logo-text logo-text-mobile" href="<?= ADDRESS ?>/index.php" aria-label="<?= e(t('home')) ?>" style="text-decoration: none;">DIVANO D'ORO</a>
  <?php if ($show_search): ?>
    <div class="header-center">
      <form class="global-search" method="get" action="<?= ADDRESS ?>/index.php">
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
      $__is_home = ($__path === ADDRESS.'/index.php' || rtrim($__path,'/') === ADDRESS.'');
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
          <a href="<?= ADDRESS ?>/profile.php" class="dropdown-item">👤 <?= e(t('your_profile')) ?></a>
          
          <a href="<?= ADDRESS ?>/stats.php?mine=1" class="dropdown-item">⭐ <?= e(t('your_ratings')) ?></a>
          <a href="<?= ADDRESS ?>/profile.php?settings=1" class="dropdown-item">⚙️ <?= e(t('account_settings')) ?></a>
          <div class="dropdown-divider mobile-lang-section"></div>
          <?php
            $query_lang = $_GET;
            $query_en_menu = $query_lang;
            $query_it_menu = $query_lang;
            $query_en_menu['lang'] = 'en';
            $query_it_menu['lang'] = 'it';
            $parts_lang = parse_url($_SERVER['REQUEST_URI']);
            $path_lang = $parts_lang['path'] ?? '/';
            $url_en_menu = $path_lang . '?' . http_build_query($query_en_menu);
            $url_it_menu = $path_lang . '?' . http_build_query($query_it_menu);
          ?>
          <a href="<?= htmlspecialchars($url_en_menu) ?>" class="dropdown-item mobile-lang-item <?= current_lang() === 'en' ? 'active-lang' : '' ?>">🌐 English</a>
          <a href="<?= htmlspecialchars($url_it_menu) ?>" class="dropdown-item mobile-lang-item <?= current_lang() === 'it' ? 'active-lang' : '' ?>">🌐 Italiano</a>
          <div class="dropdown-divider"></div>
          <a href="<?= ADDRESS ?>/logout.php" class="dropdown-item">🚪 <?= e(t('sign_out')) ?></a>
        </div>
      </div>
      <div class="nav-primary">
      <?php if (function_exists('is_admin') && is_admin()): ?>
      <div class="competitions-dropdown-wrap">
        <button id="competitionsBtn" class="lang-button competitions-btn"><?= e(t('all_competitions')) ?> ▾</button>
        <div id="competitionsMenu" class="dropdown-menu competitions-menu">
          <a class="dropdown-item" href="<?= ADDRESS ?>/stats.php?sheet=votes&year=<?= $calendar_year ?>"><?= e(t('all_competitions')) ?></a>
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
              <a class="dropdown-item competition-year-link" href="<?= ADDRESS ?>/stats.php?year=<?= (int)$cy ?>"><?= (int)$cy ?><?= $cy === $act ? ' (active)' : '' ?></a>
              <?php if (function_exists('is_admin') && is_admin()): ?>
                <!-- Admin controls: quick set-active, rename, delete -->
                <form method="post" action="<?= ADDRESS ?>/admin_competitions.php" class="admin-form-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="set_active">
                  <input type="hidden" name="year" value="<?= (int)$cy ?>">
                  <button type="submit" class="dropdown-item admin-btn admin-btn-star" title="Set active year">⭐</button>
                </form>
                <button type="button" class="dropdown-item admin-btn admin-btn-edit" onclick="renameCompetition(<?= (int)$cy ?>)">✏️</button>
                <form method="post" action="<?= ADDRESS ?>/admin_competitions.php" class="admin-form-inline inline-block">
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
            <form method="post" action="<?= ADDRESS ?>/admin_competitions.php" class="admin-form-flex">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="create">
              <button type="submit" class="dropdown-item admin-btn-create">🎉 Create & set next year</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      | <a href="<?= ADDRESS ?>/index.php"> <?= e(t('home')) ?></a>
      </div>

      <?php $printed_links = true; ?>
    <?php else: ?>
      <?php if (!$is_auth_page): ?>
        <a href="<?= ADDRESS ?>/register.php"><?= e(t('register')) ?></a> |
        <a href="<?= ADDRESS ?>/login.php"><?= e(t('login')) ?></a>
        | <a href="<?= ADDRESS ?>/index.php"> <?= e(t('home')) ?></a>
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
  // Ensure language dropdown refs exist before any usage
  const langBtn = document.getElementById('langMenuBtn');
  const langDropdown = document.getElementById('langDropdown');
  
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
    form.action = '<?= ADDRESS ?>/admin_competitions.php';
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
