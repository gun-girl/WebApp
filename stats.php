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
        ( ($numExpr) / NULLIF($denExpr,0) ) AS calc_rating, v.created_at
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
    <table class="table">
      <thead>
        <tr>
          <th><?= t('movie') ?></th>
          <th><?= t('year') ?></th>
          <th><?= t('computed_rating') ?></th>
          <?php foreach ($numericCols as $col): ?>
            <th><?= t($col) ?: ucfirst(str_replace('_', ' ', e($col))) ?></th>
          <?php endforeach; ?>
          <th><?= t('when') ?></th>
          <th><?= t('actions') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= e($r['title']) ?></td>
          <td><?= e($r['year']) ?></td>
          <td><?= number_format($r['calc_rating'] ?? 0, 1) ?></td>
          <?php foreach ($numericCols as $col): ?>
            <td><?= e($r[$col] ?? '') ?></td>
          <?php endforeach; ?>
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

// General results (aggregated)
if ($hasRating) {
  $sql = "
    SELECT m.id, m.title, m.year, m.poster_url,
           COUNT(v.id) AS votes_count,
           ROUND(AVG(v.rating),2) AS avg_rating
    FROM movies m
    LEFT JOIN votes v ON v.movie_id = m.id
    GROUP BY m.id
    HAVING votes_count > 0
    ORDER BY avg_rating DESC, votes_count DESC
    LIMIT 100
  ";
  } else {
  // compute avg from vote_details per vote, then average across votes
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

    $sql = "
      SELECT m.id, m.title, m.year, m.poster_url,
             COUNT(v.id) AS votes_count,
             ROUND(AVG( ( ($numExpr) / NULLIF($denExpr,0) ) ),2) AS avg_rating
      FROM movies m
      LEFT JOIN votes v ON v.movie_id = m.id
      LEFT JOIN vote_details vd ON vd.vote_id = v.id
      GROUP BY m.id
      HAVING votes_count > 0
      ORDER BY avg_rating DESC, votes_count DESC
      LIMIT 100
    ";
  } else {
    // no numeric detail columns; fall back to vote count only
    $sql = "
      SELECT m.id, m.title, m.year, m.poster_url,
             COUNT(v.id) AS votes_count,
             NULL AS avg_rating
      FROM movies m
      LEFT JOIN votes v ON v.movie_id = m.id
      GROUP BY m.id
      HAVING votes_count > 0
      ORDER BY votes_count DESC
      LIMIT 100
    ";
  }
}
$rows = $mysqli->query($sql)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>">
<head>
  <meta charset="UTF-8">
  <title><?= t('results') ?> – <?= t('site_title') ?></title>
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    body {
      background: radial-gradient(circle at top, #0a0a0a, #000);
      color: #eee;
      font-family: 'Poppins', system-ui, sans-serif;
      margin: 0;
    }

    header {
      background: rgba(0,0,0,0.85);
      padding: 1rem 2rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-bottom: 1px solid #222;
      position: sticky;
      top: 0;
      z-index: 10;
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
      color: #f6c90e;
      font-size: 1.6rem;
      margin: 0;
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

    .results-container {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 1.5rem;
      max-width: 1200px;
      margin: 2rem auto;
      padding: 1rem;
    }

    .card {
      background: #111;
      border-radius: 0.75rem;
      overflow: hidden;
      box-shadow: 0 4px 20px rgba(0,0,0,0.4);
      transition: transform .3s ease, box-shadow .3s ease;
    }

    .card:hover {
      transform: translateY(-6px);
      box-shadow: 0 6px 25px rgba(246,201,14,0.4);
    }

    .card img {
      width: 100%;
      height: 320px;
      object-fit: cover;
      display: block;
    }

    .card-content {
      padding: 1rem;
      text-align: center;
    }

    .card-content h3 {
      font-size: 1.1rem;
      color: #fff;
      margin-bottom: .3rem;
    }

    .card-content p {
      margin: .2rem 0;
      color: #aaa;
      font-size: .9rem;
    }

    .avg-rating {
      color: #f6c90e;
      font-weight: 700;
      font-size: 1.1rem;
      margin-top: .4rem;
    }

    .nav-buttons {
      text-align: center;
      margin: 1rem auto;
    }

    .btn {
      background: #f6c90e;
      color: #000;
      padding: .6rem 1.2rem;
      border-radius: .3rem;
      font-weight: 600;
      text-decoration: none;
      transition: background .3s;
      margin: 0 .5rem;
    }

    .btn:hover { background: #ffde50; }

    footer {
      text-align: center;
      color: #666;
      padding: 2rem 0;
      border-top: 1px solid #222;
      margin-top: 2rem;
    }
  </style>
</head>

  <main>
    <div class="nav-buttons">
      <a href="export_results.php" class="btn">⬇ <?= t('download_excel') ?></a>
      <a href="?mine=1" class="btn"><?= t('my_votes') ?></a>

    </div>

    <section class="results-container">
      <?php foreach ($rows as $r): ?>
        <div class="card">
          <img src="<?= htmlspecialchars($r['poster_url'] ?: 'assets/img/no-poster.png') ?>" alt="<?= htmlspecialchars($r['title']) ?>">
          <div class="card-content">
            <h3><?= htmlspecialchars($r['title']) ?></h3>
            <p><?= htmlspecialchars($r['year']) ?></p>
            <p><?= (int)$r['votes_count'] ?> <?= t('votes') ?></p>
            <p class="avg-rating">⭐ <?= htmlspecialchars($r['avg_rating'] ?? 'N/A') ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </section>
  </main>

  <footer>
    © <?= t('site_title') ?> 2025 — <?= t('celebrating_cinema') ?>
  </footer>
</body>
</html>

<?php include __DIR__.'/includes/footer.php';
