<?php require_once __DIR__ . '/helper.php'; require_once __DIR__ . '/auth.php'; require_once __DIR__ . '/lang.php';
// Active competition id for header highlighting and actions
$header_active_comp_id = function_exists('get_active_competition_id') ? get_active_competition_id() : null;
// Calendar year used for fallback links
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
<?php $starfieldPath = __DIR__ . '/../assets/js/starfield.js'; $starfieldVer = @filemtime($starfieldPath) ?: time(); ?>
<script src="<?= ADDRESS ?>/assets/js/starfield.js?v=<?= $starfieldVer ?>"></script>
</head>
<?php
  $bodyClasses = [];
  if (isset($body_extra_class) && $body_extra_class) { $bodyClasses[] = $body_extra_class; }
  if (function_exists('current_user') && current_user()) { $bodyClasses[] = 'logged-in'; }
?>
<body class="<?= e(implode(' ', $bodyClasses)) ?>">
<div class="starfield">
  <div class="starfield-origin" style="display: none;"></div>
</div>
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
          <!--<a href="<?= ADDRESS ?>/profile.php" class="dropdown-item">👤 <?= e(t('your_profile')) ?></a>-->
          
          <a href="<?= ADDRESS ?>/stats.php?mine=1" class="dropdown-item">⭐ <?= e(t('your_ratings')) ?></a>
          <a href="<?= ADDRESS ?>/profile.php?settings=1" class="dropdown-item">⚙️ <?= e(t('account_settings')) ?></a>
          <!--<div class="dropdown-divider mobile-lang-section"></div>
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
          <div class="dropdown-divider"></div>-->
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
            // Build list of competitions using date ranges (start, end, name)
            $competitions = [];
            $hasCompetitionsTable = $mysqli->query("SHOW TABLES LIKE 'competitions'")->fetch_all(MYSQLI_NUM);
            if ($hasCompetitionsTable) {
              $rows = $mysqli->query("SELECT id, name, start, end FROM competitions ORDER BY start DESC")->fetch_all(MYSQLI_ASSOC);
              foreach ($rows as $r) {
                $start = trim($r['start'] ?? '');
                $end = trim($r['end'] ?? '');
                // Skip invalid rows (missing or malformed dates)
                if (!$start || !$end || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
                  continue;
                }
                $startYear = (int)date('Y', strtotime($start));
                $nm = trim($r['name'] ?? '');
                if ($nm === '') { $nm = t('competition_placeholder') . ' ' . $startYear; }
                $competitions[] = [
                  'id' => isset($r['id']) ? (int)$r['id'] : null,
                  'name' => $nm,
                  'start' => $start,
                  'end' => $end,
                  'year' => $startYear,
                ];
              }
            }
            // fallback: keep historical years inferred from votes if no competitions are present yet
            if (!$competitions) {
              $years = [];
              $hasVotesYearCol = $mysqli->query("SHOW COLUMNS FROM votes LIKE 'competition_year'")->fetch_all(MYSQLI_ASSOC);
              if ($hasVotesYearCol) {
                $rows2 = $mysqli->query("SELECT DISTINCT COALESCE(competition_year, YEAR(created_at)) AS y FROM votes WHERE (competition_year IS NOT NULL OR created_at IS NOT NULL)")->fetch_all(MYSQLI_ASSOC);
              } else {
                $rows2 = $mysqli->query("SELECT DISTINCT YEAR(created_at) AS y FROM votes WHERE created_at IS NOT NULL")->fetch_all(MYSQLI_ASSOC);
              }
              foreach ($rows2 as $r) { $years[] = (int)$r['y']; }
              $years = array_values(array_unique(array_map('intval', $years)));
              rsort($years, SORT_NUMERIC);
              foreach ($years as $y) {
                $competitions[] = [
                  'name' => 'Competition ' . $y,
                  'start' => $y . '-01-01',
                  'end' => $y . '-12-31',
                  'year' => $y,
                ];
              }
            }
            $actId = $header_active_comp_id;
          ?>
          <?php foreach ($competitions as $comp): ?>
            <?php $isActive = (!empty($comp['id']) && $actId && (int)$comp['id'] === (int)$actId); ?>
            <div class="competition-year-row<?= $isActive ? ' is-active' : '' ?>">
              <div class="competition-meta">
                <a class="dropdown-item competition-year-link" href="<?= ADDRESS ?>/stats.php?year=<?= (int)$comp['year'] ?>">
                  <?= e($comp['name']) ?>
                  <?php if ($isActive): ?><span class="active-badge"><?= e(t('active_badge')) ?></span><?php endif; ?>
                </a>
                <div class="competition-dates"><?= e($comp['start']) ?> → <?= e($comp['end']) ?></div>
              </div>
              <?php if (function_exists('is_admin') && is_admin()): ?>
                <?php if (!empty($comp['id'])): ?>
                  <form method="post" action="<?= ADDRESS ?>/admin_competitions.php" class="admin-form-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="set_active">
                    <input type="hidden" name="comp_id" value="<?= (int)$comp['id'] ?>">
                    <button type="submit" class="dropdown-item admin-btn admin-btn-star" title="<?= e(t('set_active_competition')) ?>">⭐</button>
                  </form>
                <?php endif; ?>
                <form method="post" action="<?= ADDRESS ?>/admin_competitions.php" class="admin-form-inline inline-block">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="start_date" value="<?= e($comp['start']) ?>">
                  <button type="submit" class="dropdown-item admin-btn admin-btn-delete" title="Delete competition">🗑</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if (function_exists('is_admin') && is_admin()): ?>
            <div class="dropdown-divider"></div>
            <button type="button" id="competitionStartFlowBtn" class="dropdown-item admin-btn-create">🎉 <?= e(t('start_competition_flow')) ?></button>
            <form method="post" action="<?= ADDRESS ?>/admin_competitions.php" class="admin-form-flex competition-create" id="competitionCreateForm" style="display:none;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="create">
              <div class="competition-create-fields">
                <label class="competition-create-label"><?= e(t('competition_name_label')) ?></label>
                <input type="text" name="name" id="competitionNameInput" placeholder="<?= e(t('competition_placeholder')) ?>" autocomplete="off">
                <label class="competition-create-label"><?= e(t('competition_start_label')) ?></label>
                <input type="date" name="start_date" id="competitionStartInput" required>
                <label class="competition-create-label"><?= e(t('competition_end_label')) ?></label>
                <input type="date" name="end_date" id="competitionEndInput" required>
              </div>
              <button type="submit" id="competitionCreateBtn" class="dropdown-item admin-btn-create">🎉 <?= e(t('create_competition_action')) ?></button>
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


  // Competition creation form validation
  const compStart = document.getElementById('competitionStartInput');
  const compEnd = document.getElementById('competitionEndInput');
  const compName = document.getElementById('competitionNameInput');
  const compCreateBtn = document.getElementById('competitionCreateBtn');
  const compForm = document.getElementById('competitionCreateForm');
  const compStartFlowBtn = document.getElementById('competitionStartFlowBtn');

  function updateCreateButtonState() {
    if (!compStart || !compEnd || !compCreateBtn) return;
    if (compStart.value) {
      compEnd.min = compStart.value;
    }
    const hasDates = compStart.value && compEnd.value && compStart.value <= compEnd.value;
    compCreateBtn.disabled = !hasDates;
    if (compName && compName.value.trim() === '' && compStart.value) {
      const yearGuess = compStart.value.split('-')[0];
      compName.placeholder = 'Competition ' + yearGuess;
    }
  }

  if (compStart && compEnd && compCreateBtn) {
    compStart.addEventListener('change', () => {
      updateCreateButtonState();
      if (compStart.value && compEnd && !compEnd.value) {
        compEnd.focus();
      }
    });
    compEnd.addEventListener('change', updateCreateButtonState);
    if (compName) {
      compName.addEventListener('input', updateCreateButtonState);
    }
    updateCreateButtonState();
  }

  if (compStartFlowBtn && compForm) {
    compStartFlowBtn.addEventListener('click', () => {
      compForm.style.display = compForm.style.display === 'none' ? 'flex' : 'none';
      if (compForm.style.display !== 'none' && compStart) {
        compStart.focus();
      }
    });
  }
</script>
<main>
