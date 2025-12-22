<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/lang.php';
require_login();

$user = current_user();
// Determine competition year early (before any queries use it)
$active_year = function_exists('get_active_year') ? get_active_year() : (int)date('Y');
// Detect whether the votes table already has competition_year column (backwards compatibility)
$hasCompetitionYear = false;
try {
  $res = $mysqli->query("SHOW COLUMNS FROM votes LIKE 'competition_year'");
  if ($res && $res->num_rows > 0) { $hasCompetitionYear = true; }
} catch (Exception $e) {
  // ignore; treat as missing
}

$edit_vote_id = (int)($_GET['edit'] ?? 0);
$movie_id = (int)($_GET['movie_id'] ?? 0);

// If editing, load the existing vote
$existing_vote = null;
if ($edit_vote_id > 0) {
  // Load vote and verify ownership (include year condition only if column exists)
  $sql = "SELECT v.*, vd.*, m.id as movie_id, m.title, m.year, m.poster_url\n            FROM votes v\n            INNER JOIN vote_details vd ON vd.vote_id = v.id\n            INNER JOIN movies m ON m.id = v.movie_id\n            WHERE v.id = ? AND v.user_id = ?" . ($hasCompetitionYear ? " AND v.competition_year = ?" : "");
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
    
    // Set movie details from existing vote
    $movie_id = $existing_vote['movie_id'];
    $movie = [
        'id' => $existing_vote['movie_id'],
        'title' => $existing_vote['title'],
        'year' => $existing_vote['year'],
        'poster_url' => $existing_vote['poster_url']
    ];
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

// Auto-determine competition status based on movie year
$auto_competition_status = 'Out of Competition'; // default
$movieYear = null;
if (isset($movie['year'])) {
  $yRaw = $movie['year'];
  if (is_numeric($yRaw)) {
    $movieYear = (int)$yRaw;
  } else {
    if (preg_match('/(\d{4})/', (string)$yRaw, $m)) {
      $movieYear = (int)$m[1];
    }
  }
  
  if ($movieYear !== null) {
    if ($movieYear === $active_year) {
      $auto_competition_status = 'In Competition';
    } elseif ($movieYear === $active_year + 1) {
      $auto_competition_status = '2026 In Competition';
    } else {
      $auto_competition_status = 'Out of Competition';
    }
  }
}

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    
    // Collect all form data
    $competition_status = $_POST['competition_status'] ?? '';
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
    // Season / Episode (only relevant for Series / Miniseries)
    $season_number = isset($_POST['season_number']) && $_POST['season_number'] !== '' ? (int)$_POST['season_number'] : null;
    $episode_number = isset($_POST['episode_number']) && $_POST['episode_number'] !== '' ? (int)$_POST['episode_number'] : null;
    
    // Validation
    if (empty($competition_status)) $errors[] = 'Competition status is required';
    if (empty($category)) $errors[] = 'Category is required';
    if (empty($where_watched)) $errors[] = 'Where watched is required';
    if ($writing < 1 || $writing > 10) $errors[] = 'Writing score must be between 1-10';
    if ($direction < 1 || $direction > 10) $errors[] = 'Direction score must be between 1-10';
    if ($acting_or_theme < 1 || $acting_or_theme > 10) $errors[] = 'Acting/Theme score must be between 1-10';
    if ($emotional_involvement < 1 || $emotional_involvement > 10) $errors[] = 'Emotional involvement score must be between 1-10';
    if ($novelty < 1 || $novelty > 10) $errors[] = 'Novelty score must be between 1-10';
    if ($casting_research < 1 || $casting_research > 10) $errors[] = 'Casting/Research score must be between 1-10';
    if ($sound < 1 || $sound > 10) $errors[] = 'Sound score must be between 1-10';
    if (in_array($category, ['Series','Miniseries'])) {
        if ($season_number === null || $season_number < 1) $errors[] = 'Season number required for series/miniseries';
        if ($episode_number === null || $episode_number < 1) $errors[] = 'Episode number required for series/miniseries';
    }
    
    if (!$errors) {
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
                    // Insert new vote (include competition_year only if column exists)
                    if ($hasCompetitionYear) {
                      $stmt = $mysqli->prepare("INSERT INTO votes (user_id, movie_id, competition_year) VALUES (?, ?, ?)");
                      $stmt->bind_param('iii', $user['id'], $movie_id, $active_year);
                    } else {
                      $stmt = $mysqli->prepare("INSERT INTO votes (user_id, movie_id) VALUES (?, ?)");
                      $stmt->bind_param('ii', $user['id'], $movie_id);
                    }
                    $stmt->execute();
                    $vote_id = $mysqli->insert_id;
                }
            }
            
            // Delete existing vote_details
            $stmt = $mysqli->prepare("DELETE FROM vote_details WHERE vote_id = ?");
            $stmt->bind_param('i', $vote_id);
            $stmt->execute();
            
            // Insert new vote_details
            $stmt = $mysqli->prepare("\n          INSERT INTO vote_details \n          (vote_id, writing, direction, acting_or_doc_theme, emotional_involvement, \n           novelty, casting_research_art, sound, competition_status, category, \n           season_number, episode_number, where_watched, adjective)\n          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n        ");
            $stmt->bind_param('idddddddssiiss', 
                $vote_id, $writing, $direction, $acting_or_theme, $emotional_involvement,
                $novelty, $casting_research, $sound, $competition_status, $category,
                $season_number, $episode_number, $where_watched, $adjective
            );
            $stmt->execute();
            
            $mysqli->commit();
            
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $_SESSION['flash'] = $edit_vote_id > 0 ? 'Vote updated successfully!' : 'Vote submitted successfully!';
            $_SESSION['flash_edit_vote_id'] = $vote_id;
            redirect(ADDRESS.'/stats.php?mine=1');
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $errors[] = 'Error saving vote: ' . $e->getMessage();
        }
    }
}
?>
<?php include __DIR__.'/includes/header.php'; ?>
<div class="vote-container">
  <div class="movie-header">
    <?php $poster = $movie['poster_url']; if(!$poster || $poster==='N/A'){ $poster=ADDRESS.'/assets/img/no-poster.svg'; } ?>
    <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($movie['title']) ?>" onerror="this.onerror=null;this.src=ADDRESS.'/assets/img/no-poster.svg';">
    <div class="movie-info">
      <h2><?= htmlspecialchars($movie['title']) ?></h2>
      <p class="year"><?= htmlspecialchars($movie['year']) ?></p>
    </div>
  </div>

  <div class="form-box">
    <h3>🌟 <?= t('official_voting_form') ?></h3>

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
            } elseif ($comp_status === '2026 In Competition') {
              echo t('2026_in_competition');
            } else {
              echo t('out_of_competition');
            }
          ?>
        </div>
        <input type="hidden" name="competition_status" value="<?= htmlspecialchars($comp_status) ?>">
      </div>

      <div class="form-group">
        <label for="category"><?= t('category') ?> <span class="required"><?= t('required') ?></span></label>
        <select name="category" id="category" required>
          <option value=""><?= t('choose') ?></option>
          <option value="Film" <?= ($existing_vote['category'] ?? '') === 'Film' ? 'selected' : '' ?>><?= t('film') ?></option>
          <option value="Series" <?= ($existing_vote['category'] ?? '') === 'Series' ? 'selected' : '' ?>><?= t('series') ?></option>
          <option value="Miniseries" <?= ($existing_vote['category'] ?? '') === 'Miniseries' ? 'selected' : '' ?>><?= t('miniseries') ?></option>
          <option value="Documentary" <?= ($existing_vote['category'] ?? '') === 'Documentary' ? 'selected' : '' ?>><?= t('documentary') ?></option>
          <option value="Animation" <?= ($existing_vote['category'] ?? '') === 'Animation' ? 'selected' : '' ?>><?= t('animation') ?></option>
        </select>
      </div>

      <div class="form-group season-episode-group-hidden" id="seasonEpisodeGroup">
        <label><?= t('season_episode') ?></label>
        <div class="season-episode-fields">
          <input type="number" name="season_number" min="1" placeholder="<?= t('season_placeholder') ?>" value="<?= htmlspecialchars($existing_vote['season_number'] ?? '') ?>">
          <input type="number" name="episode_number" min="1" placeholder="<?= t('episode_placeholder') ?>" value="<?= htmlspecialchars($existing_vote['episode_number'] ?? '') ?>">
        </div>
        <p class="helper-text"><?= t('season_episode_helper') ?></p>
      </div>

      <div class="form-group">
        <label for="where_watched"><?= t('where_watched') ?> <span class="required"><?= t('required') ?></span></label>
        <select name="where_watched" id="where_watched" required>
          <option value=""><?= t('choose') ?></option>
          <option value="Cinema" <?= ($existing_vote['where_watched'] ?? '') === 'Cinema' ? 'selected' : '' ?>><?= t('cinema') ?></option>
          <option value="Netflix" <?= ($existing_vote['where_watched'] ?? '') === 'Netflix' ? 'selected' : '' ?>>Netflix</option>
          <option value="Sky/Now TV" <?= ($existing_vote['where_watched'] ?? '') === 'Sky/Now TV' ? 'selected' : '' ?>>Sky / Now TV</option>
          <option value="Amazon Prime Video" <?= ($existing_vote['where_watched'] ?? '') === 'Amazon Prime Video' ? 'selected' : '' ?>>Amazon Prime Video</option>
          <option value="Disney+" <?= ($existing_vote['where_watched'] ?? '') === 'Disney+' ? 'selected' : '' ?>>Disney+</option>
          <option value="Apple TV+" <?= ($existing_vote['where_watched'] ?? '') === 'Apple TV+' ? 'selected' : '' ?>>Apple TV+</option>
          <option value="Tim Vision" <?= ($existing_vote['where_watched'] ?? '') === 'Tim Vision' ? 'selected' : '' ?>>Tim Vision</option>
          <option value="Paramount+" <?= ($existing_vote['where_watched'] ?? '') === 'Paramount+' ? 'selected' : '' ?>>Paramount+</option>
          <option value="Rai Play" <?= ($existing_vote['where_watched'] ?? '') === 'Rai Play' ? 'selected' : '' ?>>Rai Play</option>
          <option value="Mubi" <?= ($existing_vote['where_watched'] ?? '') === 'Mubi' ? 'selected' : '' ?>>Mubi</option>
          <option value="Hulu" <?= ($existing_vote['where_watched'] ?? '') === 'Hulu' ? 'selected' : '' ?>>Hulu</option>
          <option value="Other" <?= ($existing_vote['where_watched'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
        </select>
      </div>

      <div class="form-group">
        <label for="writing"><?= t('writing') ?> <span class="required"><?= t('required') ?></span></label>
        <div class="rating-input">
          <input type="number" name="writing" id="writing" min="1" max="10" step="0.5" placeholder="1-10" value="<?= htmlspecialchars($existing_vote['writing'] ?? '') ?>" required>
        </div>
      </div>

      <div class="form-group">
        <label for="direction"><?= t('direction') ?> <span class="required"><?= t('required') ?></span></label>
        <div class="rating-input">
          <input type="number" name="direction" id="direction" min="1" max="10" step="0.5" placeholder="1-10" value="<?= htmlspecialchars($existing_vote['direction'] ?? '') ?>" required>
        </div>
      </div>

      <div class="form-group">
        <label for="acting_or_theme"><?= t('acting_theme') ?> <span class="required"><?= t('required') ?></span></label>
        <div class="rating-input">
          <input type="number" name="acting_or_theme" id="acting_or_theme" min="1" max="10" step="0.5" placeholder="1-10" value="<?= htmlspecialchars($existing_vote['acting_or_doc_theme'] ?? '') ?>" required>
        </div>
      </div>

      <div class="form-group">
        <label for="emotional_involvement"><?= t('emotional_involvement') ?> <span class="required"><?= t('required') ?></span></label>
        <div class="rating-input">
          <input type="number" name="emotional_involvement" id="emotional_involvement" min="1" max="10" step="0.5" placeholder="1-10" value="<?= htmlspecialchars($existing_vote['emotional_involvement'] ?? '') ?>" required>
        </div>
      </div>

      <div class="form-group">
        <label for="novelty"><?= t('novelty') ?> <span class="required"><?= t('required') ?></span></label>
        <div class="rating-input">
          <input type="number" name="novelty" id="novelty" min="1" max="10" step="0.5" placeholder="1-10" value="<?= htmlspecialchars($existing_vote['novelty'] ?? '') ?>" required>
        </div>
      </div>

      <div class="form-group">
        <label for="casting_research"><?= t('casting_research') ?> <span class="required"><?= t('required') ?></span></label>
        <div class="rating-input">
          <input type="number" name="casting_research" id="casting_research" min="1" max="10" step="0.5" placeholder="1-10" value="<?= htmlspecialchars($existing_vote['casting_research_art'] ?? '') ?>" required>
        </div>
      </div>

      <div class="form-group">
        <label for="sound"><?= t('sound') ?> <span class="required"><?= t('required') ?></span></label>
        <div class="rating-input">
          <input type="number" name="sound" id="sound" min="1" max="10" step="0.5" placeholder="1-10" value="<?= htmlspecialchars($existing_vote['sound'] ?? '') ?>" required>
        </div>
      </div>

      <div class="form-group">
        <label for="adjective"><?= t('adjective') ?></label>
        <input type="text" name="adjective" id="adjective" placeholder="<?= t('adjective_placeholder') ?>" value="<?= htmlspecialchars($existing_vote['adjective'] ?? '') ?>">
      </div>

      <div class="submit-section">
        <button type="submit"><?= t('submit_vote') ?></button>
        <a href="index.php" class="btn secondary"><?= t('cancel') ?></a>
      </div>
    </form>
  </div>
</div>

<script>
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
</script>
<?php include __DIR__.'/includes/footer.php'; ?>