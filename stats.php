<?php 
require_once __DIR__.'/includes/auth.php'; 
require_once __DIR__.'/includes/lang.php';
// Ensure small-screen toolbar appears on stats pages by tagging body
$body_extra_class = 'stats-page';
include __DIR__.'/includes/header.php';

// show flash messages (if any)
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!empty($_SESSION['flash'])) {
  // If there's an edit id set, show an Edit button next to the message
  $msg = e($_SESSION['flash']);
  if (!empty($_SESSION['flash_edit_vote_id'])) {
    $editId = (int)$_SESSION['flash_edit_vote_id'];
    echo "<p class=\"flash\">{$msg} <a class=\"btn\" href=\"vote.php?edit={$editId}\">" . e(t('edit_vote')) . "</a></p>";
    unset($_SESSION['flash_edit_vote_id']);
  } else {
    echo '<p class="flash">' . $msg . '</p>';
  }
  unset($_SESSION['flash']);
}

// Which sheet to show (multi-sheet UI like Excel). Default to compact lists view.
$sheet = isset($_GET['sheet']) ? $_GET['sheet'] : 'lists';

// Resolve active competition (id, window, name, and start-year) from DB
$active_comp_id = function_exists('get_active_competition_id') ? get_active_competition_id() : null;
$active_window_start = null; $active_window_end = null; $activeCompName = '';
$activeYearNumber = (int)date('Y');
try {
  if ($active_comp_id) {
    $stmtC = $mysqli->prepare("SELECT name, start, `end` FROM competitions WHERE id = ? LIMIT 1");
    if ($stmtC) {
      $stmtC->bind_param('i', $active_comp_id);
      $stmtC->execute();
      $rowC = $stmtC->get_result()->fetch_assoc();
      if ($rowC) {
        $activeCompName = $rowC['name'] ?? '';
        $active_window_start = $rowC['start'];
        $active_window_end = $rowC['end'];
        $activeYearNumber = (int)date('Y', strtotime($active_window_start));
      }
    }
  }
  if (!$active_window_start || !$active_window_end) {
    $rowF = $mysqli->query("SELECT name, start, `end` FROM competitions ORDER BY start DESC LIMIT 1")->fetch_assoc();
    if ($rowF) {
      $activeCompName = $rowF['name'] ?? '';
      $active_window_start = $rowF['start'];
      $active_window_end = $rowF['end'];
      $activeYearNumber = (int)date('Y', strtotime($rowF['start']));
    }
  }
} catch (Throwable $e) {
  // keep defaults
}

// Use active competition by default (still allow legacy ?year=... for filtering by competition_year)
$selectedYearNumber = isset($_GET['year']) ? (int)$_GET['year'] : $activeYearNumber;
$viewYearInt = $selectedYearNumber;
$competitionLabel = $activeCompName !== '' ? $activeCompName : (string)$viewYearInt;
// Selected competition status filter ('all', 'in', 'out')
$selected_status = isset($_GET['status']) ? $_GET['status'] : 'all';
// Build list of available years - default to the active competition's start year (legacy)
$years = [$viewYearInt];
// Tab labels in the order matching the workbook
$tabs = [
  'votes' => str_replace('{year}', $competitionLabel, t('sheet_votes')),
  'results' => str_replace('{year}', $competitionLabel, t('sheet_results')),
  'views' => str_replace('{year}', $competitionLabel, t('sheet_views')),
  'judges' => t('sheet_judges'),
  'judges_comp' => t('sheet_judges_comp'),
  'titles' => t('sheet_titles'),
  'adjectives' => t('sheet_adjectives'),
  // finalists should be per-selected-year rather than fixed to 2023
  'finalists' => 'Finalists ' . $competitionLabel,
];

// Global fixed bottom tabs styling for all sheets (keeps bottom tabs visible while scrolling)
?>
<!-- Competition status selector (centered) -->
<div class="year-selector-wrap">
  <form id="statusForm" method="get" action="<?= ADDRESS ?>/stats.php" class="year-selector-form">
    <label for="statusSelect" class="year-selector-label"><?= e(t('select_competition_status')) ?>:</label>
    <select id="statusSelect" name="status" class="year-selector-select">
      <option value="all" <?= $selected_status === 'all' ? 'selected' : '' ?>><?= e(t('all_status')) ?></option>
      <option value="in" <?= $selected_status === 'in' ? 'selected' : '' ?>><?= e(t('filter_in_competition')) ?></option>
      <option value="out" <?= $selected_status === 'out' ? 'selected' : '' ?>><?= e(t('filter_out_of_competition')) ?></option>
    </select>
    <?php // Preserve other GET params (sheet, lang, etc.) when switching status ?>
    <?php foreach ($_GET as $k=>$v): if ($k === 'status') continue; if (is_array($v)) continue; ?>
      <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
    <?php endforeach; ?>
  </form>
  
  <?php
  // Display status details based on selection
  $statusDetail = '';
  if ($selected_status === 'in') {
    $statusDetail = t('status_detail_in_2025'); // generic: within active window
  } elseif ($selected_status === 'out') {
    $statusDetail = t('status_detail_out');
  } elseif ($selected_status === 'all') {
    $statusDetail = t('status_detail_all');
  }
  if ($statusDetail): ?>
    <div class="status-detail-text"><?= e($statusDetail) ?></div>
  <?php endif; ?>
</div>
<script>
  // Auto-submit the form when status selection changes
  (function(){
    var statusSel = document.getElementById('statusSelect');
    if (statusSel) statusSel.addEventListener('change', function(){ document.getElementById('statusForm').submit(); });
  })();
</script>
<?php

// detect whether votes.rating exists in the DB
$cols = $mysqli->query("SHOW COLUMNS FROM votes")->fetch_all(MYSQLI_ASSOC);
$fields = array_column($cols, 'Field');
$hasRating = in_array('rating', $fields);
// active competition year (used to scope queries)
$active_year = function_exists('get_active_year') ? get_active_year() : (int)date('Y');
// Check whether the DB has the competition_year column; if not, don't add year filters (keeps backwards compatibility)
$hasCompetitionYear = in_array('competition_year', $fields);
$activeYearInt = (int)$active_year;
// Use the year selected in the UI for filtering display queries. Keep $activeYearInt as the configured/active
// competition year (admin-facing), but use $viewYearInt when constructing SQL WHERE/JOIN fragments for this page
// so ?year=YYYY controls which year's votes/results are shown.
$viewYearInt = (int)$selected_year;
// competition status filter fragments
$statusCond = '';
$statusCondVd = ''; // for vote_details table
$subStatusV2 = $subStatusV3 = $subStatusV4 = $subStatusV5 = '';
if ($selected_status === 'in') {
  $statusCondVd = " AND COALESCE(vd.competition_status,'') IN ('Concorso','In Competizione','In Competition')";
  $subStatusV2 = " AND COALESCE(vd2.competition_status,'') IN ('Concorso','In Competizione','In Competition')";
  $subStatusV3 = " AND COALESCE(vd3.competition_status,'') IN ('Concorso','In Competizione','In Competition')";
  $subStatusV4 = " AND COALESCE(vd4.competition_status,'') IN ('Concorso','In Competizione','In Competition')";
  $subStatusV5 = " AND COALESCE(vd5.competition_status,'') IN ('Concorso','In Competizione','In Competition')";
} elseif ($selected_status === 'out') {
  $statusCondVd = " AND COALESCE(vd.competition_status,'') NOT IN ('Concorso','In Competizione','In Competition') AND COALESCE(vd.competition_status,'') <> ''";
  $subStatusV2 = " AND COALESCE(vd2.competition_status,'') NOT IN ('Concorso','In Competizione','In Competition') AND COALESCE(vd2.competition_status,'') <> ''";
  $subStatusV3 = " AND COALESCE(vd3.competition_status,'') NOT IN ('Concorso','In Competizione','In Competition') AND COALESCE(vd3.competition_status,'') <> ''";
  $subStatusV4 = " AND COALESCE(vd4.competition_status,'') NOT IN ('Concorso','In Competizione','In Competition') AND COALESCE(vd4.competition_status,'') <> ''";
  $subStatusV5 = " AND COALESCE(vd5.competition_status,'') NOT IN ('Concorso','In Competizione','In Competition') AND COALESCE(vd5.competition_status,'') <> ''";
}

// year filter fragments to reuse in queries (use selected/view year)
$yearCond = '';
$whereYearClause = '';
$leftJoinVotesForResults = "LEFT JOIN votes v ON v.movie_id = m.id";
$subYearV2 = $subYearV3 = $subYearV4 = $subYearV5 = '';
if ($hasCompetitionYear) {
  $yearCond = " AND v.competition_year = " . $viewYearInt;
  $whereYearClause = "WHERE v.competition_year = " . $viewYearInt;
  $leftJoinVotesForResults = "LEFT JOIN votes v ON v.movie_id = m.id AND v.competition_year = " . $viewYearInt;
  $subYearV2 = $subYearV3 = $subYearV4 = $subYearV5 = " AND v2.competition_year = " . $viewYearInt; // will be overwritten individually below when needed
  // correct the per-subquery fragments
  $subYearV2 = " AND v2.competition_year = " . $viewYearInt;
  $subYearV3 = " AND v3.competition_year = " . $viewYearInt;
  $subYearV4 = " AND v4.competition_year = " . $viewYearInt;
  $subYearV5 = " AND v5.competition_year = " . $viewYearInt;
} else {
  // Fallback: filter by YEAR(created_at) when competition_year column is not present
  $yearCond = " AND YEAR(v.created_at) = " . $viewYearInt;
  $whereYearClause = "WHERE YEAR(v.created_at) = " . $viewYearInt;
  // For the LEFT JOIN used in results aggregation we keep the simple join; subqueries need YEAR() checks
  $leftJoinVotesForResults = "LEFT JOIN votes v ON v.movie_id = m.id";
  $subYearV2 = " AND YEAR(v2.created_at) = " . $viewYearInt;
  $subYearV3 = " AND YEAR(v3.created_at) = " . $viewYearInt;
  $subYearV4 = " AND YEAR(v4.created_at) = " . $viewYearInt;
  $subYearV5 = " AND YEAR(v5.created_at) = " . $viewYearInt;
}

// Reusable rating expression for compact stats (fallback to average of detail columns when rating is absent)
$vdColsGlobal = $mysqli->query("SHOW COLUMNS FROM vote_details")->fetch_all(MYSQLI_ASSOC);
$scoreCandidatesGlobal = ['writing','direction','acting_or_doc_theme','emotional_involvement','novelty','casting_research_art','sound'];
$haveGlobal = array_map(function($c){ return $c['Field']; }, $vdColsGlobal);
$scoreColsGlobal = array_values(array_filter($scoreCandidatesGlobal, function($c) use ($haveGlobal){ return in_array($c,$haveGlobal); }));
$numExprGlobal = null;
if ($scoreColsGlobal) {
  $numParts = array_map(function($col){ return "COALESCE(vd.`$col`,0)"; }, $scoreColsGlobal);
  $numExprGlobal = implode('+', $numParts);
}
// Per-vote total score = sum of all detail scores (we average these totals across jurors)
$ratingExprGlobal = $numExprGlobal ? "($numExprGlobal)" : 'NULL';

// All users can view voting results (no mine parameter filtering)

// COMPACT LISTS sheet (mobile-friendly dropdown lists)
if ($sheet === 'lists') {
  // Best of by category: Movies, Series, Documentaries
  $bestMoviesSql = "SELECT m.id, m.title, m.year, m.type, m.poster_url, vd.season_number, COUNT(v.id) AS votes_count, ROUND(AVG($ratingExprGlobal),2) AS avg_rating
              FROM movies m
              JOIN votes v ON v.movie_id = m.id
              LEFT JOIN vote_details vd ON vd.vote_id = v.id
              " . $whereYearClause . $statusCondVd . " AND (vd.category IN ('Film') OR (vd.category IS NULL OR vd.category = '') AND m.type = 'movie')
              GROUP BY m.id, vd.season_number
              HAVING votes_count > 0
              ORDER BY avg_rating DESC, votes_count DESC, m.title ASC
              LIMIT 25";
  error_log("[STATS DEBUG] bestMoviesSql: " . $bestMoviesSql);
  $result = $mysqli->query($bestMoviesSql);
  if (!$result) {
    error_log("[STATS ERROR] Movies query failed: " . $mysqli->error);
    $bestMoviesRows = [];
  } else {
    $bestMoviesRows = $result->fetch_all(MYSQLI_ASSOC);
  }

  $bestSeriesSql = "SELECT m.id, m.title, m.year, m.type, m.poster_url, vd.season_number, COUNT(v.id) AS votes_count, ROUND(AVG($ratingExprGlobal),2) AS avg_rating
              FROM movies m
              JOIN votes v ON v.movie_id = m.id
              LEFT JOIN vote_details vd ON vd.vote_id = v.id
              " . $whereYearClause . $statusCondVd . " AND (vd.category IN ('Series') OR (vd.category IS NULL OR vd.category = '') AND m.type = 'series')
              GROUP BY m.id, vd.season_number
              HAVING votes_count > 0
              ORDER BY avg_rating DESC, votes_count DESC, m.title ASC
              LIMIT 25";
  $result = $mysqli->query($bestSeriesSql);
  if (!$result) {
    error_log("[STATS ERROR] Series query failed: " . $mysqli->error);
    $bestSeriesRows = [];
  } else {
    $bestSeriesRows = $result->fetch_all(MYSQLI_ASSOC);
  }

  // For documentaries, we'll check for 'movie' type but could add a category field check later
  $bestDocsSql = "SELECT m.id, m.title, m.year, m.type, m.poster_url, vd.season_number, COUNT(v.id) AS votes_count, ROUND(AVG($ratingExprGlobal),2) AS avg_rating
              FROM movies m
              JOIN votes v ON v.movie_id = m.id
              LEFT JOIN vote_details vd ON vd.vote_id = v.id
              " . $whereYearClause . $statusCondVd . " AND (vd.category IN ('Documentary','Documentario') OR m.type = 'documentary' OR m.title LIKE '%document%')
              GROUP BY m.id, vd.season_number
              HAVING votes_count > 0
              ORDER BY avg_rating DESC, votes_count DESC, m.title ASC
              LIMIT 25";
  $result = $mysqli->query($bestDocsSql);
  if (!$result) {
    error_log("[STATS ERROR] Docs query failed: " . $mysqli->error);
    $bestDocsRows = [];
  } else {
    $bestDocsRows = $result->fetch_all(MYSQLI_ASSOC);
  }

  $bestMiniseriesSql = "SELECT m.id, m.title, m.year, m.type, m.poster_url, vd.season_number, COUNT(v.id) AS votes_count, ROUND(AVG($ratingExprGlobal),2) AS avg_rating
              FROM movies m
              JOIN votes v ON v.movie_id = m.id
              LEFT JOIN vote_details vd ON vd.vote_id = v.id
              " . $whereYearClause . $statusCondVd . " AND (vd.category IN ('Miniseries','Miniserie') OR m.type = 'miniseries')
              GROUP BY m.id, vd.season_number
              HAVING votes_count > 0
              ORDER BY avg_rating DESC, votes_count DESC, m.title ASC
              LIMIT 25";
  $result = $mysqli->query($bestMiniseriesSql);
  if (!$result) {
    error_log("[STATS ERROR] Miniseries query failed: " . $mysqli->error);
    $bestMiniseriesRows = [];
  } else {
    $bestMiniseriesRows = $result->fetch_all(MYSQLI_ASSOC);
  }

  $bestAnimationSql = "SELECT m.id, m.title, m.year, m.type, m.poster_url, vd.season_number, COUNT(v.id) AS votes_count, ROUND(AVG($ratingExprGlobal),2) AS avg_rating
              FROM movies m
              JOIN votes v ON v.movie_id = m.id
              LEFT JOIN vote_details vd ON vd.vote_id = v.id
              " . $whereYearClause . $statusCondVd . " AND (vd.category IN ('Animation','Animazione') OR m.type = 'animation')
              GROUP BY m.id, vd.season_number
              HAVING votes_count > 0
              ORDER BY avg_rating DESC, votes_count DESC, m.title ASC
              LIMIT 25";
  $result = $mysqli->query($bestAnimationSql);
  if (!$result) {
    error_log("[STATS ERROR] Animation query failed: " . $mysqli->error);
    $bestAnimationRows = [];
  } else {
    $bestAnimationRows = $result->fetch_all(MYSQLI_ASSOC);
  }

  // Most viewed: ordered by view count
  $viewsSql = "SELECT m.id, m.title, m.year, m.poster_url, vd.season_number, COUNT(v.id) AS views
               FROM movies m
               JOIN votes v ON v.movie_id = m.id
               LEFT JOIN vote_details vd ON vd.vote_id = v.id
               " . $whereYearClause . $statusCondVd . "
               GROUP BY m.id, vd.season_number
               ORDER BY views DESC, m.title ASC
               LIMIT 25";
  $viewsRows = $mysqli->query($viewsSql)->fetch_all(MYSQLI_ASSOC);

  // Jurors: Get all jurors with their stats
  $judgesSql = "SELECT u.id AS user_id, u.username AS judge, COUNT(v.id) AS votes_count, ROUND(AVG($ratingExprGlobal),2) AS avg_rating
                FROM votes v
                JOIN users u ON u.id = v.user_id
                LEFT JOIN vote_details vd ON vd.vote_id = v.id
                " . $whereYearClause . $statusCondVd . "
                GROUP BY u.id, u.username
                ORDER BY votes_count DESC, u.username ASC";
  $judgesRows = $mysqli->query($judgesSql)->fetch_all(MYSQLI_ASSOC);

  // For each juror, get their voted titles with detail scores
  $jurorTitles = [];
  foreach ($judgesRows as $juror) {
    $jurorId = $juror['user_id'];
    $titlesSql = "SELECT m.id, m.title, m.year, vd.season_number, ROUND($ratingExprGlobal,2) AS rating, 
                  vd.writing, vd.direction, vd.acting_or_doc_theme, vd.emotional_involvement, 
                  vd.novelty, vd.casting_research_art, vd.sound
                  FROM votes v
                  JOIN movies m ON m.id = v.movie_id
                  LEFT JOIN vote_details vd ON vd.vote_id = v.id
                  WHERE v.user_id = ? " . str_replace('WHERE', 'AND', $whereYearClause) . $statusCondVd . "
                  ORDER BY rating DESC, m.title ASC";
    $stmt = $mysqli->prepare($titlesSql);
    $stmt->bind_param('i', $jurorId);
    $stmt->execute();
    $jurorTitles[$jurorId] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  }

  // Handle movie details modal for Best Of section
  $movieModalData = null;
  if (isset($_GET['view_movie']) && $_GET['view_movie']) {
    $movieId = (int)$_GET['view_movie'];
    // Get movie basic info
    $movieStmt = $mysqli->prepare("SELECT * FROM movies WHERE id = ?");
    $movieStmt->bind_param('i', $movieId);
    $movieStmt->execute();
    $movieInfo = $movieStmt->get_result()->fetch_assoc();
    
    if ($movieInfo) {
      // Get all votes for this movie
      $votesSql = "SELECT u.username, v.id as vote_id, ROUND($ratingExprGlobal,2) AS overall_rating,
                   vd.writing, vd.direction, vd.acting_or_doc_theme, vd.emotional_involvement, 
                   vd.novelty, vd.casting_research_art, vd.sound
                   FROM votes v
                   JOIN users u ON u.id = v.user_id
                   LEFT JOIN vote_details vd ON vd.vote_id = v.id
                   WHERE v.movie_id = ? " . str_replace('WHERE', 'AND', $whereYearClause) . $statusCondVd . "
                   ORDER BY overall_rating DESC, u.username ASC";
      $votesStmt = $mysqli->prepare($votesSql);
      $votesStmt->bind_param('i', $movieId);
      $votesStmt->execute();
      $votes = $votesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
      
      $movieModalData = [
        'info' => $movieInfo,
        'votes' => $votes
      ];
    }
  }
  ?>

  <div class="stats-dashboard">
    <div class="stats-header">
      <div>
        <h2 class="stats-title"><?= e(t('stats_compact_title')) ?></h2>
        <p class="stats-sub"><?= e(str_replace('{year}', $viewYearInt, t('sheet_results'))) ?></p>
      </div>
      <div class="nav-buttons">
        <?php if (function_exists('is_admin') && is_admin()): ?>
          <a href="export_results.php?year=<?= $viewYearInt ?>&status=<?= urlencode($selected_status) ?>" class="btn">⬇ <?= t('download_excel') ?></a>
        <?php endif; ?>
      </div>
    </div>

    <div class="stats-accordion">
      <div class="stat-card">
        <button class="stat-toggle" data-target="stat-best" aria-expanded="true">
          <span><?= t('best_of') ?></span>
          <span class="chevron">▾</span>
        </button>
        <div id="stat-best" class="stat-content open">
          <!-- Nested: Movies -->
          <div class="stat-nested-card">
            <button class="stat-nested-toggle" data-target="stat-best-movies" aria-expanded="false">
              <span>🎬 <?= t('movies') ?></span>
              <span class="chevron">▾</span>
            </button>
            <div id="stat-best-movies" class="stat-nested-content">
              <?php if ($bestMoviesRows): ?>
                <ol class="ranked-list">
                  <?php foreach ($bestMoviesRows as $idx => $row): ?>
                    <li>
                      <a href="?sheet=lists&year=<?= $viewYearInt ?>&view_movie=<?= $row['id'] ?>" class="stat-line stat-line-with-poster movie-link" data-movie-id="<?= $row['id'] ?>">
                        <span class="rank">#<?= $idx + 1 ?></span>
                        <?php if ($row['poster_url'] && $row['poster_url'] !== 'N/A'): ?>
                          <img src="<?= htmlspecialchars($row['poster_url']) ?>" alt="<?= e($row['title']) ?>" class="stat-poster" loading="lazy">
                        <?php else: ?>
                          <div class="stat-poster stat-poster-empty">📽️</div>
                        <?php endif; ?>
                        <div class="stat-main">
                          <div class="stat-title">
                            <?= e($row['title']) ?>
                            <?php if (!empty($row['season_number'])): ?>
                              <span style="color:#f6c90e;font-weight:600;"> - Season <?= (int)$row['season_number'] ?></span>
                            <?php endif; ?>
                            <span class="muted">(<?= e($row['year']) ?>)</span>
                          </div>
                          <div class="stat-meta"><?= t('average_rating') ?>: <strong><?= $row['avg_rating'] !== null ? number_format($row['avg_rating'],2) : '' ?></strong> · <?= (int)$row['votes_count'] ?> <?= t('votes') ?></div>
                        </div>
                      </a>
                    </li>
                  <?php endforeach; ?>
                </ol>
              <?php else: ?>
                <p class="stat-empty"><?= e(t('no_data_yet')) ?></p>
              <?php endif; ?>
            </div>
          </div>

          <!-- Nested: TV Series -->
          <div class="stat-nested-card">
            <button class="stat-nested-toggle" data-target="stat-best-series" aria-expanded="false">
              <span>📺 <?= t('series') ?></span>
              <span class="chevron">▾</span>
            </button>
            <div id="stat-best-series" class="stat-nested-content">
              <?php if ($bestSeriesRows): ?>
                <ol class="ranked-list">
                  <?php foreach ($bestSeriesRows as $idx => $row): ?>
                    <li>
                      <a href="?sheet=lists&year=<?= $viewYearInt ?>&view_movie=<?= $row['id'] ?>" class="stat-line stat-line-with-poster movie-link" data-movie-id="<?= $row['id'] ?>">
                        <span class="rank">#<?= $idx + 1 ?></span>
                        <?php if ($row['poster_url'] && $row['poster_url'] !== 'N/A'): ?>
                          <img src="<?= htmlspecialchars($row['poster_url']) ?>" alt="<?= e($row['title']) ?>" class="stat-poster" loading="lazy">
                        <?php else: ?>
                          <div class="stat-poster stat-poster-empty">📺</div>
                        <?php endif; ?>
                        <div class="stat-main">
                          <div class="stat-title">
                            <?= e($row['title']) ?>
                            <?php if (!empty($row['season_number'])): ?>
                              <span style="color:#f6c90e;font-weight:600;"> - Season <?= (int)$row['season_number'] ?></span>
                            <?php endif; ?>
                            <span class="muted">(<?= e($row['year']) ?>)</span>
                          </div>
                          <div class="stat-meta"><?= t('average_rating') ?>: <strong><?= $row['avg_rating'] !== null ? number_format($row['avg_rating'],2) : '' ?></strong> · <?= (int)$row['votes_count'] ?> <?= t('votes') ?></div>
                        </div>
                      </a>
                    </li>
                  <?php endforeach; ?>
                </ol>
              <?php else: ?>
                <p class="stat-empty"><?= e(t('no_data_yet')) ?></p>
              <?php endif; ?>
            </div>
          </div>

          <!-- Nested: Documentaries -->
          <div class="stat-nested-card">
            <button class="stat-nested-toggle" data-target="stat-best-docs" aria-expanded="false">
              <span>🎥 <?= t('documentaries') ?></span>
              <span class="chevron">▾</span>
            </button>
            <div id="stat-best-docs" class="stat-nested-content">
              <?php if ($bestDocsRows): ?>
                <ol class="ranked-list">
                  <?php foreach ($bestDocsRows as $idx => $row): ?>
                    <li>
                      <a href="?sheet=lists&year=<?= $viewYearInt ?>&view_movie=<?= $row['id'] ?>" class="stat-line stat-line-with-poster movie-link" data-movie-id="<?= $row['id'] ?>">
                        <span class="rank">#<?= $idx + 1 ?></span>
                        <?php if ($row['poster_url'] && $row['poster_url'] !== 'N/A'): ?>
                          <img src="<?= htmlspecialchars($row['poster_url']) ?>" alt="<?= e($row['title']) ?>" class="stat-poster" loading="lazy">
                        <?php else: ?>
                          <div class="stat-poster stat-poster-empty">🎥</div>
                        <?php endif; ?>
                        <div class="stat-main">
                          <div class="stat-title">
                            <?= e($row['title']) ?>
                            <?php if (!empty($row['season_number'])): ?>
                              <span style="color:#f6c90e;font-weight:600;"> - Season <?= (int)$row['season_number'] ?></span>
                            <?php endif; ?>
                            <span class="muted">(<?= e($row['year']) ?>)</span>
                          </div>
                          <div class="stat-meta"><?= t('average_rating') ?>: <strong><?= $row['avg_rating'] !== null ? number_format($row['avg_rating'],2) : '' ?></strong> · <?= (int)$row['votes_count'] ?> <?= t('votes') ?></div>
                        </div>
                      </a>
                    </li>
                  <?php endforeach; ?>
                </ol>
              <?php else: ?>
                <p class="stat-empty"><?= e(t('no_data_yet')) ?></p>
              <?php endif; ?>
            </div>
          </div>

          <!-- Nested: Miniseries -->
          <div class="stat-nested-card">
            <button class="stat-nested-toggle" data-target="stat-best-miniseries" aria-expanded="false">
              <span>📺 <?= t('miniseries') ?></span>
              <span class="chevron">▾</span>
            </button>
            <div id="stat-best-miniseries" class="stat-nested-content">
              <?php if ($bestMiniseriesRows): ?>
                <ol class="ranked-list">
                  <?php foreach ($bestMiniseriesRows as $idx => $row): ?>
                    <li>
                      <a href="?sheet=lists&year=<?= $viewYearInt ?>&view_movie=<?= $row['id'] ?>" class="stat-line stat-line-with-poster movie-link" data-movie-id="<?= $row['id'] ?>">
                        <span class="rank">#<?= $idx + 1 ?></span>
                        <?php if ($row['poster_url'] && $row['poster_url'] !== 'N/A'): ?>
                          <img src="<?= htmlspecialchars($row['poster_url']) ?>" alt="<?= e($row['title']) ?>" class="stat-poster" loading="lazy">
                        <?php else: ?>
                          <div class="stat-poster stat-poster-empty">📺</div>
                        <?php endif; ?>
                        <div class="stat-main">
                          <div class="stat-title">
                            <?= e($row['title']) ?>
                            <?php if (!empty($row['season_number'])): ?>
                              <span style="color:#f6c90e;font-weight:600;"> - Season <?= (int)$row['season_number'] ?></span>
                            <?php endif; ?>
                            <span class="muted">(<?= e($row['year']) ?>)</span>
                          </div>
                          <div class="stat-meta"><?= t('average_rating') ?>: <strong><?= $row['avg_rating'] !== null ? number_format($row['avg_rating'],2) : '' ?></strong> · <?= (int)$row['votes_count'] ?> <?= t('votes') ?></div>
                        </div>
                      </a>
                    </li>
                  <?php endforeach; ?>
                </ol>
              <?php else: ?>
                <p class="stat-empty"><?= e(t('no_data_yet')) ?></p>
              <?php endif; ?>
            </div>
          </div>

          <!-- Nested: Animation -->
          <div class="stat-nested-card">
            <button class="stat-nested-toggle" data-target="stat-best-animation" aria-expanded="false">
              <span>🎨 <?= t('animation') ?></span>
              <span class="chevron">▾</span>
            </button>
            <div id="stat-best-animation" class="stat-nested-content">
              <?php if ($bestAnimationRows): ?>
                <ol class="ranked-list">
                  <?php foreach ($bestAnimationRows as $idx => $row): ?>
                    <li>
                      <a href="?sheet=lists&year=<?= $viewYearInt ?>&view_movie=<?= $row['id'] ?>" class="stat-line stat-line-with-poster movie-link" data-movie-id="<?= $row['id'] ?>">
                        <span class="rank">#<?= $idx + 1 ?></span>
                        <?php if ($row['poster_url'] && $row['poster_url'] !== 'N/A'): ?>
                          <img src="<?= htmlspecialchars($row['poster_url']) ?>" alt="<?= e($row['title']) ?>" class="stat-poster" loading="lazy">
                        <?php else: ?>
                          <div class="stat-poster stat-poster-empty">🎨</div>
                        <?php endif; ?>
                        <div class="stat-main">
                          <div class="stat-title">
                            <?= e($row['title']) ?>
                            <?php if (!empty($row['season_number'])): ?>
                              <span style="color:#f6c90e;font-weight:600;"> - Season <?= (int)$row['season_number'] ?></span>
                            <?php endif; ?>
                            <span class="muted">(<?= e($row['year']) ?>)</span>
                          </div>
                          <div class="stat-meta"><?= t('average_rating') ?>: <strong><?= $row['avg_rating'] !== null ? number_format($row['avg_rating'],2) : '' ?></strong> · <?= (int)$row['votes_count'] ?> <?= t('votes') ?></div>
                        </div>
                      </a>
                    </li>
                  <?php endforeach; ?>
                </ol>
              <?php else: ?>
                <p class="stat-empty"><?= e(t('no_data_yet')) ?></p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="stat-card">
        <button class="stat-toggle" data-target="stat-views" aria-expanded="false">
          <span><?= t('most_viewed') ?></span>
          <span class="chevron">▾</span>
        </button>
        <div id="stat-views" class="stat-content">
          <?php if ($viewsRows): ?>
            <ol class="ranked-list">
              <?php foreach ($viewsRows as $idx => $row): ?>
                <li>
                  <div class="stat-line stat-line-with-poster">
                    <span class="rank">#<?= $idx + 1 ?></span>
                    <?php if ($row['poster_url'] && $row['poster_url'] !== 'N/A'): ?>
                      <img src="<?= htmlspecialchars($row['poster_url']) ?>" alt="<?= e($row['title']) ?>" class="stat-poster" loading="lazy">
                    <?php else: ?>
                      <div class="stat-poster stat-poster-empty">📽️</div>
                    <?php endif; ?>
                    <div class="stat-main">
                      <div class="stat-title">
                        <?= e($row['title']) ?>
                        <?php if (!empty($row['season_number'])): ?>
                          <span style="color:#f6c90e;font-weight:600;"> - Season <?= (int)$row['season_number'] ?></span>
                        <?php endif; ?>
                        <span class="muted">(<?= e($row['year']) ?>)</span>
                      </div>
                      <div class="stat-meta"><?= t('views') ?>: <strong><?= (int)$row['views'] ?></strong></div>
                    </div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ol>
          <?php else: ?>
            <p class="stat-empty"><?= e(t('no_data_yet')) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="stat-card">
        <button class="stat-toggle" data-target="stat-judges" aria-expanded="false">
          <span><?= t('jurors') ?></span>
          <span class="chevron">▾</span>
        </button>
        <div id="stat-judges" class="stat-content">
          <?php if ($judgesRows): ?>
            <?php foreach ($judgesRows as $idx => $juror): ?>
              <div class="stat-nested-card">
                <button class="stat-nested-toggle" data-target="stat-judge-<?= $juror['user_id'] ?>" aria-expanded="false">
                  <span class="juror-header">
                    <span class="juror-rank">#<?= $idx + 1 ?></span>
                    <span class="juror-name"><?= e($juror['judge']) ?></span>
                  </span>
                  <span class="juror-stats-inline">
                    <?= (int)$juror['votes_count'] ?> <?= t('votes') ?>
                    <?php if ($juror['avg_rating'] !== null): ?>
                      · <?= number_format($juror['avg_rating'],2) ?> avg
                    <?php endif; ?>
                  </span>
                  <span class="chevron">▾</span>
                </button>
                <div id="stat-judge-<?= $juror['user_id'] ?>" class="stat-nested-content">
                  <div class="juror-details">
                    <div class="juror-stats">
                      <div class="juror-stat-item">
                        <span class="stat-label"><?= t('total_votes') ?>:</span>
                        <span class="stat-value"><?= (int)$juror['votes_count'] ?></span>
                      </div>
                      <?php if ($juror['avg_rating'] !== null): ?>
                        <div class="juror-stat-item">
                          <span class="stat-label"><?= t('average_rating') ?>:</span>
                          <span class="stat-value"><?= number_format($juror['avg_rating'],2) ?></span>
                        </div>
                      <?php endif; ?>
                    </div>
                    
                    <div class="juror-titles">
                      <h4><?= t('voted_titles') ?></h4>
                      <?php if (isset($jurorTitles[$juror['user_id']]) && $jurorTitles[$juror['user_id']]): ?>
                        <ol class="titles-list">
                          <?php foreach ($jurorTitles[$juror['user_id']] as $title): ?>
                            <li>
                              <div class="title-header">
                                <span class="title-name">
                                  <?= e($title['title']) ?>
                                  <?php if (!empty($title['season_number'])): ?>
                                    <span style="color:#f6c90e;font-weight:600;"> - Season <?= (int)$title['season_number'] ?></span>
                                  <?php endif; ?>
                                  <span class="muted">(<?= e($title['year']) ?>)</span>
                                </span>
                                <?php if ($title['rating'] !== null): ?>
                                  <span class="title-rating">⭐ <?= number_format($title['rating'],2) ?></span>
                                <?php endif; ?>
                              </div>
                              <?php 
                                // Check which detail columns have values
                                $details = [];
                                if (!empty($title['writing'])) $details[] = 'Writing: ' . $title['writing'];
                                if (!empty($title['direction'])) $details[] = 'Direction: ' . $title['direction'];
                                if (!empty($title['acting_or_doc_theme'])) $details[] = 'Acting/Theme: ' . $title['acting_or_doc_theme'];
                                if (!empty($title['emotional_involvement'])) $details[] = 'Emotional: ' . $title['emotional_involvement'];
                                if (!empty($title['novelty'])) $details[] = 'Novelty: ' . $title['novelty'];
                                if (!empty($title['casting_research_art'])) $details[] = 'Casting/Art: ' . $title['casting_research_art'];
                                if (!empty($title['sound'])) $details[] = 'Sound: ' . $title['sound'];
                                if ($details): 
                              ?>
                                <div class="title-details">
                                  <?php foreach ($details as $detail): ?>
                                    <span class="detail-badge"><?= e($detail) ?></span>
                                  <?php endforeach; ?>
                                </div>
                              <?php endif; ?>
                            </li>
                          <?php endforeach; ?>
                        </ol>
                      <?php else: ?>
                        <p class="stat-empty"><?= e(t('no_votes_yet')) ?></p>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="stat-empty"><?= e(t('no_data_yet')) ?></p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Movie Details Modal -->
  <?php if ($movieModalData): ?>
  <div id="movieModal" class="movie-modal open">
    <div class="movie-modal-overlay" onclick="closeMovieModal()"></div>
    <div class="movie-modal-content">
      <button class="movie-modal-close" onclick="closeMovieModal()">✕</button>
      
      <div class="modal-header">
        <?php if ($movieModalData['info']['poster_url'] && $movieModalData['info']['poster_url'] !== 'N/A'): ?>
          <img src="<?= htmlspecialchars($movieModalData['info']['poster_url']) ?>" alt="<?= e($movieModalData['info']['title']) ?>" class="modal-poster">
        <?php else: ?>
          <div class="modal-poster modal-poster-empty">📽️</div>
        <?php endif; ?>
        
        <div class="modal-info">
          <h2><?= e($movieModalData['info']['title']) ?></h2>
          <p class="modal-year"><?= e($movieModalData['info']['year']) ?></p>
          <div class="modal-stats">
            <div class="modal-stat">
              <span class="label"><?= t('total_votes') ?>:</span>
              <span class="value"><?= count($movieModalData['votes']) ?></span>
            </div>
            <?php 
              $avgRating = 0;
              foreach ($movieModalData['votes'] as $v) {
                $avgRating += floatval($v['overall_rating'] ?? 0);
              }
              if (count($movieModalData['votes']) > 0) {
                $avgRating = $avgRating / count($movieModalData['votes']);
              }
            ?>
            <div class="modal-stat">
              <span class="label"><?= t('average_rating') ?>:</span>
              <span class="value"><?= number_format($avgRating, 2) ?></span>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-votes">
        <h3><?= t('vote_details') ?></h3>
        <?php if ($movieModalData['votes']): ?>
          <table class="votes-detail-table">
            <thead>
              <tr>
                <th><?= t('juror') ?></th>
                <th><?= t('overall_rating') ?></th>
                <th>Writing</th>
                <th>Direction</th>
                <th>Acting/Theme</th>
                <th>Emotional</th>
                <th>Novelty</th>
                <th>Casting/Art</th>
                <th>Sound</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($movieModalData['votes'] as $vote): ?>
                <tr>
                  <td class="juror-name"><?= e($vote['username']) ?></td>
                  <td class="overall-rating"><?= $vote['overall_rating'] !== null ? number_format($vote['overall_rating'], 2) : '-' ?></td>
                  <td><?= $vote['writing'] !== null && $vote['writing'] !== '' ? $vote['writing'] : '-' ?></td>
                  <td><?= $vote['direction'] !== null && $vote['direction'] !== '' ? $vote['direction'] : '-' ?></td>
                  <td><?= $vote['acting_or_doc_theme'] !== null && $vote['acting_or_doc_theme'] !== '' ? $vote['acting_or_doc_theme'] : '-' ?></td>
                  <td><?= $vote['emotional_involvement'] !== null && $vote['emotional_involvement'] !== '' ? $vote['emotional_involvement'] : '-' ?></td>
                  <td><?= $vote['novelty'] !== null && $vote['novelty'] !== '' ? $vote['novelty'] : '-' ?></td>
                  <td><?= $vote['casting_research_art'] !== null && $vote['casting_research_art'] !== '' ? $vote['casting_research_art'] : '-' ?></td>
                  <td><?= $vote['sound'] !== null && $vote['sound'] !== '' ? $vote['sound'] : '-' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <p class="stat-empty"><?= e(t('no_votes_yet')) ?></p>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <script>
    function closeMovieModal() {
      var modal = document.getElementById('movieModal');
      if (modal) {
        modal.classList.remove('open');
        // Remove the view_movie parameter from URL
        window.history.replaceState({}, document.title, '?sheet=lists&year=<?= $viewYearInt ?>&status=<?= urlencode($selected_status) ?>');
      }
    }

    (function(){
      // Main toggles (Best Of, Most Viewed, Jurors)
      var toggles = document.querySelectorAll('.stat-toggle');
      toggles.forEach(function(btn){
        btn.addEventListener('click', function(){
          var targetId = btn.getAttribute('data-target');
          var target = document.getElementById(targetId);
          var isOpen = btn.getAttribute('aria-expanded') === 'true';
          // Close all other main sections
          toggles.forEach(function(other){
            var otherTarget = document.getElementById(other.getAttribute('data-target'));
            if (other === btn) {
              other.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
              if (otherTarget) otherTarget.classList.toggle('open', !isOpen);
            } else {
              other.setAttribute('aria-expanded', 'false');
              if (otherTarget) otherTarget.classList.remove('open');
            }
          });
        });
      });

      // Nested toggles (Movies, Series, Documentaries, Individual Jurors)
      var nestedToggles = document.querySelectorAll('.stat-nested-toggle');
      nestedToggles.forEach(function(btn){
        btn.addEventListener('click', function(e){
          e.stopPropagation(); // Prevent triggering parent toggle
          var targetId = btn.getAttribute('data-target');
          var target = document.getElementById(targetId);
          var isOpen = btn.getAttribute('aria-expanded') === 'true';
          
          // Toggle this nested item
          btn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
          if (target) target.classList.toggle('open', !isOpen);
        });
      });
    })();
  </script>

  <?php include __DIR__.'/includes/footer.php';
  exit;
}

// RESULTS sheet (aggregated movie results)
if ($sheet === 'results') {
  // Build rating expression and available detail columns
  $vdCols = $mysqli->query("SHOW COLUMNS FROM vote_details")->fetch_all(MYSQLI_ASSOC);
  $detailCols = ['writing','direction','acting_or_doc_theme','emotional_involvement','novelty','casting_research_art','sound'];
  $have = array_map(function($c){return $c['Field'];}, $vdCols);
  $detailCols = array_values(array_filter($detailCols, function($c) use ($have){ return in_array($c,$have); }));
  $numExpr = null;
  if ($detailCols) {
    $numParts = array_map(function($col){ return "COALESCE(vd.`$col`,0)"; }, $detailCols);
    $numExpr = implode('+', $numParts);
  }
  // Aggregate using per-vote total (sum of category scores)
  $ratingExpr = $numExpr ? "($numExpr)" : 'NULL';

  $avgSelects = [];
  foreach ($detailCols as $c) { $avgSelects[] = "ROUND(AVG(vd.`$c`),2) AS avg_$c"; }
  $avgSelectSql = $avgSelects ? (",\n             ".implode(",\n             ", $avgSelects)) : '';

  $sql = "
      SELECT m.id, m.title, m.year,
             COUNT(v.id) AS votes_count,
             ROUND(AVG($ratingExpr),2) AS avg_rating
             $avgSelectSql,
                   (
                     SELECT vd2.category FROM vote_details vd2
                     JOIN votes v2 ON v2.id = vd2.vote_id
                     WHERE v2.movie_id = m.id" . $subYearV2 . $subStatusV2 . " AND TRIM(COALESCE(vd2.category,''))<>''
                     GROUP BY vd2.category ORDER BY COUNT(*) DESC LIMIT 1
                   ) AS category_mode,
             (
               SELECT vd3.where_watched FROM vote_details vd3
               JOIN votes v3 ON v3.id = vd3.vote_id
               WHERE v3.movie_id = m.id" . $subYearV3 . $subStatusV3 . " AND TRIM(COALESCE(vd3.where_watched,''))<>''
               GROUP BY vd3.where_watched ORDER BY COUNT(*) DESC LIMIT 1
             ) AS platform_mode,
             (
               SELECT vd4.competition_status FROM vote_details vd4
               JOIN votes v4 ON v4.id = vd4.vote_id
               WHERE v4.movie_id = m.id" . $subYearV4 . $subStatusV4 . " AND TRIM(COALESCE(vd4.competition_status,''))<>''
               GROUP BY vd4.competition_status ORDER BY COUNT(*) DESC LIMIT 1
             ) AS comp_mode,
             (
               SELECT GROUP_CONCAT(DISTINCT TRIM(vd5.adjective) ORDER BY TRIM(vd5.adjective) SEPARATOR ', ')
               FROM vote_details vd5 JOIN votes v5 ON v5.id = vd5.vote_id
               WHERE v5.movie_id = m.id" . $subYearV5 . $subStatusV5 . " AND TRIM(COALESCE(vd5.adjective,''))<>''
             ) AS adjectives
      FROM movies m
      " . $leftJoinVotesForResults . "
      LEFT JOIN vote_details vd ON vd.vote_id = v.id" . $statusCondVd . "
      GROUP BY m.id
      HAVING votes_count > 0
      ORDER BY avg_rating DESC, votes_count DESC
  ";
  $rows = $mysqli->query($sql)->fetch_all(MYSQLI_ASSOC);
  ?>
  
  <div class="table-container">
    <div class="nav-buttons">
      <?php if (function_exists('is_admin') && is_admin()): ?>
    <a href="export_results.php?year=<?= $viewYearInt ?>&status=<?= urlencode($selected_status) ?>" class="btn">⬇ <?= t('download_excel') ?></a>
      <?php endif; ?>
    </div>

    <table class="table">
    <thead>
      <tr>
        <th><?= t('movie') ?></th>
        <th><?= t('category') ?></th>
        <th><?= t('where_watched') ?></th>
        <th><?= t('competition_status') ?></th>
        <th><?= t('votes') ?></th>
        <th><?= t('total') ?></th>
        <?php foreach ($detailCols as $c): ?>
          <th><?= t($c) ?: ucfirst(str_replace('_',' ', $c)) ?></th>
        <?php endforeach; ?>
        <th><?= t('adjective') ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="text-left"><?= e($r['title']) ?> <span class="text-muted">(<?= e($r['year']) ?>)</span></td>
          <td><?= e($r['category_mode'] ?? '') ?></td>
          <td><?= e($r['platform_mode'] ?? '') ?></td>
          <td><?= e($r['comp_mode'] ?? '') ?></td>
          <td><?= (int)$r['votes_count'] ?></td>
          <td class="highlight"><?= $r['avg_rating']!==null ? number_format($r['avg_rating'],2) : '' ?></td>
          <?php foreach ($detailCols as $c): $key = 'avg_'.$c; ?>
            <td><?= isset($r[$key]) && $r[$key]!==null ? number_format($r[$key],2) : '' ?></td>
          <?php endforeach; ?>
          <td class="text-left"><?= e($r['adjectives'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    </table>
  </div>

  <div class="sheet-tabs">
    <?php foreach ($tabs as $code => $label): ?>
      <a class="sheet-tab <?= $sheet === $code ? 'active' : '' ?>" href="?sheet=<?= urlencode($code) ?>&amp;year=<?= $selected_year ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <?php if (function_exists('is_admin') && is_admin()): ?>
      <a class="sheet-tab <?= $sheet==='raw' ? 'active' : '' ?>" href="?sheet=raw&amp;year=<?= $selected_year ?>">RAW</a>
    <?php endif; ?>
  </div>

  <?php include __DIR__.'/includes/footer.php';
  exit;
}

// Raw votes sheet (admin only)
// VOTES sheet (row-level votes like Excel "Votazioni")
if (($sheet === 'votes') || ($sheet === 'raw' && function_exists('is_admin') && is_admin())) {
  // admins can access via ?sheet=raw too; others via ?sheet=votes (limited to own? currently all)
  // Build scoring columns list from vote_details (only the 7 score fields)
  $vdCols = $mysqli->query("SHOW COLUMNS FROM vote_details")->fetch_all(MYSQLI_ASSOC);
  $scoreCandidates = ['writing','direction','acting_or_doc_theme','emotional_involvement','novelty','casting_research_art','sound'];
  $have = array_map(function($c){ return $c['Field']; }, $vdCols);
  $scoreCols = array_values(array_filter($scoreCandidates, function($c) use ($have){ return in_array($c,$have); }));
  $numExpr = $denExpr = null;
  if ($scoreCols) {
    $numParts = array_map(function($col){ return "COALESCE(vd.`$col`,0)"; }, $scoreCols);
    $denParts = array_map(function($col){ return "(vd.`$col` IS NOT NULL)"; }, $scoreCols);
    $numExpr = implode('+',$numParts);
    $denExpr = implode('+',$denParts);
  }
  $sqlRaw = "SELECT v.id as vote_id, v.user_id, v.created_at, u.username, m.id AS movie_id, m.title, m.year,
        vd.competition_status, vd.category, vd.where_watched, vd.season_number,
        vd.acting_or_doc_theme, vd.casting_research_art, vd.writing, vd.direction, vd.emotional_involvement, vd.novelty, vd.sound,
        " . ($scoreCols ? "($numExpr) AS total_score, ($denExpr) AS non_empty_count, ($numExpr) AS calc_rating" : "NULL AS total_score, NULL AS non_empty_count, NULL AS calc_rating") . "
    FROM votes v
        JOIN users u ON u.id = v.user_id
        JOIN movies m ON m.id = v.movie_id
        LEFT JOIN vote_details vd ON vd.vote_id = v.id
  " . $whereYearClause . $statusCondVd . "
  ORDER BY v.created_at DESC";
    $rawRows = $mysqli->query($sqlRaw)->fetch_all(MYSQLI_ASSOC);
    ?>

    <style>
      @media (max-width: 768px) {
        .raw-table th.col-hide,
        .raw-table td.col-hide { display: none; }
        .raw-table th.col-total,
        .raw-table td.col-total { display: none; }
        .raw-table th.col-computed,
        .raw-table td.col-computed { display: table-cell; }
      }
      @media (min-width: 769px) {
        .raw-table th.col-computed:last-child,
        .raw-table td.col-computed:last-child { display: table-cell; }
      }
    </style>
    
    <div class="raw-wrapper">
      <h2 class="text-center"><?= t('raw_votes') ?></h2>
      <div class="nav-buttons">
        <?php if (function_exists('is_admin') && is_admin()): ?>
        <a href="export_results.php?year=<?= $viewYearInt ?>&status=<?= urlencode($selected_status) ?>" class="btn">⬇ <?= t('download_excel') ?></a>
        <?php endif; ?>
      </div>
      <table class="raw-table">
      <thead>
        <tr>
          <th class="col-edit"><?= t('edit') ?></th>
          <th class="col-username"><?= t('username') ?></th>
          <th class="col-title"><?= t('movie') ?></th>
          <th class="col-hide"><?= t('year') ?></th>
          <th class="col-hide"><?= t('competition_status') ?></th>
          <th class="col-hide"><?= t('category') ?></th>
          <th class="col-hide"><?= t('where_watched') ?></th>
          <th class="col-hide"><?= t('season') ?></th>
          <?php /* episode removed for series voting */ ?>
          <?php foreach($scoreCols as $col): ?>
            <th class="col-hide"><?= t($col) ?: ucfirst(str_replace('_',' ', e($col))) ?></th>
          <?php endforeach; ?>
          <th class="col-hide"><?= t('total') ?></th>
          <th class="col-computed"><?= t('computed_rating') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rawRows as $r): ?>
        <tr>
          <td class="col-edit">
            <?php if (isset($user) && (int)$user['id'] === (int)($r['user_id'] ?? 0)): ?>
              <a href="<?= ADDRESS ?>/vote.php?edit=<?= (int)$r['vote_id'] ?>" style="color:#f6c90e;text-decoration:none;font-weight:600;"><?= t('edit') ?></a>
            <?php else: ?>
              
            <?php endif; ?>
          </td>
          <td class="col-username"><?= e($r['username']) ?></td>
          <td class="col-title text-left"><?= e($r['title']) ?></td>
          <td class="col-hide"><?= e($r['year']) ?></td>
          <td class="col-hide"><?= e($r['competition_status']) ?></td>
          <td class="col-hide"><?= e($r['category']) ?></td>
          <td class="col-hide"><?= e($r['where_watched']) ?></td>
          <td class="col-hide"><?= e($r['season_number']) ?></td>
          <?php foreach($scoreCols as $col): ?>
            <td class="col-hide"><?= isset($r[$col]) && $r[$col] !== null ? number_format($r[$col],1) : '' ?></td>
          <?php endforeach; ?>
          <td class="raw-highlight col-hide"><?= $r['total_score'] !== null ? number_format($r['total_score'],2) : '' ?></td>
          <td class="raw-highlight col-computed"><?= $r['calc_rating'] !== null ? number_format($r['calc_rating'],2) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      </table>
      <script>
        // On small screens, truncate long titles to 3 words with ellipsis
        (function(){
          try {
            if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches) {
              var cells = document.querySelectorAll('.raw-table td.col-title');
              cells.forEach(function(td){
                var txt = (td.textContent || '').trim();
                if (!txt) return;
                td.setAttribute('title', txt); // keep full title as tooltip
                var words = txt.split(/\s+/);
                if (words.length > 3) {
                  td.textContent = words.slice(0,3).join(' ') + '...';
                }
              });
            }
          } catch(e) {}
        })();
      </script>
    </div>
    <div class="sheet-tabs">
      <?php foreach ($tabs as $code => $label): ?>
        <a class="sheet-tab <?= $sheet === $code ? 'active' : '' ?>" href="?sheet=<?= urlencode($code) ?>&amp;year=<?= $selected_year ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
      <?php if (function_exists('is_admin') && is_admin()): ?>
        <a class="sheet-tab <?= $sheet==='raw' ? 'active' : '' ?>" href="?sheet=raw&amp;year=<?= $selected_year ?>">RAW</a>
      <?php endif; ?>
    </div>
    <?php include __DIR__.'/includes/footer.php';
    exit;
}

// VIEWS sheet (platform/category pivot like "Visioni {year}")
if ($sheet === 'views') {
    // Build expressions for computed rating
    $vdCols = $mysqli->query("SHOW COLUMNS FROM vote_details")->fetch_all(MYSQLI_ASSOC);
    $numericCols = [];
    foreach ($vdCols as $c) {
      $t = strtolower($c['Type']);
      if (strpos($t,'tinyint')!==false || strpos($t,'smallint')!==false || strpos($t,'int(')!==false || strpos($t,'int ')!==false || strpos($t,'decimal')!==false || strpos($t,'float')!==false || strpos($t,'double')!==false) {
        $numericCols[] = $c['Field'];
      }
    }
    $numExpr = null;
    if ($numericCols) {
      $numParts = array_map(function($col){ return "COALESCE(vd.`$col`,0)"; }, $numericCols);
      $numExpr = implode('+',$numParts);
    }
    // Per-vote total for averaging across views/platforms
    $ratingExpr = $numExpr ? "($numExpr)" : 'NULL';

    // Summary counts per category
    $categories = ['Film','Serie','Miniserie','Documentario','Animazione'];
    $summary = [];
    foreach ($categories as $cat) {
        $catEsc = $mysqli->real_escape_string($cat);
    $q1 = $mysqli->query("SELECT COUNT(DISTINCT v.movie_id) AS uniq_titles, COUNT(v.id) AS views
              FROM votes v LEFT JOIN vote_details vd ON vd.vote_id=v.id
              WHERE COALESCE(vd.category,'Altro')='$catEsc'" . $yearCond . $statusCondVd);
        $summary[$cat] = $q1 ? $q1->fetch_assoc() : ['uniq_titles'=>0,'views'=>0];
      }
    // Also totals across all
  $qTot = $mysqli->query("SELECT COUNT(DISTINCT v.movie_id) AS uniq_titles, COUNT(v.id) AS views FROM votes v LEFT JOIN vote_details vd ON vd.vote_id = v.id " . $whereYearClause . $statusCondVd);
    $summaryTotal = $qTot ? $qTot->fetch_assoc() : ['uniq_titles'=>0,'views'=>0];

    // Platform x category metrics
    $sql = "SELECT COALESCE(NULLIF(TRIM(vd.where_watched),''),'Altro') AS platform,
                   COALESCE(NULLIF(TRIM(vd.category),''),'Altro') AS category,
                   COUNT(DISTINCT v.movie_id) AS uniq_titles,
                   COUNT(v.id) AS views,
                   ROUND(AVG($ratingExpr),2) AS avg_rating
      FROM votes v
      LEFT JOIN vote_details vd ON vd.vote_id = v.id
  " . $whereYearClause . $statusCondVd . "
      GROUP BY platform, category
            ORDER BY platform, category";
    $pivot = $mysqli->query($sql)->fetch_all(MYSQLI_ASSOC);

    // collect platforms
    $platforms = [];
    foreach ($pivot as $row) { $platforms[$row['platform']] = true; }
    $platforms = array_keys($platforms);
    sort($platforms);

    // build lookup: [platform][category] => row
    $lookup = [];
    foreach ($pivot as $r) { $lookup[$r['platform']][$r['category']] = $r; }

    ?>
    
    <div class="summary">
      <h3><?= e(t('sheet_views')) ?></h3>
      <div class="summary-grid">
        <?php foreach ($categories as $cat): $s=$summary[$cat]; ?>
          <div class="summary-item"><strong><?= e($cat) ?> — <?= e(t('unique_titles')) ?></strong><?= (int)$s['uniq_titles'] ?></div>
        <?php endforeach; ?>
        <div class="summary-item"><strong><?= e(t('total')) ?> — <?= e(t('unique_titles')) ?></strong><?= (int)$summaryTotal['uniq_titles'] ?></div>
        <?php foreach ($categories as $cat): $s=$summary[$cat]; ?>
          <div class="summary-item"><strong><?= e($cat) ?> — <?= e(t('total_views')) ?></strong><?= (int)$s['views'] ?></div>
        <?php endforeach; ?>
        <div class="summary-item"><strong><?= e(t('total_views')) ?></strong><?= (int)$summaryTotal['views'] ?></div>
      </div>
    </div>

    <div class="table-container">
      <div class="nav-buttons">
        <?php if (function_exists('is_admin') && is_admin()): ?>
            <a href="export_results.php?year=<?= $viewYearInt ?>&status=<?= urlencode($selected_status) ?>" class="btn">⬇ <?= t('download_excel') ?></a>
        <?php endif; ?>
      </div>

      <table class="table">
      <thead>
        <tr>
          <th><?= t('platform') ?></th>
          <th><?= t('qualitative_average') ?></th>
          <?php foreach ($categories as $cat): ?>
            <th><?= e($cat) ?> <?= t('titles') ?></th>
            <th><?= e($cat) ?> <?= t('average_rating') ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($platforms as $plat): ?>
          <tr>
            <td><?= e($plat) ?></td>
            <?php
              // overall platform avg
              $avg = $mysqli->query("SELECT ROUND(AVG($ratingExpr),2) AS a FROM votes v LEFT JOIN vote_details vd ON vd.vote_id=v.id WHERE COALESCE(NULLIF(TRIM(vd.where_watched),''),'Altro')='".$mysqli->real_escape_string($plat)."'" . $yearCond);
              $avgRow = $avg ? $avg->fetch_assoc() : ['a'=>null];
            ?>
            <td><?= $avgRow['a'] !== null ? number_format($avgRow['a'],2) : '' ?></td>
            <?php foreach ($categories as $cat): $cell = $lookup[$plat][$cat] ?? null; ?>
              <td><?= $cell ? (int)$cell['uniq_titles'] : 0 ?></td>
              <td><?= $cell && $cell['avg_rating']!==null ? number_format($cell['avg_rating'],2) : '' ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
      </table>
    </div>
    <div class="sheet-tabs">
      <?php foreach ($tabs as $code => $label): ?>
        <a class="sheet-tab <?= $sheet === $code ? 'active' : '' ?>" href="?sheet=<?= urlencode($code) ?>&amp;year=<?= $selected_year ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
      <?php if (function_exists('is_admin') && is_admin()): ?>
        <a class="sheet-tab <?= $sheet==='raw' ? 'active' : '' ?>" href="?sheet=raw&amp;year=<?= $selected_year ?>">RAW</a>
      <?php endif; ?>
    </div>
    <?php include __DIR__.'/includes/footer.php';
    exit;
}

// JUDGES sheet (per-judge stats)
if ($sheet === 'judges' || $sheet === 'judges_comp') {
    $compWhere = '';
    if ($sheet === 'judges_comp') {
      // Try to include common labels a user could have saved for competition
      $compWhere = "WHERE COALESCE(vd.competition_status,'') IN ('Concorso','In Competizione','In Competition')";
    }
    // Rating expression
    $vdCols = $mysqli->query("SHOW COLUMNS FROM vote_details")->fetch_all(MYSQLI_ASSOC);
    $detailCols = ['writing','direction','acting_or_doc_theme','emotional_involvement','novelty','casting_research_art','sound'];
    // ensure they exist
    $have = array_map(function($c){return $c['Field'];}, $vdCols);
    $detailCols = array_values(array_filter($detailCols, function($c) use ($have){ return in_array($c,$have); }));
    $numExpr = null;
    if ($detailCols) {
      $numParts = array_map(function($col){ return "COALESCE(vd.`$col`,0)"; }, $detailCols);
      $numExpr = implode('+',$numParts);
    }
    // Per-judge average of total scores (sum of categories per vote)
    $ratingExpr = $numExpr ? "($numExpr)" : 'NULL';

    $sql = "SELECT u.username AS judge,
                   COUNT(v.id) AS votes,
                   SUM(COALESCE(vd.category,'')='Film') AS film_count,
                   SUM(COALESCE(vd.category,'')='Serie') AS series_count,
                   SUM(COALESCE(vd.category,'')='Miniserie') AS miniseries_count,
                   SUM(COALESCE(vd.category,'')='Documentario') AS doc_count,
                   SUM(COALESCE(vd.category,'')='Animazione') AS anim_count,
                   ROUND(AVG($ratingExpr),2) AS media_totale,
                   " . (in_array('writing',$detailCols)?'ROUND(AVG(vd.writing),2)':'NULL') . " AS media_sceneggiatura,
                   " . (in_array('direction',$detailCols)?'ROUND(AVG(vd.direction),2)':'NULL') . " AS media_regia,
                   " . (in_array('acting_or_doc_theme',$detailCols)?'ROUND(AVG(vd.acting_or_doc_theme),2)':'NULL') . " AS media_recitazione,
                   " . (in_array('emotional_involvement',$detailCols)?'ROUND(AVG(vd.emotional_involvement),2)':'NULL') . " AS media_coinvolgimento,
                   " . (in_array('novelty',$detailCols)?'ROUND(AVG(vd.novelty),2)':'NULL') . " AS media_novita,
                   " . (in_array('casting_research_art',$detailCols)?'ROUND(AVG(vd.casting_research_art),2)':'NULL') . " AS media_casting,
                   " . (in_array('sound',$detailCols)?'ROUND(AVG(vd.sound),2)':'NULL') . " AS media_suono,
                   (
                      SUM(" . (in_array('writing',$detailCols)?'(vd.writing=10)':'0') . ") +
                      SUM(" . (in_array('direction',$detailCols)?'(vd.direction=10)':'0') . ") +
                      SUM(" . (in_array('acting_or_doc_theme',$detailCols)?'(vd.acting_or_doc_theme=10)':'0') . ") +
                      SUM(" . (in_array('emotional_involvement',$detailCols)?'(vd.emotional_involvement=10)':'0') . ") +
                      SUM(" . (in_array('novelty',$detailCols)?'(vd.novelty=10)':'0') . ") +
                      SUM(" . (in_array('casting_research_art',$detailCols)?'(vd.casting_research_art=10)':'0') . ") +
                      SUM(" . (in_array('sound',$detailCols)?'(vd.sound=10)':'0') . ")
                   ) AS quanti_10,
                   (
                      ( 
                        SUM(" . (in_array('writing',$detailCols)?'(vd.writing=10)':'0') . ") +
                        SUM(" . (in_array('direction',$detailCols)?'(vd.direction=10)':'0') . ") +
                        SUM(" . (in_array('acting_or_doc_theme',$detailCols)?'(vd.acting_or_doc_theme=10)':'0') . ") +
                        SUM(" . (in_array('emotional_involvement',$detailCols)?'(vd.emotional_involvement=10)':'0') . ") +
                        SUM(" . (in_array('novelty',$detailCols)?'(vd.novelty=10)':'0') . ") +
                        SUM(" . (in_array('casting_research_art',$detailCols)?'(vd.casting_research_art=10)':'0') . ") +
                        SUM(" . (in_array('sound',$detailCols)?'(vd.sound=10)':'0') . ")
                      ) / NULLIF( 
                        SUM(" . (in_array('writing',$detailCols)?'(vd.writing IS NOT NULL)':'0') . ") +
                        SUM(" . (in_array('direction',$detailCols)?'(vd.direction IS NOT NULL)':'0') . ") +
                        SUM(" . (in_array('acting_or_doc_theme',$detailCols)?'(vd.acting_or_doc_theme IS NOT NULL)':'0') . ") +
                        SUM(" . (in_array('emotional_involvement',$detailCols)?'(vd.emotional_involvement IS NOT NULL)':'0') . ") +
                        SUM(" . (in_array('novelty',$detailCols)?'(vd.novelty IS NOT NULL)':'0') . ") +
                        SUM(" . (in_array('casting_research_art',$detailCols)?'(vd.casting_research_art IS NOT NULL)':'0') . ") +
                        SUM(" . (in_array('sound',$detailCols)?'(vd.sound IS NOT NULL)':'0') . ")
                      ,0)
                   ) AS perc_10
            FROM votes v
            JOIN users u ON u.id = v.user_id
            LEFT JOIN vote_details vd ON vd.vote_id = v.id
            ";
    // ensure we always filter by competition year and optionally by competition status
    $whereParts = [];
  if ($hasCompetitionYear) { $whereParts[] = "v.competition_year = " . $viewYearInt; }
    // Add status filter from dropdown (takes precedence over judges_comp sheet)
    if (!empty($statusCondVd)) {
      $statusWhereClean = preg_replace('/^\s*AND\s+/i', '', $statusCondVd);
      $whereParts[] = $statusWhereClean;
    } elseif (!empty($compWhere)) {
      // Fallback to judges_comp logic if no status filter selected
      $compWhereClean = preg_replace('/^WHERE\s+/i', '', $compWhere);
      $whereParts[] = $compWhereClean;
    }
    if (count($whereParts) > 0) {
      $sql .= ' WHERE ' . implode(' AND ', $whereParts) . "\n            GROUP BY u.username\n            ORDER BY votes DESC, media_totale DESC";
    } else {
      $sql .= "\n            GROUP BY u.username\n            ORDER BY votes DESC, media_totale DESC";
    }
    $rows = $mysqli->query($sql)->fetch_all(MYSQLI_ASSOC);
    ?>
    
    <div class="table-container">
      <table class="table">
      <thead>
        <tr>
          <th><?= t('judge') ?></th>
          <th><?= t('votes') ?></th>
          <th><?= t('film') ?></th>
          <th><?= t('series') ?></th>
          <th><?= t('miniseries') ?></th>
          <th><?= t('documentary') ?></th>
          <th><?= t('animation') ?></th>
          <th><?= t('avg_total') ?></th>
          <th><?= t('avg_writing') ?></th>
          <th><?= t('avg_direction') ?></th>
          <th><?= t('avg_acting_theme') ?></th>
          <th><?= t('avg_emotional') ?></th>
          <th><?= t('avg_novelty') ?></th>
          <th><?= t('avg_casting_artwork') ?></th>
          <th><?= t('avg_sound') ?></th>
          <th><?= t('count_10s') ?></th>
          <th><?= t('percent_10s') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e($r['judge']) ?></td>
            <td><?= (int)$r['votes'] ?></td>
            <td><?= (int)$r['film_count'] ?></td>
            <td><?= (int)$r['series_count'] ?></td>
            <td><?= (int)$r['miniseries_count'] ?></td>
            <td><?= (int)$r['doc_count'] ?></td>
            <td><?= (int)$r['anim_count'] ?></td>
            <td><?= $r['media_totale']!==null ? number_format($r['media_totale'],2) : '' ?></td>
            <td><?= isset($r['media_sceneggiatura']) ? number_format($r['media_sceneggiatura'],2) : '' ?></td>
            <td><?= isset($r['media_regia']) ? number_format($r['media_regia'],2) : '' ?></td>
            <td><?= isset($r['media_recitazione']) ? number_format($r['media_recitazione'],2) : '' ?></td>
            <td><?= isset($r['media_coinvolgimento']) ? number_format($r['media_coinvolgimento'],2) : '' ?></td>
            <td><?= isset($r['media_novita']) ? number_format($r['media_novita'],2) : '' ?></td>
            <td><?= isset($r['media_casting']) ? number_format($r['media_casting'],2) : '' ?></td>
            <td><?= isset($r['media_suono']) ? number_format($r['media_suono'],2) : '' ?></td>
            <td><?= (int)$r['quanti_10'] ?></td>
            <td><?= $r['perc_10']!==null ? number_format($r['perc_10']*100,2).'%' : '' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      </table>
    </div>
    <div class="sheet-tabs">
      <?php foreach ($tabs as $code => $label): ?>
          <a class="sheet-tab <?= $sheet === $code ? 'active' : '' ?>" href="?sheet=<?= urlencode($code) ?>&amp;year=<?= $selected_year ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
        <?php if (function_exists('is_admin') && is_admin()): ?>
          <a class="sheet-tab <?= $sheet==='raw' ? 'active' : '' ?>" href="?sheet=raw&amp;year=<?= $selected_year ?>">RAW</a>
        <?php endif; ?>
    </div>
    <?php include __DIR__.'/includes/footer.php';
    exit;
}

// TITLES sheet (unique list like UNIQUE(SORT(...)))
if ($sheet === 'titles') {
  $rows = $mysqli->query("SELECT DISTINCT m.title FROM votes v JOIN movies m ON m.id=v.movie_id LEFT JOIN vote_details vd ON vd.vote_id=v.id " . $whereYearClause . $statusCondVd . " ORDER BY m.title ASC")->fetch_all(MYSQLI_ASSOC);
    ?>
    
    <div class="table-container">
      <div class="nav-buttons">
        <?php if (function_exists('is_admin') && is_admin()): ?>
    <a href="export_results.php?year=<?= $viewYearInt ?>&status=<?= urlencode($selected_status) ?>" class="btn">⬇ <?= t('download_excel') ?></a>
        <?php endif; ?>
      </div>

      <table class="table">
        <thead><tr><th><?= t('sheet_titles') ?></th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr><td><?= e($r['title']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="sheet-tabs">
      <?php foreach ($tabs as $code => $label): ?>
          <a class="sheet-tab <?= $sheet === $code ? 'active' : '' ?>" href="?sheet=<?= urlencode($code) ?>&amp;year=<?= $selected_year ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
        <?php if (function_exists('is_admin') && is_admin()): ?>
          <a class="sheet-tab <?= $sheet==='raw' ? 'active' : '' ?>" href="?sheet=raw&amp;year=<?= $selected_year ?>">RAW</a>
        <?php endif; ?>
    </div>
    <?php include __DIR__.'/includes/footer.php';
    exit;
}

// ADJECTIVES sheet (per-title collected adjectives)
if ($sheet === 'adjectives') {
    // Check adjective column existence
    $hasAdj = false;
    $cols = $mysqli->query("SHOW COLUMNS FROM vote_details")->fetch_all(MYSQLI_ASSOC);
    foreach ($cols as $c) { if ($c['Field'] === 'adjective') { $hasAdj = true; break; } }
    ?>
    
    <div class="nav-buttons">
      <?php if (function_exists('is_admin') && is_admin()): ?>
  <a href="export_results.php?year=<?= $viewYearInt ?>&status=<?= urlencode($selected_status) ?>" class="btn">⬇ <?= t('download_excel') ?></a>
      <?php endif; ?>
    </div>

    <?php if (!$hasAdj): ?>
      <p class="adjective-message">No adjective field in vote details.</p>
    <?php else: ?>
      <?php
  $rows = $mysqli->query("SELECT m.title, TRIM(vd.adjective) AS adjective
    FROM votes v
    JOIN movies m ON m.id=v.movie_id
    LEFT JOIN vote_details vd ON vd.vote_id=v.id
    WHERE TRIM(COALESCE(vd.adjective,''))<>''" . $yearCond . $statusCondVd . "
    ORDER BY m.title, vd.adjective")->fetch_all(MYSQLI_ASSOC);
        // aggregated list per movie
  $agg = $mysqli->query("SELECT m.title, GROUP_CONCAT(DISTINCT TRIM(vd.adjective) ORDER BY TRIM(vd.adjective) SEPARATOR ', ') AS adjectives
             FROM votes v
             JOIN movies m ON m.id=v.movie_id
             LEFT JOIN vote_details vd ON vd.vote_id=v.id
             WHERE TRIM(COALESCE(vd.adjective,''))<>''" . $yearCond . $statusCondVd . "
             GROUP BY m.title
             ORDER BY m.title")->fetch_all(MYSQLI_ASSOC);
        $aggMap = [];
        foreach ($agg as $a) { $aggMap[$a['title']] = $a['adjectives']; }
      ?>
      <div class="table-container">
        <table class="table">
        <thead>
          <tr>
            <th><?= t('film') ?></th>
            <th><?= t('adjective') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= e($r['title']) ?></td>
              <td><?= e($r['adjective']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        </table>
      </div>
    <?php endif; ?>
    <div class="sheet-tabs">
      <?php foreach ($tabs as $code => $label): ?>
        <a class="sheet-tab <?= $sheet === $code ? 'active' : '' ?>" href="?sheet=<?= urlencode($code) ?>&amp;year=<?= $selected_year ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
      <?php if (function_exists('is_admin') && is_admin()): ?>
        <a class="sheet-tab <?= $sheet==='raw' ? 'active' : '' ?>" href="?sheet=raw&amp;year=<?= $selected_year ?>">RAW</a>
      <?php endif; ?>
    </div>
    <?php include __DIR__.'/includes/footer.php';
    exit;
}

// FINALISTS sheet (per-selected-year)
if ($sheet === 'finalists') {
  // List movies that received votes in the selected competition year
  $rows = $mysqli->query("SELECT DISTINCT m.title, COALESCE(vd.category,'Altro') AS category
               FROM votes v
               JOIN movies m ON m.id=v.movie_id
               LEFT JOIN vote_details vd ON vd.vote_id=v.id
               " . $whereYearClause . "
               ORDER BY m.title")->fetch_all(MYSQLI_ASSOC);
    ?>
    
    <div class="table-container">
      <div class="nav-buttons">
        <?php if (function_exists('is_admin') && is_admin()): ?>
    <a href="export_results.php?year=<?= $viewYearInt ?>&status=<?= urlencode($selected_status) ?>" class="btn">⬇ <?= t('download_excel') ?></a>
        <?php endif; ?>
      </div>

      <table class="table">
        <thead><tr><th><?= e(t('finalists')) . ' ' . (int)$selected_year ?></th><th><?= t('category') ?></th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr><td><?= e($r['title']) ?></td><td><?= e($r['category']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="sheet-tabs">
      <?php foreach ($tabs as $code => $label): ?>
        <a class="sheet-tab <?= $sheet === $code ? 'active' : '' ?>" href="?sheet=<?= urlencode($code) ?>&amp;year=<?= $selected_year ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
      <?php if (function_exists('is_admin') && is_admin()): ?>
        <a class="sheet-tab <?= $sheet==='raw' ? 'active' : '' ?>" href="?sheet=raw&amp;year=<?= $selected_year ?>">RAW</a>
      <?php endif; ?>
    </div>
    <?php include __DIR__.'/includes/footer.php';
    exit;
}



