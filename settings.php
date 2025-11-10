<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/lang.php';

if (!function_exists('is_admin') || !is_admin()) {
    redirect('/movie-club-app/index.php');
}

$errors = [];
$success = '';
$active_year = function_exists('get_active_year') ? get_active_year() : (int)date('Y');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $year = (int)($_POST['active_year'] ?? 0);
    if ($year < 2000 || $year > 3000) {
        $errors[] = 'Please provide a valid year';
    } else {
        // Upsert settings.active_year
        $stmt = $mysqli->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('active_year', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        if ($stmt) {
            $val = (string)$year;
            $stmt->bind_param('s', $val);
            $stmt->execute();
            $success = 'Active year updated to ' . (int)$year;
            $active_year = $year;
        } else {
            $errors[] = 'Database error while updating setting';
        }
    }
}

include __DIR__ . '/includes/header.php';
?>
<style>
  .settings-box{max-width:720px;margin:2rem auto;background:#111;padding:1.5rem;border-radius:.5rem}
  label{display:block;color:#f6c90e;margin-bottom:.5rem}
  input{width:100%;padding:.6rem;border-radius:.3rem;border:1px solid #333;background:#1a1a1a;color:#fff}
  button{margin-top:1rem;padding:.6rem 1.2rem;background:#f6c90e;border:none;border-radius:.3rem}
</style>
<div class="settings-box">
  <h2>⚙️ Site Settings</h2>
  <?php if ($success): ?><p class="success"><?= htmlspecialchars($success) ?></p><?php endif; ?>
  <?php foreach ($errors as $er): ?><p class="error"><?= htmlspecialchars($er) ?></p><?php endforeach; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label for="active_year">Active competition year</label>
    <input id="active_year" name="active_year" type="number" min="2000" max="3000" value="<?= (int)$active_year ?>">
    <div>
      <button type="submit">Save</button>
      <a class="btn secondary" href="index.php">Cancel</a>
    </div>
  </form>
</div>
<?php include __DIR__.'/includes/footer.php'; ?>