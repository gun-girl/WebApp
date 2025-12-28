<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/lang.php';
require_login();

$user = current_user();
$errors = [];
$success = '';
$is_admin = is_admin();

// Ensure admins table exists
try {
    $mysqli->query("CREATE TABLE IF NOT EXISTS admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    // Table creation failed, continue anyway
}

// ========== ADMIN ACTIONS ==========
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $admin_action = $_POST['admin_action'] ?? '';
    
    // Manage admins
    if ($admin_action === 'add_admin') {
        $admin_email = trim($_POST['admin_email'] ?? '');
        if (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address';
        } else {
            try {
                $stmt = $mysqli->prepare("INSERT IGNORE INTO admins (email) VALUES (?)");
                $stmt->bind_param('s', $admin_email);
                $stmt->execute();
                if ($stmt->affected_rows > 0) {
                    $success = "Admin added: {$admin_email}";
                } else {
                    $errors[] = "Email already admin or doesn't exist";
                }
            } catch (Throwable $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
    
    if ($admin_action === 'remove_admin') {
        $admin_id = (int)($_POST['admin_id'] ?? 0);
        if ($admin_id > 0) {
            try {
                $stmt = $mysqli->prepare("DELETE FROM admins WHERE id = ?");
                $stmt->bind_param('i', $admin_id);
                $stmt->execute();
                if ($stmt->affected_rows > 0) {
                    $success = "Admin removed";
                } else {
                    $errors[] = "Admin not found";
                }
            } catch (Throwable $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// ========== USER PROFILE ACTIONS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['admin_action'])) {
    verify_csrf();
    
    // Change password
    if (!empty($_POST['change_password'])) {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        
        if ($new === '' || $confirm === '' || $current === '') {
            $errors[] = 'All password fields are required';
        } elseif ($new !== $confirm) {
            $errors[] = 'New password and confirmation do not match';
        } elseif (strlen($new) < 6) {
            $errors[] = 'New password must be at least 6 characters';
        } else {
            $stmt = $mysqli->prepare("SELECT password_hash FROM users WHERE id=?");
            $stmt->bind_param('i', $user['id']);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row || !password_verify($current, $row['password_hash'])) {
                $errors[] = 'Current password is incorrect';
            } else {
                $newHash = password_hash($new, PASSWORD_DEFAULT);
                $stmt2 = $mysqli->prepare("UPDATE users SET password_hash=? WHERE id=?");
                $stmt2->bind_param('si', $newHash, $user['id']);
                $stmt2->execute();
                $success = 'Password updated successfully';
            }
        }
    } else {
        // Update profile (username/email)
        $new_email = trim($_POST['email'] ?? '');
        $new_username = trim($_POST['username'] ?? '');
        
        if (empty($new_email) || empty($new_username)) {
            $errors[] = 'All fields are required';
        } else {
            try {
                $stmt = $mysqli->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
                $stmt->bind_param('ssi', $new_username, $new_email, $user['id']);
                $stmt->execute();
                $_SESSION['user']['username'] = $new_username;
                $_SESSION['user']['email'] = $new_email;
                $user = current_user();
                $success = 'Profile updated successfully';
            } catch (mysqli_sql_exception $e) {
                if (str_contains($e->getMessage(), 'Duplicate')) {
                    $errors[] = 'Username or email already exists';
                } else {
                    $errors[] = 'Error updating profile';
                }
            }
        }
    }
}

// Get user's votes count
$active_year = (int)date('Y');
$hasVotesYearCol = $mysqli->query("SHOW COLUMNS FROM votes LIKE 'competition_year'")->fetch_all(MYSQLI_ASSOC);
$yearExpr = $hasVotesYearCol ? "COALESCE(competition_year, YEAR(created_at))" : "YEAR(created_at)";

$stmt = $mysqli->prepare("SELECT COUNT(*) as vote_count FROM votes WHERE user_id = ? AND {$yearExpr} = ?");
$stmt->bind_param('ii', $user['id'], $active_year);
$stmt->execute();
$voteData = $stmt->get_result()->fetch_assoc();
$vote_count = $voteData['vote_count'] ?? 0;

// Get all admins for admin panel
$admins = [];
if ($is_admin) {
    try {
        $result = $mysqli->query("SELECT id, email, created_at FROM admins ORDER BY created_at DESC");
        if ($result) {
            $admins = $result->fetch_all(MYSQLI_ASSOC);
        }
    } catch (Throwable $e) {
        // Admins table may not exist
    }
}

?>
<?php include __DIR__.'/includes/header.php'; ?>

<div class="user-profile-container" style="max-width: 800px; margin: 2rem auto; padding: 1rem;">
    
    <?php if (!empty($errors)): ?>
        <div style="background: #fee; border: 1px solid #f99; border-radius: 4px; padding: 1rem; margin-bottom: 1rem;">
            <?php foreach ($errors as $err): ?>
                <p style="margin: 0.5rem 0; color: #c33;">❌ <?= e($err) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($success)): ?>
        <div style="background: #efe; border: 1px solid #9f9; border-radius: 4px; padding: 1rem; margin-bottom: 1rem;">
            <p style="margin: 0; color: #393;">✅ <?= e($success) ?></p>
        </div>
    <?php endif; ?>

    <!-- USER PROFILE SECTION -->
    <div style="background: #1a1a1a; border: 1px solid #333; border-radius: 8px; padding: 2rem; margin-bottom: 2rem;">
        <h2 style="margin-top: 0;">👤 Your Profile</h2>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 2rem;">
            <div>
                <strong>Username:</strong><br>
                <code><?= e($user['username']) ?></code>
            </div>
            <div>
                <strong>Email:</strong><br>
                <code><?= e($user['email']) ?></code>
            </div>
            <div>
                <strong>Member Since:</strong><br>
                <code><?= date('M d, Y') ?></code>
            </div>
            <div>
                <strong>Votes this Year:</strong><br>
                <code><?= $vote_count ?></code>
            </div>
        </div>
        
        <?php if ($is_admin): ?>
            <div style="background: #333; padding: 0.5rem 1rem; border-radius: 4px; margin-bottom: 2rem;">
                👑 <strong>Admin Status:</strong> <span style="color: #f6c90e;">ADMIN</span>
            </div>
        <?php endif; ?>

        <div style="border-top: 1px solid #333; padding-top: 1rem;">
            <h3>Update Profile</h3>
            <form method="POST" style="margin-bottom: 1.5rem;">
                <?= csrf_field() ?>
                <div style="margin-bottom: 1rem;">
                    <label>Username:</label><br>
                    <input type="text" name="username" value="<?= e($user['username']) ?>" required 
                           style="width: 100%; padding: 0.5rem; border: 1px solid #555; background: #222; color: #fff; border-radius: 4px;">
                </div>
                <div style="margin-bottom: 1rem;">
                    <label>Email:</label><br>
                    <input type="email" name="email" value="<?= e($user['email']) ?>" required 
                           style="width: 100%; padding: 0.5rem; border: 1px solid #555; background: #222; color: #fff; border-radius: 4px;">
                </div>
                <button type="submit" style="background: #f6c90e; color: #000; padding: 0.75rem 1.5rem; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">
                    Update Profile
                </button>
            </form>

            <h3>Change Password</h3>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="change_password" value="1">
                <div style="margin-bottom: 1rem;">
                    <label>Current Password:</label><br>
                    <input type="password" name="current_password" required 
                           style="width: 100%; padding: 0.5rem; border: 1px solid #555; background: #222; color: #fff; border-radius: 4px;">
                </div>
                <div style="margin-bottom: 1rem;">
                    <label>New Password:</label><br>
                    <input type="password" name="new_password" required 
                           style="width: 100%; padding: 0.5rem; border: 1px solid #555; background: #222; color: #fff; border-radius: 4px;">
                </div>
                <div style="margin-bottom: 1rem;">
                    <label>Confirm Password:</label><br>
                    <input type="password" name="confirm_password" required 
                           style="width: 100%; padding: 0.5rem; border: 1px solid #555; background: #222; color: #fff; border-radius: 4px;">
                </div>
                <button type="submit" style="background: #f6c90e; color: #000; padding: 0.75rem 1.5rem; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">
                    Change Password
                </button>
            </form>
        </div>
    </div>

    <!-- ADMIN PANEL SECTION -->
    <?php if ($is_admin): ?>
    <div style="background: #1a1a1a; border: 2px solid #f6c90e; border-radius: 8px; padding: 2rem;">
        <h2 style="margin-top: 0; color: #f6c90e;">👑 Admin Panel</h2>
        
        <h3>Current Admins</h3>
        <?php if (!empty($admins)): ?>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 2rem;">
                <thead>
                    <tr style="background: #333; border-bottom: 2px solid #f6c90e;">
                        <th style="padding: 0.75rem; text-align: left;">Email</th>
                        <th style="padding: 0.75rem; text-align: left;">Added</th>
                        <th style="padding: 0.75rem; text-align: center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($admins as $admin): ?>
                    <tr style="border-bottom: 1px solid #333;">
                        <td style="padding: 0.75rem;"><?= e($admin['email']) ?></td>
                        <td style="padding: 0.75rem;"><?= date('M d, Y', strtotime($admin['created_at'])) ?></td>
                        <td style="padding: 0.75rem; text-align: center;">
                            <form method="POST" style="display: inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="admin_action" value="remove_admin">
                                <input type="hidden" name="admin_id" value="<?= $admin['id'] ?>">
                                <button type="submit" style="background: #c33; color: #fff; padding: 0.5rem 1rem; border: none; border-radius: 4px; cursor: pointer;" 
                                        onclick="return confirm('Remove this admin?');">
                                    Remove
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No admins found</p>
        <?php endif; ?>

        <h3>Add New Admin</h3>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="admin_action" value="add_admin">
            <div style="display: flex; gap: 1rem;">
                <input type="email" name="admin_email" placeholder="user@example.com" required 
                       style="flex: 1; padding: 0.75rem; border: 1px solid #555; background: #222; color: #fff; border-radius: 4px;">
                <button type="submit" style="background: #f6c90e; color: #000; padding: 0.75rem 1.5rem; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">
                    Add Admin
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

</div>

<?php include __DIR__.'/includes/footer.php'; ?>
