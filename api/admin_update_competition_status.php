<?php
// Admin API to update competition status (per vote or per movie)
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/helper.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

if (($user['role'] ?? 'user') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$movieId = (int)($_POST['movie_id'] ?? 0);
$voteId  = (int)($_POST['vote_id'] ?? 0);
$status  = trim($_POST['status'] ?? '');

$validStatuses = [
    'In Competition',
    '2026 In Competition',
    'Out of Competition'
];

if (!in_array($status, $validStatuses, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid status']);
    exit;
}

if ($voteId <= 0 && $movieId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing vote_id or movie_id']);
    exit;
}

global $mysqli;

// Helper to ensure vote_details exists for a vote
function ensure_vote_details_exists(int $voteId, string $status): void {
    global $mysqli;
    $stmt = $mysqli->prepare("INSERT IGNORE INTO vote_details (vote_id, competition_status) VALUES (?, ?)");
    $stmt->bind_param('is', $voteId, $status);
    $stmt->execute();
}

// If a specific vote was provided, just update it
if ($voteId > 0) {
    ensure_vote_details_exists($voteId, $status);
    $stmt = $mysqli->prepare("UPDATE vote_details SET competition_status = ? WHERE vote_id = ?");
    $stmt->bind_param('si', $status, $voteId);
    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['error' => 'Database update failed']);
        exit;
    }
    echo json_encode(['success' => true, 'status' => $status, 'vote_id' => $voteId]);
    exit;
}

// Otherwise update all votes for a movie
$voteIdsStmt = $mysqli->prepare("SELECT id FROM votes WHERE movie_id = ?");
$voteIdsStmt->bind_param('i', $movieId);
$voteIdsStmt->execute();
$voteIds = $voteIdsStmt->get_result();

if (!$voteIds || $voteIds->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'No votes found for this movie']);
    exit;
}

// Ensure vote_details rows exist
while ($row = $voteIds->fetch_assoc()) {
    ensure_vote_details_exists((int)$row['id'], $status);
}

// Update all vote_details for this movie
$updateStmt = $mysqli->prepare(
    "UPDATE vote_details vd
     JOIN votes v ON vd.vote_id = v.id
     SET vd.competition_status = ?
     WHERE v.movie_id = ?"
);
$updateStmt->bind_param('si', $status, $movieId);
if (!$updateStmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Database update failed']);
    exit;
}

echo json_encode([
    'success' => true,
    'status' => $status,
    'movie_id' => $movieId,
    'updated_votes' => $updateStmt->affected_rows
]);
