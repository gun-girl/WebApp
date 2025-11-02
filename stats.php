<?php
require_once __DIR__.'/includes/auth.php';
include __DIR__.'/includes/header.php';

// Flash message (if any)
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!empty($_SESSION['flash'])) {
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

// Detect whether votes.rating exists
$cols = $mysqli->query("SHOW COLUMNS FROM votes")->fetch_all(MYSQLI_ASSOC);
$fields = array_column($cols, 'Field');
$hasRating = in_array('rating', $fields);

// "My votes" detailed view
if (!empty($_GET['mine']) && current_user()) {
    $uid = (int) current_user()['id'];

    if ($hasRating) {
        $stmt = $mysqli->prepare("SELECT v.id AS vote_id, m.title, m.year, v.rating, v.created_at
                                   FROM votes v
                                   JOIN movies m ON m.id = v.movie_id
                                   WHERE v.user_id = ?
                                   ORDER BY v.created_at DESC");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        echo '<div class="nav-buttons"><a href="index.php" class="btn">Home</a></div>';
        echo '<h2>Your Votes</h2>';
        echo '<table class="table">';
        echo '<thead><tr><th>Movie</th><th>Year</th><th>Your Rating</th><th>When</th><th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td>'.e($r['title']).'</td>';
            echo '<td>'.e($r['year']).'</td>';
            echo '<td>'.e($r['rating']).'</td>';
            echo '<td>'.e($r['created_at']).'</td>';
            echo '<td><a class="btn btn-small" href="vote.php?edit='.((int)$r['vote_id']).'">Edit</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        include __DIR__.'/includes/footer.php';
        exit;
    }

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
                           ( ($numExpr) / NULLIF($denExpr,0) ) AS calc_rating,
                           v.created_at
                    FROM votes v
                    JOIN movies m ON m.id = v.movie_id
                    LEFT JOIN vote_details vd ON vd.vote_id = v.id
                    WHERE v.user_id = ?
                    ORDER BY v.created_at DESC";
        $stmt = $mysqli->prepare($sqlUser);
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        // No numeric columns at all: list without computed rating
        $stmt = $mysqli->prepare("SELECT v.id AS vote_id, m.title, m.year, NULL AS calc_rating, v.created_at
                                   FROM votes v
                                   JOIN movies m ON m.id = v.movie_id
                                   WHERE v.user_id = ?
                                   ORDER BY v.created_at DESC");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    echo '<div class="nav-buttons"><a href="index.php" class="btn">Home</a></div>';
    echo '<h2>Your Votes (detailed)</h2>';
    echo '<table class="table">';
    echo '<thead><tr><th>Movie</th><th>Year</th><th>Computed Rating</th>';
    foreach ($numericCols as $col) {
        echo '<th>'.ucfirst(str_replace('_',' ', e($col))).'</th>';
    }
    echo '<th>When</th><th>Actions</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        echo '<td>'.e($r['title']).'</td>';
        echo '<td>'.e($r['year']).'</td>';
        $calc = isset($r['calc_rating']) ? number_format((float)$r['calc_rating'], 1) : '';
        echo '<td>'.$calc.'</td>';
        foreach ($numericCols as $col) {
            $val = isset($r[$col]) ? (string)$r[$col] : '';
            echo '<td>'.e($val).'</td>';
        }
        echo '<td>'.e($r['created_at']).'</td>';
        echo '<td><a class="btn btn-small" href="vote.php?edit='.((int)$r['vote_id']).'">Edit</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    include __DIR__.'/includes/footer.php';
    exit;
}

// Aggregated results (optionally only movies current user voted for)
$isMineOnly = !empty($_GET['only_mine']) && current_user();

if ($hasRating) {
    if ($isMineOnly) {
        $sql = "SELECT m.id, m.title, m.year, m.poster_url,
                        COUNT(v.id) AS votes_count,
                        ROUND(AVG(v.rating),2) AS avg_rating
                FROM movies m
                JOIN votes v ON v.movie_id = m.id AND v.user_id = ?
                GROUP BY m.id
                ORDER BY avg_rating DESC, votes_count DESC
                LIMIT 100";
    } else {
        $sql = "SELECT m.id, m.title, m.year, m.poster_url,
                        COUNT(v.id) AS votes_count,
                        ROUND(AVG(v.rating),2) AS avg_rating
                FROM movies m
                LEFT JOIN votes v ON v.movie_id = m.id
                GROUP BY m.id
                HAVING votes_count > 0
                ORDER BY avg_rating DESC, votes_count DESC
                LIMIT 100";
    }
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

        if ($isMineOnly) {
            $sql = "SELECT m.id, m.title, m.year, m.poster_url,
                            COUNT(v.id) AS votes_count,
                            ROUND(AVG( ( ($numExpr) / NULLIF($denExpr,0) ) ),2) AS avg_rating
                    FROM movies m
                    JOIN votes v ON v.movie_id = m.id AND v.user_id = ?
                    LEFT JOIN vote_details vd ON vd.vote_id = v.id
                    GROUP BY m.id
                    ORDER BY avg_rating DESC, votes_count DESC
                    LIMIT 100";
        } else {
            $sql = "SELECT m.id, m.title, m.year, m.poster_url,
                            COUNT(v.id) AS votes_count,
                            ROUND(AVG( ( ($numExpr) / NULLIF($denExpr,0) ) ),2) AS avg_rating
                    FROM movies m
                    LEFT JOIN votes v ON v.movie_id = m.id
                    LEFT JOIN vote_details vd ON vd.vote_id = v.id
                    GROUP BY m.id
                    HAVING votes_count > 0
                    ORDER BY avg_rating DESC, votes_count DESC
                    LIMIT 100";
        }
    } else {
        // no numeric detail columns; fall back to vote count only
        if ($isMineOnly) {
            $sql = "SELECT m.id, m.title, m.year, m.poster_url,
                            COUNT(v.id) AS votes_count,
                            NULL AS avg_rating
                    FROM movies m
                    JOIN votes v ON v.movie_id = m.id AND v.user_id = ?
                    GROUP BY m.id
                    ORDER BY votes_count DESC
                    LIMIT 100";
        } else {
            $sql = "SELECT m.id, m.title, m.year, m.poster_url,
                            COUNT(v.id) AS votes_count,
                            NULL AS avg_rating
                    FROM movies m
                    LEFT JOIN votes v ON v.movie_id = m.id
                    GROUP BY m.id
                    HAVING votes_count > 0
                    ORDER BY votes_count DESC
                    LIMIT 100";
        }
    }
}

// Execute
if ($isMineOnly) {
    $stmt = $mysqli->prepare($sql);
    $uid = (int) current_user()['id'];
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $rows = $mysqli->query($sql)->fetch_all(MYSQLI_ASSOC);
}

?>
<div class="nav-buttons">
  <a href="index.php" class="btn">Home</a>
  <a href="export_results.php" class="btn">Download Excel</a>
  <?php if ($isMineOnly): ?>
    <a href="stats.php" class="btn">All Results</a>
  <?php else: ?>
    <?php if (current_user()): ?>
      <a href="stats.php?only_mine=1" class="btn">My Results Only</a>
    <?php endif; ?>
  <?php endif; ?>
</div>
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
