<?php require_once __DIR__.'/includes/auth.php'; include __DIR__.'/includes/header.php';

$sql = "
  SELECT m.id, m.title, m.year, m.poster_url,
         COUNT(v.id) AS votes_count,
         ROUND(AVG(v.rating),2) AS avg_rating
  FROM movies m
  LEFT JOIN votes v ON v.movie_id = m.id
  GROUP BY m.id
  HAVING votes_count > 0
  ORDER BY avg_rating DESC, votes_count DESC
  LIMIT 100
";
$rows = $mysqli->query($sql)->fetch_all(MYSQLI_ASSOC);
?>
<h2>Results</h2>
<table class="table">
  <thead><tr><th>Movie</th><th>Year</th><th>Votes</th><th>Average</th></tr></thead>
  <tbody>
  <?php foreach($rows as $r): ?>
    <tr>
      <td><?= e($r['title']) ?></td>
      <td><?= e($r['year']) ?></td>
      <td><?= (int)$r['votes_count'] ?></td>
      <td><?= e($r['avg_rating']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php include __DIR__.'/includes/footer.php';
