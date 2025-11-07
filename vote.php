<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/lang.php';
require_login();

$user = current_user();
$edit_vote_id = (int)($_GET['edit'] ?? 0);
$movie_id = (int)($_GET['movie_id'] ?? 0);

// If editing, load the existing vote
$existing_vote = null;
if ($edit_vote_id > 0) {
    // Load vote and verify ownership
    $stmt = $mysqli->prepare("
        SELECT v.*, vd.*, m.id as movie_id, m.title, m.year, m.poster_url
        FROM votes v
        INNER JOIN vote_details vd ON vd.vote_id = v.id
        INNER JOIN movies m ON m.id = v.movie_id
        WHERE v.id = ? AND v.user_id = ?
    ");
    $stmt->bind_param('ii', $edit_vote_id, $user['id']);
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
    
    if (!$errors) {
        $mysqli->begin_transaction();
        try {
            if ($edit_vote_id > 0) {
                // Editing existing vote - verify ownership again
                $check = $mysqli->prepare("SELECT id FROM votes WHERE id = ? AND user_id = ?");
                $check->bind_param('ii', $edit_vote_id, $user['id']);
                $check->execute();
                $verify = $check->get_result()->fetch_assoc();
                
                if (!$verify) {
                    throw new Exception('Access denied');
                }
                
                $vote_id = $edit_vote_id;
            } else {
                // Check if vote exists for this movie
                $check = $mysqli->prepare("SELECT id FROM votes WHERE user_id = ? AND movie_id = ?");
                $check->bind_param('ii', $user['id'], $movie_id);
                $check->execute();
                $existing = $check->get_result()->fetch_assoc();
                
                if ($existing) {
                    $vote_id = $existing['id'];
                } else {
                    // Insert new vote
                    $stmt = $mysqli->prepare("INSERT INTO votes (user_id, movie_id) VALUES (?, ?)");
                    $stmt->bind_param('ii', $user['id'], $movie_id);
                    $stmt->execute();
                    $vote_id = $mysqli->insert_id;
                }
            }
            
            // Delete existing vote_details
            $stmt = $mysqli->prepare("DELETE FROM vote_details WHERE vote_id = ?");
            $stmt->bind_param('i', $vote_id);
            $stmt->execute();
            
            // Insert new vote_details
            $stmt = $mysqli->prepare("
                INSERT INTO vote_details 
                (vote_id, writing, direction, acting_or_doc_theme, emotional_involvement, 
                 novelty, casting_research_art, sound, competition_status, category, 
                 where_watched, adjective)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('idddddddssss', 
                $vote_id, $writing, $direction, $acting_or_theme, $emotional_involvement,
                $novelty, $casting_research, $sound, $competition_status, $category,
                $where_watched, $adjective
            );
            $stmt->execute();
            
            $mysqli->commit();
            
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $_SESSION['flash'] = $edit_vote_id > 0 ? 'Vote updated successfully!' : 'Vote submitted successfully!';
            $_SESSION['flash_edit_vote_id'] = $vote_id;
            redirect('/movie-club-app/stats.php?mine=1');
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $errors[] = 'Error saving vote: ' . $e->getMessage();
        }
    }
}
?>
<?php include __DIR__.'/includes/header.php'; ?>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Poppins', system-ui, sans-serif;
      background: radial-gradient(circle at top, #0c0c0c, #000);
      color: #eee;
      min-height: 100vh;
      padding-bottom: 3rem;
    }
    header {
      background: rgba(0,0,0,0.8);
      backdrop-filter: blur(8px);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 1rem 2rem;
      border-bottom: 1px solid #222;
      position: sticky;
      top: 0;
      z-index: 100;
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
      font-size: 1.6rem;
      letter-spacing: 1px;
      font-weight: 600;
      color: #f6c90e;
    }
    nav a {
      color: #fff;
      text-decoration: none;
      margin-left: 1rem;
      transition: color .2s;
      font-weight: 500;
    }
    nav a:hover { color: #f6c90e; }

    .vote-container {
      max-width: 900px;
      margin: 2rem auto;
      padding: 0 1.5rem;
    }
    .movie-header {
      background: #111;
      border-radius: 0.75rem;
      padding: 2rem;
      margin-bottom: 2rem;
      box-shadow: 0 4px 20px rgba(0,0,0,.5);
      display: flex;
      gap: 2rem;
      align-items: start;
    }
    .movie-header img {
      width: 150px;
      height: 220px;
      object-fit: cover;
      border-radius: .5rem;
    }
    .movie-info h2 {
      color: #f6c90e;
      font-size: 2rem;
      margin-bottom: .5rem;
    }
    .movie-info .year {
      color: #999;
      font-size: 1.2rem;
    }
    
    .form-box {
      background: #111;
      border-radius: 0.75rem;
      padding: 2rem;
      box-shadow: 0 4px 20px rgba(0,0,0,.5);
    }
    .form-box h3 {
      color: #f6c90e;
      margin-bottom: 1.5rem;
      font-size: 1.5rem;
      border-bottom: 2px solid #f6c90e;
      padding-bottom: .5rem;
    }
    .error {
      background: #612;
      color: #fee;
      padding: .8rem;
      border-radius: .3rem;
      margin-bottom: 1.5rem;
    }
    .form-group {
      margin-bottom: 2rem;
    }
    .form-group label {
      display: block;
      color: #f6c90e;
      font-weight: 600;
      margin-bottom: .7rem;
      font-size: 1.05rem;
    }
    .form-group label .required {
      color: #ff4444;
    }
    .form-group select,
    .form-group input[type="text"],
    .form-group input[type="number"] {
      width: 100%;
      padding: .8rem;
      border: 1px solid #333;
      border-radius: .3rem;
      background: #1a1a1a;
      color: #fff;
      font-size: 1rem;
      font-family: inherit;
    }
    .form-group select:focus,
    .form-group input:focus {
      outline: none;
      border-color: #f6c90e;
      box-shadow: 0 0 0 2px rgba(246,201,14,.2);
    }
    .radio-group {
      display: flex;
      flex-direction: column;
      gap: .7rem;
    }
    .radio-option {
      display: flex;
      align-items: center;
      padding: .7rem;
      background: #1a1a1a;
      border-radius: .3rem;
      cursor: pointer;
      transition: all .2s;
    }
    .radio-option:hover {
      background: #222;
      border-left: 3px solid #f6c90e;
      padding-left: calc(.7rem + 3px);
    }
    .radio-option input[type="radio"] {
      margin-right: .7rem;
      width: 18px;
      height: 18px;
      cursor: pointer;
    }
    .rating-input {
      display: flex;
      align-items: center;
      gap: 1rem;
    }
    .rating-input input[type="number"] {
      width: 100px;
      text-align: center;
      font-size: 1.2rem;
      font-weight: 600;
    }
    .rating-hint {
      color: #888;
      font-size: .9rem;
      font-style: italic;
    }
    .submit-section {
      margin-top: 2.5rem;
      display: flex;
      gap: 1rem;
    }
    button, .btn {
      padding: .9rem 2rem;
      background: #f6c90e;
      color: #000;
      border: none;
      border-radius: .3rem;
      cursor: pointer;
      font-weight: 600;
      font-size: 1.05rem;
      text-decoration: none;
      transition: background .2s;
      display: inline-block;
    }
    button:hover, .btn:hover { background: #ffde50; }
    .btn.secondary {
      background: #444;
      color: #fff;
    }
    .btn.secondary:hover { background: #555; }
    
    .helper-text {
      color: #888;
      font-size: .85rem;
      margin-top: .3rem;
    }
    
    footer {
      text-align: center;
      color: #555;
      padding: 1.5rem 0;
      font-size: .9rem;
      margin-top: 3rem;
      border-top: 1px solid #222;
    }

    @media (max-width: 768px) {
      .movie-header {
        flex-direction: column;
        align-items: center;
        text-align: center;
      }
      .submit-section {
        flex-direction: column;
      }
      button, .btn {
        width: 100%;
      }
    }
  </style>
  <div class="vote-container">
    <div class="movie-header">
      <img src="<?= htmlspecialchars($movie['poster_url'] ?: 'assets/img/no-poster.png') ?>" alt="<?= htmlspecialchars($movie['title']) ?>">
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

        <!-- Competition Status -->
        <div class="form-group">
          <label><?= t('competition_status') ?> <span class="required"><?= t('required') ?></span></label>
          <div class="radio-group">
            <label class="radio-option">
              <input type="radio" name="competition_status" value="In Competition" <?= ($existing_vote['competition_status'] ?? '') === 'In Competition' ? 'checked' : '' ?> required>
              <span><?= t('in_competition') ?></span>
            </label>
            <label class="radio-option">
              <input type="radio" name="competition_status" value="2026 In Competition" <?= ($existing_vote['competition_status'] ?? '') === '2026 In Competition' ? 'checked' : '' ?> required>
              <span><?= t('2026_in_competition') ?></span>
            </label>
            <label class="radio-option">
              <input type="radio" name="competition_status" value="Out of Competition" <?= ($existing_vote['competition_status'] ?? '') === 'Out of Competition' ? 'checked' : '' ?> required>
              <span><?= t('out_of_competition') ?></span>
            </label>
          </div>
        </div>

        <!-- Category -->
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

        <!-- Where Watched -->
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

        <!-- Ratings Section -->
        <div class="form-group">
          <label for="writing"><?= t('writing') ?> <span class="required"><?= t('required') ?></span></label>
          <div class="rating-input">
            <input type="number" name="writing" id="writing" min="1" max="10" step="0.5" placeholder="1-10" value="<?= htmlspecialchars($existing_vote['writing'] ?? '') ?>" required>
            <span class="rating-hint"><?= t('rate_1_to_10') ?></span>
          </div>
        </div>

        <div class="form-group">
          <label for="direction"><?= t('direction') ?> <span class="required"><?= t('required') ?></span></label>
          <div class="rating-input">
            <input type="number" name="direction" id="direction" min="1" max="10" step="0.5" placeholder="1-10" value="<?= htmlspecialchars($existing_vote['direction'] ?? '') ?>" required>
            <span class="rating-hint"><?= t('rate_1_to_10') ?></span>
          </div>
        </div>

        <div class="form-group">
          <label for="acting_or_theme"><?= t('acting_theme') ?> <span class="required"><?= t('required') ?></span></label>
          <div class="rating-input">
            <input type="number" name="acting_or_theme" id="acting_or_theme" min="1" max="10" step="0.5" placeholder="1-10" value="<?= htmlspecialchars($existing_vote['acting_or_doc_theme'] ?? '') ?>" required>
            <span class="rating-hint"><?= t('rate_1_to_10') ?></span>
          </div>
        </div>

        <div class="form-group">
          <label for="emotional_involvement"><?= t('emotional_involvement') ?> <span class="required"><?= t('required') ?></span></label>
          <div class="rating-input">
            <input type="number" name="emotional_involvement" id="emotional_involvement" min="1" max="10" step="0.5" placeholder="1-10" value="<?= htmlspecialchars($existing_vote['emotional_involvement'] ?? '') ?>" required>
            <span class="rating-hint"><?= t('rate_1_to_10') ?></span>
          </div>
        </div>

        <div class="form-group">
          <label for="novelty"><?= t('novelty') ?> <span class="required"><?= t('required') ?></span></label>
          <div class="rating-input">
            <input type="number" name="novelty" id="novelty" min="1" max="10" step="0.5" placeholder="1-10" value="<?= htmlspecialchars($existing_vote['novelty'] ?? '') ?>" required>
            <span class="rating-hint"><?= t('rate_1_to_10') ?></span>
          </div>
        </div>

        <div class="form-group">
          <label for="casting_research"><?= t('casting_research') ?> <span class="required"><?= t('required') ?></span></label>
          <div class="rating-input">
            <input type="number" name="casting_research" id="casting_research" min="1" max="10" step="0.5" placeholder="1-10" value="<?= htmlspecialchars($existing_vote['casting_research_art'] ?? '') ?>" required>
            <span class="rating-hint"><?= t('rate_1_to_10') ?></span>
          </div>
        </div>

        <div class="form-group">
          <label for="sound"><?= t('sound') ?> <span class="required"><?= t('required') ?></span></label>
          <div class="rating-input">
            <input type="number" name="sound" id="sound" min="1" max="10" step="0.5" placeholder="1-10" value="<?= htmlspecialchars($existing_vote['sound'] ?? '') ?>" required>
            <span class="rating-hint"><?= t('rate_1_to_10') ?></span>
          </div>
        </div>

        <!-- Adjective -->
        <div class="form-group">
          <label for="adjective"><?= t('adjective') ?></label>
          <input type="text" name="adjective" id="adjective" placeholder="<?= t('adjective_placeholder') ?>" value="<?= htmlspecialchars($existing_vote['adjective'] ?? '') ?>">
          <p class="helper-text"><?= t('adjective_helper') ?></p>
        </div>

        <div class="submit-section">
          <button type="submit"><?= t('submit_vote') ?></button>
          <a href="index.php" class="btn secondary"><?= t('cancel') ?></a>
        </div>
      </form>
    </div>
  </div>

<?php include __DIR__.'/includes/footer.php'; ?>
