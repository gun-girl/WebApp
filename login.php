<?php 
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/lang.php';

$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  verify_csrf();
  $email=trim($_POST['email']??''); $pass=$_POST['password']??'';
  $stmt=$mysqli->prepare("SELECT id,username,email,password_hash FROM users WHERE email=?");
  $stmt->bind_param('s',$email); $stmt->execute();
  $res=$stmt->get_result()->fetch_assoc();
  if ($res && password_verify($pass,$res['password_hash'])) {
    login_user($res); redirect('/movie-club-app/index.php');
  } else $errors[]=t('invalid_credentials');
}
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>">
<head>
  <meta charset="UTF-8">
  <title><?= t('login') ?> – <?= t('site_title') ?></title>
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

    .login-container {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2rem;
    }
    .login-box {
      background: #111;
      border-radius: 0.75rem;
      padding: 2rem;
      max-width: 420px;
      width: 100%;
      box-shadow: 0 4px 20px rgba(0,0,0,.5);
    }
    .login-box h2 {
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
    <div class="header-logo">
      <img src="/movie-club-app/assests/img/logo.png" alt="<?= t('site_title') ?>">
    </div>
    <nav>
      <a href="/movie-club-app/register.php"><?= t('register') ?></a>
      | <a href="/movie-club-app/login.php"><?= t('login') ?></a>
      | <a href="/movie-club-app/index.php">🏠 <?= t('home') ?></a>
      | <a href="?lang=en"><?= t('lang_en') ?></a>
      | <a href="?lang=it"><?= t('lang_it') ?></a>
    </nav>
  </header>

  <div class="login-container">
    <div class="login-box">
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

  <footer><?= t('footer_text') ?></footer>
</body>
</html>
