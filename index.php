<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/lang.php';
require_login();

// Prevent caching so the login check always runs on protected pages
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/includes/omdb.php';
require_once __DIR__ . '/includes/helper.php';


$user = current_user();
$searchRequested = array_key_exists('search', $_GET);
$searchTerm = trim($_GET['search'] ?? '');
if ($searchTerm === '1') {
  $searchTerm = '';
}
$movies = [];
$inCompetitionNote = '';

if ($searchRequested) {
  if ($searchTerm !== '') {
    // Use OMDb-backed helper: search locally first, otherwise fetch and cache
    $movies = get_movie_or_fetch($searchTerm);
    
    // Filter out unreleased movies from search results
    $today = date('Y-m-d');
    $currentYear = (int)date('Y');
    $movies = array_filter($movies, function($m) use ($today, $currentYear) {
      if (!empty($m['released']) && $m['released'] !== '0000-00-00') {
        return $m['released'] <= $today;
      }
      $movieYear = (int)($m['year'] ?? 0);
      return $movieYear <= $currentYear;
    });
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

  // Prefer IMDb Moviemeter Top 10 when available
  $imdbTopWeek = fetch_imdb_top_weekly(10);
  if (!empty($imdbTopWeek)) {
    $inCompetition = $imdbTopWeek;
    $inCompetitionNote = t('imdb_top_week_note');
  }

  // Fallbacks if nothing to show
  if (empty($inCompetition)) {
    $fallbackRecent = fetch_recent_releases($activeYear);
    if (!empty($fallbackRecent)) {
      $inCompetition = $fallbackRecent;
      $inCompetitionNote = $inCompetitionNote ?: 'Recent releases';
    }
  }

  // Filter out unreleased movies (future releases)
  $today = date('Y-m-d');
  $currentYear = (int)date('Y');
  $inCompetition = array_filter($inCompetition, function($m) use ($today, $currentYear) {
    // Check released date first (most accurate)
    if (!empty($m['released']) && $m['released'] !== '0000-00-00') {
      return $m['released'] <= $today;
    }
    // Fallback to year check
    $movieYear = (int)($m['year'] ?? 0);
    return $movieYear <= $currentYear;
  });

  // 2) Top Voted: compute average of per-vote totals (sum of category scores)
  $vdCols = $mysqli->query("SHOW COLUMNS FROM vote_details")->fetch_all(MYSQLI_ASSOC);
  $scoreCandidates = ['writing','direction','acting_or_doc_theme','emotional_involvement','novelty','casting_research_art','sound'];
  $haveCols = array_map(function($c){ return $c['Field']; }, $vdCols);
  $scoreCols = array_values(array_filter($scoreCandidates, function($c) use ($haveCols){ return in_array($c, $haveCols); }));
  $topRated = [];
  if ($scoreCols) {
    $numParts = array_map(function($col){ return "COALESCE(vd.`$col`,0)"; }, $scoreCols);
    $scoreExpr = '('.implode('+', $numParts).')';
    $q = "SELECT m.*, COUNT(v.id) AS votes_count,
                 ROUND(AVG($scoreExpr),2) AS avg_rating
          FROM movies m
          JOIN votes v ON v.movie_id = m.id
          LEFT JOIN vote_details vd ON vd.vote_id = v.id
          WHERE m.poster_url IS NOT NULL
            AND m.poster_url != ''
            AND m.poster_url != 'N/A'
          GROUP BY m.id
          HAVING votes_count > 0 AND avg_rating IS NOT NULL
          ORDER BY avg_rating DESC, votes_count DESC
          LIMIT 24";
    $topVoted = $mysqli->query($q)->fetch_all(MYSQLI_ASSOC);
    
    // Filter out unreleased movies from Top Voted
    $today = date('Y-m-d');
    $currentYear = (int)date('Y');
    $topVoted = array_filter($topVoted, function($m) use ($today, $currentYear) {
      if (!empty($m['released']) && $m['released'] !== '0000-00-00') {
        return $m['released'] <= $today;
      }
      $movieYear = (int)($m['year'] ?? 0);
      return $movieYear <= $currentYear;
    });
  }

  if (empty($inCompetition) && !empty($topVoted)) {
    $inCompetition = $topVoted;
    $inCompetitionNote = $inCompetitionNote ?: 'Top voted fallback';
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
        // Show movies/series (including miniseries) always; only hide other types without posters
        global $omdbClient;
        $mainResults = [];
        $hiddenResults = [];
        foreach ($movies as $movie) {
          $type = $movie['type'] ?? '';
          $poster = $movie['poster_url'] ?? null;
          $hasPoster = ($poster && $poster !== 'N/A' && $poster !== '');
          
          // Check if this is a miniseries
          $isMiniseries = ($type === 'series' && $omdbClient && $omdbClient->isMiniseries($movie['imdb_id'] ?? ''));
          
          // For series/miniseries, fetch season information and create separate cards
          if ($type === 'series' || $isMiniseries) {
            $totalSeasons = 1;
            $imdb_id = $movie['imdb_id'] ?? '';
            
            if ($omdbClient && $imdb_id) {
              try {
                $detail = $omdbClient->getDetail($imdb_id);
                if ($detail && isset($detail['totalSeasons']) && is_numeric($detail['totalSeasons'])) {
                  $totalSeasons = (int)$detail['totalSeasons'];
                }
              } catch (Exception $e) {
                error_log("Failed to fetch OMDb details for series: " . $e->getMessage());
              }
            }
            
            // Create a separate card for each season
            for ($seasonNum = 1; $seasonNum <= $totalSeasons; $seasonNum++) {
              $seasonMovie = $movie;
              $seasonMovie['season_number'] = $seasonNum;
              
              // Fetch season-specific poster and year
              if ($omdbClient && $imdb_id) {
                try {
                  $seasonDetail = $omdbClient->getSeasonDetail($imdb_id, $seasonNum);
                  if ($seasonDetail) {
                    // Use season poster if available
                    if (!empty($seasonDetail['Poster']) && $seasonDetail['Poster'] !== 'N/A') {
                      $seasonPoster = str_replace('http://', 'https://', $seasonDetail['Poster']);
                      $seasonMovie['poster_url'] = $seasonPoster;
                    }
                    // Extract year from Season field or from first episode's release date
                    if (!empty($seasonDetail['Season'])) {
                      $seasonMovie['season_year'] = $seasonDetail['Season'];
                    }
                    if (!empty($seasonDetail['Episodes']) && is_array($seasonDetail['Episodes'])) {
                      $firstEpisode = $seasonDetail['Episodes'][0];
                      if (!empty($firstEpisode['Released']) && $firstEpisode['Released'] !== 'N/A') {
                        $releaseDate = $firstEpisode['Released'];
                        // Use the first episode release date for competition logic
                        $ts = strtotime($releaseDate);
                        if ($ts) {
                          $seasonMovie['released'] = date('Y-m-d', $ts);
                        }
                        if (preg_match('/(\d{4})/', $releaseDate, $matches)) {
                          $seasonMovie['season_year'] = $matches[1];
                          // Also set general year so competition badge uses season year
                          $seasonMovie['year'] = $matches[1];
                        }
                      }
                    }
                  }
                } catch (Exception $e) {
                  error_log("Failed to fetch season $seasonNum details: " . $e->getMessage());
                }
              }
              
              $mainResults[] = $seasonMovie;
            }
          } elseif ($type === 'movie') {
            $mainResults[] = $movie;
          } elseif ($hasPoster) {
            $mainResults[] = $movie;
          } else {
            $hiddenResults[] = $movie;
          }
        }
      ?>
      <section class="movies-container">
        <?php foreach ($mainResults as $movie): ?>
          <div class="movie-card">
            <?php $badgeKey = competition_badge_key($movie); $in = ($badgeKey === 'badge_in_competition'); ?>
            <div class="comp-badge <?= $in ? 'in' : 'out' ?>"><?= e(t($badgeKey)) ?></div>
            <?php 
              $poster = $movie['poster_url'] ?? null;
              if (!$poster || $poster === 'N/A' || $poster === '') {
                $poster = ADDRESS . '/assets/img/no-poster.svg';
              }
            ?>
            <a class="movie-link" href="movie.php?id=<?= $movie['id'] ?>" style="text-decoration:none;color:inherit;display:block;">
              <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($movie['title']) ?>" loading="lazy" onerror="this.onerror=null;this.src='<?= ADDRESS ?>/assets/img/no-poster.svg';">
              <?php if (isset($movie['season_number'])): ?>
                <div class="season-badge">Season <?= $movie['season_number'] ?></div>
              <?php endif; ?>
              <div class="movie-info">
                <div class="movie-title"><?= htmlspecialchars($movie['title']) ?></div>
                <div class="movie-year"><?php 
                  if (isset($movie['season_year'])) {
                    echo htmlspecialchars($movie['season_year']);
                  } elseif ($movie['type'] === 'series' && !empty($movie['start_year'])) {
                    echo htmlspecialchars($movie['start_year']) . ((!empty($movie['end_year']) && $movie['end_year'] != $movie['start_year']) ? ' - ' . htmlspecialchars($movie['end_year']) : '');
                  } else {
                    echo htmlspecialchars($movie['year']);
                  }
                ?></div>
              </div>
            </a>
            <a class="vote-btn" href="vote.php?movie_id=<?= $movie['id'] ?><?= isset($movie['season_number']) ? '&season='.$movie['season_number'] : '' ?>"><?= t('vote') ?> ⭐</a>
          </div>
        <?php endforeach; ?>
      </section>
      
      <?php if ($hiddenResults): ?>
        <div style="text-align:center;margin:2rem 0;">
          <button id="showMoreBtn" class="btn" style="background:#444;color:#fff;">Show more (<?= count($hiddenResults) ?>)</button>
        </div>
        <section class="movies-container" id="noPosterMovies" style="display:none;">
          <?php foreach ($hiddenResults as $movie): ?>
            <div class="movie-card">
              <?php $badgeKey = competition_badge_key($movie); $in = ($badgeKey === 'badge_in_competition'); ?>
              <div class="comp-badge <?= $in ? 'in' : 'out' ?>"><?= e(t($badgeKey)) ?></div>
              <a class="movie-link" href="movie.php?id=<?= $movie['id'] ?>" style="text-decoration:none;color:inherit;display:block;">
                <img src="<?= ADDRESS ?>/assets/img/no-poster.svg" alt="<?= htmlspecialchars($movie['title']) ?>">
                <div class="movie-info">
                  <div class="movie-title"><?= htmlspecialchars($movie['title']) ?></div>
                  <div class="movie-year"><?= ($movie['type'] === 'series' && !empty($movie['start_year'])) ? htmlspecialchars($movie['start_year']) . ((!empty($movie['end_year']) && $movie['end_year'] != $movie['start_year']) ? ' - ' . htmlspecialchars($movie['end_year']) : '') : htmlspecialchars($movie['year']) ?></div>
                </div>
              </a>
              <a class="vote-btn" href="vote.php?movie_id=<?= $movie['id'] ?>"><?= t('vote') ?> ⭐</a>
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
      <h2><?= $inCompetitionNote ? e($inCompetitionNote) : t('in_competition_section') ?></h2>
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
            <a class="movie-link" href="movie.php?id=<?= $movie['id'] ?>" style="text-decoration:none;color:inherit;display:block;">
              <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($movie['title']) ?>" loading="lazy" onerror="this.onerror=null;this.src='<?= ADDRESS ?>/assets/img/no-poster.svg';">
              <div class="movie-info">
                <div class="movie-title"><?= htmlspecialchars($movie['title']) ?></div>
                <div class="movie-year"><?= ($movie['type'] === 'series' && !empty($movie['start_year'])) ? htmlspecialchars($movie['start_year']) . ((!empty($movie['end_year']) && $movie['end_year'] != $movie['start_year']) ? ' - ' . htmlspecialchars($movie['end_year']) : '') : htmlspecialchars($movie['year']) ?></div>
              </div>
            </a>
            <a class="vote-btn" href="vote.php?movie_id=<?= $movie['id'] ?>"><?= t('vote') ?> ⭐</a>
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
            <a class="movie-link" href="movie.php?id=<?= $movie['id'] ?>" style="text-decoration:none;color:inherit;display:block;">
              <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 200 300'%3E%3Cdefs%3E%3ClinearGradient id='grad' x1='0%25' y1='0%25' x2='100%25' y2='100%25'%3E%3Cstop offset='0%25' style='stop-color:%23f6c90e;stop-opacity:1' /%3E%3Cstop offset='50%25' style='stop-color:%23ffa500;stop-opacity:1' /%3E%3Cstop offset='100%25' style='stop-color:%23ff6b6b;stop-opacity:1' /%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='200' height='300' fill='url(%23grad)'/%3E%3Ctext x='100' y='150' font-size='80' text-anchor='middle' dominant-baseline='middle' fill='rgba(0,0,0,0.1)'%3E🎬%3C/text%3E%3C/svg%3E" alt="<?= htmlspecialchars($movie['title']) ?>" style="width:100%;height:240px;object-fit:cover;">
              <div class="movie-info">
                <div class="movie-title"><?= htmlspecialchars($movie['title']) ?></div>
                <div class="movie-year"><?= ($movie['type'] === 'series' && !empty($movie['start_year'])) ? htmlspecialchars($movie['start_year']) . ((!empty($movie['end_year']) && $movie['end_year'] != $movie['start_year']) ? ' - ' . htmlspecialchars($movie['end_year']) : '') : htmlspecialchars($movie['year']) ?></div>
              </div>
            </a>
            <a class="vote-btn" href="vote.php?movie_id=<?= $movie['id'] ?>"><?= t('vote') ?> ⭐</a>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <section class="home-section">
      <h2><?= t('top_voted') ?></h2>
      <div class="movie-row-wrap">
        <button class="row-nav prev" aria-label="Previous" data-target="rowTop" data-dir="-1">‹</button>
        <div class="row-fade left"></div>
        <div id="rowTop" class="movie-row">
        <?php 
        $topWithPoster = array_filter($topVoted, fn($m) => !empty($m['poster_url']) && $m['poster_url'] !== 'N/A');
        $topWithoutPoster = array_diff_key($topVoted, $topWithPoster);
        foreach ($topWithPoster as $movie): ?>
          <div class="movie-card">
            <?php $poster = $movie['poster_url']; ?>
            <a class="movie-link" href="movie.php?id=<?= $movie['id'] ?>" style="text-decoration:none;color:inherit;display:block;">
              <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($movie['title']) ?>" loading="lazy" onerror="this.onerror=null;this.src='<?= ADDRESS ?>/assets/img/no-poster.svg';">
              <div class="movie-info">
                <div class="movie-title"><?= htmlspecialchars($movie['title']) ?></div>
                <div class="movie-year"><?= ($movie['type'] === 'series' && !empty($movie['start_year'])) ? htmlspecialchars($movie['start_year']) . ((!empty($movie['end_year']) && $movie['end_year'] != $movie['start_year']) ? ' - ' . htmlspecialchars($movie['end_year']) : '') : htmlspecialchars($movie['year']) ?></div>
              </div>
            </a>
            <a class="vote-btn" href="vote.php?movie_id=<?= $movie['id'] ?>"><?= t('vote') ?> ⭐</a>
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
            <a class="movie-link" href="movie.php?id=<?= $movie['id'] ?>" style="text-decoration:none;color:inherit;display:block;">
              <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 200 300'%3E%3Cdefs%3E%3ClinearGradient id='grad' x1='0%25' y1='0%25' x2='100%25' y2='100%25'%3E%3Cstop offset='0%25' style='stop-color:%23f6c90e;stop-opacity:1' /%3E%3Cstop offset='50%25' style='stop-color:%23ffa500;stop-opacity:1' /%3E%3Cstop offset='100%25' style='stop-color:%23ff6b6b;stop-opacity:1' /%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='200' height='300' fill='url(%23grad)'/%3E%3Ctext x='100' y='150' font-size='80' text-anchor='middle' dominant-baseline='middle' fill='rgba(0,0,0,0.1)'%3E🎬%3C/text%3E%3C/svg%3E" alt="<?= htmlspecialchars($movie['title']) ?>" style="width:100%;height:240px;object-fit:cover;">
              <div class="movie-info">
                <div class="movie-title"><?= htmlspecialchars($movie['title']) ?></div>
                <div class="movie-year"><?= ($movie['type'] === 'series' && !empty($movie['start_year'])) ? htmlspecialchars($movie['start_year']) . ((!empty($movie['end_year']) && $movie['end_year'] != $movie['start_year']) ? ' - ' . htmlspecialchars($movie['end_year']) : '') : htmlspecialchars($movie['year']) ?></div>
              </div>
            </a>
            <a class="vote-btn" href="vote.php?movie_id=<?= $movie['id'] ?>"><?= t('vote') ?> ⭐</a>
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
