<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/includes/omdb.php';
require_login();

$user = current_user();
$searchRequested = array_key_exists('search', $_GET);
$searchTerm = trim($_GET['search'] ?? '');
$movies = [];

if ($searchRequested) {
  if ($searchTerm !== '') {
    // Use OMDb-backed helper: search locally first, otherwise fetch and cache
    $movies = get_movie_or_fetch($searchTerm);
  }
} else {
  // Prepare homepage sections when not searching
  // 1) In Competition: movies with vote_details.competition_status indicating competition
  $inCompetition = [];
  try {
    $q = "SELECT DISTINCT m.*
          FROM movies m
          JOIN votes v ON v.movie_id = m.id
          LEFT JOIN vote_details vd ON vd.vote_id = v.id
          WHERE vd.competition_status IN ('in_competition','2026_in_competition')
          ORDER BY v.created_at DESC
          LIMIT 24";
    $inCompetition = $mysqli->query($q)->fetch_all(MYSQLI_ASSOC);
  } catch (Throwable $e) { $inCompetition = []; }

  // 2) Top Rated: detect schema and compute avg
  $cols = $mysqli->query("SHOW COLUMNS FROM votes")->fetch_all(MYSQLI_ASSOC);
  $fields = array_column($cols, 'Field');
  $hasRating = in_array('rating', $fields);
  $topRated = [];
  if ($hasRating) {
    $q = "SELECT m.*, ROUND(AVG(v.rating),2) AS avg_rating, COUNT(v.id) AS votes_count
          FROM movies m
          JOIN votes v ON v.movie_id = m.id
          GROUP BY m.id
          HAVING votes_count > 0
          ORDER BY avg_rating DESC, votes_count DESC
          LIMIT 24";
    $topRated = $mysqli->query($q)->fetch_all(MYSQLI_ASSOC);
  } else {
    // build numeric average from vote_details like stats.php
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
      $q = "SELECT m.*, COUNT(v.id) AS votes_count,
                   ROUND(AVG( ( ($numExpr) / NULLIF($denExpr,0) ) ),2) AS avg_rating
            FROM movies m
            JOIN votes v ON v.movie_id = m.id
            LEFT JOIN vote_details vd ON vd.vote_id = v.id
            GROUP BY m.id
            HAVING votes_count > 0
            ORDER BY avg_rating DESC, votes_count DESC
            LIMIT 24";
      $topRated = $mysqli->query($q)->fetch_all(MYSQLI_ASSOC);
    }
  }

  // 3) Recently Added: by latest vote timestamp when available
  $recent = [];
  try {
    $q = "SELECT m.*
          FROM movies m
          JOIN votes v ON v.movie_id = m.id
          GROUP BY m.id
          ORDER BY MAX(v.created_at) DESC
          LIMIT 24";
    $recent = $mysqli->query($q)->fetch_all(MYSQLI_ASSOC);
  } catch (Throwable $e) {
    $recent = $mysqli->query("SELECT * FROM movies ORDER BY id DESC LIMIT 24")->fetch_all(MYSQLI_ASSOC);
  }
}
?>
<?php include __DIR__ . '/includes/header.php'; ?>
<style>

    /* === SEARCH SCREEN === */
    .search-screen {
      max-width: 640px;
      margin: 2.6rem auto 1.8rem;
      padding: 1.9rem 1.7rem 2.2rem;
      background: rgba(12,12,12,0.88);
      border-radius: 1.6rem;
      box-shadow: 0 18px 42px rgba(0,0,0,.48);
      backdrop-filter: blur(10px);
      text-align: center;
    }
    .search-screen__form {
      display: flex;
      align-items: center;
      gap: .75rem;
    }
    .search-screen__form input {
      flex: 1;
      padding: .75rem .9rem;
      border-radius: .6rem;
      border: 1px solid #2d2d2d;
      background: rgba(20,20,20,0.95);
      color: #fff;
      font-size: 1rem;
      box-shadow: inset 0 0 0 1px rgba(255,255,255,0.03);
    }
    .search-screen__form input:focus {
      outline: 2px solid rgba(246,201,14,0.55);
      border-color: rgba(246,201,14,0.55);
    }
    .search-screen__form button {
      background: linear-gradient(135deg, #f8523b, #f6c90e);
      color: #000;
      border: none;
      padding: .75rem 1.4rem;
      border-radius: .6rem;
      cursor: pointer;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
      transition: transform .2s ease, box-shadow .2s ease;
    }
    .search-screen__form button:hover {
      transform: translateY(-1px);
      box-shadow: 0 10px 20px rgba(248,82,59,0.35);
    }
    .search-screen__hint {
      margin-top: 1.2rem;
      color: #a7a7a7;
      font-size: .95rem;
    }
    .search-screen__meta {
      margin-top: 1.2rem;
      color: #f6c90e;
      font-size: .95rem;
      font-weight: 600;
      letter-spacing: .03em;
    }
    .search-screen__meta span {
      display: inline-block;
      margin-left: .35rem;
      color: #fff;
    }
    .search-empty {
      text-align: center;
      color: #b3b3b3;
      margin: 0 auto 3rem;
      max-width: 520px;
      font-size: .95rem;
    }

    /* === MOVIE GRID === */
    .movies-container {
      display: grid;
      /* Keep card width consistent; do not stretch when fewer results */
      grid-template-columns: repeat(auto-fill, 240px);
      justify-content: center; /* center tracks when leftover space exists */
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
    @media (max-width: 540px) {
      .search-screen {
        margin: 2.2rem 1.1rem 1.5rem;
        padding: 1.6rem 1.3rem 1.8rem;
      }
      .search-screen__form {
        flex-direction: column;
        align-items: stretch;
      }
      .search-screen__form button {
        width: 100%;
      }
    }
  </style>
  <!-- Search screen -->

  <?php if ($searchRequested): ?>
    <section class="search-screen">
      <form class="search-screen__form" method="get" action="/movie-club-app/index.php">
        <input id="search-page-field" type="text" name="search" placeholder="<?= e(t('search_movies')) ?>" value="<?= htmlspecialchars($searchTerm) ?>"<?= $searchTerm === '' ? ' autofocus' : '' ?>>
        <button type="submit"><?= e(t('search')) ?></button>
      </form>
      <?php if ($searchTerm === ''): ?>
        <p class="search-screen__hint"><?= e(t('search_intro')) ?></p>
      <?php else: ?>
        <p class="search-screen__meta"><?= e(t('results')) ?>:<span>"<?= htmlspecialchars($searchTerm) ?>"</span></p>
      <?php endif; ?>
    </section>

    <?php if ($searchTerm !== '' && $movies): ?>
      <section class="movies-container">
        <?php foreach ($movies as $movie): ?>
          <div class="movie-card">
            <?php $poster = ($movie['poster_url'] ?? null); if (!$poster || $poster==='N/A') $poster='/movie-club-app/assets/img/no-poster.svg'; ?>
            <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($movie['title']) ?>" onerror="this.onerror=null;this.src='/movie-club-app/assets/img/no-poster.svg';">
            <div class="movie-info">
              <div class="movie-title"><?= htmlspecialchars($movie['title']) ?></div>
              <div class="movie-year"><?= htmlspecialchars($movie['year']) ?></div>
              <a class="rate-btn" href="vote.php?movie_id=<?= $movie['id'] ?>"><?= t('rate') ?> ⭐</a>
            </div>
          </div>
        <?php endforeach; ?>
      </section>
    <?php elseif ($searchTerm !== ''): ?>
      <p class="search-empty"><?= e(t('search_no_results')) ?></p>
    <?php endif; ?>
  <?php else: ?>
    <style>
      .home-section { max-width: 1200px; margin: 1rem auto 2rem; padding: 0 1rem; }
      .home-section h2 { color:#f6c90e; margin: .5rem 0 1rem; font-size:1.3rem }
      .movie-row-wrap{ position:relative }
      .movie-row { display:grid; grid-auto-flow:column; grid-auto-columns:minmax(180px, 220px); gap:1rem; overflow-x:auto; padding:0 .25rem .5rem; scroll-snap-type:x mandatory; scroll-behavior:smooth }
      .movie-row .movie-card { width: 200px; scroll-snap-align:start }
      .movie-row img { height: 280px }
      .movie-row::-webkit-scrollbar { height: 8px }
      .movie-row::-webkit-scrollbar-thumb { background:#333; border-radius:4px }

      /* Left/Right nav arrows */
      .row-nav{ position:absolute; top:35%; transform:translateY(-50%); background:rgba(0,0,0,.55); border:1px solid #333; color:#fff; width:36px; height:64px; border-radius:.4rem; display:flex; align-items:center; justify-content:center; cursor:pointer; z-index:2 }
      .row-nav:hover{ background:rgba(0,0,0,.75) }
      .row-nav[disabled]{ opacity:.35; cursor:default }
      .row-nav.prev{ left:-6px }
      .row-nav.next{ right:-6px }
      .row-fade{ position:absolute; top:0; bottom:8px; width:50px; pointer-events:none; z-index:1 }
      .row-fade.left{ left:0; background:linear-gradient(90deg, rgba(17,17,17,1) 0%, rgba(17,17,17,0) 100%) }
      .row-fade.right{ right:0; background:linear-gradient(270deg, rgba(17,17,17,1) 0%, rgba(17,17,17,0) 100%) }
    </style>

    <section class="home-section">
      <h2><?= t('in_competition_section') ?></h2>
      <div class="movie-row-wrap">
        <button class="row-nav prev" aria-label="Previous" data-target="rowIn" data-dir="-1">‹</button>
        <div class="row-fade left"></div>
        <div id="rowIn" class="movie-row">
        <?php foreach ($inCompetition as $movie): ?>
          <div class="movie-card">
            <?php $poster = ($movie['poster_url'] ?? null); if (!$poster || $poster==='N/A') $poster='/movie-club-app/assets/img/no-poster.svg'; ?>
            <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($movie['title']) ?>" onerror="this.onerror=null;this.src='/movie-club-app/assets/img/no-poster.svg';">
            <div class="movie-info">
              <div class="movie-title"><?= htmlspecialchars($movie['title']) ?></div>
              <div class="movie-year"><?= htmlspecialchars($movie['year']) ?></div>
              <a class="rate-btn" href="vote.php?movie_id=<?= $movie['id'] ?>"><?= t('rate') ?> ⭐</a>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
        <div class="row-fade right"></div>
        <button class="row-nav next" aria-label="Next" data-target="rowIn" data-dir="1">›</button>
      </div>
    </section>

    <section class="home-section">
      <h2><?= t('top_rated') ?></h2>
      <div class="movie-row-wrap">
        <button class="row-nav prev" aria-label="Previous" data-target="rowTop" data-dir="-1">‹</button>
        <div class="row-fade left"></div>
        <div id="rowTop" class="movie-row">
        <?php foreach ($topRated as $movie): ?>
          <div class="movie-card">
            <?php $poster = ($movie['poster_url'] ?? null); if (!$poster || $poster==='N/A') $poster='/movie-club-app/assets/img/no-poster.svg'; ?>
            <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($movie['title']) ?>" onerror="this.onerror=null;this.src='/movie-club-app/assets/img/no-poster.svg';">
            <div class="movie-info">
              <div class="movie-title"><?= htmlspecialchars($movie['title']) ?></div>
              <div class="movie-year"><?= htmlspecialchars($movie['year']) ?></div>
              <a class="rate-btn" href="vote.php?movie_id=<?= $movie['id'] ?>"><?= t('rate') ?> ⭐</a>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
        <div class="row-fade right"></div>
        <button class="row-nav next" aria-label="Next" data-target="rowTop" data-dir="1">›</button>
      </div>
    </section>

    <section class="home-section">
      <h2><?= t('recently_added') ?></h2>
      <div class="movie-row-wrap">
        <button class="row-nav prev" aria-label="Previous" data-target="rowRec" data-dir="-1">‹</button>
        <div class="row-fade left"></div>
        <div id="rowRec" class="movie-row">
        <?php foreach ($recent as $movie): ?>
          <div class="movie-card">
            <?php $poster = ($movie['poster_url'] ?? null); if (!$poster || $poster==='N/A') $poster='/movie-club-app/assets/img/no-poster.svg'; ?>
            <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($movie['title']) ?>" onerror="this.onerror=null;this.src='/movie-club-app/assets/img/no-poster.svg';">
            <div class="movie-info">
              <div class="movie-title"><?= htmlspecialchars($movie['title']) ?></div>
              <div class="movie-year"><?= htmlspecialchars($movie['year']) ?></div>
              <a class="rate-btn" href="vote.php?movie_id=<?= $movie['id'] ?>"><?= t('rate') ?> ⭐</a>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
        <div class="row-fade right"></div>
        <button class="row-nav next" aria-label="Next" data-target="rowRec" data-dir="1">›</button>
      </div>
    </section>
    <script>
      (function(){
        function updateButtons(row){
          var prevBtn = document.querySelector('.row-nav.prev[data-target="'+row.id+'"]');
          var nextBtn = document.querySelector('.row-nav.next[data-target="'+row.id+'"]');
          if (!prevBtn || !nextBtn) return;
          prevBtn.disabled = row.scrollLeft <= 2;
          nextBtn.disabled = (row.scrollLeft + row.clientWidth >= row.scrollWidth - 2);
        }
        function scrollRow(e){
          var target = e.currentTarget.getAttribute('data-target');
          var dir = parseInt(e.currentTarget.getAttribute('data-dir'),10);
          var row = document.getElementById(target);
          if (!row) return;
          var amt = Math.max(300, Math.floor(row.clientWidth * 0.9));
          row.scrollBy({left: dir * amt, behavior: 'smooth'});
          setTimeout(function(){ updateButtons(row); }, 350);
        }
        ['rowIn','rowTop','rowRec'].forEach(function(id){
          var row = document.getElementById(id);
          if (!row) return;
          updateButtons(row);
          row.addEventListener('scroll', function(){ updateButtons(row); }, {passive:true});
        });
        document.querySelectorAll('.row-nav').forEach(function(btn){ btn.addEventListener('click', scrollRow); });
        window.addEventListener('resize', function(){ ['rowIn','rowTop','rowRec'].forEach(function(id){ var r=document.getElementById(id); if(r) updateButtons(r); }); });
      })();
    </script>
  <?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
