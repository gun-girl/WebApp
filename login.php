<?php 
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/lang.php';

$errors=[];
$notices=[];

// Determine which view to show
$tokenEmail = null;
if (isset($_GET['token']) && isset($_GET['email'])) {
  $token = $_GET['token'];
  $email = $_GET['email'];
  
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Invalid email address.';
    $mode = 'login';
  } else {
    // Validate token exists and hasn't expired
    $tokenHash = hash('sha256', $token);
    $stmt = $mysqli->prepare("SELECT id FROM password_resets WHERE email = ? AND token_hash = ? AND expires_at > NOW() LIMIT 1");
    $stmt->bind_param('ss', $email, $tokenHash);
    $stmt->execute();
    $reset_rec = $stmt->get_result()->fetch_assoc();
    
    if (!$reset_rec) {
      $errors[] = 'Invalid or expired reset link. Please request a new one.';
      $mode = 'login';
    } else {
      $tokenEmail = $email;
      $mode = 'reset_password';
    }
  }
} else {
  $mode = isset($_GET['reset']) ? 'request_reset' : 'login';
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $action = $_POST['action'] ?? 'login';
  verify_csrf();

  if (empty($errors)) {
    if ($action === 'request_reset') {
      $email = trim($_POST['email'] ?? '');
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email.';
      } else {
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE LOWER(email)=LOWER(?) LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user) {
          $mysqli->query("CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(email), INDEX(expires_at)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

          $token = bin2hex(random_bytes(32));
          $tokenHash = hash('sha256', $token);
          $expires = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');
          $ins = $mysqli->prepare("INSERT INTO password_resets(email, token_hash, expires_at) VALUES(?,?,?)");
          $ins->bind_param('sss', $email, $tokenHash, $expires);
          $ins->execute();

          $baseUrl = get_base_url();
          $resetLink = $baseUrl . '/login.php?token=' . urlencode($token) . '&email=' . urlencode($email);
          $subject = 'Password reset instructions';
          $body = "Hi,\n\nWe received a request to reset your password. Click the link below to choose a new one. This link expires in 1 hour.\n\n$resetLink\n\nIf you did not request this, you can safely ignore this email.";
          @mail($email, $subject, $body, 'From: no-reply@divanodoro.it');
        }
        $notices[] = 'A reset link has been sent to your email.';
      }
      $mode = 'request_reset';

    } elseif ($action === 'reset_password') {
      $email = trim($_POST['email'] ?? '');
      $newPass = $_POST['password'] ?? '';
      $confirmPass = $_POST['password_confirm'] ?? '';

      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
      } elseif (empty($newPass)) {
        $errors[] = 'Password cannot be empty.';
      } elseif ($newPass !== $confirmPass) {
        $errors[] = 'Passwords do not match.';
      } elseif (strlen($newPass) < 6) {
        $errors[] = 'Password must be at least 6 characters long.';
      } else {
        $token = $_POST['token'] ?? '';
        $tokenHash = hash('sha256', $token);
        $stmt = $mysqli->prepare("SELECT id FROM password_resets WHERE email = ? AND token_hash = ? AND expires_at > NOW() LIMIT 1");
        $stmt->bind_param('ss', $email, $tokenHash);
        $stmt->execute();
        $reset_rec = $stmt->get_result()->fetch_assoc();

        if (!$reset_rec) {
          $errors[] = 'Invalid or expired reset link. Please request a new one.';
        } else {
          $hashedPass = password_hash($newPass, PASSWORD_DEFAULT);
          $upd = $mysqli->prepare("UPDATE users SET password_hash = ? WHERE LOWER(email) = LOWER(?)");
          $upd->bind_param('ss', $hashedPass, $email);
          $upd->execute();

          $del = $mysqli->prepare("DELETE FROM password_resets WHERE email = ?");
          $del->bind_param('s', $email);
          $del->execute();

          $notices[] = 'Your password has been reset successfully. You can now log in.';
          $mode = 'login';
        }
      }

    } elseif ($action === 'login') {
      $email=trim($_POST['email']??'');
      $pass=$_POST['password']??'';

      if (!$mysqli->ping()) {
        $errors[] = 'Database connection failed. Please try again later.';
      } else {
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
        } else {
          if (!$hasRole) {
            $res['role'] = 'user';
          }

          if (password_verify($pass,$res['password_hash'])) {
            login_user($res);
            redirect(ADDRESS.'/index.php');
          } else {
            $errors[] = 'Incorrect password.';
          }
        }
      }
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
      <?php foreach($notices as $msg): ?>
        <p class="success"><?= htmlspecialchars($msg) ?></p>
      <?php endforeach; ?>

      <?php if ($mode === 'login'): ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="login">
          <label><?= t('email') ?>
            <input type="email" name="email" placeholder="<?= t('email_placeholder') ?>" required autocomplete="email">
          </label>
          <label><?= t('password') ?>
            <input type="password" name="password" placeholder="<?= t('password_placeholder') ?>" required autocomplete="current-password">
          </label>
          <button type="submit"><?= t('login') ?></button>
          <div style="margin-top: 1rem; text-align: center;">
            <a href="<?= ADDRESS ?>/login.php?reset=1" style="font-size: 0.9rem; color: #666;"><?= t('forgot_password') ?? 'Forgot Password?' ?></a>
          </div>
          <a href="<?= ADDRESS ?>/register.php" class="btn secondary"><?= t('create_account') ?></a>
        </form>
      <?php elseif ($mode === 'request_reset'): ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="request_reset">
          <label><?= t('email') ?>
            <input type="email" name="email" placeholder="<?= t('email_placeholder') ?>" required autocomplete="email">
          </label>
          <button type="submit">Send reset link</button>
          <div style="margin-top: 1rem; text-align: center;">
            <a href="<?= ADDRESS ?>/login.php" style="font-size: 0.9rem; color: #666;">Back to login</a>
          </div>
        </form>
      <?php elseif ($mode === 'reset_password'): ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="reset_password">
          <input type="hidden" name="email" value="<?= htmlspecialchars($tokenEmail) ?>">
          <input type="hidden" name="token" value="<?= htmlspecialchars($_GET['token'] ?? '') ?>">
          <label>New Password
            <input type="password" name="password" placeholder="Enter new password" required autocomplete="new-password">
          </label>
          <label>Confirm Password
            <input type="password" name="password_confirm" placeholder="Confirm new password" required autocomplete="new-password">
          </label>
          <button type="submit">Reset Password</button>
          <div style="margin-top: 1rem; text-align: center;">
            <a href="<?= ADDRESS ?>/login.php" style="font-size: 0.9rem; color: #666;">Back to login</a>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

<?php include __DIR__.'/includes/footer.php'; ?>
