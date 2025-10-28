<?php 
require_once __DIR__ . '/includes/auth.php';

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
  if ($username === '' || $email === '' || $pass === '') $errors[] = 'All fields are required';
  if (!$errors) {
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    try {
      $stmt = $mysqli->prepare("INSERT INTO users(username,email,password_hash) VALUES(?,?,?)");
      $stmt->bind_param('sss', $username, $email, $hash);
      $stmt->execute();
      $id = $stmt->insert_id;
      login_user(['id' => $id, 'username' => $username, 'email' => $email]);
      redirect('/movie-club-app/index.php');
    } catch (mysqli_sql_exception $e) {
      $errors[] = str_contains($e->getMessage(), 'Duplicate') ? 'Username or email already exists' : 'DB error';
    }
  }
}
include __DIR__ . '/includes/header.php'; ?>
<h2>Register</h2>
<?php foreach ($errors as $er) echo '<p class="error">' . e($er) . '</p>'; ?>
<form method="post">
  <?= csrf_field() ?>
  <label>Username <input name="username" required></label>
  <label>Email <input type="email" name="email" required></label>
  <label>Password <input type="password" name="password" required></label>
  <button type="submit">Create account</button>
</form>
<?php include __DIR__ . '/includes/footer.php';
