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
$currentYear = date('Y');
$sheet = isset($_GET['sheet']) ? $_GET['sheet'] : 'results';
// Tab labels in the order matching the workbook
$tabs = [
  'votes' => str_replace('{year}', $currentYear, t('sheet_votes')),
  'results' => str_replace('{year}', $currentYear, t('sheet_results')),
  'views' => str_replace('{year}', $currentYear, t('sheet_views')),
  'judges' => t('sheet_judges'),
  'judges_comp' => t('sheet_judges_comp'),
  'titles' => t('sheet_titles'),
  'adjectives' => t('sheet_adjectives'),
  'finalists_2023' => t('sheet_finalists_2023'),
];

// Global fixed bottom tabs styling for all sheets (keeps bottom tabs visible while scrolling)
?>
<style>
  /* Fixed, full-width bottom tab bar so the buttons span the full viewport */
  .sheet-tabs {
    position: fixed !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
  z-index: 1400 !important;
  /* full-width dark footer bar (restored color) */
  background: rgba(8,8,8,0.95) !important;
    padding: .35rem .6rem !important;
    display: flex !important;
    gap: .5rem !important;
    align-items: center !important;
    justify-content: center !important;
    border-top: 1px solid rgba(255,255,255,0.03) !important;
    border-radius: 0 !important;
    box-shadow: 0 -6px 18px rgba(0,0,0,0.35) !important;
    width: 100% !important;
    overflow-x: auto !important;
    -webkit-overflow-scrolling: touch !important;
  }
  .sheet-tabs .sheet-tab { flex: 0 0 auto !important; }

  /* Ensure tabs don't cover page content */
  main { padding-bottom: 140px !important; }

  /* Small-screen: left-align the tabs row so it's easier to scroll horizontally */
  @media (max-width:900px) {
    .sheet-tabs { justify-content: flex-start !important; padding: .25rem .5rem !important; }
  }
</style>
<?php

// detect whether votes.rating exists in the DB
$cols = $mysqli->query("SHOW COLUMNS FROM votes")->fetch_all(MYSQLI_ASSOC);
$fields = array_column($cols, 'Field');
$hasRating = in_array('rating', $fields);

// If user requested their own votes, show per-user list
if (!empty($_GET['mine']) && current_user()) {
  $uid = current_user()['id'];
  if ($hasRating) {
    $stmt = $mysqli->prepare("SELECT v.id AS vote_id, m.title, m.year, v.rating, v.created_at FROM votes v JOIN movies m ON m.id=v.movie_id WHERE v.user_id=? ORDER BY v.created_at DESC");
    $stmt->bind_param('i', $uid);
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
        WHERE v.user_id = ?
        ORDER BY v.created_at DESC";
      $stmt = $mysqli->prepare($sqlUser);
      $stmt->bind_param('i', $uid);
      $stmt->execute();
      $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
      // no numeric detail columns: just list votes without computed rating
      $stmt = $mysqli->prepare("SELECT v.id AS vote_id, m.title, m.year, NULL AS calc_rating, v.created_at FROM votes v JOIN movies m ON m.id=v.movie_id WHERE v.user_id=? ORDER BY v.created_at DESC");
      $stmt->bind_param('i', $uid);
      $stmt->execute();
      $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    ?>
    <div class="nav-buttons">
      <a href="index.php" class="btn"><?= t('home') ?></a>
    </div>
    <h2><?= t('your_votes_detailed') ?></h2>
    <style>
      .table { border-collapse: collapse; width: 100%; margin: 1rem 0; }
      .table th, .table td { padding: 0.5rem; text-align: center; border: 1px solid #444; }
      .table th { background: #f6c90e; color: #000; font-weight: bold; }
      .table td.highlight { background: #ffffcc; color: #000; font-weight: bold; }
      .table td { background: #1a1a1a; }
      .table tr:hover td { background: #2a2a2a; }
    </style>
    <table class="table">
      <thead>
        <tr>
          <th><?= t('movie') ?></th>
          <th><?= t('year') ?></th>
          <?php foreach ($numericCols as $col): ?>
            <th><?= t($col) ?: ucfirst(str_replace('_', ' ', e($col))) ?></th>
          <?php endforeach; ?>
          <th style="background: #ffd700;">Totale</th>
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
               WHERE v2.movie_id = m.id AND TRIM(COALESCE(vd2.category,''))<>''
               GROUP BY vd2.category ORDER BY COUNT(*) DESC LIMIT 1
             ) AS category_mode,
             (
               SELECT vd3.where_watched FROM vote_details vd3
               JOIN votes v3 ON v3.id = vd3.vote_id
               WHERE v3.movie_id = m.id AND TRIM(COALESCE(vd3.where_watched,''))<>''
               GROUP BY vd3.where_watched ORDER BY COUNT(*) DESC LIMIT 1
             ) AS platform_mode,
             (
               SELECT vd4.competition_status FROM vote_details vd4
               JOIN votes v4 ON v4.id = vd4.vote_id
               WHERE v4.movie_id = m.id AND TRIM(COALESCE(vd4.competition_status,''))<>''
               GROUP BY vd4.competition_status ORDER BY COUNT(*) DESC LIMIT 1
             ) AS comp_mode,
             (
               SELECT GROUP_CONCAT(DISTINCT TRIM(vd5.adjective) ORDER BY TRIM(vd5.adjective) SEPARATOR ', ')
               FROM vote_details vd5 JOIN votes v5 ON v5.id = vd5.vote_id
               WHERE v5.movie_id = m.id AND TRIM(COALESCE(vd5.adjective,''))<>''
             ) AS adjectives
      FROM movies m
      LEFT JOIN votes v ON v.movie_id = m.id
      LEFT JOIN vote_details vd ON vd.vote_id = v.id
      GROUP BY m.id
      HAVING votes_count > 0
      ORDER BY avg_rating DESC, votes_count DESC
  ";
  $rows = $mysqli->query($sql)->fetch_all(MYSQLI_ASSOC);
  ?>
  <style>
    .nav-buttons { text-align:center; margin:1rem auto; }
    .btn { background:#f6c90e; color:#000; padding:.6rem 1.2rem; border-radius:.3rem; font-weight:600; text-decoration:none; transition:background .3s; margin:0 .5rem; }
    .btn:hover { background:#ffde50; }
  /* Unified table styling (same as "My Votes" table) - expanded to avoid extra scrollbars */
    .table { border-collapse: collapse; width: 100%; margin: 1rem auto; max-width: 1200px; font-size:0.9rem; }
    .table th, .table td { padding: 0.5rem; text-align: center; vertical-align: middle; border: 1px solid #444; }
    .table th { background: #f6c90e; color: #000; font-weight: bold; }
    .table td { background: #1a1a1a; color: #ddd; }
  .table tr:hover td { background: #2a2a2a; }
  .highlight { background:#ffffcc; color:#000; font-weight:700; }
  .sheet-tabs{max-width:1400px;margin:1rem auto;padding:.5rem 1rem;display:flex;gap:.5rem;border-top:1px solid #333}
  .sheet-tab{background:#1a1a1a;color:#ccc;border:1px solid #333;border-bottom:none;border-radius:.5rem .5rem 0 0;padding:.5rem 1rem;text-decoration:none}
  .sheet-tab.active{background:#f6c90e;color:#000;font-weight:700}
  header { position: fixed !important; top: 0; left: 0; right: 0; z-index: 1200; }
  main { padding-top: 92px !important; }
  html, body { overflow-y: auto !important; }
  </style>

  <div class="nav-buttons">
    <?php if (function_exists('is_admin') && is_admin()): ?>
      <a href="export_results.php" class="btn">⬇ <?= t('download_excel') ?></a>
    <?php endif; ?>
    <a href="?mine=1" class="btn"><?= t('my_votes') ?></a>
  </div>

  <table class="table">
    <thead>
      <tr>
        <th><?= t('movie') ?></th>
        <th><?= t('category') ?></th>
        <th><?= t('where_watched') ?></th>
        <th><?= t('competition_status') ?></th>
        <th><?= t('votes') ?></th>
        <th>Totale</th>
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
  
  <div class="sheet-copyright">© IL DIVANO D’ORO</div>
  <div class="sheet-tabs">
    <?php foreach ($tabs as $code => $label): ?>
      <a class="sheet-tab <?= $sheet === $code ? 'active' : '' ?>" href="?sheet=<?= urlencode($code) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <?php if (function_exists('is_admin') && is_admin()): ?>
      <a class="sheet-tab <?= $sheet==='raw' ? 'active' : '' ?>" href="?sheet=raw">RAW</a>
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
  ORDER BY v.created_at DESC";
    $rawRows = $mysqli->query($sqlRaw)->fetch_all(MYSQLI_ASSOC);
    ?>
    <style>
  /* Use unified table styling and remove narrow wrapper to avoid separate scrollbar */
    .raw-wrapper{max-width:1200px; width:100%; margin:0 auto; padding:0 1rem}
    .raw-table{border-collapse:collapse;width:100%;margin:1rem 0;font-size:0.9rem}
    .raw-table th,.raw-table td{padding:.5rem;border:1px solid #444;text-align:center;vertical-align:middle}
    .raw-table th{background:#f6c90e;color:#000;font-weight:700}
  .raw-table td{background:#1a1a1a;color:#ddd}
  .raw-table tbody tr:hover td{background:#2a2a2a}
  .raw-highlight{background:#ffffcc;color:#000;font-weight:700}
  .sheet-tabs{max-width:1400px;margin:1rem auto;padding:.5rem 1rem;display:flex;gap:.5rem;border-top:1px solid #333}
  .sheet-tab{background:#1a1a1a;color:#ccc;border:1px solid #333;border-bottom:none;border-radius:.5rem .5rem 0 0;padding:.5rem 1rem;text-decoration:none}
  .sheet-tab.active{background:#f6c90e;color:#000;font-weight:700}
    </style>

  <style>
    /* Make the sheet-tabs sticky at the bottom so tabs remain visible while scrolling */
    .sheet-tabs {
      position: sticky;
      bottom: 0;
      z-index: 1100;
      background: rgba(8,8,8,0.95);
      padding: .5rem 1rem;
      display: flex;
      gap: .5rem;
      align-items: center;
      justify-content: center;
      border-top: 1px solid #222;
    }
    .sheet-tabs .sheet-tab { flex: 0 0 auto; }

    /* Prevent the sticky tabs from covering content */
    main { padding-bottom: 96px !important; }
  </style>
    <div class="raw-wrapper">
      <h2 style="text-align:center;"><?= t('raw_votes') ?></h2>
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
          <th>Totale</th>
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
    <div class="sheet-copyright">© IL DIVANO D’ORO</div>
    <div class="sheet-tabs">
      <?php foreach ($tabs as $code => $label): ?>
        <a class="sheet-tab <?= $sheet === $code ? 'active' : '' ?>" href="?sheet=<?= urlencode($code) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
      <?php if (function_exists('is_admin') && is_admin()): ?>
        <a class="sheet-tab <?= $sheet==='raw' ? 'active' : '' ?>" href="?sheet=raw">RAW</a>
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
                            WHERE COALESCE(vd.category,'Altro')='$catEsc'");
      $summary[$cat] = $q1 ? $q1->fetch_assoc() : ['uniq_titles'=>0,'views'=>0];
    }
    // Also totals across all
    $qTot = $mysqli->query("SELECT COUNT(DISTINCT v.movie_id) AS uniq_titles, COUNT(v.id) AS views FROM votes v");
    $summaryTotal = $qTot ? $qTot->fetch_assoc() : ['uniq_titles'=>0,'views'=>0];

    // Platform x category metrics
    $sql = "SELECT COALESCE(NULLIF(TRIM(vd.where_watched),''),'Altro') AS platform,
                   COALESCE(NULLIF(TRIM(vd.category),''),'Altro') AS category,
                   COUNT(DISTINCT v.movie_id) AS uniq_titles,
                   COUNT(v.id) AS views,
                   ROUND(AVG($ratingExpr),2) AS avg_rating
            FROM votes v
            LEFT JOIN vote_details vd ON vd.vote_id = v.id
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
    <style>
      .table{border-collapse:collapse;width:100%;margin:1rem 0;font-size:0.9rem;max-width:1200px}
      .table th,.table td{border:1px solid #444;padding:0.5rem;text-align:center;vertical-align:middle}
      .table th{background:#f6c90e;color:#000;font-weight:700}
      .table td{background:#1a1a1a;color:#ddd}
      .sheet-tabs{max-width:1400px;margin:1rem auto;padding:.5rem 1rem;display:flex;gap:.5rem;border-top:1px solid #333}
      .sheet-tab{background:#1a1a1a;color:#ccc;border:1px solid #333;border-bottom:none;border-radius:.5rem .5rem 0 0;padding:.5rem 1rem;text-decoration:none}
      .sheet-tab.active{background:#f6c90e;color:#000;font-weight:700}
      .summary{max-width:1400px;margin:1rem auto;background:#111;border:1px solid #333;border-radius:.5rem;padding:1rem}
      .summary h3{margin:0 0 .5rem 0}
      .summary-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:.5rem}
      .summary-item{background:#1a1a1a;border:1px solid #333;border-radius:.3rem;padding:.5rem}
      .summary-item strong{display:block;color:#f6c90e;margin-bottom:.25rem}
    </style>
    <div class="summary">
      <h3><?= e(t('sheet_views')) ?></h3>
      <div class="summary-grid">
        <?php foreach ($categories as $cat): $s=$summary[$cat]; ?>
          <div class="summary-item"><strong><?= e($cat) ?> — Titoli Unici</strong><?= (int)$s['uniq_titles'] ?></div>
        <?php endforeach; ?>
        <div class="summary-item"><strong>TOTALE — Titoli Unici</strong><?= (int)$summaryTotal['uniq_titles'] ?></div>
        <?php foreach ($categories as $cat): $s=$summary[$cat]; ?>
          <div class="summary-item"><strong><?= e($cat) ?> — Totale Visioni</strong><?= (int)$s['views'] ?></div>
        <?php endforeach; ?>
        <div class="summary-item"><strong>TOTALE — Visioni</strong><?= (int)$summaryTotal['views'] ?></div>
      </div>
    </div>

    <table class="table">
      <thead>
        <tr>
          <th>Piattaforma</th>
          <th>Media Qualitativa</th>
          <?php foreach ($categories as $cat): ?>
            <th><?= e($cat) ?> Titoli</th>
            <th><?= e($cat) ?> Media Voto</th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($platforms as $plat): ?>
          <tr>
            <td><?= e($plat) ?></td>
            <?php
              // overall platform avg
              $avg = $mysqli->query("SELECT ROUND(AVG($ratingExpr),2) AS a FROM votes v LEFT JOIN vote_details vd ON vd.vote_id=v.id WHERE COALESCE(NULLIF(TRIM(vd.where_watched),''),'Altro')='".$mysqli->real_escape_string($plat)."'");
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
    <div class="sheet-copyright">© IL DIVANO D’ORO</div>
    <div class="sheet-tabs">
      <?php foreach ($tabs as $code => $label): ?>
        <a class="sheet-tab <?= $sheet === $code ? 'active' : '' ?>" href="?sheet=<?= urlencode($code) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
      <?php if (function_exists('is_admin') && is_admin()): ?>
        <a class="sheet-tab <?= $sheet==='raw' ? 'active' : '' ?>" href="?sheet=raw">RAW</a>
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
            $compWhere
            GROUP BY u.username
            ORDER BY votes DESC, media_totale DESC";
    $rows = $mysqli->query($sql)->fetch_all(MYSQLI_ASSOC);
    ?>
    <style>
      .table{border-collapse:collapse;width:100%;margin:1rem 0;font-size:0.9rem;max-width:1200px}
      .table th,.table td{border:1px solid #444;padding:0.5rem;text-align:center;vertical-align:middle}
      .table th{background:#f6c90e;color:#000;font-weight:700}
      .table td{background:#1a1a1a;color:#ddd}
      .sheet-tabs{max-width:1400px;margin:1rem auto;padding:.5rem 1rem;display:flex;gap:.5rem;border-top:1px solid #333}
      .sheet-tab{background:#1a1a1a;color:#ccc;border:1px solid #333;border-bottom:none;border-radius:.5rem .5rem 0 0;padding:.5rem 1rem;text-decoration:none}
      .sheet-tab.active{background:#f6c90e;color:#000;font-weight:700}
    </style>
    <table class="table">
      <thead>
        <tr>
          <th>Giudice</th>
          <th>Voti</th>
          <th>Film</th>
          <th>Serie</th>
          <th>Miniserie</th>
          <th>Documentario</th>
          <th>Animazione</th>
          <th>Media Tot Votazioni</th>
          <th>Media Sceneggiatura</th>
          <th>Media Regia</th>
          <th>Media Recitazione/Tema</th>
          <th>Media Coinvolgimento</th>
          <th>Media Senso di Nuovo</th>
          <th>Media Casting/Artwork</th>
          <th>Media Sonoro</th>
          <th>Quanti 10</th>
          <th>% di 10</th>
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
    <div class="sheet-copyright">© IL DIVANO D’ORO</div>
    <div class="sheet-tabs">
      <?php foreach ($tabs as $code => $label): ?>
        <a class="sheet-tab <?= $sheet === $code ? 'active' : '' ?>" href="?sheet=<?= urlencode($code) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
      <?php if (function_exists('is_admin') && is_admin()): ?>
        <a class="sheet-tab <?= $sheet==='raw' ? 'active' : '' ?>" href="?sheet=raw">RAW</a>
      <?php endif; ?>
    </div>
    <?php include __DIR__.'/includes/footer.php';
    exit;
}

// TITLES sheet (unique list like UNIQUE(SORT(...)))
if ($sheet === 'titles') {
    $rows = $mysqli->query("SELECT DISTINCT m.title FROM votes v JOIN movies m ON m.id=v.movie_id ORDER BY m.title ASC")->fetch_all(MYSQLI_ASSOC);
    ?>
    <style>
      .table{border-collapse:collapse;width:100%;margin:1rem 0;font-size:.85rem}
      .table th,.table td{border:1px solid #444;padding:.5rem;text-align:center;vertical-align:middle}
      .table th{background:#f6c90e;color:#000;font-weight:700}
      .table td{background:#1a1a1a;color:#ddd}
      .sheet-tabs{max-width:1200px;margin:1rem auto;padding:.5rem 1rem;display:flex;gap:.5rem;border-top:1px solid #333}
      .sheet-tab{background:#1a1a1a;color:#ccc;border:1px solid #333;border-bottom:none;border-radius:.5rem .5rem 0 0;padding:.5rem 1rem;text-decoration:none}
      .sheet-tab.active{background:#f6c90e;color:#000;font-weight:700}
    </style>
    <table class="table">
      <thead><tr><th><?= t('sheet_titles') ?></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td><?= e($r['title']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="sheet-copyright">© IL DIVANO D’ORO</div>
    <div class="sheet-tabs">
      <?php foreach ($tabs as $code => $label): ?>
        <a class="sheet-tab <?= $sheet === $code ? 'active' : '' ?>" href="?sheet=<?= urlencode($code) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
      <?php if (function_exists('is_admin') && is_admin()): ?>
        <a class="sheet-tab <?= $sheet==='raw' ? 'active' : '' ?>" href="?sheet=raw">RAW</a>
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
    <style>
      .table{border-collapse:collapse;width:100%;margin:1rem 0;font-size:.85rem}
      .table th,.table td{border:1px solid #444;padding:.5rem;text-align:center;vertical-align:middle}
      .table th{background:#f6c90e;color:#000;font-weight:700}
      .table td{background:#1a1a1a;color:#ddd}
      .sheet-tabs{max-width:1200px;margin:1rem auto;padding:.5rem 1rem;display:flex;gap:.5rem;border-top:1px solid #333}
      .sheet-tab{background:#1a1a1a;color:#ccc;border:1px solid #333;border-bottom:none;border-radius:.5rem .5rem 0 0;padding:.5rem 1rem;text-decoration:none}
      .sheet-tab.active{background:#f6c90e;color:#000;font-weight:700}
    </style>
    <?php if (!$hasAdj): ?>
      <p style="padding:1rem;background:#111;border:1px solid #333;border-radius:.5rem;max-width:1200px;margin:1rem auto;">No adjective field in vote details.</p>
    <?php else: ?>
      <?php
        $rows = $mysqli->query("SELECT m.title, TRIM(vd.adjective) AS adjective
                                 FROM votes v
                                 JOIN movies m ON m.id=v.movie_id
                                 LEFT JOIN vote_details vd ON vd.vote_id=v.id
                                 WHERE TRIM(COALESCE(vd.adjective,''))<>''
                                 ORDER BY m.title, vd.adjective")->fetch_all(MYSQLI_ASSOC);
        // aggregated list per movie
        $agg = $mysqli->query("SELECT m.title, GROUP_CONCAT(DISTINCT TRIM(vd.adjective) ORDER BY TRIM(vd.adjective) SEPARATOR ', ') AS adjectives
                               FROM votes v
                               JOIN movies m ON m.id=v.movie_id
                               LEFT JOIN vote_details vd ON vd.vote_id=v.id
                               WHERE TRIM(COALESCE(vd.adjective,''))<>''
                               GROUP BY m.title
                               ORDER BY m.title")->fetch_all(MYSQLI_ASSOC);
        $aggMap = [];
        foreach ($agg as $a) { $aggMap[$a['title']] = $a['adjectives']; }
      ?>
      <table class="table">
        <thead>
          <tr>
            <th>FILM</th>
            <th>AGGETTIVO</th>
            <th>Aggettivi</th>
            <?php for ($i=1;$i<=10;$i++): ?>
              <th>Aggettivo<?= $i ?></th>
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
    <div class="sheet-copyright">© IL DIVANO D’ORO</div>
    <div class="sheet-tabs">
      <?php foreach ($tabs as $code => $label): ?>
        <a class="sheet-tab <?= $sheet === $code ? 'active' : '' ?>" href="?sheet=<?= urlencode($code) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
      <?php if (function_exists('is_admin') && is_admin()): ?>
        <a class="sheet-tab <?= $sheet==='raw' ? 'active' : '' ?>" href="?sheet=raw">RAW</a>
      <?php endif; ?>
    </div>
    <?php include __DIR__.'/includes/footer.php';
    exit;
}

// FINALISTS 2023 sheet (placeholder based on 2023 titles seen)
if ($sheet === 'finalists_2023') {
    $rows = $mysqli->query("SELECT DISTINCT m.title, COALESCE(vd.category,'Altro') AS category
                             FROM votes v
                             JOIN movies m ON m.id=v.movie_id
                             LEFT JOIN vote_details vd ON vd.vote_id=v.id
                             WHERE m.year=2023
                             ORDER BY m.title")->fetch_all(MYSQLI_ASSOC);
    ?>
    <style>
      .table{border-collapse:collapse;width:100%;margin:1rem 0;font-size:.85rem}
      .table th,.table td{border:1px solid #444;padding:.5rem;text-align:center;vertical-align:middle}
      .table th{background:#f6c90e;color:#000;font-weight:700}
      .table td{background:#1a1a1a;color:#ddd}
      .sheet-tabs{max-width:1200px;margin:1rem auto;padding:.5rem 1rem;display:flex;gap:.5rem;border-top:1px solid #333}
      .sheet-tab{background:#1a1a1a;color:#ccc;border:1px solid #333;border-bottom:none;border-radius:.5rem .5rem 0 0;padding:.5rem 1rem;text-decoration:none}
      .sheet-tab.active{background:#f6c90e;color:#000;font-weight:700}
    </style>
    <table class="table">
      <thead><tr><th><?= t('sheet_finalists_2023') ?></th><th>Categoria</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td><?= e($r['title']) ?></td><td><?= e($r['category']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="sheet-copyright">© IL DIVANO D’ORO</div>
    <div class="sheet-tabs">
      <?php foreach ($tabs as $code => $label): ?>
        <a class="sheet-tab <?= $sheet === $code ? 'active' : '' ?>" href="?sheet=<?= urlencode($code) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
      <?php if (function_exists('is_admin') && is_admin()): ?>
        <a class="sheet-tab <?= $sheet==='raw' ? 'active' : '' ?>" href="?sheet=raw">RAW</a>
      <?php endif; ?>
    </div>
    <?php include __DIR__.'/includes/footer.php';
    exit;
}
