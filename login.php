<?php require_once __DIR__.'/includes/auth.php';

$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  verify_csrf();
  $email=trim($_POST['email']??''); $pass=$_POST['password']??'';
  $stmt=$mysqli->prepare("SELECT id,username,email,password_hash FROM users WHERE email=?");
  $stmt->bind_param('s',$email); $stmt->execute();
  $res=$stmt->get_result()->fetch_assoc();
  if ($res && password_verify($pass,$res['password_hash'])) {
    login_user($res); redirect('/movie-club-app/index.php');
  } else $errors[]='Invalid email or password';
}
include __DIR__.'/includes/header.php'; ?>
<h2>Login</h2>
<?php foreach($errors as $er) echo '<p class="error">'.e($er).'</p>'; ?>
<form method="post">
  <?= csrf_field() ?>
  <label>Email <input type="email" name="email" required></label>
  <label>Password <input type="password" name="password" required></label>
  <button type="submit">Login</button>
</form>
<?php include __DIR__.'/includes/footer.php';
