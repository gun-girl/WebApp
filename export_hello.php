<?php
// Export aggregated voting results in an Excel-compatible format (.xls via HTML table)
// Keep it dependency-free and reuse the same aggregation logic as stats.php

require_once __DIR__.'/includes/auth.php'; // loads $mysqli and helper functions

// Build the results query (same logic as in stats.php)
$cols = $mysqli->query("SHOW COLUMNS FROM votes")->fetch_all(MYSQLI_ASSOC);
$fields = array_column($cols, 'Field');
$hasRating = in_array('rating', $fields);

if ($hasRating) {
	$sql = "
		SELECT m.id, m.title, m.year, COUNT(v.id) AS votes_count, ROUND(AVG(v.rating),2) AS avg_rating
		FROM movies m
		LEFT JOIN votes v ON v.movie_id = m.id
		GROUP BY m.id
		HAVING votes_count > 0
		ORDER BY avg_rating DESC, votes_count DESC
	";
} else {
	$vdCols = $mysqli->query("SHOW COLUMNS FROM vote_details")->fetch_all(MYSQLI_ASSOC);
	$numericCols = [];
	foreach ($vdCols as $c) {
		$t = strtolower($c['Type']);
		if (strpos($t, 'tinyint') !== false || strpos($t, 'smallint') !== false || strpos($t, 'int(') !== false || strpos($t, 'int ') !== false || strpos($t, 'decimal') !== false || strpos($t, 'float') !== false || strpos($t, 'double') !== false) {
			$numericCols[] = $c['Field'];
		}
	}
	if ($numericCols) {
		$numParts = array_map(function($col){ return "COALESCE(vd.`$col`,0)"; }, $numericCols);
		$denParts = array_map(function($col){ return "(vd.`$col` IS NOT NULL)"; }, $numericCols);
		$numExpr = implode('+', $numParts);
		$denExpr = implode('+', $denParts);
		$sql = "
			SELECT m.id, m.title, m.year, COUNT(v.id) AS votes_count,
						 ROUND(AVG( ( ($numExpr) / NULLIF($denExpr,0) ) ),2) AS avg_rating
			FROM movies m
			LEFT JOIN votes v ON v.movie_id = m.id
			LEFT JOIN vote_details vd ON vd.vote_id = v.id
			GROUP BY m.id
			HAVING votes_count > 0
			ORDER BY avg_rating DESC, votes_count DESC
		";
	} else {
		$sql = "
			SELECT m.id, m.title, m.year, COUNT(v.id) AS votes_count, NULL AS avg_rating
			FROM movies m
			LEFT JOIN votes v ON v.movie_id = m.id
			GROUP BY m.id
			HAVING votes_count > 0
			ORDER BY votes_count DESC
		";
	}
}

$rows = $mysqli->query($sql)->fetch_all(MYSQLI_ASSOC);

// Output headers for Excel-compatible download
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="results.xls"');
header('Pragma: no-cache');
header('Expires: 0');

echo "<html><head><meta charset=\"UTF-8\"></head><body>\n";
echo "<table border=\"1\">";
echo "<thead><tr><th>Movie</th><th>Year</th><th>Votes</th><th>Average</th></tr></thead><tbody>";
foreach ($rows as $r) {
	$title = e($r['title']);
	$year = e($r['year']);
	$votes = (int)$r['votes_count'];
	$avg   = e($r['avg_rating']);
	echo "<tr><td>{$title}</td><td>{$year}</td><td>{$votes}</td><td>{$avg}</td></tr>";
}
echo "</tbody></table>\n";
echo "</body></html>";
