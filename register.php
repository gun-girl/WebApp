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
      redirect('/movie-club-app/index.php');
    } catch (mysqli_sql_exception $e) {
      $errors[] = str_contains($e->getMessage(), 'Duplicate') ? t('username_email_exists') : 'DB error';
    }
  }
}
?>
<?php include __DIR__.'/includes/header.php'; ?>
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
    /* header/nav styles are provided by shared header */

    .register-container {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2rem;
    }
    .register-box {
      background: #111;
      border-radius: 0.75rem;
      padding: 2rem;
      max-width: 420px;
      width: 100%;
      box-shadow: 0 4px 20px rgba(0,0,0,.5);
    }
    .register-box h2 {
      color: #f6c90e;
      margin-bottom: 1.5rem;
      font-size: 1.8rem;
      text-align: center;
    }
    .error {
      background: #612;
      color: #fee;
      padding: .6rem;
      border-radius: .3rem;
      margin-bottom: 1rem;
      font-size: .9rem;
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
    input::placeholder { color: #777; }
    button, .btn {
      display: inline-block;
      width: 100%;
      padding: .7rem;
      margin-top: .5rem;
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
</style>

  <div class="register-container">
    <div class="register-box">
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
        <a href="/movie-club-app/login.php" class="btn secondary"><?= t('already_have_account') ?></a>
      </form>
    </div>
  </div>

<?php include __DIR__.'/includes/footer.php'; ?>
