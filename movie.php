<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/includes/helper.php';
require_once __DIR__ . '/includes/omdb.php';
require_login();

$movieId = (int)($_GET['id'] ?? 0);
$seasonNumber = (int)($_GET['season'] ?? 0);  // Get season parameter if provided

if ($movieId <= 0) {
    http_response_code(400);
  echo t('invalid_movie_id');
    exit;
}

$stmt = $mysqli->prepare(
  "SELECT m.*
   FROM movies m
   WHERE m.id = ?
   LIMIT 1"
);
$stmt->bind_param('i', $movieId);
$stmt->execute();
$movie = $stmt->get_result()->fetch_assoc();

if (!$movie) {
    http_response_code(404);
  echo t('movie_not_found');
    exit;
}

// If season is specified and movie is a series, fetch season-specific data
$seasonData = null;
if ($seasonNumber > 0 && $movie['type'] === 'series') {
  $hasSeasonsTable = false;
  try {
    $tableCheck = $mysqli->query("SHOW TABLES LIKE 'seasons'");
    $hasSeasonsTable = $tableCheck && $tableCheck->num_rows > 0;
  } catch (Throwable $e) {
    $hasSeasonsTable = false;
  }

  if ($hasSeasonsTable) {
    try {
      $seasonStmt = $mysqli->prepare(
        "SELECT season_number, release_date, year 
         FROM seasons 
         WHERE movie_id = ? AND season_number = ?
         LIMIT 1"
      );
      if ($seasonStmt) {
        $seasonStmt->bind_param('ii', $movieId, $seasonNumber);
        $seasonStmt->execute();
        $seasonData = $seasonStmt->get_result()->fetch_assoc();
      }
    } catch (Throwable $e) {
      $seasonData = null;
    }
  }

  if (!$seasonData && !empty($movie['imdb_id'])) {
    $seasonApiData = fetch_season_data($movie['imdb_id'], $seasonNumber);
    $seasonReleaseDate = '';
    $seasonYear = null;

    if (!empty($seasonApiData['Released']) && $seasonApiData['Released'] !== 'N/A') {
      $ts = strtotime($seasonApiData['Released']);
      if ($ts) {
        $seasonReleaseDate = date('Y-m-d', $ts);
        $seasonYear = (int)date('Y', $ts);
      }
    }

    if (!$seasonYear && !empty($seasonApiData['Episodes']) && is_array($seasonApiData['Episodes'])) {
      foreach ($seasonApiData['Episodes'] as $ep) {
        if (!empty($ep['Released']) && $ep['Released'] !== 'N/A') {
          $ts = strtotime($ep['Released']);
          if ($ts) {
            $seasonYear = (int)date('Y', $ts);
            if (!$seasonReleaseDate) {
              $seasonReleaseDate = date('Y-m-d', $ts);
            }
            break;
          }
        }
      }
    }

    if ($seasonReleaseDate || $seasonYear) {
      $seasonData = [
        'season_number' => $seasonNumber,
        'release_date' => $seasonReleaseDate,
        'year' => $seasonYear
      ];
    }
  }
}

// Check if movie/season has been released yet
$today = date('Y-m-d');
$currentYear = (int)date('Y');
$isReleased = false;

// Use season-specific data if available, otherwise use movie data
$releaseDate = $seasonData ? ($seasonData['release_date'] ?? '') : ($movie['released'] ?? '');
$releaseYear = $seasonData ? ($seasonData['year'] ?? null) : (int)($movie['year'] ?? 0);

if (!empty($releaseDate) && $releaseDate !== '0000-00-00') {
  $isReleased = ($releaseDate <= $today);
} else {
  $isReleased = ($releaseYear <= $currentYear);
}


if (!$isReleased) {
  http_response_code(403);
  echo t('not_released_yet');
  exit;
}

$currentUser = current_user();
$canAdmin = $currentUser && (($currentUser['role'] ?? 'user') === 'admin');
$showAdminPanel = false;

// Load translations to get status labels dynamically
$statusInCompetition = t('in_competition');
$statusOutCompetition = t('out_of_competition');

// Always compute status dynamically from active competition window (season-aware)
$movieForStatus = $movie;
if ($seasonNumber > 0 && $seasonData) {
  if (!empty($seasonData['release_date'])) {
    $movieForStatus['released'] = $seasonData['release_date'];
  }
  if (!empty($seasonData['year'])) {
    $movieForStatus['year'] = $seasonData['year'];
  }
}
$currentStatus = is_in_competition($movieForStatus) ? $statusInCompetition : $statusOutCompetition;

$yearLabel = '';
if ($seasonNumber > 0 && $seasonData) {
    // Display season-specific year if available
    $yearLabel = htmlspecialchars($seasonData['year'] ?? '');
} else if ($movie['type'] === 'series' && !empty($movie['start_year'])) {
    // Display series year range only if no specific season is requested
    $yearLabel = htmlspecialchars($movie['start_year']) . ((!empty($movie['end_year']) && $movie['end_year'] != $movie['start_year']) ? ' - ' . htmlspecialchars($movie['end_year']) : '');
} else {
    // Display movie year
    $yearLabel = htmlspecialchars($movie['year'] ?? '');
}

$poster = $movie['poster_url'] ?? '';
if (!$poster || $poster === 'N/A') {
    $poster = ADDRESS . '/assets/img/no-poster.svg';
}

// Map status to label - already translated dynamically
function status_label(string $status): string {
  return $status;  // Already translated from t()
}

include __DIR__ . '/includes/header.php';
?>
<style>
  .movie-detail { max-width: 960px; margin: 2rem auto; padding: 1.5rem; background:#111; color:#eee; border:1px solid #333; border-radius:10px; box-shadow:0 10px 40px rgba(0,0,0,0.35); }
  .movie-detail__grid { display:grid; grid-template-columns: 240px 1fr; gap:1.5rem; align-items:flex-start; }
  .movie-detail__poster { width:100%; border-radius:8px; border:1px solid #222; }
  .badge { display:inline-block; padding:0.35rem 0.7rem; border-radius:999px; font-weight:700; font-size:0.9rem; }
  .badge.in { background:#1f6b3b; color:#d2ffd2; }
  .badge.out { background:#5a1f1f; color:#ffd6d6; }
  /* removed legacy 2026-specific badge */
  .admin-panel { margin-top:1.5rem; padding:1rem; border:1px solid #444; border-radius:8px; background:#0b0b0b; }
  .admin-panel h3 { margin-top:0; color:#f6c90e; }
  .btn { display:inline-block; padding:0.65rem 1.2rem; border:none; border-radius:6px; cursor:pointer; font-weight:700; }
  .btn-primary { background:#f6c90e; color:#000; }
  .btn-secondary { background:#444; color:#fff; }
  .actions { display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap; }
  @media (max-width: 720px) { .movie-detail__grid { grid-template-columns: 1fr; } }
</style>

<div class="movie-detail">
  <div class="movie-detail__grid">
    <div>
      <img class="movie-detail__poster" src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($movie['title']) ?>" onerror="this.onerror=null;this.src='<?= ADDRESS ?>/assets/img/no-poster.svg';">
    </div>
    <div>
      <h1 style="margin:0 0 0.5rem 0; font-size:2rem; color:#f6c90e;"><?= htmlspecialchars($movie['title']) ?><?= $seasonNumber > 0 ? ' - ' . t('season') . ' ' . $seasonNumber : '' ?></h1>
      <p style="margin:0 0 0.5rem 0; color:#bbb; font-size:1rem;"><?= t('type') ?>: <?= htmlspecialchars(t($movie['type'] ?? '')) ?></p>
      <p style="margin:0 0 1rem 0; color:#bbb; font-size:1rem;"><?= t('year') ?>: <?= $yearLabel ?></p>
      <?php 
        $displayReleaseDate = $seasonData ? ($seasonData['release_date'] ?? '') : ($movie['released'] ?? '');
        if (!empty($displayReleaseDate) && $displayReleaseDate !== '0000-00-00'): 
      ?>
        <p style="margin:0 0 1rem 0; color:#bbb; font-size:1rem;"><?= t('released') ?>: <?= htmlspecialchars($displayReleaseDate) ?></p>
      <?php endif; ?>
      <div style="margin:0.5rem 0 1rem 0;">
        <?php
          $cls = ($currentStatus === $statusInCompetition) ? 'in' : 'out';
        ?>
        <span id="compStatusBadge" class="badge <?= $cls ?>"><?= status_label($currentStatus) ?></span>
      </div>
      <div class="actions">
        <a class="btn btn-primary" href="vote.php?movie_id=<?= $movieId ?>"><?= t('vote') ?> ⭐</a>
      </div>
    </div>
  </div>

  <?php if ($canAdmin && $showAdminPanel): ?>
    <div class="admin-panel">
      <h3><?= t('admin_competition_status_title') ?></h3>
      <p style="margin-top:0; color:#ccc;"><?= t('admin_competition_status_desc') ?></p>
      <div style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:center;">
        <select id="adminCompStatus" style="padding:0.5rem; border-radius:6px; border:1px solid #555; background:#1a1a1a; color:#fff; min-width:200px;">
          <option value="<?= htmlspecialchars($statusInCompetition) ?>" <?= $currentStatus === $statusInCompetition ? 'selected' : '' ?>><?= htmlspecialchars($statusInCompetition) ?></option>
          <option value="<?= htmlspecialchars($statusOutCompetition) ?>" <?= $currentStatus === $statusOutCompetition ? 'selected' : '' ?>><?= htmlspecialchars($statusOutCompetition) ?></option>
        </select>
        <button class="btn btn-primary" onclick="saveAdminCompStatus()"><?= t('save_changes') ?></button>
        <span id="adminStatusMessage" style="color:#aaa;"></span>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
  const msgUpdatedForVotes = <?= json_encode(t('updated_for_votes')) ?>;
  const msgUpdateFailed = <?= json_encode(t('update_failed')) ?>;

  function saveAdminCompStatus() {
    var select = document.getElementById('adminCompStatus');
    var status = select.value;
    var message = document.getElementById('adminStatusMessage');
    message.textContent = '';

    var formData = new FormData();
    formData.append('movie_id', '<?= $movieId ?>');
    formData.append('status', status);

    fetch(window.location.origin + '/api/admin_update_competition_status.php', {
      method: 'POST',
      body: formData,
      credentials: 'include'
    }).then(function(response) {
      if (!response.ok) {
        return response.text().then(function(text) { throw new Error('HTTP ' + response.status + ': ' + text); });
      }
      return response.json();
    }).then(function(data) {
      if (data.success) {
        message.style.color = '#7bf3a1';
        message.textContent = (msgUpdatedForVotes || '').replace('%d', (data.updated_votes || 0));
        var badge = document.getElementById('compStatusBadge');
        if (badge) {
          badge.textContent = statusLabel(status);
          badge.className = 'badge ' + statusClass(status);
        }
      } else {
        message.style.color = '#ff9b9b';
        message.textContent = data.error || msgUpdateFailed || '';
      }
    }).catch(function(err) {
      message.style.color = '#ff9b9b';
      message.textContent = err.message;
    });
  }

  function statusLabel(status) {
    return status;  // Already translated dynamically
  }

  function statusClass(status) {
    if (status === '<?= htmlspecialchars($statusInCompetition) ?>') return 'badge in';
    return 'badge out';
  }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
