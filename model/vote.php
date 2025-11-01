<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/config.php';
require_login();

$user_id = current_user()['id'];
$movie_id = isset($_GET['movie_id']) ? (int)$_GET['movie_id'] : 0;

// Fetch movie info
$stmt = $mysqli->prepare("SELECT id, title, year, poster_url FROM movies WHERE id=?");
$stmt->bind_param('i', $movie_id);
$stmt->execute();
$movie = $stmt->get_result()->fetch_assoc();

if (!$movie) {
  exit("Movie not found.");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $juror_name = trim($_POST['juror_name']);
  $competition = $_POST['competition'];
  $category = $_POST['category'];
  $platform = $_POST['platform'];
  $writing = (float)$_POST['writing'];
  $direction = (float)$_POST['direction'];
  $acting_theme = (float)$_POST['acting_theme'];
  $emotional_involvement = (float)$_POST['emotional_involvement'];
  $novelty = (float)$_POST['novelty'];
  $casting_research_artwork = (float)$_POST['casting_research_artwork'];
  $sound = (float)$_POST['sound'];
  $adjective = trim($_POST['adjective']);

  $stmt = $mysqli->prepare("
    REPLACE INTO votes 
      (user_id, movie_id, juror_name, competition, category, platform,
       writing, direction, acting_theme, emotional_involvement, novelty, 
       casting_research_artwork, sound, adjective)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");
  $stmt->bind_param(
    'iisssssdddddds',
    $user_id,
    $movie_id,
    $juror_name,
    $competition,
    $category,
    $platform,
    $writing,
    $direction,
    $acting_theme,
    $emotional_involvement,
    $novelty,
    $casting_research_artwork,
    $sound,
    $adjective
  );
  $stmt->execute();

  header("Location: stats.php");
  exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Vote for <?= htmlspecialchars($movie['title']) ?> - IL DIVANO D’ORO</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    body { background:#111; color:#f5f5f5; font-family:system-ui,sans-serif; }
    header { text-align:center; padding:1rem; }
    .form-container { max-width:600px; margin:2rem auto; background:#1b1b1b; padding:1.5rem; border-radius:.5rem; }
    .form-group { margin-bottom:1rem; }
    label { display:block; margin-bottom:.3rem; font-weight:bold; }
    input, select, textarea { width:100%; padding:.5rem; border-radius:.25rem; border:1px solid #333; background:#222; color:#fff; }
    button { background:#2b7; color:#fff; padding:.6rem 1.2rem; border:0; border-radius:.3rem; cursor:pointer; }
    button:hover { background:#3c8; }
  </style>
</head>
<body>
<header>
  <h1>Vote for <?= htmlspecialchars($movie['title']) ?> (<?= $movie['year'] ?>)</h1>
  <a href="index.php" class="btn">🏠 Home</a>
</header>

<div class="form-container">
  <form method="post">

    <div class="form-group">
      <label for="juror_name">Juror Name</label>
      <input type="text" name="juror_name" id="juror_name" required>
    </div>

    <div class="form-group">
      <label for="competition">Competition</label>
      <select name="competition" id="competition" required>
        <option value="In Competition">In Competition</option>
        <option value="Out of Competition">Out of Competition</option>
      </select>
    </div>

    <div class="form-group">
      <label for="category">Category</label>
      <select name="category" id="category" required>
        <option value="Film">Film</option>
        <option value="Series">Series</option>
        <option value="Mini-Series">Mini-Series</option>
        <option value="Documentary">Documentary</option>
        <option value="Animation">Animation</option>
      </select>
    </div>

    <div class="form-group">
      <label for="platform">Platform</label>
      <select name="platform" id="platform" required>
        <option value="Cinema">Cinema</option>
        <option value="Netflix">Netflix</option>
        <option value="Sky / Now TV">Sky / Now TV</option>
        <option value="Amazon Prime Video">Amazon Prime Video</option>
        <option value="Disney+">Disney+</option>
        <option value="Apple TV+">Apple TV+</option>
        <option value="Tim Vision">Tim Vision</option>
        <option value="Paramount+">Paramount+</option>
        <option value="Rai Play">Rai Play</option>
        <option value="Mubi">Mubi</option>
        <option value="Hulu">Hulu</option>
        <option value="Other">Other</option>
      </select>
    </div>

    <hr style="border:1px solid #333;margin:1rem 0;">

    <?php
    $criteria = [
      'writing' => 'Writing',
      'direction' => 'Direction',
      'acting_theme' => 'Acting / Theme',
      'emotional_involvement' => 'Emotional Involvement',
      'novelty' => 'Sense of Novelty',
      'casting_research_artwork' => 'Casting / Artwork / Research',
      'sound' => 'Sound'
    ];
    foreach ($criteria as $name => $label): ?>
      <div class="form-group">
        <label for="<?= $name ?>"><?= $label ?> (1–10)</label>
        <input type="number" step="0.5" min="1" max="10" name="<?= $name ?>" id="<?= $name ?>" required>
      </div>
    <?php endforeach; ?>

    <div class="form-group">
      <label for="adjective">Describe with one adjective</label>
      <input type="text" name="adjective" id="adjective" maxlength="255">
    </div>

    <button type="submit">Submit Vote</button>
  </form>
</div>

</body>
</html>
