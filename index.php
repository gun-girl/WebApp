<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config.php';
require_login();

$user = current_user();
$search = $_GET['search'] ?? '';

$sql = "SELECT * FROM movies";
if ($search) {
  $sql .= " WHERE title LIKE ?";
  $stmt = $mysqli->prepare($sql);
  $like = "%$search%";
  $stmt->bind_param('s', $like);
  $stmt->execute();
  $movies = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
  $movies = $mysqli->query($sql)->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>IL DIVANO D’ORO – Movies</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    /* === GLOBAL === */
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Poppins', system-ui, sans-serif;
      background: radial-gradient(circle at top, #0c0c0c, #000);
      color: #eee;
      min-height: 100vh;
    }
    header {
      background: rgba(0,0,0,0.8);
      backdrop-filter: blur(8px);
      position: sticky;
      top: 0;
      z-index: 1000;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 1rem 2rem;
      border-bottom: 1px solid #222;
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
</head>

<body>
  <header>
    <h1>🎬 IL DIVANO D’ORO</h1>
    <nav>
      <span>Hello, <?= htmlspecialchars($user['username']) ?></span>
      | <a href="logout.php">Logout</a>
      | <a href="stats.php">Results</a>
      | <a href="index.php">🏠 Home</a>
    </nav>
  </header>

  <form method="get" class="search-bar">
    <input type="text" name="search" placeholder="Search movies..." value="<?= htmlspecialchars($search) ?>">
    <button type="submit">Search</button>
  </form>

  <section class="movies-container">
    <?php foreach ($movies as $movie): ?>
      <div class="movie-card">
        <img src="<?= htmlspecialchars($movie['poster_url'] ?: 'assets/img/no-poster.png') ?>" alt="<?= htmlspecialchars($movie['title']) ?>">
        <div class="movie-info">
          <div class="movie-title"><?= htmlspecialchars($movie['title']) ?></div>
          <div class="movie-year"><?= htmlspecialchars($movie['year']) ?></div>
          <a class="rate-btn" href="vote.php?movie_id=<?= $movie['id'] ?>">Rate ⭐</a>
        </div>
      </div>
    <?php endforeach; ?>
  </section>

  <footer>© IL DIVANO D’ORO 2025 — All rights reserved.</footer>
</body>
</html>
