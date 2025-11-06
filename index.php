<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/includes/omdb.php';
require_login();

$user = current_user();
$search = $_GET['search'] ?? '';

$sql = "SELECT * FROM movies";
if ($search) {
  // Use OMDb-backed helper: search locally first, otherwise fetch and cache
  $movies = get_movie_or_fetch($search);
} else {
  $movies = $mysqli->query($sql)->fetch_all(MYSQLI_ASSOC);
}
?>
<?php include __DIR__ . '/includes/header.php'; ?>
<style>

    /* === SEARCH === */
    .search-bar {
      max-width: 400px;
      margin: 1.5rem auto;
      display: flex;
      justify-content: center;
      gap: .5rem;
    }
    .search-bar input {
      flex: 1;
      padding: .7rem;
      border-radius: .3rem;
      border: none;
      background: #222;
      color: #fff;
      font-size: 1rem;
    }
    .search-bar button {
      background: #f6c90e;
      color: #000;
      border: none;
      padding: .7rem 1rem;
      border-radius: .3rem;
      cursor: pointer;
      font-weight: 600;
    }
    .search-bar button:hover { background: #ffde50; }

    /* === MOVIE GRID === */
    .movies-container {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 1.5rem;
      padding: 2rem;
      max-width: 1200px;
      margin: auto;
    }
    .movie-card {
      background: #111;
      border-radius: 0.75rem;
      overflow: hidden;
      box-shadow: 0 4px 15px rgba(0,0,0,.4);
      transition: transform .3s ease, box-shadow .3s ease;
      position: relative;
    }
    .movie-card:hover {
      transform: translateY(-6px);
      box-shadow: 0 6px 25px rgba(246,201,14,.4);
    }
    .movie-card img {
      width: 100%;
      height: 320px;
      object-fit: cover;
      display: block;
    }
    .movie-info {
      padding: 1rem;
    }
    .movie-title {
      font-size: 1.1rem;
      font-weight: 600;
      margin-bottom: .3rem;
      color: #fff;
    }
    .movie-year {
      color: #999;
      font-size: .9rem;
      margin-bottom: .6rem;
    }
    .rate-btn {
      display: inline-block;
      padding: .4rem .9rem;
      background: #f6c90e;
      color: #000;
      border-radius: .3rem;
      font-weight: 600;
      text-decoration: none;
      transition: background .2s;
    }
    .rate-btn:hover {
      background: #ffde50;
    }

    footer {
      text-align: center;
      color: #555;
      padding: 1.5rem 0;
      font-size: .9rem;
      margin-top: 2rem;
      border-top: 1px solid #222;
    }

    /* === RESPONSIVE === */
    @media (max-width: 640px) {
      header h1 { font-size: 1.3rem; }
      .movies-container { padding: 1rem; }
    }
  </style>
  <form method="get" class="search-bar">
    <input type="text" name="search" placeholder="<?= t('search_movies') ?>" value="<?= htmlspecialchars($search) ?>">
    <button type="submit"><?= t('search') ?></button>
  </form>

  <section class="movies-container">
    <?php foreach ($movies as $movie): ?>
      <div class="movie-card">
        <?php
          $poster = $movie['poster_url'] ?? null;
          if (!$poster || $poster === 'N/A') {
            $poster = '/movie-club-app/assests/img/no-poster.png';
          }
        ?>
        <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($movie['title']) ?>">
        <div class="movie-info">
          <div class="movie-title"><?= htmlspecialchars($movie['title']) ?></div>
          <div class="movie-year"><?= htmlspecialchars($movie['year']) ?></div>
          <a class="rate-btn" href="vote.php?movie_id=<?= $movie['id'] ?>"><?= t('rate') ?> ⭐</a>
        </div>
      </div>
    <?php endforeach; ?>
  </section>

<?php include __DIR__ . '/includes/footer.php'; ?>
