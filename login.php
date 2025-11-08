<?php 
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/lang.php';

$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  verify_csrf();
  $email=trim($_POST['email']??''); $pass=$_POST['password']??'';
  // Be backward-compatible if the 'role' column hasn't been added yet
  $hasRole = false;
  try {
    $resCols = $mysqli->query("SHOW COLUMNS FROM users LIKE 'role'");
    $hasRole = $resCols && $resCols->num_rows > 0;
  } catch (Throwable $e) { /* ignore */ }

  $sql = $hasRole
    ? "SELECT id,username,email,password_hash,role FROM users WHERE email=?"
    : "SELECT id,username,email,password_hash FROM users WHERE email=?";
  $stmt=$mysqli->prepare($sql);
  $stmt->bind_param('s',$email); $stmt->execute();
  $res=$stmt->get_result()->fetch_assoc();
  if ($res && !$hasRole) { $res['role'] = 'user'; }
  if ($res && password_verify($pass,$res['password_hash'])) {
  login_user($res); redirect('/movie-club-app/index.php');
  } else $errors[]=t('invalid_credentials');
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

  /* Uses shared container/card styles from style.css via .container-800 and .card-box */
  /* Redundant explicit widths to guarantee visual match if any cache/override remains */
  .login-container{ max-width:800px; margin:0 auto; width:100%; padding:2rem; }
  .login-box{ max-width:800px; min-width:800px; width:100%; margin:0 auto; }
    .login-box h2 {
      color: #f6c90e;
      margin-bottom: 1.5rem; /* match profile */
      font-size: 1.8rem;     /* match profile */
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
      padding: .7rem;        /* match profile */
      margin-top: .3rem;
      border: none;
      border-radius: .3rem;
      background: #222;
      color: #fff;
      font-size: 1rem;       /* match profile */
    }
    input::placeholder { color: #777; }
    button, .btn {
      display: inline-block;
      width: 100%;
      padding: .7rem;        /* match profile */
      margin-top: .5rem;
      background: #f6c90e;
      color: #000;
      border: none;
      border-radius: .3rem;
      cursor: pointer;
      font-weight: 600;
      font-size: 1rem;       /* match profile */
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

  <div class="login-container container-800">
    <div class="login-box card-box">
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
        <a href="/movie-club-app/register.php" class="btn secondary"><?= t('create_account') ?></a>
      </form>
    </div>
  </div>

<?php include __DIR__.'/includes/footer.php'; ?>
