<?php require_once __DIR__.'/includes/auth.php'; include __DIR__.'/includes/header.php';

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
  // Always consult OMDb for any non-empty search so users can find any movie.
  include_once __DIR__ . '/includes/omdb.php';
  // Provide a fallback stub if the OMDb helper isn't available to avoid undefined function errors
  if (!function_exists('get_movie_or_fetch')) {
    function get_movie_or_fetch($q) {
      // OMDb integration not configured; no-op fallback.
      return null;
    }
  }
  // Ask OMDb to fetch and insert matching movies into local DB (if key present)
  if (function_exists('get_movie_or_fetch')) {
    try {
      get_movie_or_fetch($q);
    } catch (Throwable $e) {
      /* ignore fetch errors */
    }
  }

  // Broad local search: match the whole phrase and individual words to return more results
  $words = array_values(array_filter(array_map('trim', preg_split('/\s+/', $q)), function($w){ return strlen($w) >= 2; }));
  $likes = [];
  // include full phrase first
  $likes[] = '%' . $q . '%';
  foreach ($words as $w) { $likes[] = '%' . $w . '%'; }

  $clauses = array_fill(0, count($likes), 'title LIKE ?');
  $sql = "SELECT id,title,year,poster_url FROM movies WHERE " . implode(' OR ', $clauses) . " ORDER BY year DESC LIMIT 50";
  $stmt = $mysqli->prepare($sql);
  $types = str_repeat('s', count($likes));
  // bind params dynamically
  $stmt->bind_param($types, ...$likes);
  $stmt->execute();
  $movies = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
  $movies = $mysqli->query("SELECT id,title,year,poster_url FROM movies ORDER BY id DESC LIMIT 20")->fetch_all(MYSQLI_ASSOC);
}
?>
<h2>Movies</h2>
<form method="get" class="search">
  <input name="q" placeholder="Search title…" value="<?= e($q) ?>">
  <button>Search</button>
</form>

<div class="grid">
<?php foreach($movies as $m): ?>
  <article class="card">
    <img src="<?= e($m['poster_url'] ?: '/movie-club-app/assets/img/placeholder.jpg') ?>" alt="" loading="lazy">
    <h3><?= e($m['title']) ?></h3>
    <p><?= e($m['year']) ?></p>
    <?php if (current_user()): ?>
      <form method="post" action="/movie-club-app/vote.php">
        <?= csrf_field() ?>
        <input type="hidden" name="movie_id" value="<?= (int)$m['id'] ?>">
        <label>Rate (1–5) <input type="number" name="rating" min="1" max="5" required></label>
        <button>Vote</button>
      </form>
    <?php else: ?>
      <p><a href="/movie-club-app/login.php">Login to vote</a></p>
    <?php endif; ?>
  </article>
<?php endforeach; ?>
</div>
<?php include __DIR__.'/includes/footer.php';
