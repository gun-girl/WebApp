<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/lang.php';
require_login();

$user = current_user();
$errors = [];
$success = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    
  // If changing password
  if (!empty($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if ($new === '' || $confirm === '' || $current === '') {
      $errors[] = 'All password fields are required';
    } elseif ($new !== $confirm) {
      $errors[] = 'New password and confirmation do not match';
    } elseif (strlen($new) < 6) {
      $errors[] = 'New password must be at least 6 characters';
    } else {
      // Verify current password
      $stmt = $mysqli->prepare("SELECT password_hash FROM users WHERE id=?");
      $stmt->bind_param('i', $user['id']);
      $stmt->execute();
      $row = $stmt->get_result()->fetch_assoc();
      if (!$row || !password_verify($current, $row['password_hash'])) {
        $errors[] = 'Current password is incorrect';
      } else {
        $newHash = password_hash($new, PASSWORD_DEFAULT);
        $stmt2 = $mysqli->prepare("UPDATE users SET password_hash=? WHERE id=?");
        $stmt2->bind_param('si', $newHash, $user['id']);
        $stmt2->execute();
        $success = 'Password updated successfully';
      }
    }
  } else {
    // Standard profile update (username/email)
    $new_email = trim($_POST['email'] ?? '');
    $new_username = trim($_POST['username'] ?? '');
        
    if (empty($new_email) || empty($new_username)) {
      $errors[] = t('all_fields_required');
    } else {
      try {
        $stmt = $mysqli->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
        $stmt->bind_param('ssi', $new_username, $new_email, $user['id']);
        $stmt->execute();
                
        // Update session
        $_SESSION['user']['username'] = $new_username;
        $_SESSION['user']['email'] = $new_email;
        $user = current_user();
                
        $success = t('profile_updated');
      } catch (mysqli_sql_exception $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
          $errors[] = t('username_email_exists');
        } else {
          $errors[] = t('error_updating_profile');
        }
      }
    }
  }
}

// Get user's votes count for the current calendar year (treat calendar year as the active year for user-facing counts)
$active_year = (int)date('Y');
// Safely count votes for the active year. Some installations may not have a competition_year column,
// so detect the column and use COALESCE(competition_year, YEAR(created_at)) when present, otherwise
// fall back to YEAR(created_at).
$hasVotesYearCol = $mysqli->query("SHOW COLUMNS FROM votes LIKE 'competition_year'")->fetch_all(MYSQLI_ASSOC);
if ($hasVotesYearCol) {
  $stmt = $mysqli->prepare("SELECT COUNT(*) as vote_count FROM votes WHERE user_id = ? AND COALESCE(competition_year, YEAR(created_at)) = ?");
} else {
  $stmt = $mysqli->prepare("SELECT COUNT(*) as vote_count FROM votes WHERE user_id = ? AND YEAR(created_at) = ?");
}
$stmt->bind_param('ii', $user['id'], $active_year);
$stmt->execute();
$vote_data = $stmt->get_result()->fetch_assoc();
$vote_count = $vote_data['vote_count'] ?? 0;

include __DIR__.'/includes/header.php';
?>
<?php /* page styles moved to assets/css/style.css */ ?>

<div class="profile-container">
    <div class="profile-box">
      <h2>👤 <?= t('my_profile') ?></h2>
      
      <?php if ($success): ?>
        <p class="success"><?= htmlspecialchars($success) ?></p>
      <?php endif; ?>
      
      <?php foreach ($errors as $er): ?>
        <p class="error"><?= htmlspecialchars($er) ?></p>
      <?php endforeach; ?>

      <div class="profile-info">
        <div class="info-card">
          <h3><?= t('username') ?></h3>
          <p><?= htmlspecialchars($user['username']) ?></p>
        </div>
        
        <div class="info-card">
          <h3><?= t('email') ?></h3>
          <p><?= htmlspecialchars($user['email']) ?></p>
        </div>
        
        <div class="info-card">
          <h3><?= t('total_votes') ?></h3>
          <p><?= $vote_count ?> <?= t('movies_voted') ?></p>
        </div>
        
        <?php if (function_exists('is_admin') && is_admin()): ?>
        <div class="info-card admin-card">
          <h3> Admin Status</h3>
          <p>You are an admin</p>
        </div>
        <?php endif; ?>
      </div>

      <div class="profile-spacing">
        <a href="stats.php?mine=1&year=<?= (int)$active_year ?>" class="btn"><?= t('view_my_votes') ?></a>
        <a href="logout.php" class="btn btn-logout"><?= t('sign_out') ?></a>
      </div>
      
      <div class="profile-lang-section">
        <h3>🌐 <?= t('language') ?></h3>
        <div class="lang-buttons">
          <?php
            $current = current_lang();
            $q = $_GET;
            unset($q['lang']);
            $qs = http_build_query($q);
            $url_en = $_SERVER['PHP_SELF'] . '?lang=en' . ($qs ? '&'.$qs : '');
            $url_it = $_SERVER['PHP_SELF'] . '?lang=it' . ($qs ? '&'.$qs : '');
          ?>
          <a href="<?= e($url_en) ?>" class="lang-btn <?= $current === 'en' ? 'active' : '' ?>">English</a>
          <a href="<?= e($url_it) ?>" class="lang-btn <?= $current === 'it' ? 'active' : '' ?>">Italiano</a>
        </div>
      </div>
    </div>

    <div class="profile-box">
      <h2> <?= t('edit_profile') ?></h2>
      <form method="post">
        <?= csrf_field() ?>
        <label><?= t('username') ?>
          <input name="username" value="<?= htmlspecialchars($user['username']) ?>" required>
        </label>
        <label><?= t('email') ?>
          <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
        </label>
        <button type="submit"><?= t('save_changes') ?></button>
        <a href="index.php" class="btn secondary"><?= t('cancel') ?></a>
      </form>
    </div>

    <div class="profile-box">
      <h2>🔒 Change Password</h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="change_password" value="1">
        <div class="grid-2">
          <label>Current Password
            <input type="password" name="current_password" placeholder="Enter current password" required>
          </label>
          <label>New Password
            <input type="password" name="new_password" placeholder="Enter new password" required>
          </label>
        </div>
        <label>Confirm New Password
          <input type="password" name="confirm_password" placeholder="Re-type new password" required>
        </label>
        <button type="submit">Update Password</button>
      </form>
    </div>
  </div>

<?php include __DIR__.'/includes/footer.php'; ?>
