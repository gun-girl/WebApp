<?php
/**
 * Async Worker - Background maintenance tasks
 * This file is designed to run asynchronously without blocking page loads
 */

// Prevent timeout and output buffering issues
ignore_user_abort(true);
set_time_limit(30);
ob_start();

require_once __DIR__.'/../config.php';
require_once __DIR__.'/omdb.php';

// Don't output anything - this runs in background
header('Content-Type: application/json');
http_response_code(200);

// Flush headers immediately
ob_end_clean();

// Simple response to confirm worker started
echo json_encode(['status' => 'processing']);
flush();

// Now do the actual work in background
try {
  // 1. Auto-fetch missing posters
  error_log("[AsyncWorker] Starting auto_fetch_missing_posters");
  auto_fetch_missing_posters();
  error_log("[AsyncWorker] Completed auto_fetch_missing_posters");
  
  // 2. Clean up old query cache entries (older than 7 days)
  error_log("[AsyncWorker] Cleaning up old query cache");
  $mysqli->query("DELETE FROM query_cache WHERE date < DATE_SUB(NOW(), INTERVAL 7 DAY)");
  error_log("[AsyncWorker] Query cache cleanup completed");
  
  // 3. Clean up old search queue entries (older than 7 days)
  if ($mysqli->query("SHOW TABLES LIKE 'async_search_queue'")->num_rows > 0) {
    error_log("[AsyncWorker] Cleaning up old async search queue");
    $mysqli->query("DELETE FROM async_search_queue WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    error_log("[AsyncWorker] Async search queue cleanup completed");
  }
  
  // 4. Clean up old series season cache (older than 7 days)
  if ($mysqli->query("SHOW TABLES LIKE 'series_seasons_cache'")->num_rows > 0) {
    error_log("[AsyncWorker] Cleaning up old series season cache");
    $mysqli->query("DELETE FROM series_seasons_cache WHERE last_fetched_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    error_log("[AsyncWorker] Series season cache cleanup completed");
  }
  
  error_log("[AsyncWorker] All maintenance tasks completed successfully");
  
} catch (Throwable $e) {
  error_log("[AsyncWorker] Error during maintenance: " . $e->getMessage());
}

// Exit cleanly - output was already sent
exit(0);
