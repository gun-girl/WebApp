<?php
require_once __DIR__.'/includes/auth.php'; require_login();

// Get the available vote detail fields
$voteDetailFields = [];
$detailsResult = $mysqli->query("SHOW COLUMNS FROM vote_details");
if ($detailsResult) {
    foreach ($detailsResult->fetch_all(MYSQLI_ASSOC) as $col) {
        if ($col['Field'] !== 'vote_id' && $col['Field'] !== 'id') {
            $voteDetailFields[] = $col['Field'];
        }
    }
}

// Check if we're editing an existing vote
if (!empty($_GET['edit'])) {
    $vote_id = (int)$_GET['edit'];
    $user_id = current_user()['id'];
    
    // Get the vote and its details
    $stmt = $mysqli->prepare("
        SELECT v.*, m.title, m.year, vd.*
        FROM votes v 
        JOIN movies m ON m.id = v.movie_id
        LEFT JOIN vote_details vd ON vd.vote_id = v.id
        WHERE v.id = ? AND v.user_id = ?
    ");
    $stmt->bind_param('ii', $vote_id, $user_id);
    $stmt->execute();
    $vote = $stmt->get_result()->fetch_assoc();
    
    if (!$vote) {
        exit('Vote not found or unauthorized');
    }
    
    include __DIR__.'/includes/header.php';
    ?>
    <h2>Edit Vote for <?= e($vote['title']) ?> (<?= e($vote['year']) ?>)</h2>
    <form method="post" class="vote-form">
        <?= csrf_field() ?>
        <input type="hidden" name="vote_id" value="<?= $vote_id ?>">
        <input type="hidden" name="movie_id" value="<?= $vote['movie_id'] ?>">
        <?php foreach ($voteDetailFields as $field): ?>
            <div class="form-group">
                <label for="<?= $field ?>"><?= ucfirst(str_replace('_', ' ', $field)) ?>:</label>
                <input type="number" min="1" max="10" 
                       name="<?= $field ?>" 
                       id="<?= $field ?>" 
                       value="<?= $vote[$field] ?? '' ?>">
            </div>
        <?php endforeach; ?>
        <button type="submit">Update Vote</button>
        <a href="stats.php?mine=1" class="btn">Cancel</a>
    </form>
    <?php
    include __DIR__.'/includes/footer.php';
    exit;
}

// Handle form submission
verify_csrf();

// Process vote submission
$user_id = current_user()['id'];
$movie_id = (int)($_POST['movie_id'] ?? 0);
$vote_id = (int)($_POST['vote_id'] ?? 0);

if ($movie_id <= 0) exit('Invalid input');

$user_id = current_user()['id'];

// ensure movie exists (cheap guard)
$exists = $mysqli->prepare("SELECT id FROM movies WHERE id=?");
$exists->bind_param('i', $movie_id); 
$exists->execute();
if (!$exists->get_result()->fetch_row()) exit('Unknown movie');

// Start transaction
$mysqli->begin_transaction();

try {
    if ($vote_id) {
        // Update existing vote
        $stmt = $mysqli->prepare("UPDATE votes SET movie_id = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param('iii', $movie_id, $vote_id, $user_id);
        $stmt->execute();
        if ($stmt->affected_rows === 0) {
            throw new Exception('Vote not found or unauthorized');
        }
    } else {
            // Before inserting, ensure the user hasn't already voted this movie
            $check = $mysqli->prepare("SELECT id FROM votes WHERE user_id = ? AND movie_id = ?");
            $check->bind_param('ii', $user_id, $movie_id);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();
            if ($existing) {
                // user already voted this movie - rollback and inform them with an edit link
                $mysqli->rollback();
                if (session_status() !== PHP_SESSION_ACTIVE) session_start();
                $_SESSION['flash'] = 'You have already voted this movie.';
                $_SESSION['flash_edit_vote_id'] = (int)$existing['id'];
                redirect('/movie-club-app/stats.php?mine=1');
            }

            // Insert new vote
            $stmt = $mysqli->prepare("INSERT INTO votes (user_id, movie_id) VALUES (?, ?)");
            $stmt->bind_param('ii', $user_id, $movie_id);
            $stmt->execute();
            $vote_id = $mysqli->insert_id;
    }

    // Handle vote details
    // First, collect all the vote details from the form
    $voteDetails = [];
    foreach ($voteDetailFields as $field) {
        if (isset($_POST[$field]) && $_POST[$field] !== '') {
            $voteDetails[$field] = (int)$_POST[$field];
        }
    }

    if (!empty($voteDetails)) {
        // Delete existing details if any
        $stmt = $mysqli->prepare("DELETE FROM vote_details WHERE vote_id = ?");
        $stmt->bind_param('i', $vote_id);
        $stmt->execute();

        // Insert new vote details
        $fields = array_keys($voteDetails);
        $placeholders = implode(',', array_fill(0, count($fields), '?'));
        $sql = "INSERT INTO vote_details (vote_id, " . implode(',', $fields) . ") VALUES (?" . str_repeat(',?', count($fields)) . ")";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare vote_details statement: ' . $mysqli->error);
        }

        $types = 'i' . str_repeat('i', count($fields));
        $values = array_merge([$vote_id], array_values($voteDetails));
        
        $bind = array_merge([$types], array_map(function($val) { return $val; }, $values));
        foreach ($bind as $i => $val) {
            $bind[$i] = &$bind[$i];
        }
        
        call_user_func_array([$stmt, 'bind_param'], $bind);
        $stmt->execute();
    }

    // Commit transaction
    $mysqli->commit();

    // Set success message and redirect
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['flash'] = $vote_id ? 'Vote updated successfully' : 'Thank you for voting';
    redirect('/movie-club-app/stats.php?mine=1');

} catch (Exception $e) {
    // Roll back transaction on error
    $mysqli->rollback();
    die('Error: ' . $e->getMessage());
}
