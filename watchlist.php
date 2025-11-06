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
    redirect('/movie-club-app/watchlist.php');
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

<style>
  body {
    background: radial-gradient(circle at top, #0a0a0a, #000);
    color: #eee;
    font-family: 'Poppins', system-ui, sans-serif;
    margin: 0;
  }
  
  .watchlist-container {
    max-width: 1400px;
    margin: 2rem auto;
    padding: 0 2rem;
  }
  
  .watchlist-header {
    margin-bottom: 2rem;
  }
  
  .watchlist-header h1 {
    color: #f6c90e;
    font-size: 2.5rem;
    margin-bottom: 0.5rem;
  }
  
  .watchlist-header p {
    color: #999;
    font-size: 1.1rem;
  }
  
  .watchlist-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 2rem;
    margin-top: 2rem;
  }
  
  .watchlist-card {
    background: #111;
    border-radius: 0.75rem;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.4);
    transition: transform .3s ease, box-shadow .3s ease;
    position: relative;
  }
  
  .watchlist-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 6px 25px rgba(246,201,14,0.4);
  }
  
  .watchlist-card img {
    width: 100%;
    height: 300px;
    object-fit: cover;
    display: block;
  }
  
  .watchlist-card-content {
    padding: 1rem;
  }
  
  .watchlist-card-content h3 {
    font-size: 1rem;
    color: #fff;
    margin-bottom: .3rem;
  }
  
  .watchlist-card-content p {
    margin: .2rem 0;
    color: #aaa;
    font-size: .85rem;
  }
  
  .watchlist-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 1rem;
  }
  
  .btn {
    background: #f6c90e;
    color: #000;
    padding: .5rem 1rem;
    border: none;
    border-radius: .3rem;
    font-weight: 600;
    text-decoration: none;
    transition: background .3s;
    cursor: pointer;
    font-size: .85rem;
    display: inline-block;
    text-align: center;
  }
  
  .btn:hover {
    background: #ffde50;
  }
  
  .btn-secondary {
    background: #333;
    color: #fff;
  }
  
  .btn-secondary:hover {
    background: #444;
  }
  
  .empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: #666;
  }
  
  .empty-state h2 {
    color: #999;
    font-size: 1.8rem;
    margin-bottom: 1rem;
  }
  
  .empty-state p {
    font-size: 1.1rem;
    margin-bottom: 2rem;
  }
  
  .flash {
    background: #f6c90e;
    color: #000;
    padding: 1rem;
    border-radius: .5rem;
    margin-bottom: 1rem;
    font-weight: 600;
  }
  
  .remove-btn {
    background: #dc3545;
    color: #fff;
  }
  
  .remove-btn:hover {
    background: #c82333;
  }
</style>

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
      <a href="/movie-club-app/index.php" class="btn"><?= e(t('browse_movies')) ?></a>
    </div>
  <?php else: ?>
    <div class="watchlist-grid">
      <?php foreach ($watchlist_items as $item): ?>
        <div class="watchlist-card">
          <img src="<?= htmlspecialchars($item['poster_url'] ?: '/movie-club-app/assests/img/no-poster.png') ?>" alt="<?= htmlspecialchars($item['title']) ?>">
          <div class="watchlist-card-content">
            <h3><?= htmlspecialchars($item['title']) ?></h3>
            <p><?= htmlspecialchars($item['year']) ?></p>
            <p style="color: #666; font-size: 0.75rem;">Added: <?= date('M d, Y', strtotime($item['added_at'])) ?></p>
            
            <div class="watchlist-actions">
              <a href="/movie-club-app/vote.php?movie_id=<?= $item['movie_id'] ?>" class="btn"><?= e(t('rate')) ?></a>
              <form method="post" style="display: inline;">
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
