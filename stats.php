<?php 
require_once __DIR__.'/includes/auth.php'; 
require_once __DIR__.'/includes/lang.php';
include __DIR__.'/includes/header.php';

// show flash messages (if any)
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!empty($_SESSION['flash'])) {
  // If there's an edit id set, show an Edit button next to the message
  $msg = e($_SESSION['flash']);
  if (!empty($_SESSION['flash_edit_vote_id'])) {
    $editId = (int)$_SESSION['flash_edit_vote_id'];
    echo "<p class=\"flash\">{$msg} <a class=\"btn\" href=\"vote.php?edit={$editId}\">Edit vote</a></p>";
    unset($_SESSION['flash_edit_vote_id']);
  } else {
    echo '<p class="flash">' . $msg . '</p>';
  }
  unset($_SESSION['flash']);
}

// Which sheet to show (multi-sheet UI like Excel)
$sheet = isset($_GET['sheet']) ? $_GET['sheet'] : 'results';
// Prefer configured active year when available; fallback to current year
$currentYear = function_exists('get_active_year') ? (int)get_active_year() : (int)date('Y');
// Selected competition year (filter votes). Default to configured active year or ?year= in URL
$selected_year = (int)($_GET['year'] ?? $currentYear);
// Build list of available years from both `competitions` table (if present) and votes table
$years = [];
// from competitions table if exists
$hasCompetitionsTable = $mysqli->query("SHOW TABLES LIKE 'competitions'")->fetch_all(MYSQLI_NUM);
if ($hasCompetitionsTable) {
  $rows = $mysqli->query("SELECT year FROM competitions")->fetch_all(MYSQLI_ASSOC);
  foreach ($rows as $r) { $years[] = (int)$r['year']; }
}
// from votes table: prefer competition_year when present else YEAR(created_at)
$hasVotesYearCol = $mysqli->query("SHOW COLUMNS FROM votes LIKE 'competition_year'")->fetch_all(MYSQLI_ASSOC);
if ($hasVotesYearCol) {
  $rows2 = $mysqli->query("SELECT DISTINCT COALESCE(competition_year, YEAR(created_at)) AS y FROM votes WHERE (competition_year IS NOT NULL OR created_at IS NOT NULL)")->fetch_all(MYSQLI_ASSOC);
  foreach ($rows2 as $r) { $years[] = (int)$r['y']; }
} else {
  $rows2 = $mysqli->query("SELECT DISTINCT YEAR(created_at) AS y FROM votes WHERE created_at IS NOT NULL")->fetch_all(MYSQLI_ASSOC);
  foreach ($rows2 as $r) { $years[] = (int)$r['y']; }
}
// always include the active/current year so it appears in the list
if ($currentYear) { $years[] = (int)$currentYear; }
// normalize: unique, sort descending
$years = array_map('intval', array_values(array_unique($years)));
rsort($years, SORT_NUMERIC);
// Tab labels in the order matching the workbook
$tabs = [
  'votes' => str_replace('{year}', $selected_year, t('sheet_votes')),
  'results' => str_replace('{year}', $selected_year, t('sheet_results')),
  'views' => str_replace('{year}', $selected_year, t('sheet_views')),
  'judges' => t('sheet_judges'),
  'judges_comp' => t('sheet_judges_comp'),
  'titles' => t('sheet_titles'),
  'adjectives' => t('sheet_adjectives'),
  // finalists should be per-selected-year rather than fixed to 2023
  'finalists' => 'Finalists ' . $selected_year,
];

// Global fixed bottom tabs styling for all sheets (keeps bottom tabs visible while scrolling)
?>
<!-- Inline year selector (centered) -->
<div style="text-align:center;margin:1rem 0;">
  <form id="yearForm" method="get" action="/movie-club-app/stats.php" style="display:inline-block;">
    <label for="yearSelect" style="color:#ddd;margin-right:.5rem;font-weight:600;">Select year:</label>
    <select id="yearSelect" name="year" style="padding:.5rem .6rem;border-radius:.35rem;background:#1a1a1a;color:#fff;border:1px solid #333;font-weight:600;">
      <?php foreach ($years as $y): ?>
        <option value="<?= (int)$y ?>" <?= ((int)$y === (int)$selected_year) ? 'selected' : '' ?>><?= (int)$y ?></option>
      <?php endforeach; ?>
    </select>
    <?php // Preserve other GET params (sheet, mine, lang, etc.) when switching years ?>
    <?php foreach ($_GET as $k=>$v): if ($k === 'year') continue; if (is_array($v)) continue; ?>
      <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
    <?php endforeach; ?>
  </form>
</div>
<script>
  // Auto-submit the year form when selection changes
  (function(){
    var sel = document.getElementById('yearSelect');
    if (sel) sel.addEventListener('change', function(){ document.getElementById('yearForm').submit(); });
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

// If user requested their own votes, show per-user list
if (!empty($_GET['mine']) && current_user()) {
  $uid = current_user()['id'];
  if ($hasRating) {
    if ($hasCompetitionYear) {
  $stmt = $mysqli->prepare("SELECT v.id AS vote_id, m.title, m.year, v.rating, v.created_at FROM votes v JOIN movies m ON m.id=v.movie_id WHERE v.user_id=? AND v.competition_year = ? ORDER BY v.created_at DESC");
  $stmt->bind_param('ii', $uid, $viewYearInt);
    } else {
      $stmt = $mysqli->prepare("SELECT v.id AS vote_id, m.title, m.year, v.rating, v.created_at FROM votes v JOIN movies m ON m.id=v.movie_id WHERE v.user_id=? ORDER BY v.created_at DESC");
      $stmt->bind_param('i', $uid);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    ?>
    <h2><?= t('your_votes') ?></h2>
    <table class="table">
      <thead><tr><th><?= t('movie') ?></th><th><?= t('year') ?></th><th><?= t('your_rating') ?></th><th><?= t('when') ?></th></tr></thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= e($r['title']) ?></td>
          <td><?= e($r['year']) ?></td>
          <td><?= e($r['rating']) ?></td>
          <td><?= e($r['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
  } else {
    // No rating column: compute a per-vote score from vote_details (average of available numeric fields)
    $vdCols = $mysqli->query("SHOW COLUMNS FROM vote_details")->fetch_all(MYSQLI_ASSOC);
    $numericCols = [];
    foreach ($vdCols as $c) {
      $t = strtolower($c['Type']);
      if (strpos($t, 'tinyint') !== false || strpos($t, 'smallint') !== false || strpos($t, 'int(') !== false || strpos($t, 'int ') !== false || strpos($t, 'decimal') !== false || strpos($t, 'float') !== false || strpos($t, 'double') !== false) {
        $numericCols[] = $c['Field'];
      }
    }

    if ($numericCols) {
      $numParts = array_map(function($col){ return "COALESCE(vd.`$col`,0)"; }, $numericCols);
      $denParts = array_map(function($col){ return "(vd.`$col` IS NOT NULL)"; }, $numericCols);
      $numExpr = implode('+', $numParts);
      $denExpr = implode('+', $denParts);

    $sqlUser = "SELECT v.id AS vote_id, m.title, m.year, 
      vd.writing, vd.direction, vd.acting_or_doc_theme, vd.emotional_involvement,
      vd.novelty, vd.casting_research_art, vd.sound,
      vd.competition_status, vd.category, vd.where_watched, vd.season_number, vd.episode_number,
        ($numExpr) AS total_score,
        $denExpr AS non_empty_count,
        ( ($numExpr) / NULLIF($denExpr,0) ) AS calc_rating, 
        v.created_at
        FROM votes v
        JOIN movies m ON m.id=v.movie_id
        LEFT JOIN vote_details vd ON vd.vote_id = v.id
  WHERE v.user_id = ?" . ($hasCompetitionYear ? " AND v.competition_year = " . $viewYearInt : "") . "
        ORDER BY v.created_at DESC";
      $stmt = $mysqli->prepare($sqlUser);
      if ($hasCompetitionYear) {
        // bind user id only, year already inlined into SQL (integer) to avoid different param counts
        $stmt->bind_param('i', $uid);
      } else {
        $stmt->bind_param('i', $uid);
      }
      $stmt->execute();
      $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
      // no numeric detail columns: just list votes without computed rating
      if ($hasCompetitionYear) {
  $stmt = $mysqli->prepare("SELECT v.id AS vote_id, m.title, m.year, NULL AS calc_rating, v.created_at FROM votes v JOIN movies m ON m.id=v.movie_id WHERE v.user_id=? AND v.competition_year = " . $viewYearInt . " ORDER BY v.created_at DESC");
        $stmt->bind_param('i', $uid);
      } else {
        $stmt = $mysqli->prepare("SELECT v.id AS vote_id, m.title, m.year, NULL AS calc_rating, v.created_at FROM votes v JOIN movies m ON m.id=v.movie_id WHERE v.user_id=? ORDER BY v.created_at DESC");
        $stmt->bind_param('i', $uid);
      }
      $stmt->execute();
      $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    ?>
    <div class="nav-buttons">
      <a href="index.php" class="btn"><?= t('home') ?></a>
    </div>
    <h2><?= t('your_votes_detailed') ?></h2>
    
    <div class="nav-buttons">
      <?php if (function_exists('is_admin') && is_admin()): ?>
        <a href="export_results.php" class="btn">⬇ <?= t('download_excel') ?></a>
      <?php endif; ?>
  <a href="?mine=1&year=<?= $viewYearInt ?>" class="btn"><?= t('my_votes') ?></a>
    </div>

    <table class="table">
      <thead>
        <tr>
          <th><?= t('movie') ?></th>
          <th><?= t('year') ?></th>
          <?php foreach ($numericCols as $col): ?>
            <th><?= t($col) ?: ucfirst(str_replace('_', ' ', e($col))) ?></th>
          <?php endforeach; ?>
          <th style="background: #ffd700;"><?= t('total') ?></th>
          <th style="background: #ffd700;"><?= t('computed_rating') ?></th>
          <th><?= t('season') ?></th>
          <th><?= t('episode') ?></th>
          <th><?= t('when') ?></th>
          <th><?= t('actions') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= e($r['title']) ?></td>
          <td><?= e($r['year']) ?></td>
          <?php foreach ($numericCols as $col): ?>
            <td><?= number_format($r[$col] ?? 0, 1) ?></td>
          <?php endforeach; ?>
          <td class="highlight"><?= number_format($r['total_score'] ?? 0, 2) ?></td>
          <td class="highlight"><?= number_format($r['calc_rating'] ?? 0, 2) ?></td>
          <td><?= e($r['season_number'] ?? '') ?></td>
          <td><?= e($r['episode_number'] ?? '') ?></td>
          <td><?= e($r['created_at']) ?></td>
          <td>
            <a href="vote.php?edit=<?= $r['vote_id'] ?>" class="btn btn-small"><?= t('edit') ?></a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
  }
  include __DIR__.'/includes/footer.php';
  exit;
}

// RESULTS sheet (aggregated movie results)
if ($sheet === 'results') {
  // Build rating expression and available detail columns
  $vdCols = $mysqli->query("SHOW COLUMNS FROM vote_details")->fetch_all(MYSQLI_ASSOC);
  $detailCols = ['writing','direction','acting_or_doc_theme','emotional_involvement','novelty','casting_research_art','sound'];
  $have = array_map(function($c){return $c['Field'];}, $vdCols);
  $detailCols = array_values(array_filter($detailCols, function($c) use ($have){ return in_array($c,$have); }));
  $numExpr = $denExpr = null;
  if (!$hasRating && $detailCols) {
    $numParts = array_map(function($col){ return "COALESCE(vd.`$col`,0)"; }, $detailCols);
    $denParts = array_map(function($col){ return "(vd.`$col` IS NOT NULL)"; }, $detailCols);
    $numExpr = implode('+', $numParts);
    $denExpr = implode('+', $denParts);
  }
  $ratingExpr = $hasRating ? 'v.rating' : ($numExpr ? "(($numExpr)/NULLIF($denExpr,0))" : 'NULL');

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
                     WHERE v2.movie_id = m.id" . $subYearV2 . " AND TRIM(COALESCE(vd2.category,''))<>''
                     GROUP BY vd2.category ORDER BY COUNT(*) DESC LIMIT 1
                   ) AS category_mode,
             (
               SELECT vd3.where_watched FROM vote_details vd3
               JOIN votes v3 ON v3.id = vd3.vote_id
               WHERE v3.movie_id = m.id" . $subYearV3 . " AND TRIM(COALESCE(vd3.where_watched,''))<>''
               GROUP BY vd3.where_watched ORDER BY COUNT(*) DESC LIMIT 1
             ) AS platform_mode,
             (
               SELECT vd4.competition_status FROM vote_details vd4
               JOIN votes v4 ON v4.id = vd4.vote_id
               WHERE v4.movie_id = m.id" . $subYearV4 . " AND TRIM(COALESCE(vd4.competition_status,''))<>''
               GROUP BY vd4.competition_status ORDER BY COUNT(*) DESC LIMIT 1
             ) AS comp_mode,
             (
               SELECT GROUP_CONCAT(DISTINCT TRIM(vd5.adjective) ORDER BY TRIM(vd5.adjective) SEPARATOR ', ')
               FROM vote_details vd5 JOIN votes v5 ON v5.id = vd5.vote_id
               WHERE v5.movie_id = m.id" . $subYearV5 . " AND TRIM(COALESCE(vd5.adjective,''))<>''
             ) AS adjectives
      FROM movies m
      " . $leftJoinVotesForResults . "
      LEFT JOIN vote_details vd ON vd.vote_id = v.id
      GROUP BY m.id
      HAVING votes_count > 0
      ORDER BY avg_rating DESC, votes_count DESC
  ";
  $rows = $mysqli->query($sql)->fetch_all(MYSQLI_ASSOC);
  ?>
  

  <div class="nav-buttons">
    <?php if (function_exists('is_admin') && is_admin()): ?>
  <a href="export_results.php?year=<?= $viewYearInt ?>" class="btn">⬇ <?= t('download_excel') ?></a>
    <?php endif; ?>
  <a href="?mine=1&year=<?= $viewYearInt ?>" class="btn"><?= t('my_votes') ?></a>
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
          <td style="text-align:left;"><?= e($r['title']) ?> <span style="color:#888;">(<?= e($r['year']) ?>)</span></td>
          <td><?= e($r['category_mode'] ?? '') ?></td>
          <td><?= e($r['platform_mode'] ?? '') ?></td>
          <td><?= e($r['comp_mode'] ?? '') ?></td>
          <td><?= (int)$r['votes_count'] ?></td>
          <td class="highlight"><?= $r['avg_rating']!==null ? number_format($r['avg_rating']*10,2) : '' ?></td>
          <?php foreach ($detailCols as $c): $key = 'avg_'.$c; ?>
            <td><?= isset($r[$key]) && $r[$key]!==null ? number_format($r[$key],2) : '' ?></td>
          <?php endforeach; ?>
          <td style="text-align:left;"><?= e($r['adjectives'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

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
  $sqlRaw = "SELECT v.id as vote_id, v.created_at, u.username, m.title, m.year,
        vd.competition_status, vd.category, vd.where_watched, vd.season_number, vd.episode_number,
        vd.acting_or_doc_theme, vd.casting_research_art, vd.writing, vd.direction, vd.emotional_involvement, vd.novelty, vd.sound,
        " . ($scoreCols ? "($numExpr) AS total_score, ($denExpr) AS non_empty_count, (($numExpr)/NULLIF($denExpr,0)) AS calc_rating" : "NULL AS total_score, NULL AS non_empty_count, NULL AS calc_rating") . "
    FROM votes v
        JOIN users u ON u.id = v.user_id
        JOIN movies m ON m.id = v.movie_id
        LEFT JOIN vote_details vd ON vd.vote_id = v.id
  " . $whereYearClause . "
  ORDER BY v.created_at DESC";
    $rawRows = $mysqli->query($sqlRaw)->fetch_all(MYSQLI_ASSOC);
    ?>
    
    <div class="raw-wrapper">
      <h2 style="text-align:center;"><?= t('raw_votes') ?></h2>
      <div class="nav-buttons">
        <?php if (function_exists('is_admin') && is_admin()): ?>
        <a href="export_results.php?year=<?= $viewYearInt ?>" class="btn">⬇ <?= t('download_excel') ?></a>
        <?php endif; ?>
  <a href="?mine=1&year=<?= $viewYearInt ?>" class="btn"><?= t('my_votes') ?></a>
      </div>
      <table class="raw-table">
      <thead>
        <tr>
          <th>ID</th>
          <th><?= t('when') ?></th>
          <th><?= t('username') ?></th>
          <th><?= t('movie') ?></th>
          <th><?= t('year') ?></th>
          <th><?= t('competition_status') ?></th>
          <th><?= t('category') ?></th>
          <th><?= t('where_watched') ?></th>
          <th><?= t('season') ?></th>
          <th><?= t('episode') ?></th>
          <?php foreach($scoreCols as $col): ?>
            <th><?= t($col) ?: ucfirst(str_replace('_',' ', e($col))) ?></th>
          <?php endforeach; ?>
          <th><?= t('total') ?></th>
          <th><?= t('computed_rating') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rawRows as $r): ?>
        <tr>
          <td><?= (int)$r['vote_id'] ?></td>
          <td><?= e($r['created_at']) ?></td>
          <td><?= e($r['username']) ?></td>
          <td><?= e($r['title']) ?></td>
          <td><?= e($r['year']) ?></td>
          <td><?= e($r['competition_status']) ?></td>
          <td><?= e($r['category']) ?></td>
          <td><?= e($r['where_watched']) ?></td>
          <td><?= e($r['season_number']) ?></td>
          <td><?= e($r['episode_number']) ?></td>
          <?php foreach($scoreCols as $col): ?>
            <td><?= isset($r[$col]) && $r[$col] !== null ? number_format($r[$col],1) : '' ?></td>
          <?php endforeach; ?>
          <td class="raw-highlight"><?= $r['total_score'] !== null ? number_format($r['total_score'],2) : '' ?></td>
          <td class="raw-highlight"><?= $r['calc_rating'] !== null ? number_format($r['calc_rating'],2) : '' ?></td>
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
    $numExpr = $denExpr = null;
    if ($numericCols) {
      $numParts = array_map(function($col){ return "COALESCE(vd.`$col`,0)"; }, $numericCols);
      $denParts = array_map(function($col){ return "(vd.`$col` IS NOT NULL)"; }, $numericCols);
      $numExpr = implode('+',$numParts);
      $denExpr = implode('+',$denParts);
    }
    $ratingExpr = $hasRating ? 'v.rating' : ($numExpr ? "(($numExpr)/NULLIF($denExpr,0))" : 'NULL');

    // Summary counts per category
    $categories = ['Film','Serie','Miniserie','Documentario','Animazione'];
    $summary = [];
    foreach ($categories as $cat) {
        $catEsc = $mysqli->real_escape_string($cat);
    $q1 = $mysqli->query("SELECT COUNT(DISTINCT v.movie_id) AS uniq_titles, COUNT(v.id) AS views
              FROM votes v LEFT JOIN vote_details vd ON vd.vote_id=v.id
              WHERE COALESCE(vd.category,'Altro')='$catEsc'" . $yearCond);
        $summary[$cat] = $q1 ? $q1->fetch_assoc() : ['uniq_titles'=>0,'views'=>0];
      }
    // Also totals across all
  $qTot = $mysqli->query("SELECT COUNT(DISTINCT v.movie_id) AS uniq_titles, COUNT(v.id) AS views FROM votes v " . $whereYearClause);
    $summaryTotal = $qTot ? $qTot->fetch_assoc() : ['uniq_titles'=>0,'views'=>0];

    // Platform x category metrics
    $sql = "SELECT COALESCE(NULLIF(TRIM(vd.where_watched),''),'Altro') AS platform,
                   COALESCE(NULLIF(TRIM(vd.category),''),'Altro') AS category,
                   COUNT(DISTINCT v.movie_id) AS uniq_titles,
                   COUNT(v.id) AS views,
                   ROUND(AVG($ratingExpr),2) AS avg_rating
      FROM votes v
      LEFT JOIN vote_details vd ON vd.vote_id = v.id
  " . $whereYearClause . "
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

    <div class="nav-buttons">
      <?php if (function_exists('is_admin') && is_admin()): ?>
          <a href="export_results.php?year=<?= $viewYearInt ?>" class="btn">⬇ <?= t('download_excel') ?></a>
      <?php endif; ?>
  <a href="?mine=1&year=<?= $viewYearInt ?>" class="btn"><?= t('my_votes') ?></a>
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
    $numExpr = $denExpr = null;
    if ($detailCols) {
      $numParts = array_map(function($col){ return "COALESCE(vd.`$col`,0)"; }, $detailCols);
      $denParts = array_map(function($col){ return "(vd.`$col` IS NOT NULL)"; }, $detailCols);
      $numExpr = implode('+',$numParts);
      $denExpr = implode('+',$denParts);
    }
    $ratingExpr = $hasRating ? 'v.rating' : ($numExpr ? "(($numExpr)/NULLIF($denExpr,0))" : 'NULL');

    $sql = "SELECT u.username AS judge,
                   COUNT(v.id) AS votes,
                   SUM(COALESCE(vd.category,'')='Film') AS film_count,
                   SUM(COALESCE(vd.category,'')='Serie') AS series_count,
                   SUM(COALESCE(vd.category,'')='Miniserie') AS miniseries_count,
                   SUM(COALESCE(vd.category,'')='Documentario') AS doc_count,
                   SUM(COALESCE(vd.category,'')='Animazione') AS anim_count,
                   ROUND(AVG($ratingExpr)*10,2) AS media_totale,
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
    if (!empty($compWhere)) {
      // compWhere starts with WHERE ... so strip it and append
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
  $rows = $mysqli->query("SELECT DISTINCT m.title FROM votes v JOIN movies m ON m.id=v.movie_id " . $whereYearClause . " ORDER BY m.title ASC")->fetch_all(MYSQLI_ASSOC);
    ?>
    
    <div class="nav-buttons">
      <?php if (function_exists('is_admin') && is_admin()): ?>
  <a href="export_results.php?year=<?= $viewYearInt ?>" class="btn">⬇ <?= t('download_excel') ?></a>
      <?php endif; ?>
  <a href="?mine=1&year=<?= $viewYearInt ?>" class="btn"><?= t('my_votes') ?></a>
    </div>

    <table class="table">
      <thead><tr><th><?= t('sheet_titles') ?></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td><?= e($r['title']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
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
  <a href="export_results.php?year=<?= $viewYearInt ?>" class="btn">⬇ <?= t('download_excel') ?></a>
      <?php endif; ?>
  <a href="?mine=1&year=<?= $viewYearInt ?>" class="btn"><?= t('my_votes') ?></a>
    </div>

    <?php if (!$hasAdj): ?>
      <p style="padding:1rem;background:#111;border:1px solid #333;border-radius:.5rem;max-width:1200px;margin:1rem auto;">No adjective field in vote details.</p>
    <?php else: ?>
      <?php
  $rows = $mysqli->query("SELECT m.title, TRIM(vd.adjective) AS adjective
    FROM votes v
    JOIN movies m ON m.id=v.movie_id
    LEFT JOIN vote_details vd ON vd.vote_id=v.id
    WHERE TRIM(COALESCE(vd.adjective,''))<>''" . $yearCond . "
    ORDER BY m.title, vd.adjective")->fetch_all(MYSQLI_ASSOC);
        // aggregated list per movie
  $agg = $mysqli->query("SELECT m.title, GROUP_CONCAT(DISTINCT TRIM(vd.adjective) ORDER BY TRIM(vd.adjective) SEPARATOR ', ') AS adjectives
             FROM votes v
             JOIN movies m ON m.id=v.movie_id
             LEFT JOIN vote_details vd ON vd.vote_id=v.id
             WHERE TRIM(COALESCE(vd.adjective,''))<>''" . $yearCond . "
             GROUP BY m.title
             ORDER BY m.title")->fetch_all(MYSQLI_ASSOC);
        $aggMap = [];
        foreach ($agg as $a) { $aggMap[$a['title']] = $a['adjectives']; }
      ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= t('film') ?></th>
            <th><?= t('adjective') ?></th>
            <th><?= t('adjectives') ?></th>
            <?php for ($i=1;$i<=10;$i++): ?>
              <th><?= t('adjective') . $i ?></th>
            <?php endfor; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): $list = $aggMap[$r['title']] ?? ''; $parts = $list? explode(', ',$list):[]; ?>
            <tr>
              <td><?= e($r['title']) ?></td>
              <td><?= e($r['adjective']) ?></td>
              <td><?= e($list) ?></td>
              <?php for ($i=0;$i<10;$i++): ?>
                <td><?= isset($parts[$i])? e($parts[$i]) : '' ?></td>
              <?php endfor; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
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
    
    <div class="nav-buttons">
      <?php if (function_exists('is_admin') && is_admin()): ?>
  <a href="export_results.php?year=<?= $viewYearInt ?>" class="btn">⬇ <?= t('download_excel') ?></a>
      <?php endif; ?>
  <a href="?mine=1&year=<?= $viewYearInt ?>" class="btn"><?= t('my_votes') ?></a>
    </div>

    <table class="table">
      <thead><tr><th><?= e(t('finalists')) . ' ' . (int)$selected_year ?></th><th><?= t('category') ?></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td><?= e($r['title']) ?></td><td><?= e($r['category']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
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
