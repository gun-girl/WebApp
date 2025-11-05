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
<!DOCTYPE html>
<html lang="<?= current_lang() ?>">
<head>
  <meta charset="UTF-8">
  <title><?= t('site_title') ?> – Movies</title>
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

    /* === USER DROPDOWN MENU === */
    .user-menu {
      position: relative;
      display: inline-block;
    }
    .user-button {
      background: transparent;
      border: none;
      color: #fff;
      font-weight: 500;
      cursor: pointer;
      padding: .5rem 1rem;
      border-radius: .3rem;
      transition: all .2s;
      font-family: inherit;
      font-size: inherit;
    }
    .user-button:hover {
      background: rgba(246,201,14,.1);
      color: #f6c90e;
    }
    .dropdown-menu {
      display: none;
      position: absolute;
      top: 100%;
      right: 0;
      background: #1a1a1a;
      border: 1px solid #333;
      border-radius: .5rem;
      min-width: 250px;
      box-shadow: 0 8px 25px rgba(0,0,0,.6);
      margin-top: .5rem;
      z-index: 1000;
      overflow: hidden;
    }
    .dropdown-menu.show {
      display: block;
      animation: fadeIn .2s ease;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .dropdown-item {
      display: block;
      padding: .8rem 1.2rem;
      color: #ccc;
      text-decoration: none;
      transition: all .2s;
      border-bottom: 1px solid #282828;
      font-size: .95rem;
    }
    .dropdown-item:hover {
      background: rgba(246,201,14,.1);
      color: #f6c90e;
      padding-left: 1.5rem;
    }
    .dropdown-divider {
      height: 2px;
      background: #333;
      margin: .3rem 0;
    }

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
    <div class="header-logo">
      <img src="/movie-club-app/assests/img/logo.png" alt="<?= t('site_title') ?>">
    </div>
    <nav>
      <div class="user-menu">
        <button class="user-button" id="userMenuBtn">
          <?= t('hello') ?>, <?= htmlspecialchars($user['username']) ?> 👤
        </button>
        <div class="dropdown-menu" id="userDropdown">
          <a href="profile.php" class="dropdown-item">👤 <?= t('profile') ?></a>
          <a href="stats.php?mine=1" class="dropdown-item">📊 <?= t('view_my_votes') ?></a>
          <a href="profile.php" class="dropdown-item">✉️ <?= t('email') ?>: <?= htmlspecialchars($user['email']) ?></a>
          <div class="dropdown-divider"></div>
          <a href="logout.php" class="dropdown-item">🚪 <?= t('logout') ?></a>
        </div>
      </div>
      | <a href="stats.php"><?= t('all_votes') ?></a>
      | <a href="index.php">🏠 <?= t('home') ?></a>
      | <a href="?lang=en"><?= t('lang_en') ?></a>
      | <a href="?lang=it"><?= t('lang_it') ?></a>
    </nav>
  </header>

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

  <footer><?= t('footer_text') ?></footer>

  <script>
    // User dropdown menu toggle
    const userBtn = document.getElementById('userMenuBtn');
    const dropdown = document.getElementById('userDropdown');
    
    userBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      dropdown.classList.toggle('show');
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
      if (!dropdown.contains(e.target) && e.target !== userBtn) {
        dropdown.classList.remove('show');
      }
    });
  </script>
</body>
</html>
