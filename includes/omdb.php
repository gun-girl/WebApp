<?php
require_once __DIR__.'/../config.php';

class OmdbApiClient {
  private $apiKey;
  private $mysqli;

  // Normalize strings for loose matching (strip punctuation, lowercase, collapse spaces)
  private function normalizeKey(string $s): string {
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
  }

  // Insert/update one search item into movies table (parses years, released, poster)
  private function upsertSearchItem(array $m, string $typeHint): void {
    $imdb = $m['imdbID'] ?? null;
    if (!$imdb) return;
    $title = $m['Title'] ?? '';
    $rawYear = $m['Year'] ?? '0000';

    // Normalize dashes (API sometimes returns EN DASH/EM DASH)
    $normalizedYear = str_replace(["\u2013", "\u2014", "–", "—"], '-', $rawYear);

    // Parse year for movies vs series
    // Format: "2020" for movies, "2016-2025" or "2016-" for series
    $year = (int)substr($normalizedYear, 0, 4);
    $startYear = null;
    $endYear = null;
    if (strpos($normalizedYear, '-') !== false) {
      $yearParts = explode('-', $normalizedYear);
      $startYear = (int)$yearParts[0];
      $endYear = isset($yearParts[1]) && $yearParts[1] !== '' ? (int)$yearParts[1] : null;
    } else {
      $startYear = $year;
    }

    $type = $m['Type'] ?? $typeHint;
    $poster = $m['Poster'] ?? null;
    $releasedYmd = null;

    // Poster handling
    if ($poster === 'N/A' || $poster === '' || empty($poster)) {
      $poster = null;
    } elseif ($poster) {
      $poster = str_replace('http://', 'https://', $poster);
      if (!$this->isValidPoster($poster)) {
        $poster = null;
      }
    }

    // Try to get detail once (poster and released)
    $detail = $this->getDetail($imdb);
    if ($detail) {
      if (!$poster && !empty($detail['Poster']) && $detail['Poster'] !== 'N/A') {
        $posterCandidate = str_replace('http://', 'https://', $detail['Poster']);
        if ($this->isValidPoster($posterCandidate)) {
          $poster = $posterCandidate;
        }
      }
      if (!empty($detail['Released'])) {
        $ts = strtotime($detail['Released']);
        if ($ts) {
          $releasedYmd = date('Y-m-d', $ts);
        }
      }
    }

    $stmt = $this->mysqli->prepare("INSERT INTO movies (imdb_id,title,year,type,start_year,end_year,poster_url,released,last_fetched_at)
      VALUES (?,?,?,?,?,?,?,?,NOW()) 
      ON DUPLICATE KEY UPDATE 
      title=VALUES(title),year=VALUES(year),type=VALUES(type),start_year=VALUES(start_year),end_year=VALUES(end_year),
      released=COALESCE(VALUES(released),released),
      poster_url=COALESCE(VALUES(poster_url),poster_url),last_fetched_at=NOW()");
    $stmt->bind_param('ssisiiss', $imdb, $title, $year, $type, $startYear, $endYear, $poster, $releasedYmd);
    $stmt->execute();
    error_log("[OmdbApiClient] Inserted: $title ($rawYear) - start: $startYear, end: $endYear");
  }

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
  
  public function search($query, bool $allowFuzzy = true): array {
    error_log("[OmdbApiClient] Starting search for: '$query'");
    
    $normalized = $this->normalizeKey($query);

    // STEP 1: Check if we have cached results from the last 24 hours
    $check = $this->mysqli->prepare("
      SELECT m.* FROM movies m
      WHERE (
        m.title LIKE CONCAT('%',?,'%')
        OR LOWER(REPLACE(REPLACE(REPLACE(REPLACE(m.title,'.',''),':',''),'&',' '),'-',' ')) LIKE CONCAT('%',?,'%')
      )
      AND m.type IN ('movie', 'series')
      AND m.last_fetched_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
      ORDER BY 
        CASE WHEN m.title = ? THEN 0 ELSE 1 END,
        m.year DESC
      LIMIT 30
    ");
    $check->bind_param('sss', $query, $normalized, $query);
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
        $this->upsertSearchItem($m, $t);
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
      WHERE (
        title LIKE CONCAT('%',?,'%')
        OR LOWER(REPLACE(REPLACE(REPLACE(REPLACE(title,'.',''),':',''),'&',' '),'-',' ')) LIKE CONCAT('%',?,'%')
      )
      AND type IN ('movie', 'series')
      ORDER BY 
        CASE WHEN title = ? THEN 0 ELSE 1 END,
        year DESC
      LIMIT 30
    ");
    $check->bind_param('sss', $query, $normalized, $query);
    $check->execute();
    $results = $check->get_result()->fetch_all(MYSQLI_ASSOC);
    if (empty($results) && $allowFuzzy) {
      $fuzzy = $this->fuzzyFallback($query, $normalized);
      if (!empty($fuzzy)) return $fuzzy;
    }
    error_log("[OmdbApiClient] Returning " . count($results) . " results");
    return $results;
  }

  // Fuzzy fallback: when no results, try longest tokens and pick closest Levenshtein matches
  private function fuzzyFallback(string $query, string $normalizedQuery): array {
    // Pick longest tokens (len>=3)
    $tokens = array_filter(explode(' ', $normalizedQuery), fn($w) => strlen($w) >= 3);
    if (empty($tokens)) return [];
    usort($tokens, fn($a,$b) => strlen($b) <=> strlen($a));
    $tokens = array_slice(array_unique($tokens), 0, 2);

    $candidates = [];
    foreach ($tokens as $tok) {
      foreach (['movie','series','episode'] as $t) {
        $url = ADDRESS . '/includes/async-worker.php';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $httpCode !== 200) continue;
        $json = json_decode($response, true);
        if (empty($json['Search'])) continue;
        foreach ($json['Search'] as $m) {
          $titleNorm = $this->normalizeKey($m['Title'] ?? '');
          if ($titleNorm === '') continue;
          $len = max(strlen($normalizedQuery), strlen($titleNorm));
          if ($len === 0) continue;
          $dist = levenshtein($normalizedQuery, $titleNorm);
          $ratio = $dist / $len; // lower is better
          // Accept reasonably close matches
          if ($ratio <= 0.55) {
            $candidates[] = ['ratio' => $ratio, 'data' => $m, 'type' => $t];
          }
        }
      }
      if (count($candidates) >= 5) break;
    }

    if (empty($candidates)) return [];
    usort($candidates, fn($a,$b) => $a['ratio'] <=> $b['ratio']);
    $top = array_slice($candidates, 0, 5);
    foreach ($top as $c) {
      $this->upsertSearchItem($c['data'], $c['type']);
    }

    // Return from DB using the same retrieval logic
    $stmt = $this->mysqli->prepare("
      SELECT * FROM movies 
      WHERE (
        title LIKE CONCAT('%',?,'%')
        OR LOWER(REPLACE(REPLACE(REPLACE(REPLACE(title,'.',''),':',''),'&',' '),'-',' ')) LIKE CONCAT('%',?,'%')
      )
      AND type IN ('movie', 'series')
      ORDER BY 
        CASE WHEN title = ? THEN 0 ELSE 1 END,
        year DESC
      LIMIT 30
    ");
    $stmt->bind_param('sss', $query, $normalizedQuery, $query);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
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
  
  /**
   * Helper: Check if a movie/series is a miniseries based on totalSeasons or Genre
   */
  public function isMiniseries($imdb_id) {
    $detail = $this->getDetail($imdb_id);
    if (!$detail) {
      return false;
    }
    
    $totalSeasons = $detail['totalSeasons'] ?? null;
    $genre = strtolower($detail['Genre'] ?? '');
    
    // If totalSeasons is 1 or Genre contains "mini-series"
    return ($totalSeasons === '1' || $totalSeasons === 1 || 
            strpos($genre, 'mini-series') !== false || 
            strpos($genre, 'miniseries') !== false);
  }
}

  // Fuzzy fallback: when no results, try longest tokens and pick closest Levenshtein matches
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
  $url = ADDRESS . '/includes/async-worker.php';
  
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // MUST be true to prevent output leaking!
  curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100); // Ultra-short timeout
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 50);
  curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
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
 * Get seasons for a series that match the active competition year
 * Competition for year Y includes content released from November (Y-1) onwards
 * Returns array of season info: ['season_number' => int, 'year' => int, 'first_episode_date' => string]
 */
function get_series_seasons_for_active_year(string $imdb_id, int $activeYear): array {
  global $omdbClient;
  if (!$omdbClient || !$omdbClient->isConfigured()) {
    error_log("[get_series_seasons] OMDb client not configured");
    return [];
  }
  
  // First get series detail to know total seasons
  $detail = $omdbClient->getDetail($imdb_id);
  if (!$detail || empty($detail['totalSeasons'])) {
    error_log("[get_series_seasons] No detail or totalSeasons for {$imdb_id}");
    return [];
  }
  
  $totalSeasons = (int)$detail['totalSeasons'];
  error_log("[get_series_seasons] Checking {$totalSeasons} seasons for {$imdb_id} against competition year {$activeYear}");
  
  // Competition starts in November of the previous year
  $competitionStartDate = ($activeYear - 1) . '-11-01';
  error_log("[get_series_seasons] Competition period starts: {$competitionStartDate}");
  
  $matchingSeasons = [];
  
  // Check each season
  for ($seasonNum = 1; $seasonNum <= $totalSeasons; $seasonNum++) {
    $apiKey = $omdbClient->getKey();
    $url = "https://www.omdbapi.com/?apikey={$apiKey}&i={$imdb_id}&Season={$seasonNum}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    curl_close($ch);
    
    if ($response) {
      $seasonData = json_decode($response, true);
      if (!empty($seasonData['Episodes']) && is_array($seasonData['Episodes'])) {
        // Get the first episode's release date
        $firstEpisode = $seasonData['Episodes'][0];
        $releaseDate = $firstEpisode['Released'] ?? null;
        
        if ($releaseDate && $releaseDate !== 'N/A') {
          $releaseYear = (int)substr($releaseDate, 0, 4);
          error_log("[get_series_seasons] Season {$seasonNum} released: {$releaseDate}");
          
          // Check if this season was released during the competition period
          if ($releaseDate >= $competitionStartDate) {
            error_log("[get_series_seasons] Season {$seasonNum} MATCHES (released {$releaseDate} >= {$competitionStartDate})!");
            $matchingSeasons[] = [
              'season_number' => $seasonNum,
              'year' => $releaseYear,
              'first_episode_date' => $releaseDate
            ];
          }
        }
      }
    }
  }
  
  error_log("[get_series_seasons] Found " . count($matchingSeasons) . " matching seasons");
  return $matchingSeasons;
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
          $normalizedYear = str_replace(["\u2013", "\u2014", "–", "—"], '-', $rawYear);
          $movieYear = (int)substr($normalizedYear, 0, 4);
          
          // Parse year for movies vs series
          $startYear = null;
          $endYear = null;
          if (strpos($normalizedYear, '-') !== false) {
            // Series: extract start and end year
            $yearParts = explode('-', $normalizedYear);
            $startYear = (int)$yearParts[0];
            $endYear = isset($yearParts[1]) && $yearParts[1] !== '' ? (int)$yearParts[1] : null;
          } else {
            // Movie: just the single year
            $startYear = $movieYear;
          }
          
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
              "INSERT INTO movies (imdb_id, title, year, type, start_year, end_year, poster_url, last_fetched_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, NOW()) 
               ON DUPLICATE KEY UPDATE 
               title=VALUES(title), year=VALUES(year), type=VALUES(type), start_year=VALUES(start_year), end_year=VALUES(end_year),
               poster_url=COALESCE(VALUES(poster_url), poster_url), last_fetched_at=NOW()"
            );
            // s s i s i i s => imdb, title, year, type, start_year, end_year, poster_url
            $stmt->bind_param('ssisiis', $imdb, $title, $movieYear, $mType, $startYear, $endYear, $poster);
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
