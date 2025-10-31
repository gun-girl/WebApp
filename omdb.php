<?php
require_once __DIR__.'/../config.php';

/**
 * @return Movie[]
 */
function get_movie_or_fetch($query): array {
  global $mysqli, $OMDB_API_KEY;
  // try local by title
  $stmt = $mysqli->prepare("SELECT * FROM movies WHERE title LIKE CONCAT('%',?,'%') ORDER BY year DESC LIMIT 30");
  $stmt->bind_param('s',$query); $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  if($rows || !$OMDB_API_KEY) return $rows;

  // fetch from OMDb search
  $url = "https://www.omdbapi.com/?apikey={$OMDB_API_KEY}&type=movie&s=".urlencode($query);
  $json = json_decode(file_get_contents($url), true);
  if(($json['Search'] ?? null)){
    foreach($json['Search'] as $m){
      $imdb = $m['imdbID']; $title=$m['Title']; $year=intval($m['Year']);
      $type = $m['Type'] ?? 'movie'; $poster=$m['Poster'] ?? null;
      $stmt = $mysqli->prepare("INSERT INTO movies (imdb_id,title,year,type,poster_url,last_fetched_at)
        VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE title=VALUES(title),year=VALUES(year),type=VALUES(type),poster_url=VALUES(poster_url), last_fetched_at=NOW()");
      $stmt->bind_param('ssiss',$imdb,$title,$year,$type,$poster); $stmt->execute();
    }
    // return from DB
    $stmt = $mysqli->prepare("SELECT * FROM movies WHERE title LIKE CONCAT('%',?,'%') ORDER BY year DESC LIMIT 30");
    $stmt->bind_param('s',$query); $stmt->execute();
    $movieData= $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return Movie::getMoviesFromArray($movieData);
  }
  return [];
}
