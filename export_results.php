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
    ORDER BY v.created_at DESC
";

$votes = $mysqli->query($sql)->fetch_all(MYSQLI_ASSOC);

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
echo '</Workbook>';
