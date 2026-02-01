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

// Force export labels to Italian regardless of UI language, but keep them centralized in translations
if (function_exists('current_lang')) {
    $itStrings = include __DIR__ . '/includes/strings/it.php';
    if (is_array($itStrings)) {
        $L = $itStrings; // override runtime dictionary for this request
    }
}

// Resolve competition window (start/end dates) same way stats.php does
// This ensures export uses the SAME filters as the stats display
$active_comp_id = function_exists('get_active_competition_id') ? get_active_competition_id() : null;
$active_window_start = null;
$active_window_end = null;
$activeYearNumber = (int)date('Y');

try {
    if ($active_comp_id) {
        $stmtC = $mysqli->prepare("SELECT name, start, `end` FROM competitions WHERE id = ? LIMIT 1");
        if ($stmtC) {
            $stmtC->bind_param('i', $active_comp_id);
            $stmtC->execute();
            $rowC = $stmtC->get_result()->fetch_assoc();
            if ($rowC) {
                $active_window_start = $rowC['start'];
                $active_window_end = $rowC['end'];
                $activeYearNumber = (int)date('Y', strtotime($active_window_end ?: $active_window_start));
            }
        }
    }
    if (!$active_window_start || !$active_window_end) {
        $rowF = $mysqli->query("SELECT name, start, `end` FROM competitions ORDER BY start DESC LIMIT 1")->fetch_assoc();
        if ($rowF) {
            $active_window_start = $rowF['start'];
            $active_window_end = $rowF['end'];
            $activeYearNumber = (int)date('Y', strtotime($rowF['end'] ?: $rowF['start']));
        }
    }
} catch (Throwable $e) {
    // keep defaults
}

// Resolve selected year from ?year= parameter, or use active competition's year
$selectedYearNumber = isset($_GET['year']) ? (int)$_GET['year'] : $activeYearNumber;
$exportYear = $selectedYearNumber;

// If a specific year is requested, find the competition window for that year
// This aligns the export with the stats.php filtering logic
$window_start = $active_window_start;
$window_end = $active_window_end;

if ($selectedYearNumber && isset($mysqli)) {
    try {
        $stmtY = $mysqli->prepare("SELECT id, name, start, `end` FROM competitions WHERE YEAR(start) = ? OR YEAR(`end`) = ? ORDER BY start DESC LIMIT 1");
        if ($stmtY) {
            $stmtY->bind_param('ii', $selectedYearNumber, $selectedYearNumber);
            $stmtY->execute();
            $rowY = $stmtY->get_result()->fetch_assoc();
            if ($rowY) {
                $window_start = $rowY['start'];
                $window_end = $rowY['end'];
            }
        }
    } catch (Throwable $e) { /* ignore */ }
}

// Ensure only admins can run exports
// When running from CLI for debugging, allow execution and optionally pass year as first arg
$bypass_admin = false;
if (PHP_SAPI === 'cli') {
    $bypass_admin = true;
    if (isset($argv[1])) {
        $exportYear = (int)$argv[1];
        // Re-resolve window for CLI-specified year
        if (isset($mysqli)) {
            try {
                $stmtCli = $mysqli->prepare("SELECT start, `end` FROM competitions WHERE YEAR(start) = ? OR YEAR(`end`) = ? ORDER BY start DESC LIMIT 1");
                if ($stmtCli) {
                    $stmtCli->bind_param('ii', $exportYear, $exportYear);
                    $stmtCli->execute();
                    $rowCli = $stmtCli->get_result()->fetch_assoc();
                    if ($rowCli) {
                        $window_start = $rowCli['start'];
                        $window_end = $rowCli['end'];
                    }
                }
            } catch (Throwable $e) { /* ignore */ }
        }
    }
}
if (!$bypass_admin && !is_admin()) {
    redirect(ADDRESS.'/index.php');
}

// Get competition status filter from URL parameter
$selected_status = isset($_GET['status']) ? $_GET['status'] : 'all';

// Query database for competition statuses dynamically and classify
$inCompetitionStatuses = [];
$outCompetitionStatuses = [];
$compStatusResult = $mysqli->query("SELECT DISTINCT TRIM(COALESCE(vd.competition_status,'')) AS status FROM vote_details vd WHERE TRIM(COALESCE(vd.competition_status,'')) <> '' ORDER BY status");
if ($compStatusResult) {
    foreach ($compStatusResult->fetch_all(MYSQLI_ASSOC) as $row) {
        $status = trim($row['status']);
        $low = strtolower($status);
        if ($status === '') continue;
        // Heuristics: treat strings containing 'fuori' or 'out' as OUT of competition
        if (strpos($low, 'fuori') !== false || strpos($low, 'out') !== false) {
                $outCompetitionStatuses[] = $status;
                continue;
        }
        // Treat strings containing 'concor' or 'competit' or year range as IN competition
        if (strpos($low, 'concor') !== false || strpos($low, 'competit') !== false || preg_match('/^\d{4}-\d{4}$/', $status)) {
                $inCompetitionStatuses[] = $status;
                continue;
        }
        // Unknown labels default to IN to avoid excluding legitimate in-window statuses
        $inCompetitionStatuses[] = $status;
    }
}

// Fallback to legacy values if none found
if (empty($inCompetitionStatuses)) {
    $inCompetitionStatuses = ['Concorso', 'In Competizione', 'In Competition'];
}
if (empty($outCompetitionStatuses)) {
    $outCompetitionStatuses = ['Fuori Concorso', 'Out of Competition'];
}

// Build status filter for queries
$statusFilter = '';
if ($selected_status === 'in') {
    $statusList = "'" . implode("','", array_map(function($s) use ($mysqli) { return $mysqli->real_escape_string($s); }, $inCompetitionStatuses)) . "'";
    $statusFilter = " AND COALESCE(vd.competition_status,'') IN ($statusList)";
} elseif ($selected_status === 'out') {
    $statusListOut = "'" . implode("','", array_map(function($s) use ($mysqli) { return $mysqli->real_escape_string($s); }, $outCompetitionStatuses)) . "'";
    $statusFilter = " AND COALESCE(vd.competition_status,'') IN ($statusListOut)";
}

// Get all unique categories from database dynamically
$allCategories = [];
$catResult = $mysqli->query("SELECT DISTINCT COALESCE(vd.category,'') AS cat FROM vote_details vd WHERE TRIM(COALESCE(vd.category,'')) <> '' ORDER BY cat");
if ($catResult) {
  foreach ($catResult->fetch_all(MYSQLI_ASSOC) as $row) {
    if (!empty(trim($row['cat']))) {
      $allCategories[] = trim($row['cat']);
    }
  }
}
if (empty($allCategories)) {
  $allCategories = ['Film', 'Serie', 'Miniserie', 'Documentario', 'Animazione'];
}

// Build movie release date filter using competition window
// This ensures export uses the SAME DATE RANGE as stats.php (m.released)
$whereMovieDate = "1=1"; // Default: no filter
if ($window_start && $window_end) {
    $whereMovieDate = "m.released >= '" . $mysqli->real_escape_string($window_start) . "' AND m.released <= '" . $mysqli->real_escape_string($window_end) . "'";
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

// Localized sheet names - use dynamic year substitution for all sheets
$sheetVotesName = str_replace('{year}', $exportYear, t('sheet_votes'));
$sheetViewsName = str_replace('{year}', $exportYear, t('sheet_views'));
$sheetResultsName = str_replace('{year}', $exportYear, t('sheet_results'));
$sheetJudgesName = t('sheet_judges');
$sheetJudgesCompName = t('sheet_judges_comp');
$sheetTitlesName = t('sheet_titles');
$sheetAdjectivesName = t('sheet_adjectives');
// Dynamic finalists sheet name based on export year
$sheetFinalistsName = 'Finalists ' . $exportYear;

// Build headers for Votazioni (votes) sheet using translations
$headers = [
    t('header_timestamp'),
    t('juror'),
    t('header_title_question'),
    t('header_category_question'),
    t('header_where_watched'),
    t('writing'),
    t('direction'),
    t('acting_or_doc_theme'),
    t('emotional_involvement'),
    t('novelty'),
    t('casting_research_art'),
    t('sound'),
    t('header_rethink'),
    t('adjectives'),
    t('juror'),
    t('year'),
    t('season'),
    t('episode')
];
foreach ($extraVd as $ex) { $headers[] = $ex; }

// Load detailed votes for Votazioni sheet (filter by competition window via movie release date)
$sqlVotes = "SELECT v.*, m.title AS title, u.username AS username, vd.* FROM votes v LEFT JOIN vote_details vd ON vd.vote_id = v.id LEFT JOIN movies m ON m.id=v.movie_id LEFT JOIN users u ON u.id=v.user_id WHERE " . $whereMovieDate . $statusFilter . " ORDER BY m.title, u.username";
$votes = $mysqli->query($sqlVotes)->fetch_all(MYSQLI_ASSOC);

// DEBUG: Log query and result count for troubleshooting
if (PHP_SAPI === 'cli' || (isset($_GET['debug']) && is_admin())) {
    error_log("Export SQL: " . $sqlVotes);
    error_log("Votes found: " . count($votes));
    error_log("Movie date filter: " . $whereMovieDate);
    error_log("Status filter: " . $statusFilter);
}

// Build aggregated results (Risultati)
$sqlResults = "SELECT m.title AS title, COALESCE(vd.category,'') AS category, COALESCE(NULLIF(TRIM(vd.where_watched),''),'') AS where_watched, COALESCE(vd.competition_status,'') AS competition_status, COUNT(v.id) AS vote_count, ROUND(AVG(vd.writing),2) AS avg_writing, ROUND(AVG(vd.direction),2) AS avg_direction, ROUND(AVG(vd.acting_or_doc_theme),2) AS avg_acting, ROUND(AVG(vd.emotional_involvement),2) AS avg_emotional, ROUND(AVG(vd.novelty),2) AS avg_novelty, ROUND(AVG(vd.casting_research_art),2) AS avg_casting, ROUND(AVG(vd.sound),2) AS avg_sound, GROUP_CONCAT(DISTINCT TRIM(vd.adjective) SEPARATOR ', ') AS adjectives FROM votes v LEFT JOIN vote_details vd ON vd.vote_id = v.id LEFT JOIN movies m ON m.id=v.movie_id WHERE " . $whereMovieDate . $statusFilter . " GROUP BY m.title, vd.category, vd.where_watched, vd.competition_status ORDER BY m.title";
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

// Votazioni (detailed votes)
echo '<Worksheet ss:Name="' . htmlspecialchars($sheetVotesName) . '">';
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
    emit_cell(translate_category($vote['category'] ?? ''));
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
echo '<Worksheet ss:Name="' . htmlspecialchars($sheetViewsName) . '">';
echo '<Table>';
echo '<Row>';
emit_cell(t('platform'),'String','Header'); emit_cell(t('category'),'String','Header'); emit_cell(t('unique_titles'),'String','Header'); emit_cell(t('views'),'String','Header'); emit_cell(t('avg_rating_total'),'String','Header');
echo '</Row>';
$sqlViews = "SELECT COALESCE(NULLIF(TRIM(vd.where_watched),''),'Altro') AS platform, COALESCE(NULLIF(TRIM(vd.category),''),'Altro') AS category, COUNT(DISTINCT v.movie_id) AS uniq_titles, COUNT(v.id) AS views, ROUND(AVG($ratingExpr),2) AS avg_rating FROM votes v LEFT JOIN vote_details vd ON vd.vote_id = v.id JOIN movies m ON m.id = v.movie_id WHERE " . $whereMovieDate . $statusFilter . " GROUP BY platform, category ORDER BY platform, category";
$views = $mysqli->query($sqlViews)->fetch_all(MYSQLI_ASSOC);
foreach ($views as $r) {
    echo '<Row>';
    emit_cell($r['platform']); emit_cell(translate_category($r['category'])); emit_cell((int)$r['uniq_titles'],'Number'); emit_cell((int)$r['views'],'Number');
    emit_cell(isset($r['avg_rating']) ? $r['avg_rating'] : '','Number');
    echo '</Row>';
}
echo '</Table>';
echo '</Worksheet>';

// Giudici (all judges)
echo '<Worksheet ss:Name="' . htmlspecialchars($sheetJudgesName) . '">';
echo '<Table>';
echo '<Row>';
$judgeHeaders = [t('judge'), t('votes')];
foreach ($allCategories as $cat) { $judgeHeaders[] = translate_category($cat); }
$judgeHeaders[] = t('avg_total');
foreach ($judgeHeaders as $hc) { emit_cell($hc,'String','Header'); }
echo '</Row>';
$catSums = [];
foreach ($allCategories as $cat) {
  $catEsc = $mysqli->real_escape_string($cat);
  $catSums[] = "SUM(COALESCE(vd.category,'')='$catEsc') AS cat_" . md5($cat);
}
$catSumsStr = implode(', ', $catSums);
$sqlJudges = "SELECT u.username AS judge, COUNT(v.id) AS votes, $catSumsStr, ROUND(AVG($ratingExpr),2) AS avg_rating FROM votes v JOIN users u ON u.id = v.user_id LEFT JOIN vote_details vd ON vd.vote_id = v.id JOIN movies m ON m.id = v.movie_id WHERE " . $whereMovieDate . $statusFilter . " GROUP BY u.username ORDER BY votes DESC";
$judges = $mysqli->query($sqlJudges)->fetch_all(MYSQLI_ASSOC);
foreach ($judges as $j) {
    echo '<Row>';
    emit_cell($j['judge']); 
    emit_cell((int)$j['votes'],'Number');
    foreach ($allCategories as $cat) {
      $key = 'cat_' . md5($cat);
      emit_cell((int)($j[$key] ?? 0),'Number');
    }
    emit_cell(isset($j['avg_rating']) ? $j['avg_rating'] : '','Number');
    echo '</Row>';
}
echo '</Table>'; echo '</Worksheet>';

// Giudici Solo Concorso (Competition judges only)
echo '<Worksheet ss:Name="' . htmlspecialchars($sheetJudgesCompName) . '">';
echo '<Table>';
echo '<Row>';
$judgeCompHeaders = [t('judge'), t('votes')];
foreach ($allCategories as $cat) { $judgeCompHeaders[] = translate_category($cat); }
foreach ($judgeCompHeaders as $hc) { emit_cell($hc,'String','Header'); }
echo '</Row>';
$statusListComp = "'" . implode("','", array_map(function($s) use ($mysqli) { return $mysqli->real_escape_string($s); }, $inCompetitionStatuses)) . "'";
$sqlJudComp = "SELECT u.username AS judge, COUNT(v.id) AS votes, $catSumsStr FROM votes v JOIN users u ON u.id = v.user_id LEFT JOIN vote_details vd ON vd.vote_id = v.id JOIN movies m ON m.id = v.movie_id WHERE COALESCE(vd.competition_status,'') IN ($statusListComp) AND " . $whereMovieDate . " GROUP BY u.username ORDER BY votes DESC";
$judcomp = $mysqli->query($sqlJudComp)->fetch_all(MYSQLI_ASSOC);
foreach ($judcomp as $jc) { 
    echo '<Row>'; 
    emit_cell($jc['judge']); 
    emit_cell((int)$jc['votes'],'Number');
    foreach ($allCategories as $cat) {
      $key = 'cat_' . md5($cat);
      emit_cell((int)($jc[$key] ?? 0),'Number');
    }
    echo '</Row>'; 
}
echo '</Table>'; echo '</Worksheet>';

// Elenco Titoli (Title List)
echo '<Worksheet ss:Name="' . htmlspecialchars($sheetTitlesName) . '">';
echo '<Table>';
echo '<Row>';
emit_cell(t('title'),'String','Header');
echo '</Row>';
$rowsTitles = $mysqli->query("SELECT DISTINCT m.title FROM votes v JOIN movies m ON m.id=v.movie_id LEFT JOIN vote_details vd ON vd.vote_id=v.id WHERE " . $whereMovieDate . $statusFilter . " ORDER BY m.title ASC")->fetch_all(MYSQLI_ASSOC);
foreach ($rowsTitles as $rt) { echo '<Row>'; emit_cell($rt['title']); echo '</Row>'; }
echo '</Table>'; echo '</Worksheet>';

// Elenco Aggettivi (Adjective List)
echo '<Worksheet ss:Name="' . htmlspecialchars($sheetAdjectivesName) . '">';
echo '<Table>';
echo '<Row>';
emit_cell(t('movie'),'String','Header');
emit_cell(t('adjective'),'String','Header');
emit_cell(t('titles'),'String','Header');
emit_cell(t('adjectives'),'String','Header');
echo '</Row>';
$rowsAdj = $mysqli->query("SELECT m.title, GROUP_CONCAT(DISTINCT TRIM(vd.adjective) ORDER BY TRIM(vd.adjective) SEPARATOR ', ') AS adjectives FROM votes v JOIN movies m ON m.id=v.movie_id LEFT JOIN vote_details vd ON vd.vote_id=v.id WHERE TRIM(COALESCE(vd.adjective,''))<>'' AND " . $whereMovieDate . $statusFilter . " GROUP BY m.title ORDER BY m.title")->fetch_all(MYSQLI_ASSOC);
foreach ($rowsAdj as $a) { echo '<Row>'; emit_cell($a['title']); emit_cell(''); emit_cell($a['title']); emit_cell($a['adjectives']); echo '</Row>'; }
echo '</Table>'; echo '</Worksheet>';

// Finalisti
echo '<Worksheet ss:Name="' . htmlspecialchars($sheetFinalistsName) . '">';
echo '<Table>';
echo '<Row>';
emit_cell(t('title'),'String','Header');
emit_cell(t('category'),'String','Header');
echo '</Row>';
$rowsFinal = $mysqli->query("SELECT DISTINCT m.title, COALESCE(vd.category,'') AS category FROM votes v JOIN movies m ON m.id=v.movie_id LEFT JOIN vote_details vd ON vd.vote_id=v.id WHERE " . $whereMovieDate . $statusFilter . " ORDER BY m.title")->fetch_all(MYSQLI_ASSOC);
foreach ($rowsFinal as $f) { echo '<Row>'; emit_cell($f['title']); emit_cell(translate_category($f['category'])); echo '</Row>'; }
echo '</Table>'; echo '</Worksheet>';

// Risultati (Aggregated Results)
echo '<Worksheet ss:Name="' . htmlspecialchars($sheetResultsName) . '">';
echo '<Table>';

// Header row for Risultati
$resultsHeaders = [
    t('title'),
    t('category'),
    t('platform'),
    t('competition_status'),
    t('views'),
    t('total'),
    t('writing'),
    t('direction'),
    t('acting_or_doc_theme'),
    t('emotional_involvement'),
    t('novelty'),
    t('casting_research_art'),
    t('sound'),
    t('header_rethink'),
    t('adjectives')
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
    emit_cell(translate_category($result['category'] ?? ''));
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
