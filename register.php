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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Register – IL DIVANO D'ORO</title>
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
</head>
<body>
  <header>
    <h1>🎬 IL DIVANO D'ORO</h1>
    <nav>
      <a href="/movie-club-app/register.php">Register</a>
      | <a href="/movie-club-app/login.php">Login</a>
      | <a href="/movie-club-app/index.php">🏠 Home</a>
    </nav>
  </header>

  <div class="register-container">
    <div class="register-box">
      <h2>Register</h2>
      <?php foreach ($errors as $er): ?>
        <p class="error"><?= htmlspecialchars($er) ?></p>
      <?php endforeach; ?>
      <form method="post">
        <?= csrf_field() ?>
        <label>Username
          <input name="username" placeholder="Choose a username" required>
        </label>
        <label>Email
          <input type="email" name="email" placeholder="you@example.com" required>
        </label>
        <label>Password
          <input type="password" name="password" placeholder="••••••••" required>
        </label>
        <button type="submit">Create account</button>
        <a href="/movie-club-app/login.php" class="btn secondary">Already have an account?</a>
      </form>
    </div>
  </div>

  <footer>© IL DIVANO D'ORO 2025 — All rights reserved.</footer>
</body>
</html>
