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
    $totalSeasons = null;

    // Poster handling from search results
    if ($poster === 'N/A' || $poster === '' || empty($poster)) {
      $poster = null;
    } elseif ($poster) {
      $poster = str_replace('http://', 'https://', $poster);
      if (!$this->isValidPoster($poster)) {
        $poster = null;
      }
    }

    // Check if we already have this movie with complete data cached
    $checkCache = $this->mysqli->prepare("SELECT total_seasons, poster_url, released FROM movies WHERE imdb_id = ?");
    $checkCache->bind_param('s', $imdb);
    $checkCache->execute();
    $existing = $checkCache->get_result()->fetch_assoc();
    
    // Only fetch details if:
    // 1. It's a series/miniseries AND we don't have totalSeasons yet
    // 2. OR we're missing poster/release date
    $needsDetail = false;
    
    if (($type === 'series' || $type === 'miniseries') && empty($existing['total_seasons'])) {
      $needsDetail = true;
    } elseif (!$poster && empty($existing['poster_url'])) {
      $needsDetail = true;
    }

    if ($needsDetail) {
      error_log("[OmdbApiClient] Fetching additional details for $imdb ($title)");
      $detail = $this->getDetail($imdb);
      if ($detail) {
        // Update poster if missing
        if (!$poster && !empty($detail['Poster']) && $detail['Poster'] !== 'N/A') {
          $posterCandidate = str_replace('http://', 'https://', $detail['Poster']);
          if ($this->isValidPoster($posterCandidate)) {
            $poster = $posterCandidate;
          }
        }
        
        // Update release date if missing
        if (!empty($detail['Released'])) {
          $ts = strtotime($detail['Released']);
          if ($ts) {
            $releasedYmd = date('Y-m-d', $ts);
          }
        }
        
        // Get totalSeasons for series
        if (!empty($detail['totalSeasons']) && is_numeric($detail['totalSeasons'])) {
          $totalSeasons = (int)$detail['totalSeasons'];
          error_log("[OmdbApiClient] Found $totalSeasons seasons for $title");
        }
      }
    } else {
      // Use existing cached data
      if (!empty($existing['total_seasons'])) {
        $totalSeasons = (int)$existing['total_seasons'];
      }
      if (!$poster && !empty($existing['poster_url'])) {
        $poster = $existing['poster_url'];
      }
      if (!$releasedYmd && !empty($existing['released'])) {
        $releasedYmd = $existing['released'];
      }
    }

    $stmt = $this->mysqli->prepare("INSERT INTO movies (imdb_id,title,year,type,start_year,end_year,poster_url,released,total_seasons,last_fetched_at)
      VALUES (?,?,?,?,?,?,?,?,?,NOW()) 
      ON DUPLICATE KEY UPDATE 
      title=VALUES(title),
      year=VALUES(year),
      type=VALUES(type),
      start_year=VALUES(start_year),
      end_year=VALUES(end_year),
      released=COALESCE(VALUES(released),released),
      poster_url=COALESCE(VALUES(poster_url),poster_url),
      total_seasons=COALESCE(VALUES(total_seasons),total_seasons),
      last_fetched_at=NOW()");
    $stmt->bind_param('ssisiissi', $imdb, $title, $year, $type, $startYear, $endYear, $poster, $releasedYmd, $totalSeasons);
    $stmt->execute();
    error_log("[OmdbApiClient] Cached: $title ($rawYear) - Seasons: " . ($totalSeasons ?? 'N/A'));
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

    // STEP 5: Return results from database after API insert
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
        $url = "https://www.omdbapi.com/?apikey={$this->apiKey}&type={$t}&s=".urlencode($tok);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
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
  
  /**
   * Get basic movie/series details by IMDb ID with caching
   */
  public function getDetail($imdbId): ?array {
    if (!$this->isConfigured()) return null;
    
    // Check cache first (valid for 7 days for basic details)
    $stmt = $this->mysqli->prepare("SELECT poster_url, released, total_seasons, last_fetched_at FROM movies 
                                     WHERE imdb_id = ? 
                                     AND last_fetched_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                                     AND poster_url IS NOT NULL 
                                     AND released IS NOT NULL");
    $stmt->bind_param('s', $imdbId);
    $stmt->execute();
    $cached = $stmt->get_result()->fetch_assoc();
    
    if ($cached && $cached['poster_url'] && $cached['released'] !== '0000-00-00') {
      error_log("[OmdbApiClient] Movie details for $imdbId loaded from DATABASE CACHE");
      // Return cached data in OMDb format
      $result = [
        'Response' => 'True',
        'imdbID' => $imdbId,
        'Poster' => $cached['poster_url'],
        'Released' => $cached['released']
      ];
      
      // Include totalSeasons if available
      if (!empty($cached['total_seasons'])) {
        $result['totalSeasons'] = (string)$cached['total_seasons'];
      }
      
      return $result;
    }
    
    // Not in cache - fetch from API
    error_log("[OmdbApiClient] Fetching details from API for $imdbId");
    $url = "https://www.omdbapi.com/?apikey={$this->apiKey}&i=".urlencode($imdbId);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response === false || $httpCode !== 200) {
      error_log("[OmdbApiClient] API request failed for $imdbId");
      return null;
    }
    
    $json = json_decode($response, true);
    
    if (!empty($json['Response']) && $json['Response'] === 'True') {
      error_log("[OmdbApiClient] Movie details for $imdbId fetched from API and CACHED");
      
      // Cache poster, release date, and totalSeasons in movies table
      $poster = $json['Poster'] ?? null;
      if ($poster && $poster !== 'N/A') {
        $poster = str_replace('http://', 'https://', $poster);
      } else {
        $poster = null;
      }
      
      $released = null;
      if (!empty($json['Released'])) {
        $ts = strtotime($json['Released']);
        if ($ts) {
          $released = date('Y-m-d', $ts);
        }
      }
      
      $totalSeasons = null;
      if (!empty($json['totalSeasons']) && is_numeric($json['totalSeasons'])) {
        $totalSeasons = (int)$json['totalSeasons'];
      }
      
      // Update the cache
      $update = $this->mysqli->prepare("UPDATE movies 
                                        SET poster_url = COALESCE(?, poster_url),
                                            released = COALESCE(?, released),
                                            total_seasons = COALESCE(?, total_seasons),
                                            last_fetched_at = NOW()
                                        WHERE imdb_id = ?");
      $update->bind_param('ssis', $poster, $released, $totalSeasons, $imdbId);
      $update->execute();
      
      return $json;
    }
    
    return null;
  }
  
  /**
   * Get season-specific details with caching
   */
  public function getSeasonDetail($imdbId, $seasonNumber): ?array {
    if (!$this->isConfigured()) return null;
    
    // Check cache first (valid for 7 days)
    $stmt = $this->mysqli->prepare("SELECT data, last_fetched_at FROM series_seasons_cache 
                                     WHERE imdb_id = ? AND season_number = ? 
                                     AND last_fetched_at > DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stmt->bind_param('si', $imdbId, $seasonNumber);
    $stmt->execute();
    $cached = $stmt->get_result()->fetch_assoc();
    
    if ($cached) {
      $data = json_decode($cached['data'], true);
      if ($data && isset($data['Response']) && $data['Response'] === 'True') {
        error_log("[OmdbApiClient] Season $seasonNumber for $imdbId loaded from CACHE");
        return $data;
      }
    }
    
    // Not in cache - fetch from API
    $url = "https://www.omdbapi.com/?apikey={$this->apiKey}&i=".urlencode($imdbId)."&Season=".intval($seasonNumber);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response === false || $httpCode !== 200) {
      error_log("[OmdbApiClient] API request failed for season $seasonNumber of $imdbId");
      return null;
    }
    
    $json = json_decode($response, true);
    
    if (!empty($json['Response']) && $json['Response'] === 'True') {
      // Cache the successful result
      $jsonData = json_encode($json);
      $stmt = $this->mysqli->prepare("INSERT INTO series_seasons_cache (imdb_id, season_number, data, last_fetched_at) 
                                       VALUES (?, ?, ?, NOW())
                                       ON DUPLICATE KEY UPDATE data = ?, last_fetched_at = NOW()");
      $stmt->bind_param('siss', $imdbId, $seasonNumber, $jsonData, $jsonData);
      $stmt->execute();
      error_log("[OmdbApiClient] Season $seasonNumber for $imdbId fetched and CACHED");
      return $json;
    }
    
    return null;
  }
  
  /**
   * Helper: Check if a movie/series is a miniseries
   */
  public function isMiniseries($imdb_id) {
    $detail = $this->getDetail($imdb_id);
    if (!$detail) return false;
    
    $totalSeasons = $detail['totalSeasons'] ?? null;
    $genre = strtolower($detail['Genre'] ?? '');
    
    return ($totalSeasons === '1' || $totalSeasons === 1 || 
            strpos($genre, 'mini-series') !== false || 
            strpos($genre, 'miniseries') !== false);
  }
}

// Global instance for OMDb API interactions
$omdbClient = new OmdbApiClient($OMDB_API_KEY, $mysqli);

// Trigger async background tasks without blocking page load
try {
  if (PHP_SAPI !== 'cli') {
    trigger_async_maintenance();
  }
} catch (Throwable $e) { /* silent fail */ }

// Trigger async maintenance tasks without blocking page load
function trigger_async_maintenance(): void {
  $url = ADDRESS . '/includes/async-worker.php';
  
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 50);
  curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  @curl_exec($ch);
  curl_close($ch);
}

// Backward compatible wrappers
function get_movie_or_fetch($query): array {
  global $omdbClient;
  return $omdbClient->search($query);
}

function fetch_omdb_detail_by_id(string $imdb_id, $season = null): ?array {
  global $omdbClient;
  if ($season !== null) {
    return $omdbClient->getSeasonDetail($imdb_id, $season);
  }
  return $omdbClient->getDetail($imdb_id);
}

function fetch_season_data($imdb_id, $season_number) {
  global $omdbClient;
  return $omdbClient->getSeasonDetail($imdb_id, $season_number);
}

/**
 * Fetch recent releases for a given year
 */
function fetch_recent_releases(int $year = null): array {
  global $mysqli, $omdbClient;
  if (!$omdbClient->isConfigured()) return [];
  if ($year === null) $year = (int)date('Y');
  
  $collected = [];
  $searches = ['action', 'drama', 'thriller', 'comedy', 'series', 'documentary', 'adventure', 'romance', 'mystery'];
  
  foreach ($searches as $term) {
    foreach (['movie', 'series'] as $type) {
      $url = "https://www.omdbapi.com/?apikey=".$omdbClient->getKey()."&s={$term}&y={$year}&type={$type}";
      
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
          if (isset($collected[$imdb])) continue;
          
          $title = $m['Title'];
          $rawYear = $m['Year'];
          $normalizedYear = str_replace(["\u2013", "\u2014", "–", "—"], '-', $rawYear);
          $movieYear = (int)substr($normalizedYear, 0, 4);
          
          $startYear = null;
          $endYear = null;
          if (strpos($normalizedYear, '-') !== false) {
            $yearParts = explode('-', $normalizedYear);
            $startYear = (int)$yearParts[0];
            $endYear = isset($yearParts[1]) && $yearParts[1] !== '' ? (int)$yearParts[1] : null;
          } else {
            $startYear = $movieYear;
          }
          
          $mType = $m['Type'] ?? $type;
          $poster = $m['Poster'] ?? null;
          if ($poster === 'N/A' || $poster === '') { 
            $poster = null; 
          } elseif ($poster) {
            $poster = str_replace('http://', 'https://', $poster);
          }
          
          if ($poster) {
            $stmt = $mysqli->prepare(
              "INSERT INTO movies (imdb_id, title, year, type, start_year, end_year, poster_url, last_fetched_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, NOW()) 
               ON DUPLICATE KEY UPDATE 
               title=VALUES(title), year=VALUES(year), type=VALUES(type), start_year=VALUES(startYear), end_year=VALUES(endYear),
               poster_url=COALESCE(VALUES(poster_url), poster_url), last_fetched_at=NOW()"
            );
            $stmt->bind_param('ssisiis', $imdb, $title, $movieYear, $mType, $startYear, $endYear, $poster);
            $stmt->execute();
            
            $collected[$imdb] = $mysqli->insert_id ?: (int)$mysqli->query("SELECT id FROM movies WHERE imdb_id='".addslashes($imdb)."'")->fetch_row()[0];
          }
        }
      }
      
      if (count($collected) >= 50) break;
    }
    
    if (count($collected) >= 50) break;
  }
  
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
 * Auto-fetch missing posters
 */
function auto_fetch_missing_posters(): void {
  global $mysqli, $omdbClient;
  
  if (!$omdbClient->isConfigured()) return;
  
  $missing = $mysqli->query("
    SELECT id, title FROM movies 
    WHERE type IN ('movie', 'series')
    AND (poster_url IS NULL OR poster_url = '' OR poster_url = 'N/A') 
    LIMIT 10
  ")->fetch_all(MYSQLI_ASSOC);
  
  if (empty($missing)) return;
  
  foreach ($missing as $movie) {
    try {
      $omdbClient->search($movie['title']);
    } catch (Throwable $e) {
      // Silently skip
    }
  }
}
