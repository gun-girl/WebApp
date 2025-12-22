<?php 
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/lang.php';

$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  verify_csrf();
  $email=trim($_POST['email']??''); $pass=$_POST['password']??'';
  
  // Check database connection
  if (!$mysqli->ping()) {
    $errors[] = 'Database connection failed. Please try again later.';
  } else {
    // Be backward-compatible if the 'role' column hasn't been added yet
    $hasRole = false;
    try {
      $resCols = $mysqli->query("SHOW COLUMNS FROM users LIKE 'role'");
      $hasRole = $resCols && $resCols->num_rows > 0;
    } catch (Throwable $e) { /* ignore */ }

    $sql = $hasRole
      ? "SELECT id,username,email,password_hash,role FROM users WHERE LOWER(email)=LOWER(?)"
      : "SELECT id,username,email,password_hash FROM users WHERE LOWER(email)=LOWER(?)";
    $stmt=$mysqli->prepare($sql);
    $stmt->bind_param('s',$email); 
    $stmt->execute();
    $res=$stmt->get_result()->fetch_assoc();
    
    if (!$res) {
      $errors[] = 'No account found with that email address.';
    } elseif (!$hasRole) {
      $res['role'] = 'user';
    }
    
    if ($res && password_verify($pass,$res['password_hash'])) {
      login_user($res); redirect(ADDRESS.'/index.php');
    } elseif ($res) {
      $errors[] = 'Incorrect password.';
    }
  }
}
?>
<?php include __DIR__.'/includes/header.php'; ?>
<?php /* page styles moved to assets/css/style.css */ ?>

  <div class="auth-shell">
    <div class="login-box card-box auth-card">
      <h2><?= t('login') ?></h2>
      <?php foreach($errors as $er): ?>
        <p class="error"><?= htmlspecialchars($er) ?></p>
      <?php endforeach; ?>
      <form method="post">
        <?= csrf_field() ?>
        <label><?= t('email') ?>
          <input type="email" name="email" placeholder="<?= t('email_placeholder') ?>" required>
        </label>
        <label><?= t('password') ?>
          <input type="password" name="password" placeholder="<?= t('password_placeholder') ?>" required>
        </label>
        <button type="submit"><?= t('login') ?></button>
        <div style="margin-top: 1rem; text-align: center;">
          <a href="<?= ADDRESS ?>/reset_password.php" style="font-size: 0.9rem; color: #666;"><?= t('forgot_password') ?? 'Forgot Password?' ?></a>
        </div>
        <a href="<?= ADDRESS ?>/register.php" class="btn secondary"><?= t('create_account') ?></a>
      </form>
    </div>
  </div>

<?php include __DIR__.'/includes/footer.php'; ?>
