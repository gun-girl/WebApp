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

// Get user's votes count
$stmt = $mysqli->prepare("SELECT COUNT(*) as vote_count FROM votes WHERE user_id = ?");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$vote_data = $stmt->get_result()->fetch_assoc();
$vote_count = $vote_data['vote_count'];
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>">
<head>
  <meta charset="UTF-8">
  <title><?= t('profile') ?> – <?= t('site_title') ?></title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Poppins', system-ui, sans-serif;
      background: radial-gradient(circle at top, #0c0c0c, #000);
      color: #eee;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    header {
      background: rgba(0,0,0,0.8);
      backdrop-filter: blur(8px);
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

    .profile-container {
      flex: 1;
      padding: 2rem;
      max-width: 800px;
      margin: auto;
      width: 100%;
    }
    .profile-box {
      background: #111;
      border-radius: 0.75rem;
      padding: 2rem;
      box-shadow: 0 4px 20px rgba(0,0,0,.5);
      margin-bottom: 2rem;
    }
    .profile-box h2 {
      color: #f6c90e;
      margin-bottom: 1.5rem;
      font-size: 1.8rem;
    }
    .profile-info {
      display: grid;
      gap: 1.5rem;
    }
    .info-card {
      background: #1a1a1a;
      padding: 1.5rem;
      border-radius: .5rem;
      border-left: 4px solid #f6c90e;
    }
    .info-card h3 {
      color: #f6c90e;
      font-size: 1rem;
      margin-bottom: .5rem;
      text-transform: uppercase;
      letter-spacing: 1px;
    }
    .info-card p {
      color: #ccc;
      font-size: 1.1rem;
    }
    .success {
      background: #1a5;
      color: #efe;
      padding: .8rem;
      border-radius: .3rem;
      margin-bottom: 1rem;
    }
    .error {
      background: #612;
      color: #fee;
      padding: .8rem;
      border-radius: .3rem;
      margin-bottom: 1rem;
    }
    label {
      display: block;
      margin-bottom: 1rem;
      color: #ccc;
      font-weight: 500;
    }
    input {
      display: block;
      width: 100%;
      padding: .7rem;
      margin-top: .3rem;
      border: none;
      border-radius: .3rem;
      background: #222;
      color: #fff;
      font-size: 1rem;
    }
    button, .btn {
      display: inline-block;
      padding: .7rem 1.5rem;
      margin-top: .5rem;
      margin-right: .5rem;
      background: #f6c90e;
      color: #000;
      border: none;
      border-radius: .3rem;
      cursor: pointer;
      font-weight: 600;
      font-size: 1rem;
      text-align: center;
      text-decoration: none;
      transition: background .2s;
    }
    button:hover, .btn:hover { background: #ffde50; }
    .btn.secondary {
      background: #444;
      color: #fff;
    }
    .btn.secondary:hover { background: #555; }
    
    footer {
      text-align: center;
      color: #555;
      padding: 1.5rem 0;
      font-size: .9rem;
      border-top: 1px solid #222;
    }
  </style>
</head>
<body>
  <header>
    <div class="header-logo">
      <img src="/movie-club-app/assests/img/logo.png" alt="<?= t('site_title') ?>">
    </div>
    <nav>
      <span><?= t('hello') ?>, <?= htmlspecialchars($user['username']) ?></span>
      | <a href="logout.php"><?= t('logout') ?></a>
      | <a href="stats.php"><?= t('results') ?></a>
      | <a href="index.php">🏠 <?= t('home') ?></a>
      | <a href="?lang=en"><?= t('lang_en') ?></a>
      | <a href="?lang=it"><?= t('lang_it') ?></a>
    </nav>
  </header>

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
          <p><?= $vote_count ?> <?= t('movies_rated') ?></p>
        </div>
      </div>

      <div style="margin-top: 1.5rem;">
        <a href="stats.php?mine=1" class="btn"><?= t('view_my_votes') ?></a>
      </div>
    </div>

    <div class="profile-box">
      <h2>✏️ <?= t('edit_profile') ?></h2>
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
  </div>

  <footer><?= t('footer_text') ?></footer>
</body>
</html>
