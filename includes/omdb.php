<?php
require_once __DIR__.'/../config.php';

class OmdbApiClient {
  private $apiKey;
  private $mysqli;
  
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
    
    // STEP 1: Check if we already have this movie/series in database
    $check = $this->mysqli->prepare("SELECT * FROM movies WHERE title LIKE CONCAT('%',?,'%') ORDER BY year DESC LIMIT 30");
    $check->bind_param('s', $query);
    $check->execute();
    $localResults = $check->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // If we have results in database, return them immediately (cached from previous API call)
    if (!empty($localResults)) {
      error_log("[OmdbApiClient] Found " . count($localResults) . " cached results in database for '$query'");
      return $localResults;
    }
    
    error_log("[OmdbApiClient] No results in database for '$query'. Calling API...");
    
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
        
        if ($poster === 'N/A' || $poster === '') {
          $poster = null;
        }

        // Try to get better poster for series
        if ($type === 'series' && !$poster) {
          $detail = $this->getDetail($imdb);
          if ($detail && !empty($detail['Poster']) && $detail['Poster'] !== 'N/A') {
            $poster = $detail['Poster'];
          }
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

    // STEP 5: Return results from database after API insert
    $check = $this->mysqli->prepare("SELECT * FROM movies WHERE title LIKE CONCAT('%',?,'%') ORDER BY year DESC LIMIT 30");
    $check->bind_param('s', $query);
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
          if ($poster === 'N/A' || $poster === '') { $poster = null; }
          
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