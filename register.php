<?php 
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lang.php';

$errors = [];
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Provide a fallback verify_csrf() if it's not defined by included files.
  if (!function_exists('verify_csrf')) {
    function verify_csrf() {
      if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
      }
      global $errors;
      // Expect a csrf_token field from the form; on mismatch record an error.
      if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $errors[] = 'Invalid CSRF token';
      }
    }
  }
  verify_csrf();
  $username = trim($_POST['username'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $pass = $_POST['password'] ?? '';
  if ($username === '' || $email === '' || $pass === '') $errors[] = t('all_fields_required');
  if (!$errors) {
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    try {
      $role = 'user';
      $stmt = $mysqli->prepare("INSERT INTO users(username,email,password_hash,role) VALUES(?,?,?,?)");
      $stmt->bind_param('ssss', $username, $email, $hash, $role);
      $stmt->execute();
      $id = $stmt->insert_id;
      login_user(['id' => $id, 'username' => $username, 'email' => $email, 'role' => $role]);
      redirect(ADDRESS.'/index.php');
    } catch (mysqli_sql_exception $e) {
      $errors[] = str_contains($e->getMessage(), 'Duplicate') ? t('username_email_exists') : 'DB error';
    }
  }
}
?>
<?php include __DIR__.'/includes/header.php'; ?>
<?php /* page styles moved to assets/css/style.css */ ?>

  <div class="auth-shell">
    <div class="register-box card-box auth-card">
      <h2><?= t('register') ?></h2>
      <?php foreach ($errors as $er): ?>
        <p class="error"><?= htmlspecialchars($er) ?></p>
      <?php endforeach; ?>
      <form method="post">
        <?= csrf_field() ?>
        <label><?= t('username') ?>
          <input name="username" placeholder="<?= t('username_placeholder') ?>" required>
        </label>
        <label><?= t('email') ?>
          <input type="email" name="email" placeholder="<?= t('email_placeholder') ?>" required>
        </label>
        <label><?= t('password') ?>
          <input type="password" name="password" placeholder="<?= t('password_placeholder') ?>" required>
        </label>
        <button type="submit"><?= t('create_account') ?></button>
        <a href="<?= ADDRESS ?>/login.php" class="btn secondary"><?= t('already_have_account') ?></a>
      </form>
    </div>
  </div>

<?php include __DIR__.'/includes/footer.php'; ?>
