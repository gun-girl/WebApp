<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/includes/omdb.php';
require_once __DIR__ . '/includes/helper.php';
require_login();

$user = current_user();
$searchRequested = array_key_exists('search', $_GET);
$searchTerm = trim($_GET['search'] ?? '');
if ($searchTerm === '1') {
  $searchTerm = '';
}
$movies = [];

if ($searchRequested) {
  if ($searchTerm !== '') {
    // Use OMDb-backed helper: search locally first, otherwise fetch and cache
    $movies = get_movie_or_fetch($searchTerm);
  }
} else {
  // Prepare homepage sections when not searching
  $activeYear = function_exists('get_active_year') ? get_active_year() : (int)date('Y');
  // 1) In Competition carousel: show movies released in the active year only (2025)
  //    Priority: voted titles (most recent) first, then supplement with unvoted titles, then API (throttled)
  $inCompetition = [];
  try {
    // First: fetch voted active-year movies (most recent activity first)
    $stmt = $mysqli->prepare(
      "SELECT m.*, COUNT(v.id) AS votes_count
       FROM movies m
       JOIN votes v ON v.movie_id = m.id
       WHERE CAST(m.year AS UNSIGNED) = ?
         AND m.poster_url IS NOT NULL
         AND m.poster_url != ''
         AND m.poster_url != 'N/A'
       GROUP BY m.id
       ORDER BY MAX(v.created_at) DESC, votes_count DESC
       LIMIT 50"
    );
    $stmt->bind_param('i', $activeYear);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) { $inCompetition = $res->fetch_all(MYSQLI_ASSOC); }
    
    // Second: if we have fewer than 50, supplement with unvoted active-year movies already in DB
    if (count($inCompetition) < 50) {
      $votedIds = array_column($inCompetition, 'id');
      $remaining = 50 - count($inCompetition);
      
      if ($votedIds) {
        // Exclude already fetched movies
        $placeholders = implode(',', array_fill(0, count($votedIds), '?'));
        $sql = "SELECT m.*
                FROM movies m
                WHERE CAST(m.year AS UNSIGNED) = ?
                  AND m.poster_url IS NOT NULL
                  AND m.poster_url != ''
                  AND m.poster_url != 'N/A'
                  AND m.id NOT IN ($placeholders)
                ORDER BY m.id DESC
                LIMIT ?";
        $stmt2 = $mysqli->prepare($sql);
        $types = 'i' . str_repeat('i', count($votedIds)) . 'i';
        $params = array_merge([$activeYear], $votedIds, [$remaining]);
        $stmt2->bind_param($types, ...$params);
      } else {
        // No voted movies yet, just fetch all active-year movies
        $stmt2 = $mysqli->prepare(
          "SELECT m.*
           FROM movies m
           WHERE CAST(m.year AS UNSIGNED) = ?
             AND m.poster_url IS NOT NULL
             AND m.poster_url != ''
             AND m.poster_url != 'N/A'
           ORDER BY m.id DESC
           LIMIT ?"
        );
        $stmt2->bind_param('ii', $activeYear, $remaining);
      }
      
      $stmt2->execute();
      $res2 = $stmt2->get_result();
      if ($res2) { 
        $unvoted = $res2->fetch_all(MYSQLI_ASSOC);
        $inCompetition = array_merge($inCompetition, $unvoted);
      }
    }
    
    // Third: throttle OMDb API fetch to avoid slowing homepage
    // Only fetch if we still have fewer than min threshold AND last fetch was >6h ago
    if (count($inCompetition) < 24) {
      $fetchKey = 'last_fetch_recent_releases_' . $activeYear;
      $lastFetch = function_exists('get_setting') ? get_setting($fetchKey, '') : '';
      $canFetch = (!$lastFetch) || (strtotime($lastFetch) < (time() - 6*3600));
      if ($canFetch) {
        $apiMovies = fetch_recent_releases($activeYear);
        if ($apiMovies) {
          $existingIds = array_column($inCompetition, 'id');
          $newMovies = array_filter($apiMovies, function($m) use ($existingIds) {
            return !in_array($m['id'], $existingIds);
          });
          $inCompetition = array_merge($inCompetition, array_slice($newMovies, 0, 50 - count($inCompetition)));
          if (function_exists('set_setting')) { set_setting($fetchKey, date('Y-m-d H:i:s')); }
        }
      }
    }
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
          WHERE m.poster_url IS NOT NULL
            AND m.poster_url != ''
            AND m.poster_url != 'N/A'
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
            WHERE m.poster_url IS NOT NULL
              AND m.poster_url != ''
              AND m.poster_url != 'N/A'
            GROUP BY m.id
            HAVING votes_count > 0
            ORDER BY avg_rating DESC, votes_count DESC
            LIMIT 24";
      $topRated = $mysqli->query($q)->fetch_all(MYSQLI_ASSOC);
    }
  }

}

?>
<?php // Add body class to signal active search state (for hiding duplicate header search on wide screens)
$body_extra_class = $searchRequested ? 'has-search' : ''; ?>
<?php include __DIR__ . '/includes/header.php'; ?>
  <!-- Search screen -->

  <?php if ($searchRequested): ?>
    <section class="search-screen">
      <form class="search-screen__form" method="get" action="<?= ADDRESS ?>/index.php" id="searchForm">
        <input id="search-page-field" type="text" name="search" placeholder="<?= e(t('search_movies')) ?>" value="<?= htmlspecialchars($searchTerm) ?>"<?= $searchTerm === '' ? ' autofocus' : '' ?>>
        <button type="submit"><?= e(t('search')) ?></button>
      </form>
      <div id="searchLoading" class="search-loading" style="display:none;">
        <div class="spinner"></div>
        <p><?= e(t('searching')) ?>...</p>
      </div>
      <?php if ($searchTerm === ''): ?>
        <p class="search-screen__hint"><?= e(t('search_intro')) ?></p>
      <?php else: ?>
        <p class="search-screen__meta"><?= e(t('results')) ?>:<span>"<?= htmlspecialchars($searchTerm) ?>"</span></p>
      <?php endif; ?>
    </section>

    <?php if ($searchTerm !== '' && $movies): ?>
      <?php
        // Split movies into with-poster and without-poster groups
        $moviesWithPoster = [];
        $moviesWithoutPoster = [];
        foreach ($movies as $movie) {
          $poster = $movie['poster_url'] ?? null;
          if ($poster && $poster !== 'N/A' && $poster !== '') {
            $moviesWithPoster[] = $movie;
          } else {
            $moviesWithoutPoster[] = $movie;
          }
        }
      ?>
      <section class="movies-container">
        <?php foreach ($moviesWithPoster as $movie): ?>
          <div class="movie-card">
            <?php $badgeKey = competition_badge_key($movie); $in = ($badgeKey === 'badge_in_competition'); ?>
            <div class="comp-badge <?= $in ? 'in' : 'out' ?>"><?= e(t($badgeKey)) ?></div>
            <?php $poster = $movie['poster_url']; ?>
            <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($movie['title']) ?>" onerror="this.onerror=null;this.src='<?= ADDRESS ?>/assets/img/no-poster.svg';">
            <div class="movie-info">
              <div class="movie-title"><?= htmlspecialchars($movie['title']) ?></div>
              <div class="movie-year"><?= htmlspecialchars($movie['year']) ?></div>
              <a class="rate-btn" href="vote.php?movie_id=<?= $movie['id'] ?>"><?= t('rate') ?> ⭐</a>
            </div>
          </div>
        <?php endforeach; ?>
      </section>
      
      <?php if ($moviesWithoutPoster): ?>
        <div style="text-align:center;margin:2rem 0;">
          <button id="showMoreBtn" class="btn" style="background:#444;color:#fff;">Show more (<?= count($moviesWithoutPoster) ?>)</button>
        </div>
        <section class="movies-container" id="noPosterMovies" style="display:none;">
          <?php foreach ($moviesWithoutPoster as $movie): ?>
            <div class="movie-card">
              <?php $badgeKey = competition_badge_key($movie); $in = ($badgeKey === 'badge_in_competition'); ?>
              <div class="comp-badge <?= $in ? 'in' : 'out' ?>"><?= e(t($badgeKey)) ?></div>
              <img src="<?= ADDRESS ?>/assets/img/no-poster.svg" alt="<?= htmlspecialchars($movie['title']) ?>">
              <div class="movie-info">
                <div class="movie-title"><?= htmlspecialchars($movie['title']) ?></div>
                <div class="movie-year"><?= htmlspecialchars($movie['year']) ?></div>
                <a class="rate-btn" href="vote.php?movie_id=<?= $movie['id'] ?>"><?= t('rate') ?> ⭐</a>
              </div>
            </div>
          <?php endforeach; ?>
        </section>
        <script>
          document.getElementById('showMoreBtn').addEventListener('click', function() {
            document.getElementById('noPosterMovies').style.display = 'grid';
            this.style.display = 'none';
          });
        </script>
      <?php endif; ?>
    <?php elseif ($searchTerm !== ''): ?>
      <p class="search-empty"><?= e(t('search_no_results')) ?></p>
    <?php endif; ?>
  <?php else: ?>
    

    <section class="home-section">
      <h2><?= t('in_competition_section') ?></h2>
      <div class="movie-row-wrap">
        <button class="row-nav prev" aria-label="Previous" data-target="rowIn" data-dir="-1">‹</button>
        <div class="row-fade left"></div>
        <div id="rowIn" class="movie-row">
        <?php 
        $inWithPoster = array_filter($inCompetition, fn($m) => !empty($m['poster_url']) && $m['poster_url'] !== 'N/A');
        $inWithoutPoster = array_diff_key($inCompetition, $inWithPoster);
        foreach ($inWithPoster as $movie): ?>
          <div class="movie-card">
            <?php $poster = $movie['poster_url']; ?>
            <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($movie['title']) ?>" onerror="this.onerror=null;this.src='<?= ADDRESS ?>/assets/img/no-poster.svg';">
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
      <?php if (!empty($inWithoutPoster)): ?>
      <div style="text-align:center;margin:1rem 0;">
        <button class="showMoreHidden" data-target="inWithoutPosterMovies" style="background:#f6c90e;color:#000;padding:0.75rem 1.5rem;border:none;border-radius:4px;cursor:pointer;font-weight:bold;">Show more (<?= count($inWithoutPoster) ?>)</button>
      </div>
      <div id="inWithoutPosterMovies" class="movie-row" style="display:none;gap:1.5rem;padding:2rem;max-width:1200px;margin:auto;grid-template-columns:repeat(auto-fill,240px);justify-content:center;">
        <?php foreach ($inWithoutPoster as $movie): ?>
          <div class="movie-card">
            <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 200 300'%3E%3Cdefs%3E%3ClinearGradient id='grad' x1='0%25' y1='0%25' x2='100%25' y2='100%25'%3E%3Cstop offset='0%25' style='stop-color:%23f6c90e;stop-opacity:1' /%3E%3Cstop offset='50%25' style='stop-color:%23ffa500;stop-opacity:1' /%3E%3Cstop offset='100%25' style='stop-color:%23ff6b6b;stop-opacity:1' /%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='200' height='300' fill='url(%23grad)'/%3E%3Ctext x='100' y='150' font-size='80' text-anchor='middle' dominant-baseline='middle' fill='rgba(0,0,0,0.1)'%3E🎬%3C/text%3E%3C/svg%3E" alt="<?= htmlspecialchars($movie['title']) ?>" style="width:100%;height:240px;object-fit:cover;">
            <div class="movie-info">
              <div class="movie-title"><?= htmlspecialchars($movie['title']) ?></div>
              <div class="movie-year"><?= htmlspecialchars($movie['year']) ?></div>
              <a class="rate-btn" href="vote.php?movie_id=<?= $movie['id'] ?>"><?= t('rate') ?> ⭐</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <section class="home-section">
      <h2><?= t('top_rated') ?></h2>
      <div class="movie-row-wrap">
        <button class="row-nav prev" aria-label="Previous" data-target="rowTop" data-dir="-1">‹</button>
        <div class="row-fade left"></div>
        <div id="rowTop" class="movie-row">
        <?php 
        $topWithPoster = array_filter($topRated, fn($m) => !empty($m['poster_url']) && $m['poster_url'] !== 'N/A');
        $topWithoutPoster = array_diff_key($topRated, $topWithPoster);
        foreach ($topWithPoster as $movie): ?>
          <div class="movie-card">
            <?php $poster = $movie['poster_url']; ?>
            <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($movie['title']) ?>" onerror="this.onerror=null;this.src='<?= ADDRESS ?>/assets/img/no-poster.svg';">
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
      <?php if (!empty($topWithoutPoster)): ?>
      <div style="text-align:center;margin:1rem 0;">
        <button class="showMoreHidden" data-target="topWithoutPosterMovies" style="background:#f6c90e;color:#000;padding:0.75rem 1.5rem;border:none;border-radius:4px;cursor:pointer;font-weight:bold;">Show more (<?= count($topWithoutPoster) ?>)</button>
      </div>
      <div id="topWithoutPosterMovies" class="movie-row" style="display:none;gap:1.5rem;padding:2rem;max-width:1200px;margin:auto;grid-template-columns:repeat(auto-fill,240px);justify-content:center;">
        <?php foreach ($topWithoutPoster as $movie): ?>
          <div class="movie-card">
            <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 200 300'%3E%3Cdefs%3E%3ClinearGradient id='grad' x1='0%25' y1='0%25' x2='100%25' y2='100%25'%3E%3Cstop offset='0%25' style='stop-color:%23f6c90e;stop-opacity:1' /%3E%3Cstop offset='50%25' style='stop-color:%23ffa500;stop-opacity:1' /%3E%3Cstop offset='100%25' style='stop-color:%23ff6b6b;stop-opacity:1' /%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='200' height='300' fill='url(%23grad)'/%3E%3Ctext x='100' y='150' font-size='80' text-anchor='middle' dominant-baseline='middle' fill='rgba(0,0,0,0.1)'%3E🎬%3C/text%3E%3C/svg%3E" alt="<?= htmlspecialchars($movie['title']) ?>" style="width:100%;height:240px;object-fit:cover;">
            <div class="movie-info">
              <div class="movie-title"><?= htmlspecialchars($movie['title']) ?></div>
              <div class="movie-year"><?= htmlspecialchars($movie['year']) ?></div>
              <a class="rate-btn" href="vote.php?movie_id=<?= $movie['id'] ?>"><?= t('rate') ?> ⭐</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>
    <script>
      // Handle show more buttons
      document.querySelectorAll('.showMoreHidden').forEach(function(btn) {
        btn.addEventListener('click', function() {
          var target = document.getElementById(this.getAttribute('data-target'));
          if (target) {
            target.style.display = 'grid';
            this.style.display = 'none';
          }
        });
      });
      
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
        ['rowIn','rowTop'].forEach(function(id){
          var row = document.getElementById(id);
          if (!row) return;
          updateButtons(row);
          row.addEventListener('scroll', function(){ updateButtons(row); }, {passive:true});
        });
        document.querySelectorAll('.row-nav').forEach(function(btn){ btn.addEventListener('click', scrollRow); });
        window.addEventListener('resize', function(){ ['rowIn','rowTop'].forEach(function(id){ var r=document.getElementById(id); if(r) updateButtons(r); }); });
      })();
    </script>
  <?php endif; ?>
  
  <?php if (isset($_GET['search']) && $_GET['search'] === '1'): ?>
    <script>
      // Auto-focus search input when coming from Vote button
      window.addEventListener('DOMContentLoaded', function() {
        var searchInput = document.getElementById('search');
        if (searchInput) {
          searchInput.focus();
          searchInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      });
    </script>
  <?php endif; ?>
  
  <script>
    // Show loading indicator when searching
    (function() {
      var searchForm = document.getElementById('searchForm');
      var loading = document.getElementById('searchLoading');
      if (searchForm && loading) {
        searchForm.addEventListener('submit', function(e) {
          var searchInput = document.getElementById('search-page-field');
          if (searchInput && searchInput.value.trim() !== '' && searchInput.value !== '1') {
            loading.style.display = 'block';
          }
        });
      }
    })();
  </script>

<?php include __DIR__ . '/includes/footer.php'; ?>
