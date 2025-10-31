<?php require_once __DIR__.'/includes/auth.php'; include __DIR__.'/includes/header.php';

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
    <h2>Your Votes</h2>
    <table class="table">
      <thead><tr><th>Movie</th><th>Year</th><th>Your Rating</th><th>When</th></tr></thead>
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
        vd.novelty, vd.casting_research_art, vd.music, vd.release_year,
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
      <a href="index.php" class="btn">Home</a>
    </div>
    <h2>Your Votes (detailed)</h2>
    <table class="table">
      <thead>
        <tr>
          <th>Movie</th>
          <th>Year</th>
          <th>Computed Rating</th>
          <?php foreach ($numericCols as $col): ?>
            <th><?= ucfirst(str_replace('_', ' ', e($col))) ?></th>
          <?php endforeach; ?>
          <th>When</th>
          <th>Actions</th>
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
            <a href="vote.php?edit=<?= $r['vote_id'] ?>" class="btn btn-small">Edit</a>
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
<h2>Results</h2>
<table class="table">
  <thead><tr><th>Movie</th><th>Year</th><th>Votes</th><th>Average</th></tr></thead>
  <tbody>
  <?php foreach($rows as $r): ?>
    <tr>
      <td><?= e($r['title']) ?></td>
      <td><?= e($r['year']) ?></td>
      <td><?= (int)$r['votes_count'] ?></td>
      <td><?= e($r['avg_rating']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php include __DIR__.'/includes/footer.php';
