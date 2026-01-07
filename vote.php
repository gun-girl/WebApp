<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/lang.php';
require_once __DIR__.'/includes/omdb.php';
require_login();

$user = current_user();
// Resolve active competition (id, window, and year) from DB
$active_comp_id = function_exists('get_active_competition_id') ? get_active_competition_id() : null;
$active_window_start = null; $active_window_end = null; $active_year = (int)date('Y');
try {
  if ($active_comp_id) {
    $stmtC = $mysqli->prepare("SELECT start, `end` FROM competitions WHERE id = ? LIMIT 1");
    if ($stmtC) {
      $stmtC->bind_param('i', $active_comp_id);
      $stmtC->execute();
      $rowC = $stmtC->get_result()->fetch_assoc();
      if ($rowC) {
        $active_window_start = $rowC['start'];
        $active_window_end = $rowC['end'];
        $active_year = (int)date('Y', strtotime($active_window_start));
      }
    }
  }
  if (!$active_window_start || !$active_window_end) {
    // fallback: latest competition
    $rowF = $mysqli->query("SELECT start, `end` FROM competitions ORDER BY start DESC LIMIT 1")->fetch_assoc();
    if ($rowF) {
      $active_window_start = $rowF['start'];
      $active_window_end = $rowF['end'];
      $active_year = (int)date('Y', strtotime($rowF['start']));
    }
  }
} catch (Throwable $e) {
  // keep defaults
}
// Detect whether the votes table already has competition_year column (backwards compatibility)
$hasCompetitionYear = false;
$hasVoteValue = false;
$hasRatingCol = false;
try {
  $res = $mysqli->query("SHOW COLUMNS FROM votes LIKE 'competition_year'");
  if ($res && $res->num_rows > 0) { $hasCompetitionYear = true; }
  $cols = $mysqli->query("SHOW COLUMNS FROM votes");
  if ($cols) {
    $fields = array_column($cols->fetch_all(MYSQLI_ASSOC), 'Field');
    $hasVoteValue = in_array('vote_value', $fields, true);
    $hasRatingCol = in_array('rating', $fields, true);
  }
} catch (Exception $e) {
  // ignore; treat as missing
}

$edit_vote_id = (int)($_GET['edit'] ?? 0);
$movie_id = (int)($_GET['movie_id'] ?? 0);
$url_season = isset($_GET['season']) ? (int)$_GET['season'] : null;

error_log("[VOTE DEBUG] ===== VOTE PAGE LOADED =====");
error_log("[VOTE DEBUG] movie_id: $movie_id");
error_log("[VOTE DEBUG] url_season: " . ($url_season ?? 'NULL'));
error_log("[VOTE DEBUG] GET params: " . json_encode($_GET));

// Check if user already voted for this movie+season combination (for reminder)
$existing_vote_for_movie = null;
if ($movie_id > 0 && $edit_vote_id === 0) {
  // For series/miniseries with season, check season-specific vote
  if ($url_season !== null) {
    $checkSql = "SELECT v.id FROM votes v 
                 LEFT JOIN vote_details vd ON vd.vote_id = v.id
                 WHERE v.user_id = ? AND v.movie_id = ? AND vd.season_number = ?" 
                 . ($hasCompetitionYear ? " AND v.competition_year = ?" : "");
    $checkStmt = $mysqli->prepare($checkSql);
    if ($hasCompetitionYear) {
      $checkStmt->bind_param('iiii', $user['id'], $movie_id, $url_season, $active_year);
    } else {
      $checkStmt->bind_param('iii', $user['id'], $movie_id, $url_season);
    }
  } else {
    // Regular movie vote check
    $checkSql = "SELECT v.id FROM votes v WHERE v.user_id = ? AND v.movie_id = ?" . ($hasCompetitionYear ? " AND v.competition_year = ?" : "");
    $checkStmt = $mysqli->prepare($checkSql);
    if ($hasCompetitionYear) {
      $checkStmt->bind_param('iii', $user['id'], $movie_id, $active_year);
    } else {
      $checkStmt->bind_param('ii', $user['id'], $movie_id);
    }
  }
  $checkStmt->execute();
  $existing_vote_for_movie = $checkStmt->get_result()->fetch_assoc();
}

// If editing, load the existing vote
$existing_vote = null;
if ($edit_vote_id > 0) {
  // Load vote and verify ownership (include year condition only if column exists)
  $sql = "SELECT v.*, vd.*, m.*\n            FROM votes v\n            INNER JOIN vote_details vd ON vd.vote_id = v.id\n            INNER JOIN movies m ON m.id = v.movie_id\n            WHERE v.id = ? AND v.user_id = ?" . ($hasCompetitionYear ? " AND v.competition_year = ?" : "");
  $stmt = $mysqli->prepare($sql);
  if ($hasCompetitionYear) {
    $stmt->bind_param('iii', $edit_vote_id, $user['id'], $active_year);
  } else {
    $stmt->bind_param('ii', $edit_vote_id, $user['id']);
  }
    $stmt->execute();
    $existing_vote = $stmt->get_result()->fetch_assoc();
    
    if (!$existing_vote) {
        die('Vote not found or access denied');
    }
    
    $movie_id = $existing_vote['movie_id'];
    $movie = $existing_vote;
} else {
    // New vote - require movie_id
    if ($movie_id <= 0) {
        die('Invalid movie');
    }
    
    // Get movie details
    $stmt = $mysqli->prepare("SELECT * FROM movies WHERE id = ?");
    $stmt->bind_param('i', $movie_id);
    $stmt->execute();
    $movie = $stmt->get_result()->fetch_assoc();
    
    if (!$movie) {
        die('Movie not found');
    }
}

// Check if movie has been released yet
$today = date('Y-m-d');
$currentYear = (int)date('Y');
$isReleased = false;

if (!empty($movie['released']) && $movie['released'] !== '0000-00-00') {
  $isReleased = ($movie['released'] <= $today);
} else {
  $movieYear = (int)($movie['year'] ?? 0);
  $isReleased = ($movieYear <= $currentYear);
}

if (!$isReleased && !$edit_vote_id) {
  die('This title has not been released yet and cannot be voted on.');
}

// Best-effort: backfill released date from OMDb if missing
if (!empty($movie['imdb_id']) && (empty($movie['released']) || $movie['released'] === '0000-00-00')) {
  // If it's a series/miniseries and we have a season, fetch season data
  if ($url_season && in_array($movie['type'], ['series', 'miniseries'])) {
    $season_data = fetch_season_data($movie['imdb_id'], $url_season);
    
    // Try to extract release date from season data if available
    if ($season_data && !empty($season_data['Released']) && $season_data['Released'] !== 'N/A') {
      $ts = strtotime($season_data['Released']);
      if ($ts) {
        $releasedYmd = date('Y-m-d', $ts);
        $update = $mysqli->prepare("UPDATE movies SET released = ? WHERE id = ?");
        if ($update) {
          $update->bind_param('si', $releasedYmd, $movie_id);
          $update->execute();
        }
        $movie['released'] = $releasedYmd;
      }
    } else if ($season_data && !empty($season_data['Episodes']) && is_array($season_data['Episodes'])) {
      // If no overall season date, try to get the first episode's release date
      foreach ($season_data['Episodes'] as $episode) {
        if (!empty($episode['Released']) && $episode['Released'] !== 'N/A') {
          $ts = strtotime($episode['Released']);
          if ($ts) {
            $releasedYmd = date('Y-m-d', $ts);
            $update = $mysqli->prepare("UPDATE movies SET released = ? WHERE id = ?");
            if ($update) {
              $update->bind_param('si', $releasedYmd, $movie_id);
              $update->execute();
            }
            $movie['released'] = $releasedYmd;
            break;
          }
        }
      }
    }
  } else {
    // Regular movie/show - fetch basic details
    $detail = fetch_omdb_detail_by_id($movie['imdb_id']);
    if ($detail && !empty($detail['Released'])) {
      $ts = strtotime($detail['Released']);
      if ($ts) {
        $releasedYmd = date('Y-m-d', $ts);
        $update = $mysqli->prepare("UPDATE movies SET released = ? WHERE id = ?");
        if ($update) {
          $update->bind_param('si', $releasedYmd, $movie_id);
          $update->execute();
        }
        $movie['released'] = $releasedYmd;
      }
    }
  }
}

// For series/miniseries with a specific season, get season-specific release date
$seasonReleaseDate = null;
if ($url_season && in_array($movie['type'], ['series', 'miniseries']) && !empty($movie['imdb_id'])) {
  $season_data = fetch_season_data($movie['imdb_id'], $url_season);
  error_log("[VOTE DEBUG] Fetching season $url_season for {$movie['title']} (IMDB: {$movie['imdb_id']})");
  error_log("[VOTE DEBUG] Season data received: " . ($season_data ? 'YES' : 'NO'));
  
  if ($season_data) {
    error_log("[VOTE DEBUG] Season Released field: " . ($season_data['Released'] ?? 'NULL'));
    error_log("[VOTE DEBUG] Episodes count: " . (isset($season_data['Episodes']) && is_array($season_data['Episodes']) ? count($season_data['Episodes']) : 0));
    
    // Try season-level Released field first
    if (!empty($season_data['Released']) && $season_data['Released'] !== 'N/A') {
      $ts = strtotime($season_data['Released']);
      if ($ts) {
        $seasonReleaseDate = date('Y-m-d', $ts);
        error_log("[VOTE DEBUG] Season release date from Season field: $seasonReleaseDate");
      }
    }
    // Fall back to first episode's release date
    if (!$seasonReleaseDate && !empty($season_data['Episodes']) && is_array($season_data['Episodes'])) {
      foreach ($season_data['Episodes'] as $episode) {
        error_log("[VOTE DEBUG] Episode {$episode['Episode']}: Released = " . ($episode['Released'] ?? 'NULL'));
        if (!empty($episode['Released']) && $episode['Released'] !== 'N/A') {
          $ts = strtotime($episode['Released']);
          if ($ts) {
            $seasonReleaseDate = date('Y-m-d', $ts);
            error_log("[VOTE DEBUG] Season release date from Episode {$episode['Episode']}: $seasonReleaseDate");
            break;
          }
        }
      }
    }
  }
  error_log("[VOTE DEBUG] Final seasonReleaseDate: " . ($seasonReleaseDate ?? 'NULL'));
}

// Auto-determine competition status based on the active competition window from DB
$auto_competition_status = 'Out of Competition';
// Use season-specific date if available, otherwise use movie's general release date
$releaseDate = $seasonReleaseDate ?? ($movie['released'] ?? null);
error_log("[VOTE DEBUG] Movie released field: " . ($movie['released'] ?? 'NULL'));
error_log("[VOTE DEBUG] Using release date: " . ($releaseDate ?? 'NULL'));

if (!$releaseDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $releaseDate)) {
  if (!empty($movie['year']) && preg_match('/(\d{4})/', (string)$movie['year'], $m)) {
    $releaseDate = $m[1] . '-01-01';
    error_log("[VOTE DEBUG] Fell back to year-based date: $releaseDate");
  }
}

if ($releaseDate && $active_window_start && $active_window_end) {
  error_log("[VOTE DEBUG] Checking date $releaseDate against active window $active_window_start to $active_window_end");
  if ($releaseDate >= $active_window_start && $releaseDate <= $active_window_end) {
    $auto_competition_status = 'In Competition';
    error_log("[VOTE DEBUG] Result: In Competition");
  } else {
    error_log("[VOTE DEBUG] Result: Out of Competition");
  }
}

// Repair existing vote_detail competition_status if it's out-of-date
if (!empty($existing_vote['id']) && isset($existing_vote['competition_status']) && $existing_vote['competition_status'] !== $auto_competition_status) {
  $fix = $mysqli->prepare("UPDATE vote_details SET competition_status = ? WHERE vote_id = ?");
  if ($fix) {
    $fix->bind_param('si', $auto_competition_status, $existing_vote['id']);
    $fix->execute();
    $existing_vote['competition_status'] = $auto_competition_status;
  }
}

$errors = [];
$errorFields = [];
$old = function($name, $default = '') {
  return ($_SERVER['REQUEST_METHOD'] === 'POST') ? ($_POST[$name] ?? $default) : $default;
};
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    
    // Collect all form data
    // Always recompute competition status server-side to prevent stale/incorrect values
    $competition_status = $auto_competition_status;
    $category = $_POST['category'] ?? '';
    $where_watched = $_POST['where_watched'] ?? '';
    $writing = (float)($_POST['writing'] ?? 0);
    $direction = (float)($_POST['direction'] ?? 0);
    $acting_or_theme = (float)($_POST['acting_or_theme'] ?? 0);
    $emotional_involvement = (float)($_POST['emotional_involvement'] ?? 0);
    $novelty = (float)($_POST['novelty'] ?? 0);
    $casting_research = (float)($_POST['casting_research'] ?? 0);
    $sound = (float)($_POST['sound'] ?? 0);
    $adjective = trim($_POST['adjective'] ?? '');
    // Season (only relevant for Series / Miniseries)
    $season_number = isset($_POST['season_number']) && $_POST['season_number'] !== '' ? (int)$_POST['season_number'] : null;
    
    // Validation
    if (empty($competition_status)) { $errors[] = 'Competition status is required'; $errorFields['competition_status'] = 'Competition status is required'; }
    if (empty($category)) { $errors[] = 'Category is required'; $errorFields['category'] = 'Category is required'; }
    if (empty($where_watched)) { $errors[] = 'Where watched is required'; $errorFields['where_watched'] = 'Where watched is required'; }
    if ($writing < 1 || $writing > 10) { $errors[] = 'Writing score must be between 1-10'; $errorFields['writing'] = 'Must be between 1 and 10'; }
    if ($direction < 1 || $direction > 10) { $errors[] = 'Direction score must be between 1-10'; $errorFields['direction'] = 'Must be between 1 and 10'; }
    if ($acting_or_theme < 1 || $acting_or_theme > 10) { $errors[] = 'Acting/Theme score must be between 1-10'; $errorFields['acting_or_theme'] = 'Must be between 1 and 10'; }
    if ($emotional_involvement < 1 || $emotional_involvement > 10) { $errors[] = 'Emotional involvement score must be between 1-10'; $errorFields['emotional_involvement'] = 'Must be between 1 and 10'; }
    if ($novelty < 1 || $novelty > 10) { $errors[] = 'Novelty score must be between 1-10'; $errorFields['novelty'] = 'Must be between 1 and 10'; }
    if ($casting_research < 1 || $casting_research > 10) { $errors[] = 'Casting/Research score must be between 1-10'; $errorFields['casting_research'] = 'Must be between 1 and 10'; }
    if ($sound < 1 || $sound > 10) { $errors[] = 'Sound score must be between 1-10'; $errorFields['sound'] = 'Must be between 1 and 10'; }
    if (in_array($category, ['Series','Miniseries'])) {
      if ($season_number === null || $season_number < 1) { $errors[] = 'Season number required for series/miniseries'; $errorFields['season_number'] = 'Season required'; }
    }
    
    if (!$errors) {
      // Compute aggregate rating once for legacy columns (vote_value/rating) if present
      $scoreFields = [$writing, $direction, $acting_or_theme, $emotional_involvement, $novelty, $casting_research, $sound];
      $nonNullScores = array_filter($scoreFields, function($v){ return $v !== null && $v !== ''; });
      $computed_rating = $nonNullScores ? array_sum($nonNullScores) / count($nonNullScores) : null;

        $mysqli->begin_transaction();
        try {
            if ($edit_vote_id > 0) {
              // Editing existing vote - verify ownership again (year scoped only if column exists)
              $sqlVerify = "SELECT id FROM votes WHERE id = ? AND user_id = ?" . ($hasCompetitionYear ? " AND competition_year = ?" : "");
              $check = $mysqli->prepare($sqlVerify);
              if ($hasCompetitionYear) {
                $check->bind_param('iii', $edit_vote_id, $user['id'], $active_year);
              } else {
                $check->bind_param('ii', $edit_vote_id, $user['id']);
              }
                $check->execute();
                $verify = $check->get_result()->fetch_assoc();
                
                if (!$verify) {
                    throw new Exception('Access denied');
                }
                
                $vote_id = $edit_vote_id;
            } else {
                // Check if vote exists for this movie in the active competition year (if column exists)
                $sqlCheck = "SELECT id FROM votes WHERE user_id = ? AND movie_id = ?" . ($hasCompetitionYear ? " AND competition_year = ?" : "");
                $check = $mysqli->prepare($sqlCheck);
                if ($hasCompetitionYear) {
                  $check->bind_param('iii', $user['id'], $movie_id, $active_year);
                } else {
                  $check->bind_param('ii', $user['id'], $movie_id);
                }
                $check->execute();
                $existing = $check->get_result()->fetch_assoc();
                
                if ($existing) {
                    $vote_id = $existing['id'];
                } else {
                    // Insert new vote (include optional columns if present)
                    $columns = ['user_id', 'movie_id'];
                    $types = 'ii';
                    $params = [$user['id'], $movie_id];
                    if ($hasCompetitionYear) { $columns[] = 'competition_year'; $types .= 'i'; $params[] = $active_year; }
                    if ($hasRatingCol) { $columns[] = 'rating'; $types .= 'd'; $params[] = $computed_rating; }
                    if ($hasVoteValue) { $columns[] = 'vote_value'; $types .= 'd'; $params[] = $computed_rating; }
                    $placeholders = implode(',', array_fill(0, count($columns), '?'));
                    $sqlInsert = "INSERT INTO votes (" . implode(',', $columns) . ") VALUES ($placeholders)";
                    $stmt = $mysqli->prepare($sqlInsert);
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    $vote_id = $mysqli->insert_id;
                }
            }

            // Keep legacy vote_value/rating columns in sync when they exist
            if ($computed_rating !== null && ($hasRatingCol || $hasVoteValue)) {
              $setParts = [];
              $bindTypes = '';
              $bindValues = [];
              if ($hasRatingCol) { $setParts[] = 'rating = ?'; $bindTypes .= 'd'; $bindValues[] = $computed_rating; }
              if ($hasVoteValue) { $setParts[] = 'vote_value = ?'; $bindTypes .= 'd'; $bindValues[] = $computed_rating; }
              if ($setParts) {
                $updateSql = "UPDATE votes SET " . implode(', ', $setParts) . " WHERE id = ?";
                $bindTypes .= 'i';
                $bindValues[] = $vote_id;
                $up = $mysqli->prepare($updateSql);
                $up->bind_param($bindTypes, ...$bindValues);
                $up->execute();
              }
            }
            
            // Delete existing vote_details
            $stmt = $mysqli->prepare("DELETE FROM vote_details WHERE vote_id = ?");
            $stmt->bind_param('i', $vote_id);
            $stmt->execute();
            
            // Insert new vote_details
            $stmt = $mysqli->prepare("\n          INSERT INTO vote_details \n          (vote_id, writing, direction, acting_or_doc_theme, emotional_involvement, \n           novelty, casting_research_art, sound, competition_status, category, \n           season_number, where_watched, adjective)\n          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n        ");
            // Bind parameters: i=int, d=decimal, s=string
            // vote_id(i), 7 scores(d), competition_status(s), category(s), season(i), where_watched(s), adjective(s)
            $types = 'i' . str_repeat('d', 7) . 'ss' . 'i' . 'ss';
            $stmt->bind_param($types, 
              $vote_id, $writing, $direction, $acting_or_theme, $emotional_involvement,
              $novelty, $casting_research, $sound, $competition_status, $category,
              $season_number, $where_watched, $adjective
            );
            $stmt->execute();
            
            $mysqli->commit();
            
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $_SESSION['flash'] = $edit_vote_id > 0 ? 'Vote updated successfully!' : 'Vote submitted successfully!';
            $_SESSION['flash_edit_vote_id'] = $vote_id;
            redirect(ADDRESS.'/stats.php?mine=1');
            
        } catch (Exception $e) {
            $mysqli->rollback();
            error_log("Vote submission error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
            error_log("Stack trace: " . $e->getTraceAsString());
            $errors[] = 'Error saving vote: ' . $e->getMessage();
        }
    }
}
?>
<?php include __DIR__.'/includes/header.php'; ?>
<style>
  .form-group { position: relative; }
  .form-box { max-width: 520px; margin: 0 auto; }
  .form-group select,
  .form-group input[type="number"],
  .form-group input[type="text"] {
    width: 100%;
    max-width: 100%;
    height: 46px;
    font-size: 15px;
    padding: 10px 12px;
  }
  .has-error input,
  .has-error select {
    border: 2px solid #d9534f;
    box-shadow: 0 0 0 1px rgba(217,83,79,0.25);
  }
  .error-hint { color: #d9534f; font-size: 0.9em; margin-top: 4px; }
  .inline-error { margin-top: 4px; background: rgba(0,0,0,0.6); padding: 3px 8px; border-radius: 6px; display: inline-block; }
  .rating-input { display: grid; grid-template-columns: 38px 1fr 38px; align-items: center; gap: 8px; max-width: 220px; }
  .rating-input input { width: 100%; max-width: 100%; text-align: center; font-size: 16px; height: 44px; padding: 8px 6px; }
  .spinner-btn { width: 38px; height: 38px; border: 1px solid #444; background: #222; color: #ffd447; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; }
  .spinner-btn:active { transform: translateY(1px); }
  .comp-status-display { min-height: 48px; padding: 10px 12px; border: 1px solid #444; border-radius: 10px; line-height: 1.2; }

  /* Modal for already voted reminder */
  .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 2000; align-items: center; justify-content: center; }
  .modal-overlay.active { display: flex; }
  .modal-box { background: #1a1a1a; border: 2px solid #f6c90e; border-radius: 1rem; padding: 2rem; max-width: 480px; text-align: center; box-shadow: 0 8px 32px rgba(246,201,14,0.3); }
  .modal-box h2 { color: #f6c90e; margin-bottom: 1rem; }
  .modal-box p { color: #ddd; margin-bottom: 1.5rem; line-height: 1.5; }
  .modal-buttons { display: flex; gap: 1rem; justify-content: center; }
  .modal-btn { padding: 0.75rem 1.5rem; border: none; border-radius: 0.5rem; font-weight: 600; cursor: pointer; font-size: 1rem; }
  .modal-btn.edit { background: #f6c90e; color: #000; }
  .modal-btn.edit:hover { background: #ffde50; }
  .modal-btn.view { background: #444; color: #fff; }
  .modal-btn.view:hover { background: #555; }

  @media (max-width: 480px) {
    .form-group select,
    .form-group input[type="number"],
    .form-group input[type="text"] { height: 44px; font-size: 14px; padding: 10px; }
    .rating-input { grid-template-columns: 34px 1fr 34px; gap: 6px; max-width: 200px; }
    .rating-input input { height: 42px; font-size: 15px; }
    .spinner-btn { width: 34px; height: 34px; font-size: 14px; }
    .comp-status-display { padding: 10px; }
  }
</style>

<?php if ($existing_vote_for_movie && $edit_vote_id === 0): ?>
<div class="modal-overlay active">
  <div class="modal-box">
    <h2><?= t('already_voted_title') ?></h2>
    <p><?= sprintf(t('already_voted_message'), '<strong>'.htmlspecialchars($movie['title']).'</strong>') ?></p>
    <p style="font-size: 0.9rem; color: #aaa;"><?= t('already_voted_hint') ?></p>
    <div class="modal-buttons">
      <button class="modal-btn edit" onclick="editVote(<?= $existing_vote_for_movie['id'] ?>)"> <?= t('edit_vote') ?></button>
      <button class="modal-btn view" onclick="goToMovie()"><?= t('back') ?></button>
    </div>
  </div>
</div>

<script>
  function editVote(voteId) {
    window.location.href = '<?= ADDRESS ?>/vote.php?edit=' + voteId;
  }
  function goToMovie() {
    window.history.back();
  }
</script>
<?php endif; ?>
<div class="vote-container">
  <div class="movie-header">
    <?php $poster = $movie['poster_url']; if(!$poster || $poster==='N/A'){ $poster=ADDRESS.'/assets/img/no-poster.svg'; } ?>
    <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($movie['title']) ?>" onerror="this.onerror=null;this.src=ADDRESS+'/assets/img/no-poster.svg';">
    <div class="movie-info">
      <h2><?= htmlspecialchars($movie['title']) ?></h2>
      <p class="year"><?= ($movie['type'] === 'series' && !empty($movie['start_year'])) ? htmlspecialchars($movie['start_year']) . ((!empty($movie['end_year']) && $movie['end_year'] != $movie['start_year']) ? ' - ' . htmlspecialchars($movie['end_year']) : '') : htmlspecialchars($movie['year']) ?></p>
    </div>
  </div>

  <div class="form-box">
    <h3><?= t('official_voting_form') ?></h3>

    <?php foreach ($errors as $er): ?>
      <p class="error"><?= htmlspecialchars($er) ?></p>
    <?php endforeach; ?>

    <form method="post">
      <?= csrf_field() ?>

      <div class="form-group">
        <label><?= t('competition_status') ?></label>
        <?php $comp_status = $existing_vote['competition_status'] ?? $auto_competition_status; ?>
        <div class="comp-status-display">
          <?php
            if ($comp_status === 'In Competition') {
              echo t('in_competition');
            } else {
              echo t('out_of_competition');
            }
          ?>
        </div>
        <input type="hidden" name="competition_status" value="<?= htmlspecialchars($comp_status) ?>">
      </div>

      <div class="form-group<?= isset($errorFields['category']) ? ' has-error' : '' ?>">
        <label for="category"><?= t('category') ?> <span class="required"><?= t('required') ?></span></label>
        <select name="category" id="category" required>
          <option value=""><?= t('choose') ?></option>
          <?php
            // Load all category-to-type mappings from database
            $allCategories = [];
            $derivedCategory = '';
            $typeMap = [];
            try {
              $catResult = $mysqli->query("SELECT category, type FROM category_types ORDER BY category ASC");
              if ($catResult) {
                $catRows = $catResult->fetch_all(MYSQLI_ASSOC);
                foreach ($catRows as $row) {
                  $allCategories[] = $row['category'];
                  $typeMap[strtolower($row['type'])] = $row['category'];
                }
              }
            } catch (Throwable $e) {
              // Fallback to hardcoded if query fails
              $allCategories = ['Film', 'Series', 'Miniseries', 'Documentary', 'Animation'];
              $typeMap = [
                'movie' => 'Film',
                'film' => 'Film',
                'series' => 'Series',
                'miniseries' => 'Miniseries',
                'documentary' => 'Documentary',
                'animation' => 'Animation',
                'anime' => 'Animation',
              ];
            }
            // Derive default category from movie type
            if (empty($existing_vote)) {
              $mt = strtolower($movie['type'] ?? '');
              if (isset($typeMap[$mt])) { $derivedCategory = $typeMap[$mt]; }
            }
            $catVal = $old('category', $existing_vote['category'] ?? ($derivedCategory ?: ''));
            
            // Render all categories dynamically
            foreach ($allCategories as $cat): ?>
              <option value="<?= htmlspecialchars($cat) ?>" <?= $catVal === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
            <?php endforeach;
          ?>
        </select>
        <?php if (isset($errorFields['category'])): ?><p class="error-hint"><?= htmlspecialchars($errorFields['category']) ?></p><?php endif; ?>
      </div>

      <div class="form-group season-episode-group-hidden<?= isset($errorFields['season_number']) ? ' has-error' : '' ?>" id="seasonEpisodeGroup">
        <label><?= t('season') ?></label>
        <div class="season-episode-fields">
          <input type="number" name="season_number" min="1" placeholder="<?= t('season_placeholder') ?>" value="<?= htmlspecialchars($old('season_number', $existing_vote['season_number'] ?? ($url_season ?? ''))) ?>">
        </div>
        <p class="helper-text"><?= t('season_episode_helper') ?></p>
        <?php if (isset($errorFields['season_number'])): ?><p class="error-hint"><?= htmlspecialchars($errorFields['season_number']) ?></p><?php endif; ?>
      </div>

      <div class="form-group<?= isset($errorFields['where_watched']) ? ' has-error' : '' ?>">
        <label for="where_watched"><?= t('where_watched') ?> <span class="required"><?= t('required') ?></span></label>
        <select name="where_watched" id="where_watched" required>
          <option value=""><?= t('choose') ?></option>
          <?php $watchVal = $old('where_watched', $existing_vote['where_watched'] ?? ''); ?>
          <option value="Cinema" <?= $watchVal === 'Cinema' ? 'selected' : '' ?>><?= t('cinema') ?></option>
          <option value="Netflix" <?= $watchVal === 'Netflix' ? 'selected' : '' ?>>Netflix</option>
          <option value="Sky/Now TV" <?= $watchVal === 'Sky/Now TV' ? 'selected' : '' ?>>Sky / Now TV</option>
          <option value="Amazon Prime Video" <?= $watchVal === 'Amazon Prime Video' ? 'selected' : '' ?>>Amazon Prime Video</option>
          <option value="Disney+" <?= $watchVal === 'Disney+' ? 'selected' : '' ?>>Disney+</option>
          <option value="Apple TV+" <?= $watchVal === 'Apple TV+' ? 'selected' : '' ?>>Apple TV+</option>
          <option value="Tim Vision" <?= $watchVal === 'Tim Vision' ? 'selected' : '' ?>>Tim Vision</option>
          <option value="Paramount+" <?= $watchVal === 'Paramount+' ? 'selected' : '' ?>>Paramount+</option>
          <option value="Rai Play" <?= $watchVal === 'Rai Play' ? 'selected' : '' ?>>Rai Play</option>
          <option value="Mubi" <?= $watchVal === 'Mubi' ? 'selected' : '' ?>>Mubi</option>
          <option value="Hulu" <?= $watchVal === 'Hulu' ? 'selected' : '' ?>>Hulu</option>
          <option value="Other" <?= $watchVal === 'Other' ? 'selected' : '' ?>>Other</option>
        </select>
        <?php if (isset($errorFields['where_watched'])): ?><p class="error-hint"><?= htmlspecialchars($errorFields['where_watched']) ?></p><?php endif; ?>
      </div>

      <div class="form-group<?= isset($errorFields['writing']) ? ' has-error' : '' ?>">
        <label for="writing"><?= t('writing') ?> <span class="required"><?= t('required') ?></span></label>
        <div class="rating-input">
          <input type="number" name="writing" id="writing" min="1" max="10" step="0.5" placeholder="1-10" value="<?= htmlspecialchars($old('writing', $existing_vote['writing'] ?? '')) ?>" required>
        </div>
        <?php if (isset($errorFields['writing'])): ?><p class="error-hint"><?= htmlspecialchars($errorFields['writing']) ?></p><?php endif; ?>
      </div>

      <div class="form-group<?= isset($errorFields['direction']) ? ' has-error' : '' ?>">
        <label for="direction"><?= t('direction') ?> <span class="required"><?= t('required') ?></span></label>
        <div class="rating-input">
          <input type="number" name="direction" id="direction" min="1" max="10" step="0.5" placeholder="1-10" value="<?= htmlspecialchars($old('direction', $existing_vote['direction'] ?? '')) ?>" required>
        </div>
        <?php if (isset($errorFields['direction'])): ?><p class="error-hint"><?= htmlspecialchars($errorFields['direction']) ?></p><?php endif; ?>
      </div>

      <div class="form-group<?= isset($errorFields['acting_or_theme']) ? ' has-error' : '' ?>">
        <label for="acting_or_theme"><?= t('acting_theme') ?> <span class="required"><?= t('required') ?></span></label>
        <div class="rating-input">
          <input type="number" name="acting_or_theme" id="acting_or_theme" min="1" max="10" step="0.5" placeholder="1-10" value="<?= htmlspecialchars($old('acting_or_theme', $existing_vote['acting_or_doc_theme'] ?? '')) ?>" required>
        </div>
        <?php if (isset($errorFields['acting_or_theme'])): ?><p class="error-hint"><?= htmlspecialchars($errorFields['acting_or_theme']) ?></p><?php endif; ?>
      </div>

      <div class="form-group<?= isset($errorFields['emotional_involvement']) ? ' has-error' : '' ?>">
        <label for="emotional_involvement"><?= t('emotional_involvement') ?> <span class="required"><?= t('required') ?></span></label>
        <div class="rating-input">
          <input type="number" name="emotional_involvement" id="emotional_involvement" min="1" max="10" step="0.5" placeholder="1-10" value="<?= htmlspecialchars($old('emotional_involvement', $existing_vote['emotional_involvement'] ?? '')) ?>" required>
        </div>
        <?php if (isset($errorFields['emotional_involvement'])): ?><p class="error-hint"><?= htmlspecialchars($errorFields['emotional_involvement']) ?></p><?php endif; ?>
      </div>

      <div class="form-group<?= isset($errorFields['novelty']) ? ' has-error' : '' ?>">
        <label for="novelty"><?= t('novelty') ?> <span class="required"><?= t('required') ?></span></label>
        <div class="rating-input">
          <input type="number" name="novelty" id="novelty" min="1" max="10" step="0.5" placeholder="1-10" value="<?= htmlspecialchars($old('novelty', $existing_vote['novelty'] ?? '')) ?>" required>
        </div>
        <?php if (isset($errorFields['novelty'])): ?><p class="error-hint"><?= htmlspecialchars($errorFields['novelty']) ?></p><?php endif; ?>
      </div>

      <div class="form-group<?= isset($errorFields['casting_research']) ? ' has-error' : '' ?>">
        <label for="casting_research"><?= t('casting_research') ?> <span class="required"><?= t('required') ?></span></label>
        <div class="rating-input">
          <input type="number" name="casting_research" id="casting_research" min="1" max="10" step="0.5" placeholder="1-10" value="<?= htmlspecialchars($old('casting_research', $existing_vote['casting_research_art'] ?? '')) ?>" required>
        </div>
        <?php if (isset($errorFields['casting_research'])): ?><p class="error-hint"><?= htmlspecialchars($errorFields['casting_research']) ?></p><?php endif; ?>
      </div>

      <div class="form-group<?= isset($errorFields['sound']) ? ' has-error' : '' ?>">
        <label for="sound"><?= t('sound') ?> <span class="required"><?= t('required') ?></span></label>
        <div class="rating-input">
          <input type="number" name="sound" id="sound" min="1" max="10" step="0.5" placeholder="1-10" value="<?= htmlspecialchars($old('sound', $existing_vote['sound'] ?? '')) ?>" required>
        </div>
        <?php if (isset($errorFields['sound'])): ?><p class="error-hint"><?= htmlspecialchars($errorFields['sound']) ?></p><?php endif; ?>
      </div>

      <div class="form-group">
        <label for="adjective"><?= t('adjective') ?></label>
        <input type="text" name="adjective" id="adjective" placeholder="<?= t('adjective_placeholder') ?>" value="<?= htmlspecialchars($old('adjective', $existing_vote['adjective'] ?? '')) ?>">
      </div>

      <div class="submit-section">
        <button type="submit"><?= t('submit_vote') ?></button>
        <a href="index.php" class="btn secondary"><?= t('cancel') ?></a>
      </div>
    </form>
  </div>
</div>

<script>
  // Track API calls
  window.apiCallCount = 0;
  window.apiCallLog = [];
  
  function logApiCall(endpoint, cached = false) {
    window.apiCallCount++;
    const log = {
      count: window.apiCallCount,
      endpoint: endpoint,
      cached: cached,
      timestamp: new Date().toISOString()
    };
    window.apiCallLog.push(log);
    console.log(`[API Call #${window.apiCallCount}] ${cached ? '(CACHED)' : '(LIVE)'} ${endpoint}`);
  }
  
  // Log summary on page load
  window.addEventListener('load', () => {
    console.log('=== API Call Summary ===');
    console.log(`Total API calls: ${window.apiCallCount}`);
    console.log(`Cached calls: ${window.apiCallLog.filter(c => c.cached).length}`);
    console.log(`Live calls: ${window.apiCallLog.filter(c => !c.cached).length}`);
    console.table(window.apiCallLog);
  });

  const categorySelect = document.getElementById('category');
  const seasonEpisodeGroup = document.getElementById('seasonEpisodeGroup');
  function toggleSeasonEpisode(){
    const v = categorySelect.value;
    if(v === 'Series' || v === 'Miniseries') {
      seasonEpisodeGroup.style.display = 'block';
    } else {
      seasonEpisodeGroup.style.display = 'none';
    }
  }
  toggleSeasonEpisode();
  categorySelect.addEventListener('change', toggleSeasonEpisode);

  // Live guardrails for numeric fields (min/max)
  const numberInputs = document.querySelectorAll('input[type="number"][max]');
  function validateNumberInput(el) {
    const min = el.min !== '' ? parseFloat(el.min) : null;
    const max = el.max !== '' ? parseFloat(el.max) : null;
    const step = el.step !== '' ? parseFloat(el.step) : 0.5;
    let val = el.value !== '' ? parseFloat(el.value) : null;
    let err = '';

    if (val !== null && !Number.isNaN(val)) {
      // snap to step
      val = Math.round(val / step) * step;
      if (max !== null && val > max) val = max;
      if (min !== null && val < min) val = min;
      // keep at most 2 digits before decimal
      if (val >= 100) val = max !== null ? Math.min(max, 99) : 99;
      el.value = val;
      if (max !== null && val > max) err = 'Max ' + max;
      else if (min !== null && val < min) err = 'Min ' + min;
    } else if (el.value !== '') {
      err = 'Invalid number';
    }

    const group = el.closest('.form-group');
    if (group) {
      group.classList.toggle('has-error', !!err);
      group.querySelectorAll('.inline-error').forEach(n => n.remove());
      if (err) {
        const p = document.createElement('p');
        p.className = 'error-hint inline-error';
        p.textContent = err;
        group.appendChild(p);
      }
    }
    el.setCustomValidity(err);
  }
  numberInputs.forEach(el => {
    el.setAttribute('inputmode','decimal');
    el.setAttribute('maxlength','4');
    el.addEventListener('input', () => validateNumberInput(el));
    el.addEventListener('blur', () => validateNumberInput(el));
    validateNumberInput(el);
  });

  // Guard submit in case browser skips built-ins
  const voteForm = document.querySelector('.vote-container form');
  if (voteForm) {
    voteForm.addEventListener('submit', (e) => {
      let invalid = false;
      numberInputs.forEach(el => {
        validateNumberInput(el);
        if (!el.checkValidity()) invalid = true;
      });
      if (invalid) {
        e.preventDefault();
      }
    });
  }

  // Add +/- volume controls for rating inputs
  const ratingInputs = document.querySelectorAll('.rating-input input[type="number"]');
  ratingInputs.forEach(input => {
    const container = input.closest('.rating-input');
    if (!container) return;
    const minus = document.createElement('button');
    minus.type = 'button';
    minus.className = 'spinner-btn minus';
    minus.textContent = '-';
    const plus = document.createElement('button');
    plus.type = 'button';
    plus.className = 'spinner-btn plus';
    plus.textContent = '+';
    container.insertBefore(minus, input);
    container.appendChild(plus);

    function bump(dir) {
      const step = input.step !== '' ? parseFloat(input.step) : 0.5;
      const min = input.min !== '' ? parseFloat(input.min) : null;
      const max = input.max !== '' ? parseFloat(input.max) : null;
      let val = input.value !== '' ? parseFloat(input.value) : (min !== null ? min : 0);
      if (Number.isNaN(val)) val = min !== null ? min : 0;
      val = val + dir * step;
      val = Math.round(val / step) * step;
      if (max !== null && val > max) val = max;
      if (min !== null && val < min) val = min;
      input.value = val;
      validateNumberInput(input);
    }
    minus.addEventListener('click', () => bump(-1));
    plus.addEventListener('click', () => bump(1));
  });
</script>
<?php include __DIR__.'/includes/footer.php'; ?>