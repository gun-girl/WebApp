<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/includes/helper.php';
require_once __DIR__ . '/includes/omdb.php';
require_login();

$movieId = (int)($_GET['id'] ?? 0);
if ($movieId <= 0) {
    http_response_code(400);
    echo 'Invalid movie id';
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
    echo 'Movie not found';
    exit;
}

// Check if movie has been released yet
$today = date('Y-m-d');
$currentYear = (int)date('Y');
$isReleased = false;

if (!empty($movie['released']) && $movie['released'] !== '0000-00-00') {
  $isReleased = ($movie['released'] <= $today);
} else {
  $movieYear = (int)($movie['year'] ?? 0);
  $isReleased = ($movieYear <= $currentYear);
}

if (!$isReleased) {
  http_response_code(403);
  echo 'This title has not been released yet.';
  exit;
}

$currentUser = current_user();
$canAdmin = $currentUser && (($currentUser['role'] ?? 'user') === 'admin');

// Always compute status dynamically from active competition window
$currentStatus = is_in_competition($movie) ? 'In Competition' : 'Out of Competition';

$yearLabel = ($movie['type'] === 'series' && !empty($movie['start_year']))
    ? htmlspecialchars($movie['start_year']) . ((!empty($movie['end_year']) && $movie['end_year'] != $movie['start_year']) ? ' - ' . htmlspecialchars($movie['end_year']) : '')
    : htmlspecialchars($movie['year'] ?? '');

$poster = $movie['poster_url'] ?? '';
if (!$poster || $poster === 'N/A') {
    $poster = ADDRESS . '/assets/img/no-poster.svg';
}

// Map status to label
function status_label(string $status): string {
  if ($status === 'In Competition') return t('in_competition');
  return t('out_of_competition');
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
      <h1 style="margin:0 0 0.5rem 0; font-size:2rem; color:#f6c90e;"><?= htmlspecialchars($movie['title']) ?></h1>
      <p style="margin:0 0 0.5rem 0; color:#bbb; font-size:1rem;">Type: <?= htmlspecialchars(ucfirst($movie['type'] ?? '')) ?></p>
      <p style="margin:0 0 1rem 0; color:#bbb; font-size:1rem;">Year: <?= $yearLabel ?></p>
      <?php if (!empty($movie['released'])): ?>
        <p style="margin:0 0 1rem 0; color:#bbb; font-size:1rem;">Released: <?= htmlspecialchars($movie['released']) ?></p>
      <?php endif; ?>
      <div style="margin:0.5rem 0 1rem 0;">
        <?php
          $cls = ($currentStatus === 'In Competition') ? 'in' : 'out';
        ?>
        <span id="compStatusBadge" class="badge <?= $cls ?>"><?= status_label($currentStatus) ?></span>
      </div>
      <div class="actions">
        <a class="btn btn-primary" href="vote.php?movie_id=<?= $movieId ?>"><?= t('vote') ?> ⭐</a>
      </div>
    </div>
  </div>

  <?php if ($canAdmin): ?>
    <div class="admin-panel">
      <h3>Admin: Competition Status</h3>
      <p style="margin-top:0; color:#ccc;">Changing this will update the competition status for all votes of this title.</p>
      <div style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:center;">
        <select id="adminCompStatus" style="padding:0.5rem; border-radius:6px; border:1px solid #555; background:#1a1a1a; color:#fff; min-width:200px;">
          <option value="In Competition" <?= $currentStatus === 'In Competition' ? 'selected' : '' ?>>In Competition</option>
          <option value="Out of Competition" <?= $currentStatus === 'Out of Competition' ? 'selected' : '' ?>>Out of Competition</option>
        </select>
        <button class="btn btn-primary" onclick="saveAdminCompStatus()"><?= t('save_changes') ?></button>
        <span id="adminStatusMessage" style="color:#aaa;"></span>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
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
        message.textContent = 'Updated for ' + (data.updated_votes || 0) + ' vote(s).';
        var badge = document.getElementById('compStatusBadge');
        if (badge) {
          badge.textContent = statusLabel(status);
          badge.className = 'badge ' + statusClass(status);
        }
      } else {
        message.style.color = '#ff9b9b';
        message.textContent = data.error || 'Update failed';
      }
    }).catch(function(err) {
      message.style.color = '#ff9b9b';
      message.textContent = err.message;
    });
  }

  function statusLabel(status) {
    if (status === 'In Competition') return '<?= t('in_competition') ?>';
    return '<?= t('out_of_competition') ?>';
  }

  function statusClass(status) {
    if (status === 'In Competition') return 'badge in';
    return 'badge out';
  }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
