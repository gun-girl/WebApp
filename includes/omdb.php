<?php
require_once __DIR__.'/../config.php';
// get_movie_or_fetch(): Given a text query, returns matching movies from the local DB.
// If a query has not been attempted today and an OMDb API key is configured,
// it queries OMDb for movies/series, stores results in the local DB, then returns rows.
function get_movie_or_fetch($query): array {
  global $mysqli, $OMDB_API_KEY;
  // Check if this query was already attempted today
  $stmt = $mysqli->prepare("SELECT 1 FROM query_cache WHERE query = ? AND DATE(date) = CURDATE() LIMIT 1");
  $stmt->bind_param('s', $query);
  $stmt->execute();
  $triedToday = (bool) $stmt->get_result()->fetch_row();

  // If attemted today, check the local cache for matching results
  if ($triedToday) {
    $check = $mysqli->prepare("SELECT * FROM movies WHERE title LIKE CONCAT('%',?,'%') ORDER BY year DESC LIMIT 30");
    $check->bind_param('s', $query);
    $check->execute();
    $rows = $check->get_result()->fetch_all(MYSQLI_ASSOC);
    if ($rows) return $rows;
    return [];
  }

  $upsert = $mysqli->prepare("INSERT INTO query_cache (query,date) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE date = NOW()");
  $upsert->bind_param('s', $query);
  $upsert->execute();
  if (!$OMDB_API_KEY) return [];

  // If not, interrogate the OMDb REST API and cache the results
  $searchTypes = ['movie','series'];
  $collected = [];
  foreach ($searchTypes as $t) {
    $url = "https://www.omdbapi.com/?apikey={$OMDB_API_KEY}&type={$t}&s=".urlencode($query);
    $json = json_decode(@file_get_contents($url), true);
    if (!empty($json['Search'])) {
      foreach ($json['Search'] as $m) {
        $imdb = $m['imdbID'];
        $title = $m['Title'];
        $rawYear = $m['Year'];
        $year = (int)substr($rawYear,0,4);
        $type = $m['Type'] ?? $t;
        $poster = $m['Poster'] ?? null;
        if ($poster === 'N/A' || $poster === '') { $poster = null; }

        $detailPoster = null;
        if ($type === 'series' || !$poster) {
          $detail = fetch_omdb_detail_by_id($imdb);
            if ($detail) {
              if (!$poster && !empty($detail['Poster']) && $detail['Poster'] !== 'N/A') {
                $poster = $detail['Poster'];
              }
            }
        }

        $stmt = $mysqli->prepare("INSERT INTO movies (imdb_id,title,year,type,poster_url,last_fetched_at)
        VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE title=VALUES(title),year=VALUES(year),type=VALUES(type),poster_url=COALESCE(VALUES(poster_url),poster_url), last_fetched_at=NOW()");
        $stmt->bind_param('ssiss',$imdb,$title,$year,$type,$poster);
        $stmt->execute();
        $collected[$imdb] = true;
      }
    }
  }

  // Return cached after insertion
  $stmt = $mysqli->prepare("SELECT * FROM movies WHERE title LIKE CONCAT('%',?,'%') ORDER BY year DESC LIMIT 30");
  $stmt->bind_param('s',$query); $stmt->execute();
  $movieData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  return $movieData;
}
// fetch_omdb_detail_by_id(): Given an IMDb ID, fetches detailed metadata from OMDb.
// Returns the decoded JSON array when OMDb responds with success; otherwise null.
function fetch_omdb_detail_by_id(string $imdb_id): ?array {
  global $OMDB_API_KEY;
  if (!$OMDB_API_KEY) return null;
  $url = "https://www.omdbapi.com/?apikey={$OMDB_API_KEY}&i=".urlencode($imdb_id);
  $json = json_decode(@file_get_contents($url), true);
  if (!empty($json['Response']) && $json['Response'] === 'True') return $json;
  return null;
}

/**
 * fetch_recent_releases(): Fetch popular recent movies/series from OMDb API for specified year.
 * Returns an array of movie data fetched and stored in the database.
 */
function fetch_recent_releases(int $year = null): array {
  global $mysqli, $OMDB_API_KEY;
  if (!$OMDB_API_KEY) return [];
  if ($year === null) $year = (int)date('Y');
  
  $collected = [];
  // Search for diverse terms to get various types of content (movies, series, documentaries)
  $searches = ['action', 'drama', 'thriller', 'comedy', 'series', 'documentary', 'adventure', 'romance', 'mystery'];
  
  foreach ($searches as $term) {
    // Search both movies and series types
    foreach (['movie', 'series'] as $type) {
      $url = "https://www.omdbapi.com/?apikey={$OMDB_API_KEY}&s={$term}&y={$year}&type={$type}";
      $json = @json_decode(@file_get_contents($url), true);
      
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