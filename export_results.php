<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/lang.php';

// Get current year for filename and headers
$currentYear = date('Y');

// Query all votes with details
$sql = "
    SELECT 
        u.username,
        m.title,
        m.year,
        vd.competition_status,
        vd.category,
        vd.where_watched,
        vd.writing,
        vd.direction,
        vd.acting_or_doc_theme,
        vd.emotional_involvement,
        vd.novelty,
        vd.casting_research_art,
        vd.sound,
        vd.adjective,
        v.created_at
    FROM votes v
    INNER JOIN users u ON u.id = v.user_id
    INNER JOIN movies m ON m.id = v.movie_id
    INNER JOIN vote_details vd ON vd.vote_id = v.id
    ORDER BY m.title ASC, v.created_at DESC
";

$votes = $mysqli->query($sql)->fetch_all(MYSQLI_ASSOC);

// Query for aggregated results
$resultsSql = "
    SELECT 
        m.title,
        m.year,
        vd.category,
        vd.where_watched,
        vd.competition_status,
        COUNT(DISTINCT v.id) AS vote_count,
        ROUND(AVG(vd.writing), 2) AS avg_writing,
        ROUND(AVG(vd.direction), 2) AS avg_direction,
        ROUND(AVG(vd.acting_or_doc_theme), 2) AS avg_acting,
        ROUND(AVG(vd.emotional_involvement), 2) AS avg_emotional,
        ROUND(AVG(vd.novelty), 2) AS avg_novelty,
        ROUND(AVG(vd.casting_research_art), 2) AS avg_casting,
        ROUND(AVG(vd.sound), 2) AS avg_sound,
        GROUP_CONCAT(DISTINCT u.username SEPARATOR ', ') AS voters,
        GROUP_CONCAT(DISTINCT vd.adjective SEPARATOR ', ') AS adjectives
    FROM votes v
    INNER JOIN movies m ON m.id = v.movie_id
    INNER JOIN vote_details vd ON vd.vote_id = v.id
    INNER JOIN users u ON u.id = v.user_id
    GROUP BY m.id, m.title, m.year, vd.category, vd.where_watched, vd.competition_status
    ORDER BY m.title ASC
";

$results = $mysqli->query($resultsSql)->fetch_all(MYSQLI_ASSOC);

// Output as Excel-compatible HTML with formulas
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="IL_DIVANO_DORO_' . $currentYear . '_Results.xls"');
header('Pragma: no-cache');
header('Expires: 0');

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<?mso-application progid="Excel.Sheet"?>';
echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">';

echo '<Styles>
  <Style ss:ID="Header">
    <Font ss:Bold="1"/>
    <Interior ss:Color="#F6C90E" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="Number">
    <NumberFormat ss:Format="0.0"/>
  </Style>
  <Style ss:ID="Formula">
    <NumberFormat ss:Format="0.00"/>
    <Interior ss:Color="#FFFFCC" ss:Pattern="Solid"/>
  </Style>
</Styles>';

echo '<Worksheet ss:Name="Votazioni ' . $currentYear . '">';
echo '<Table>';

// Header row
$headers = [
    'Informazioni Giurati',
    'Cosa hai guardato?',
    'In che categoria vorresti far appartenere il titolo?',
    'Dove lo hai visto?',
    'Scrittura',
    'Regia',
    'Scelta del cast / Tematiche (documentari)',
    'Convolgimento Emotivo',
    'Senso Di Novità',
    'Casting (documentari) / Artwork (Animazione)',
    'Sonoro',
    'Totale',
    'Voto calcolato',
    'Descrivi con un aggettivo quanto vuoi uscito il ' . $currentYear,
    'Giurato',
    'anno è uscito il film'
];

echo '<Row>';
foreach ($headers as $header) {
    echo '<Cell ss:StyleID="Header"><Data ss:Type="String">' . htmlspecialchars($header) . '</Data></Cell>';
}
echo '</Row>';

// Data rows
$rowNum = 2; // Start from row 2 (after header)
foreach ($votes as $vote) {
    echo '<Row>';
    
    // Informazioni Giurati (username)
    echo '<Cell><Data ss:Type="String">' . htmlspecialchars($vote['username']) . '</Data></Cell>';
    
    // Cosa hai guardato? (title + year)
    echo '<Cell><Data ss:Type="String">' . htmlspecialchars($vote['title']) . '</Data></Cell>';
    
    // Categoria
    echo '<Cell><Data ss:Type="String">' . htmlspecialchars($vote['category'] ?: '') . '</Data></Cell>';
    
    // Dove lo hai visto
    echo '<Cell><Data ss:Type="String">' . htmlspecialchars($vote['where_watched'] ?: '') . '</Data></Cell>';
    
    // Scrittura
    echo '<Cell ss:StyleID="Number"><Data ss:Type="Number">' . $vote['writing'] . '</Data></Cell>';
    
    // Regia
    echo '<Cell ss:StyleID="Number"><Data ss:Type="Number">' . $vote['direction'] . '</Data></Cell>';
    
    // Scelta del cast / Tematiche
    echo '<Cell ss:StyleID="Number"><Data ss:Type="Number">' . $vote['acting_or_doc_theme'] . '</Data></Cell>';
    
    // Convolgimento Emotivo
    echo '<Cell ss:StyleID="Number"><Data ss:Type="Number">' . $vote['emotional_involvement'] . '</Data></Cell>';
    
    // Senso Di Novità
    echo '<Cell ss:StyleID="Number"><Data ss:Type="Number">' . $vote['novelty'] . '</Data></Cell>';
    
    // Casting / Artwork
    echo '<Cell ss:StyleID="Number"><Data ss:Type="Number">' . $vote['casting_research_art'] . '</Data></Cell>';
    
    // Sonoro
    echo '<Cell ss:StyleID="Number"><Data ss:Type="Number">' . $vote['sound'] . '</Data></Cell>';
    
    // Totale (Formula: SUM of columns E through K)
    echo '<Cell ss:StyleID="Formula"><Data ss:Type="Number">' . 
         ($vote['writing'] + $vote['direction'] + $vote['acting_or_doc_theme'] + 
          $vote['emotional_involvement'] + $vote['novelty'] + 
          $vote['casting_research_art'] + $vote['sound']) . '</Data></Cell>';
    
    // Voto calcolato (Formula: Average of columns E through K)
    $avg = ($vote['writing'] + $vote['direction'] + $vote['acting_or_doc_theme'] + 
            $vote['emotional_involvement'] + $vote['novelty'] + 
            $vote['casting_research_art'] + $vote['sound']) / 7;
    echo '<Cell ss:StyleID="Formula"><Data ss:Type="Number">' . number_format($avg, 2, '.', '') . '</Data></Cell>';
    
    // Descrivi con un aggettivo
    echo '<Cell><Data ss:Type="String">' . htmlspecialchars($vote['adjective'] ?: '') . '</Data></Cell>';
    
    // Giurato (duplicate for formula reference)
    echo '<Cell><Data ss:Type="String">' . htmlspecialchars($vote['username']) . '</Data></Cell>';
    
    // anno è uscito il film
    echo '<Cell><Data ss:Type="Number">' . $vote['year'] . '</Data></Cell>';
    
    echo '</Row>';
    $rowNum++;
}

echo '</Table>';
echo '</Worksheet>';

// Second Sheet - Risultati (Aggregated Results)
echo '<Worksheet ss:Name="Risultati ' . $currentYear . '">';
echo '<Table>';

// Header row for Risultati
$resultsHeaders = [
    'TITOLO',
    'Categoria',
    'PIATTAFORMA',
    'Concorso',
    'N Valori',
    'Totale',
    'Scrittura',
    'Regia',
    'Recit. / Tema',
    'Coinv. Emotivo',
    'S. di Nuovo',
    'Casting / Ricerca / Artwork',
    'Sonoro',
    'Ripens.',
    'Aggettivi'
];

echo '<Row>';
foreach ($resultsHeaders as $header) {
    echo '<Cell ss:StyleID="Header"><Data ss:Type="String">' . htmlspecialchars($header) . '</Data></Cell>';
}
echo '</Row>';

// Data rows for Risultati
foreach ($results as $result) {
    echo '<Row>';
    
    // TITOLO
    echo '<Cell><Data ss:Type="String">' . htmlspecialchars($result['title']) . '</Data></Cell>';
    
    // Categoria
    echo '<Cell><Data ss:Type="String">' . htmlspecialchars($result['category'] ?: '') . '</Data></Cell>';
    
    // PIATTAFORMA
    echo '<Cell><Data ss:Type="String">' . htmlspecialchars($result['where_watched'] ?: '') . '</Data></Cell>';
    
    // Concorso
    echo '<Cell><Data ss:Type="String">' . htmlspecialchars($result['competition_status'] ?: '') . '</Data></Cell>';
    
    // N Valori (vote count)
    echo '<Cell><Data ss:Type="Number">' . $result['vote_count'] . '</Data></Cell>';
    
    // Totale (sum of all averages)
    $total = $result['avg_writing'] + $result['avg_direction'] + $result['avg_acting'] + 
             $result['avg_emotional'] + $result['avg_novelty'] + 
             $result['avg_casting'] + $result['avg_sound'];
    echo '<Cell ss:StyleID="Number"><Data ss:Type="Number">' . number_format($total, 2, '.', '') . '</Data></Cell>';
    
    // Scrittura
    echo '<Cell ss:StyleID="Number"><Data ss:Type="Number">' . $result['avg_writing'] . '</Data></Cell>';
    
    // Regia
    echo '<Cell ss:StyleID="Number"><Data ss:Type="Number">' . $result['avg_direction'] . '</Data></Cell>';
    
    // Recit. / Tema
    echo '<Cell ss:StyleID="Number"><Data ss:Type="Number">' . $result['avg_acting'] . '</Data></Cell>';
    
    // Coinv. Emotivo
    echo '<Cell ss:StyleID="Number"><Data ss:Type="Number">' . $result['avg_emotional'] . '</Data></Cell>';
    
    // S. di Nuovo
    echo '<Cell ss:StyleID="Number"><Data ss:Type="Number">' . $result['avg_novelty'] . '</Data></Cell>';
    
    // Casting / Ricerca / Artwork
    echo '<Cell ss:StyleID="Number"><Data ss:Type="Number">' . $result['avg_casting'] . '</Data></Cell>';
    
    // Sonoro
    echo '<Cell ss:StyleID="Number"><Data ss:Type="Number">' . $result['avg_sound'] . '</Data></Cell>';
    
    // Ripens. (empty placeholder for thinking/reflection column)
    echo '<Cell><Data ss:Type="String"></Data></Cell>';
    
    // Aggettivi
    echo '<Cell><Data ss:Type="String">' . htmlspecialchars($result['adjectives'] ?: '') . '</Data></Cell>';
    
    echo '</Row>';
}

echo '</Table>';
echo '</Worksheet>';

echo '</Workbook>';
