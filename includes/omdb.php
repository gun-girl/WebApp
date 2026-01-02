<?php
require_once __DIR__.'/../config.php';

class OmdbApiClient {
  private $apiKey;
  private $mysqli;

  // Validate that a poster URL is a proper HTTPS URL and not a placeholder
  private function isValidPoster(?string $url): bool {
    if (!$url) return false;
    if (stripos($url, 'https://') !== 0) return false;
    if (stripos($url, 'no-poster') !== false) return false;
    return (bool)filter_var($url, FILTER_VALIDATE_URL);
  }
  
  public function __construct($apiKey, $mysqli) {
    $this->apiKey = $apiKey;
    $this->mysqli = $mysqli;
  }
  
  public function getKey() {
    return $this->apiKey;
  }
  
  public function isConfigured() {
    return !empty($this->apiKey);
  }
  
  public function search($query): array {
    error_log("[OmdbApiClient] Starting search for: '$query'");
    
    // STEP 1: Check if we have cached results from the last 24 hours
    $check = $this->mysqli->prepare("
      SELECT m.* FROM movies m
      WHERE m.title LIKE CONCAT('%',?,'%') 
      AND m.type IN ('movie', 'series')
      AND m.poster_url IS NOT NULL 
      AND m.poster_url != '' 
      AND m.poster_url != 'N/A'
      AND m.poster_url LIKE 'https://%'
      AND m.poster_url NOT LIKE '%no-poster%'
      AND m.last_fetched_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
      ORDER BY 
        CASE WHEN m.title = ? THEN 0 ELSE 1 END,
        m.year DESC
      LIMIT 30
    ");
    $check->bind_param('ss', $query, $query);
    $check->execute();
    $cachedResults = $check->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // If we have cached results, return them immediately
    if (!empty($cachedResults)) {
      error_log("[OmdbApiClient] Found " . count($cachedResults) . " cached results in database for '$query'");
      return $cachedResults;
    }
    
    error_log("[OmdbApiClient] No valid cache for '$query'. Calling API...");
    
    // STEP 2: Check API configuration
    if (!$this->isConfigured()) {
      error_log("[OmdbApiClient] ERROR: API key not configured!");
      return [];
    }

    // STEP 3: Fetch from OMDb API
    $searchTypes = ['movie','series','episode'];
    $found_any = false;
    
    foreach ($searchTypes as $t) {
      $url = "https://www.omdbapi.com/?apikey={$this->apiKey}&type={$t}&s=".urlencode($query);
      error_log("[OmdbApiClient] API URL: $url");
      
      // Use cURL instead of file_get_contents (works on most hosting)
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_TIMEOUT, 10);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
      $response = curl_exec($ch);
      $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $curlError = curl_error($ch);
      curl_close($ch);
      
      error_log("[OmdbApiClient] HTTP Code: $httpCode, cURL Error: $curlError");
      error_log("[OmdbApiClient] Response: " . substr($response, 0, 500));
      
      if ($response === false || $httpCode !== 200) {
        error_log("[OmdbApiClient] Failed to connect to API for type=$t (HTTP $httpCode, Error: $curlError)");
        continue;
      }
      
      $json = json_decode($response, true);
      
      if (empty($json['Search'])) {
        error_log("[OmdbApiClient] No results for type=$t. Full response: " . $response);
        continue;
      }
      
      $found_any = true;
      error_log("[OmdbApiClient] Found " . count($json['Search']) . " results for type=$t");
      
      // Process and save results
      foreach ($json['Search'] as $m) {
        $imdb = $m['imdbID'] ?? null;
        if (!$imdb) continue;
        
        $title = $m['Title'] ?? '';
        $rawYear = $m['Year'] ?? '0000';
        $year = (int)substr($rawYear, 0, 4);
        $type = $m['Type'] ?? $t;
        $poster = $m['Poster'] ?? null;
        
        // Strict poster validation - only accept valid HTTPS URLs
        if ($poster === 'N/A' || $poster === '' || empty($poster)) {
          $poster = null;
        } elseif ($poster) {
          // Force HTTPS for poster URLs
          $poster = str_replace('http://', 'https://', $poster);
          // Validate URL format - must be a proper HTTPS URL and not placeholder
          if (!$this->isValidPoster($poster)) {
            $poster = null;
          }
        }

        // Try to get better poster for series
        if ($type === 'series' && !$poster) {
          $detail = $this->getDetail($imdb);
          if ($detail && !empty($detail['Poster']) && $detail['Poster'] !== 'N/A') {
            $posterCandidate = str_replace('http://', 'https://', $detail['Poster']);
            if ($this->isValidPoster($posterCandidate)) {
              $poster = $posterCandidate;
            }
          }
        }
        
        // Skip movies/series without valid posters
        if (!$poster && in_array($type, ['movie', 'series'])) {
          continue;
        }

        // Insert into database
        $stmt = $this->mysqli->prepare("INSERT INTO movies (imdb_id,title,year,type,poster_url,last_fetched_at)
          VALUES (?,?,?,?,?,NOW()) 
          ON DUPLICATE KEY UPDATE 
          title=VALUES(title),year=VALUES(year),type=VALUES(type),
          poster_url=COALESCE(VALUES(poster_url),poster_url),last_fetched_at=NOW()");
        $stmt->bind_param('ssiss', $imdb, $title, $year, $type, $poster);
        $stmt->execute();
        error_log("[OmdbApiClient] Inserted: $title ($year)");
      }
    }

    // STEP 4: Only cache if we found results
    if ($found_any) {
      // Mark this query as successfully cached today (so we don't re-query the API)
      $upsert = $this->mysqli->prepare("INSERT INTO query_cache (query,date) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE date = NOW()");
      $upsert->bind_param('s', $query);
      $upsert->execute();
      error_log("[OmdbApiClient] Cached successful search for '$query'");
    } else {
      error_log("[OmdbApiClient] No results found from API for '$query' - NOT caching (will retry next time)");
    }

    // STEP 5: Return results from database after API insert (filter by type and poster, order by relevance)
    $check = $this->mysqli->prepare("
      SELECT * FROM movies 
      WHERE title LIKE CONCAT('%',?,'%') 
      AND type IN ('movie', 'series')
      AND poster_url IS NOT NULL 
      AND poster_url != '' 
      AND poster_url != 'N/A'
      AND poster_url LIKE 'https://%'
      AND poster_url NOT LIKE '%no-poster%'
      ORDER BY 
        CASE WHEN title = ? THEN 0 ELSE 1 END,
        year DESC
      LIMIT 30
    ");
    $check->bind_param('ss', $query, $query);
    $check->execute();
    $results = $check->get_result()->fetch_all(MYSQLI_ASSOC);
    error_log("[OmdbApiClient] Returning " . count($results) . " results");
    return $results;
  }
  
  public function getDetail($imdbId): ?array {
    if (!$this->isConfigured()) return null;
    $url = "https://www.omdbapi.com/?apikey={$this->apiKey}&i=".urlencode($imdbId);
    
    // Use cURL instead of file_get_contents
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    
    if ($response === false) return null;
    
    $json = json_decode($response, true);
    if (!empty($json['Response']) && $json['Response'] === 'True') return $json;
    return null;
  }
}

    // Global instance for OMDb API interactions
    $omdbClient = new OmdbApiClient($OMDB_API_KEY, $mysqli);

    // Trigger async background tasks without blocking page load
    try {
      // Use non-blocking async trigger via AJAX/cURL background call
      if (PHP_SAPI !== 'cli') { // Only trigger if not CLI
        trigger_async_maintenance();
      }
    } catch (Throwable $e) { /* silent fail */ }

// Trigger async maintenance tasks without blocking page load
function trigger_async_maintenance(): void {
  // Use non-blocking HTTP request to background worker
  $url = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/includes/async-worker.php';
  
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
  curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100); // Ultra-short timeout
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 50);
  curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
  @curl_exec($ch); // Suppress errors
  curl_close($ch);
} 

// get_movie_or_fetch(): Backward compatible wrapper
function get_movie_or_fetch($query): array {
  global $omdbClient;
  return $omdbClient->search($query);
}

// fetch_omdb_detail_by_id(): Backward compatible wrapper
function fetch_omdb_detail_by_id(string $imdb_id): ?array {
  global $omdbClient;
  return $omdbClient->getDetail($imdb_id);
}

/**
 * fetch_recent_releases(): Fetch popular recent movies/series from OMDb API for specified year.
 * Returns an array of movie data fetched and stored in the database.
 */
function fetch_recent_releases(int $year = null): array {
  global $mysqli, $omdbClient;
  if (!$omdbClient->isConfigured()) return [];
  if ($year === null) $year = (int)date('Y');
  
  $collected = [];
  // Search for diverse terms to get various types of content (movies, series, documentaries)
  $searches = ['action', 'drama', 'thriller', 'comedy', 'series', 'documentary', 'adventure', 'romance', 'mystery'];
  
  foreach ($searches as $term) {
    // Search both movies and series types
    foreach (['movie', 'series'] as $type) {
      $url = "https://www.omdbapi.com/?apikey=".$omdbClient->getKey()."&s={$term}&y={$year}&type={$type}";
      
      // Use cURL instead of file_get_contents
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_TIMEOUT, 10);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
      $response = curl_exec($ch);
      curl_close($ch);
      
      $json = ($response !== false) ? json_decode($response, true) : null;
      
      if (!empty($json['Search'])) {
        foreach ($json['Search'] as $m) {
          $imdb = $m['imdbID'];
          if (isset($collected[$imdb])) continue; // Skip duplicates
          
          $title = $m['Title'];
          $rawYear = $m['Year'];
          $movieYear = (int)substr($rawYear, 0, 4);
          $mType = $m['Type'] ?? $type;
          $poster = $m['Poster'] ?? null;
          if ($poster === 'N/A' || $poster === '') { 
            $poster = null; 
          } elseif ($poster) {
            // Force HTTPS for poster URLs to prevent mixed content issues
            $poster = str_replace('http://', 'https://', $poster);
          }
          
          // Only insert movies with valid posters
          if ($poster) {
            $stmt = $mysqli->prepare(
              "INSERT INTO movies (imdb_id, title, year, type, poster_url, last_fetched_at)
               VALUES (?, ?, ?, ?, ?, NOW()) 
               ON DUPLICATE KEY UPDATE 
               title=VALUES(title), year=VALUES(year), type=VALUES(type), 
               poster_url=COALESCE(VALUES(poster_url), poster_url), last_fetched_at=NOW()"
            );
            $stmt->bind_param('ssiss', $imdb, $title, $movieYear, $mType, $poster);
            $stmt->execute();
            
            $collected[$imdb] = $mysqli->insert_id ?: (int)$mysqli->query("SELECT id FROM movies WHERE imdb_id='".addslashes($imdb)."'")->fetch_row()[0];
          }
        }
      }
      
      if (count($collected) >= 50) break; // Got enough
    }
    
    if (count($collected) >= 50) break;
  }
  
  // Return the newly fetched movies
  if ($collected) {
    $ids = array_values($collected);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $mysqli->prepare("SELECT * FROM movies WHERE id IN ($placeholders)");
    $types = str_repeat('i', count($ids));
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  }
  
  return [];
}

/**
 * Auto-fetch missing posters: For movies/series without posters, try to fetch them from OMDb
 */
function auto_fetch_missing_posters(): void {
  global $mysqli, $omdbClient;
  
  if (!$omdbClient->isConfigured()) return;
  
  // Find up to 10 movies/series without posters (exclude other types)
  $missing = $mysqli->query("
    SELECT id, title FROM movies 
    WHERE type IN ('movie', 'series')
    AND (poster_url IS NULL OR poster_url = '' OR poster_url = 'N/A') 
    LIMIT 10
  ")->fetch_all(MYSQLI_ASSOC);
  
  if (empty($missing)) return;
  
  foreach ($missing as $movie) {
    try {
      $results = $omdbClient->search($movie['title']);
      // The search function already saves to DB, so just update if found
      foreach ($results as $r) {
        if ($r['title'] === $movie['title']) {
          break;
        }
      }
    } catch (Throwable $e) {
      // Silently skip on error
    }
  }
}
