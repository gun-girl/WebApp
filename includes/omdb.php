<?php
require_once __DIR__.'/../config.php';

/**
 * Search movies locally; if none found and API key is set, fetch from OMDb,
 * cache into DB, then return associative arrays from DB.
 * @return array<int, array<string, mixed>>
 */
function get_movie_or_fetch($query): array {
  global $mysqli, $OMDB_API_KEY;
  // First: try local cached items
  $stmt = $mysqli->prepare("SELECT * FROM movies WHERE title LIKE CONCAT('%',?,'%') ORDER BY year DESC LIMIT 30");
  $stmt->bind_param('s',$query); $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  if ($rows) {
    // Attempt to enrich any rows missing poster_url
    foreach ($rows as &$r) {
      if (empty($r['poster_url']) && !empty($r['imdb_id']) && $OMDB_API_KEY) {
        $detail = fetch_omdb_detail_by_id($r['imdb_id']);
        if ($detail && !empty($detail['Poster']) && $detail['Poster'] !== 'N/A') {
          $r['poster_url'] = $detail['Poster'];
          $upd = $mysqli->prepare("UPDATE movies SET poster_url=? , last_fetched_at=NOW() WHERE imdb_id=?");
          $upd->bind_param('ss',$r['poster_url'],$r['imdb_id']);
          $upd->execute();
        }
      }
    }
    return $rows;
  }
  if (!$OMDB_API_KEY) return [];

  // Try broader search (first movie then series) until we get results
  $searchTypes = ['movie','series'];
  $collected = [];
  foreach ($searchTypes as $t) {
    $url = "https://www.omdbapi.com/?apikey={$OMDB_API_KEY}&type={$t}&s=".urlencode($query);
    $json = json_decode(@file_get_contents($url), true);
    if (!empty($json['Search'])) {
      foreach ($json['Search'] as $m) {
        $imdb = $m['imdbID'];
        $title = $m['Title'];
        // For series year may be "2005–2014" so keep raw then extract first numeric start
        $rawYear = $m['Year'];
        $year = (int)substr($rawYear,0,4);
        $type = $m['Type'] ?? $t;
        $poster = $m['Poster'] ?? null;
        if ($poster === 'N/A' || $poster === '') { $poster = null; }

        // Optional detail fetch to improve poster / metadata for series
        $detailPoster = null;
        if ($type === 'series' || !$poster) {
          $detail = fetch_omdb_detail_by_id($imdb);
            if ($detail) {
              if (!$poster && !empty($detail['Poster']) && $detail['Poster'] !== 'N/A') {
                $poster = $detail['Poster'];
              }
              // Could store extended metadata later once migration is in place
            }
        }

        $stmt = $mysqli->prepare("INSERT INTO movies (imdb_id,title,year,type,poster_url,last_fetched_at)
        VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE title=VALUES(title),year=VALUES(year),type=VALUES(type),poster_url=COALESCE(VALUES(poster_url),poster_url), last_fetched_at=NOW()");
        $stmt->bind_param('ssiss',$imdb,$title,$year,$type,$poster);
        $stmt->execute();
        $collected[$imdb] = true;
      }
      break; // stop after first successful type
    }
  }

  // Return cached after insertion
  $stmt = $mysqli->prepare("SELECT * FROM movies WHERE title LIKE CONCAT('%',?,'%') ORDER BY year DESC LIMIT 30");
  $stmt->bind_param('s',$query); $stmt->execute();
  $movieData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  return $movieData;
}

/**
 * Fetch detailed OMDb info by IMDb ID.
 * Returns associative array or null on failure.
 */
function fetch_omdb_detail_by_id(string $imdb_id): ?array {
  global $OMDB_API_KEY;
  if (!$OMDB_API_KEY) return null;
  $url = "https://www.omdbapi.com/?apikey={$OMDB_API_KEY}&i=".urlencode($imdb_id);
  $json = json_decode(@file_get_contents($url), true);
  if (!empty($json['Response']) && $json['Response'] === 'True') return $json;
  return null;
}
