<?php
// Prevent any accidental output (warnings, whitespace, BOMs) from breaking the XML
@ini_set('display_errors', 0);
@ini_set('display_startup_errors', 0);
error_reporting(0);
@ini_set('zlib.output_compression', '0');
// Start an output buffer so we can discard anything printed by includes before sending headers
ob_start();

require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/lang.php';

// Get current year for filename and headers
// Use active competition year if available
$currentYear = function_exists('get_active_year') ? get_active_year() : (int)date('Y');

// Respect ?year= parameter for exporting a different competition year (falls back to currentYear)
$selected_year = (int)($_GET['year'] ?? $currentYear);
$exportYear = $selected_year;

// Ensure only admins can run exports
// When running from CLI for debugging, allow execution and optionally pass year as first arg
$bypass_admin = false;
if (PHP_SAPI === 'cli') {
    $bypass_admin = true;
    if (isset($argv[1])) {
        $exportYear = (int)$argv[1];
        $selected_year = $exportYear;
    }
}
if (!$bypass_admin && !is_admin()) {
    redirect(ADDRESS.'/index.php');
}
// Prepare year filtering and detect available columns
$voteCols = $mysqli->query("SHOW COLUMNS FROM votes")->fetch_all(MYSQLI_ASSOC);
$hasCompetitionYear = false;
$hasRating = false;
foreach ($voteCols as $c) {
    if ($c['Field'] === 'competition_year') $hasCompetitionYear = true;
    if ($c['Field'] === 'rating') $hasRating = true;
}

if ($hasCompetitionYear) {
    $whereYear = "v.competition_year = " . (int)$exportYear;
} else {
    $whereYear = "YEAR(v.created_at) = " . (int)$exportYear;
}

// Inspect vote_details to find extra columns to include in exports
$vdColsAll = $mysqli->query("SHOW COLUMNS FROM vote_details")->fetch_all(MYSQLI_ASSOC);
$standardVd = ['id','vote_id','adjective','category','where_watched','competition_status','writing','direction','acting_or_doc_theme','emotional_involvement','novelty','casting_research_art','sound','season_number','year'];
$extraVd = [];
foreach ($vdColsAll as $c) {
    if (!in_array($c['Field'], $standardVd)) {
        $extraVd[] = $c['Field'];
    }
}

// Build headers for Votazioni (votes) sheet - Italian labels
$headers = [
    'S', 'Giurato/a', 'Cosa hai guardato? (Utilizzare il titolo tradotto in Italiano)',
    'A quale categoria appartien e il titolo?', 'Dove lo hai visto?', 'Scrittura', 'Regia',
    'Recitazione/ Scelta del tema (documentari)', 'Coinvolgimento Emotivo', 'Senso Di Nuovo',
    'Casting/Ricerca (documentari)/ Artwork', 'Sonoro', 'Ripens.', 'Aggettivi',
    'Giurato', 'Anno', 'Stagione', 'Episodio'
];
foreach ($extraVd as $ex) $headers[] = $ex;

// Load detailed votes for Votazioni sheet
$sqlVotes = "SELECT v.*, m.title AS title, u.username AS username, vd.* FROM votes v LEFT JOIN vote_details vd ON vd.vote_id = v.id LEFT JOIN movies m ON m.id=v.movie_id LEFT JOIN users u ON u.id=v.user_id WHERE " . $whereYear . " ORDER BY m.title, u.username";
$votes = $mysqli->query($sqlVotes)->fetch_all(MYSQLI_ASSOC);

// Build aggregated results (Risultati)
$sqlResults = "SELECT m.title AS title, COALESCE(vd.category,'') AS category, COALESCE(NULLIF(TRIM(vd.where_watched),''),'') AS where_watched, COALESCE(vd.competition_status,'') AS competition_status, COUNT(v.id) AS vote_count, ROUND(AVG(vd.writing),2) AS avg_writing, ROUND(AVG(vd.direction),2) AS avg_direction, ROUND(AVG(vd.acting_or_doc_theme),2) AS avg_acting, ROUND(AVG(vd.emotional_involvement),2) AS avg_emotional, ROUND(AVG(vd.novelty),2) AS avg_novelty, ROUND(AVG(vd.casting_research_art),2) AS avg_casting, ROUND(AVG(vd.sound),2) AS avg_sound, GROUP_CONCAT(DISTINCT TRIM(vd.adjective) SEPARATOR ', ') AS adjectives FROM votes v LEFT JOIN vote_details vd ON vd.vote_id = v.id LEFT JOIN movies m ON m.id=v.movie_id WHERE " . $whereYear . " GROUP BY m.title, vd.category, vd.where_watched, vd.competition_status ORDER BY m.title";
$results = $mysqli->query($sqlResults)->fetch_all(MYSQLI_ASSOC);
// Re-add the remaining sheets to match UI tabs: Views, Judges, Judges - Competition Only, Title List, Adjective List, Finalists, RAW

// Build rating expression for aggregated sheets
$vdCols = $mysqli->query("SHOW COLUMNS FROM vote_details")->fetch_all(MYSQLI_ASSOC);
$numericCols = [];
foreach ($vdCols as $c) {
    $t = strtolower($c['Type']);
    if (strpos($t,'tinyint')!==false || strpos($t,'smallint')!==false || strpos($t,'int(')!==false || strpos($t,'int ')!==false || strpos($t,'decimal')!==false || strpos($t,'float')!==false || strpos($t,'double')!==false) {
        $numericCols[] = $c['Field'];
    }
}
$numExpr = null;
if ($numericCols) {
    $numParts = array_map(function($col){ return "COALESCE(vd.`$col`,0)"; }, $numericCols);
    $numExpr = implode('+',$numParts);
}
// Use per-vote total (sum of category scores) for all averaged metrics
$ratingExpr = $numExpr ? "($numExpr)" : 'NULL';

// helper to safely emit a cell (avoid empty Number Data elements)
function emit_cell($value = '', $type = 'String', $style = null) {
    $attrs = $style ? ' ss:StyleID="'.htmlspecialchars($style).'"' : '';
    if ($type === 'Number') {
        if ($value === '' || $value === null) {
            // emit empty string cell to avoid invalid empty Number elements
            echo '<Cell'.$attrs.'><Data ss:Type="String"></Data></Cell>';
            return;
        }
        $num = is_numeric($value) ? $value : str_replace(',', '.', (string)$value);
        echo '<Cell'.$attrs.'><Data ss:Type="Number">'.htmlspecialchars($num).'</Data></Cell>';
        return;
    }
    echo '<Cell'.$attrs.'><Data ss:Type="String">'.htmlspecialchars((string)$value).'</Data></Cell>';
}

// Clear any output that may have been produced by included files so XML prolog is first
while (ob_get_level() > 0) { ob_end_clean(); }

// Send download headers and Workbook prolog
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="IL_DIVANO_DORO_' . $exportYear . '_Results.xls"');

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
echo '<Styles>';
echo '<Style ss:ID="Header"><Font ss:Bold="1"/></Style>';
echo '<Style ss:ID="Number"><NumberFormat ss:Format="General"/></Style>';
echo '<Style ss:ID="Formula"><NumberFormat ss:Format="General"/></Style>';
echo '</Styles>';

// Votazioni 2024 (detailed votes with proper Italian headers)
echo '<Worksheet ss:Name="Votazioni ' . $exportYear . '">';
echo '<Table>';
echo '<Row>';
foreach ($headers as $h) emit_cell($h,'String','Header');
echo '</Row>';
foreach ($votes as $vote) {
    echo '<Row>';
    // Timestamp
    emit_cell($vote['created_at'] ?? '');
    // Giurato/a
    emit_cell($vote['username']);
    // Cosa hai guardato
    emit_cell($vote['title']);
    // A quale categoria
    emit_cell($vote['category'] ?? '');
    // Dove lo hai visto
    emit_cell($vote['where_watched'] ?? '');
    // Numeric scores
    foreach (['writing','direction','acting_or_doc_theme','emotional_involvement','novelty','casting_research_art','sound'] as $col) {
        emit_cell(isset($vote[$col]) ? $vote[$col] : '','Number');
    }
    // Ripens. (placeholder)
    emit_cell('');
    // Aggettivi
    emit_cell($vote['adjective'] ?? '');
    // Giurato (duplicate for sorting)
    emit_cell($vote['username'] ?? '');
    // Anno
    emit_cell($vote['year'] ?? '','Number');
    // Stagione
    emit_cell($vote['season_number'] ?? '');
    // Episodio  
    emit_cell($vote['episode_number'] ?? '');
    // Extra columns
    foreach ($extraVd as $excol) { emit_cell($vote[$excol] ?? ''); }
    echo '</Row>';
}
echo '</Table>';
echo '</Worksheet>';

// Visioni (Views)
echo '<Worksheet ss:Name="Visioni ' . $exportYear . '">';
echo '<Table>';
echo '<Row>';
emit_cell('PIATTAFORMA','String','Header'); emit_cell('Categoria','String','Header'); emit_cell('Titoli Unici','String','Header'); emit_cell('Visioni','String','Header'); emit_cell('Media Totale','String','Header');
echo '</Row>';
$sqlViews = "SELECT COALESCE(NULLIF(TRIM(vd.where_watched),''),'Altro') AS platform, COALESCE(NULLIF(TRIM(vd.category),''),'Altro') AS category, COUNT(DISTINCT v.movie_id) AS uniq_titles, COUNT(v.id) AS views, ROUND(AVG($ratingExpr),2) AS avg_rating FROM votes v LEFT JOIN vote_details vd ON vd.vote_id = v.id WHERE " . $whereYear . " GROUP BY platform, category ORDER BY platform, category";
$views = $mysqli->query($sqlViews)->fetch_all(MYSQLI_ASSOC);
foreach ($views as $r) {
    echo '<Row>';
    emit_cell($r['platform']); emit_cell($r['category']); emit_cell((int)$r['uniq_titles'],'Number'); emit_cell((int)$r['views'],'Number');
    emit_cell(isset($r['avg_rating']) ? $r['avg_rating'] : '','Number');
    echo '</Row>';
}
echo '</Table>';
echo '</Worksheet>';

// Giudici (all judges)
echo '<Worksheet ss:Name="Giudici">';
echo '<Table>';
echo '<Row>';
foreach (['Giudice','Voti','Film','Serie','Miniserie','Documentario','Animazione','Media Tot Votazioni'] as $hc) emit_cell($hc,'String','Header');
echo '</Row>';
$sqlJudges = "SELECT u.username AS judge, COUNT(v.id) AS votes, SUM(COALESCE(vd.category,'')='Film') AS film_count, SUM(COALESCE(vd.category,'')='Serie') AS series_count, SUM(COALESCE(vd.category,'')='Miniserie') AS miniseries_count, SUM(COALESCE(vd.category,'')='Documentario') AS doc_count, SUM(COALESCE(vd.category,'')='Animazione') AS anim_count, ROUND(AVG($ratingExpr),2) AS avg_rating FROM votes v JOIN users u ON u.id = v.user_id LEFT JOIN vote_details vd ON vd.vote_id = v.id WHERE " . $whereYear . " GROUP BY u.username ORDER BY votes DESC";
$judges = $mysqli->query($sqlJudges)->fetch_all(MYSQLI_ASSOC);
foreach ($judges as $j) {
    echo '<Row>';
    emit_cell($j['judge']); emit_cell((int)$j['votes'],'Number'); emit_cell((int)$j['film_count'],'Number'); emit_cell((int)$j['series_count'],'Number'); emit_cell((int)$j['miniseries_count'],'Number'); emit_cell((int)$j['doc_count'],'Number'); emit_cell((int)$j['anim_count'],'Number'); emit_cell(isset($j['avg_rating']) ? $j['avg_rating'] : '','Number');
    echo '</Row>';
}
echo '</Table>'; echo '</Worksheet>';

// Giudici Solo Concorso (Competition judges only)
echo '<Worksheet ss:Name="Giudici Solo Concorso">';
echo '<Table>';
echo '<Row>';
foreach (['Giudice','Voti','Film','Serie','Miniserie','Documentario','Animazione'] as $hc) emit_cell($hc,'String','Header');
echo '</Row>';
$sqlJudComp = "SELECT u.username AS judge, COUNT(v.id) AS votes, SUM(COALESCE(vd.category,'')='Film') AS film_count, SUM(COALESCE(vd.category,'')='Serie') AS series_count, SUM(COALESCE(vd.category,'')='Miniserie') AS miniseries_count, SUM(COALESCE(vd.category,'')='Documentario') AS doc_count, SUM(COALESCE(vd.category,'')='Animazione') AS anim_count FROM votes v JOIN users u ON u.id = v.user_id LEFT JOIN vote_details vd ON vd.vote_id = v.id WHERE (COALESCE(vd.competition_status,'') IN ('Concorso','In Competizione','In Competition','2023-2024')) AND " . $whereYear . " GROUP BY u.username ORDER BY votes DESC";
$judcomp = $mysqli->query($sqlJudComp)->fetch_all(MYSQLI_ASSOC);
foreach ($judcomp as $jc) { 
    echo '<Row>'; 
    emit_cell($jc['judge']); 
    emit_cell((int)$jc['votes'],'Number'); 
    emit_cell((int)$jc['film_count'],'Number');
    emit_cell((int)$jc['series_count'],'Number');
    emit_cell((int)$jc['miniseries_count'],'Number');
    emit_cell((int)$jc['doc_count'],'Number');
    emit_cell((int)$jc['anim_count'],'Number');
    echo '</Row>'; 
}
echo '</Table>'; echo '</Worksheet>';

// Elenco Titoli (Title List)
echo '<Worksheet ss:Name="Elenco Titoli">';
echo '<Table>';
echo '<Row>';
emit_cell('TITOLO','String','Header');
echo '</Row>';
$rowsTitles = $mysqli->query("SELECT DISTINCT m.title FROM votes v JOIN movies m ON m.id=v.movie_id WHERE " . $whereYear . " ORDER BY m.title ASC")->fetch_all(MYSQLI_ASSOC);
foreach ($rowsTitles as $rt) { echo '<Row>'; emit_cell($rt['title']); echo '</Row>'; }
echo '</Table>'; echo '</Worksheet>';

// Elenco Aggettivi (Adjective List)
echo '<Worksheet ss:Name="Elenco Aggettivi">';
echo '<Table>';
echo '<Row>';
emit_cell('FILM','String','Header');
emit_cell('AGGETTIVO','String','Header');
emit_cell('Lista Film','String','Header');
emit_cell('Aggettivi','String','Header');
echo '</Row>';
$rowsAdj = $mysqli->query("SELECT m.title, GROUP_CONCAT(DISTINCT TRIM(vd.adjective) ORDER BY TRIM(vd.adjective) SEPARATOR ', ') AS adjectives FROM votes v JOIN movies m ON m.id=v.movie_id LEFT JOIN vote_details vd ON vd.vote_id=v.id WHERE TRIM(COALESCE(vd.adjective,''))<>'' AND " . $whereYear . " GROUP BY m.title ORDER BY m.title")->fetch_all(MYSQLI_ASSOC);
foreach ($rowsAdj as $a) { echo '<Row>'; emit_cell($a['title']); emit_cell(''); emit_cell($a['title']); emit_cell($a['adjectives']); echo '</Row>'; }
echo '</Table>'; echo '</Worksheet>';

// Finalisti 2023 (Finalists)
echo '<Worksheet ss:Name="Finalisti 2023">';
echo '<Table>';
echo '<Row>';
emit_cell('Titolo','String','Header');
emit_cell('Altro','String','Header');
echo '</Row>';
$rowsFinal = $mysqli->query("SELECT DISTINCT m.title, COALESCE(vd.category,'') AS category FROM votes v JOIN movies m ON m.id=v.movie_id LEFT JOIN vote_details vd ON vd.vote_id=v.id WHERE " . $whereYear . " ORDER BY m.title")->fetch_all(MYSQLI_ASSOC);
foreach ($rowsFinal as $f) { echo '<Row>'; emit_cell($f['title']); emit_cell($f['category']); echo '</Row>'; }
echo '</Table>'; echo '</Worksheet>';

// Risultati (Aggregated Results)
echo '<Worksheet ss:Name="Risultati ' . $exportYear . '">';
echo '<Table>';

// Header row for Risultati
$resultsHeaders = [
    'TITOLO',
    'Categoria',
    'PIATTAFORMA',
    'Concorso',
    'Visioni',
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
    emit_cell($result['title'] ?? '');
    // Categoria
    emit_cell($result['category'] ?? '');
    // PIATTAFORMA
    emit_cell($result['where_watched'] ?? '');
    // Concorso
    emit_cell($result['competition_status'] ?? '');
    // Visioni (vote count)
    emit_cell(isset($result['vote_count']) ? (int)$result['vote_count'] : '', 'Number');

    // Totale (sum of all averages) - treat missing as 0
    $avg_fields = ['avg_writing','avg_direction','avg_acting','avg_emotional','avg_novelty','avg_casting','avg_sound'];
    $total = 0.0; foreach ($avg_fields as $f) { $total += isset($result[$f]) && $result[$f] !== null && $result[$f] !== '' ? (float)$result[$f] : 0.0; }
    emit_cell(number_format($total,2,'.',''), 'Number');

    // individual averages
    foreach ($avg_fields as $f) {
        emit_cell(isset($result[$f]) && $result[$f] !== null && $result[$f] !== '' ? number_format($result[$f],2,'.','') : '', 'Number');
    }

    // Ripens. placeholder
    emit_cell('');
    // Aggettivi
    emit_cell($result['adjectives'] ?? '');
    echo '</Row>';
}

echo '</Table>';
echo '</Worksheet>';

echo '</Workbook>';
