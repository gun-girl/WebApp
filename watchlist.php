<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/lang.php';
require_login();

$user = current_user();

// Handle adding/removing from watchlist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['movie_id'])) {
    verify_csrf();
    $movie_id = (int)$_POST['movie_id'];
    $action = $_POST['action'] ?? 'add';
    
    if ($action === 'add') {
        // Add to watchlist
        $stmt = $mysqli->prepare("INSERT IGNORE INTO watchlist (user_id, movie_id, added_at) VALUES (?, ?, NOW())");
        $stmt->bind_param('ii', $user['id'], $movie_id);
        $stmt->execute();
        $message = 'Added to watchlist!';
    } else {
        // Remove from watchlist
        $stmt = $mysqli->prepare("DELETE FROM watchlist WHERE user_id = ? AND movie_id = ?");
        $stmt->bind_param('ii', $user['id'], $movie_id);
        $stmt->execute();
        $message = 'Removed from watchlist!';
    }
    
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['flash'] = $message;
    redirect(ADDRESS.'/watchlist.php');
}

// Get user's watchlist
$stmt = $mysqli->prepare("
    SELECT w.id as watchlist_id, m.id as movie_id, m.title, m.year, m.poster_url, w.added_at
    FROM watchlist w
    JOIN movies m ON m.id = w.movie_id
    WHERE w.user_id = ?
    ORDER BY w.added_at DESC
");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$watchlist_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

include __DIR__.'/includes/header.php';
?>
<?php /* page styles moved to assets/css/style.css */ ?>

<div class="watchlist-container">
  <?php
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  if (!empty($_SESSION['flash'])) {
    echo '<p class="flash">' . e($_SESSION['flash']) . '</p>';
    unset($_SESSION['flash']);
  }
  ?>
  
  <div class="watchlist-header">
    <h1>📋 <?= e(t('watchlist')) ?></h1>
    <p><?= e(t('your_saved_movies')) ?> (<?= count($watchlist_items) ?> <?= e(t('movies')) ?>)</p>
  </div>
  
  <?php if (empty($watchlist_items)): ?>
    <div class="empty-state">
      <h2><?= e(t('watchlist_empty')) ?></h2>
      <p><?= e(t('watchlist_empty_desc')) ?></p>
      <a href="<?= ADDRESS ?>/index.php" class="btn"><?= e(t('browse_movies')) ?></a>
    </div>
  <?php else: ?>
    <div class="watchlist-grid">
      <?php foreach ($watchlist_items as $item): ?>
        <div class="watchlist-card">
          <?php $poster = $item['poster_url']; if(!$poster || $poster==='N/A'){ $poster=ADDRESS.'/assets/img/no-poster.svg'; } ?>
          <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($item['title']) ?>" onerror="this.onerror=null;this.src=ADDRESS.'/assets/img/no-poster.svg';">
          <div class="watchlist-card-content">
            <h3><?= htmlspecialchars($item['title']) ?></h3>
            <p><?= htmlspecialchars($item['year']) ?></p>
            <p class="watchlist-date">Added: <?= date('M d, Y', strtotime($item['added_at'])) ?></p>
            
            <div class="watchlist-actions">
              <a href="<?= ADDRESS ?>/vote.php?movie_id=<?= $item['movie_id'] ?>" class="btn"><?= e(t('rate')) ?></a>
              <form method="post" class="watchlist-form-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="movie_id" value="<?= $item['movie_id'] ?>">
                <input type="hidden" name="action" value="remove">
                <button type="submit" class="btn remove-btn" onclick="return confirm('<?= e(t('remove_from_watchlist_confirm')) ?>')">✕</button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__.'/includes/footer.php'; ?>
