<?php
// Simple language loader and translator helper
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// change language via ?lang=xx
if (!empty($_GET['lang'])) {
    $langRequested = preg_replace('/[^a-z]/', '', strtolower($_GET['lang']));
    $_SESSION['lang'] = $langRequested;
    // Prefer redirecting to the referring page if available
    if (!empty($_SERVER['HTTP_REFERER'])) {
        header('Location: ' . $_SERVER['HTTP_REFERER']);
    } else {
        // fallback: redirect back to the same page without lang param
        $uri = $_SERVER['REQUEST_URI'];
        $parts = parse_url($uri);
        $path = $parts['path'] ?? '';
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            unset($query['lang']);
        }
        $newUrl = $path;
        if (!empty($query)) {
            $newUrl .= '?' . http_build_query($query);
        }
        header('Location: ' . $newUrl);
    }
    exit;
}

$currentLang = $_SESSION['lang'] ?? 'en';
$stringsFile = __DIR__ . "/strings/{$currentLang}.php";
if (!file_exists($stringsFile)) {
    $stringsFile = __DIR__ . "/strings/en.php"; // fallback
}
$L = include $stringsFile;

function t(string $key): string {
    global $L;
    return $L[$key] ?? $key;
}

function current_lang(): string {
    return $_SESSION['lang'] ?? 'en';
}
