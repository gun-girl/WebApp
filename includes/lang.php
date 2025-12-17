<?php
// Simple language loader and translator helper
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Persist language in a cookie for long-lived preference
$langCookieName = 'lang_pref';
$langCookieTtl = 365 * 24 * 60 * 60; // 1 year

// change language via ?lang=xx
if (!empty($_GET['lang'])) {
    $langRequested = preg_replace('/[^a-z]/', '', strtolower($_GET['lang']));
    $_SESSION['lang'] = $langRequested;
    // remember preference across sessions
    setcookie($langCookieName, $langRequested, [
        'expires' => time() + $langCookieTtl,
        'path' => '/',
        'samesite' => 'Lax'
    ]);
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

// Default to Italian; use session, then cookie, else fallback to 'it'
$currentLang = $_SESSION['lang'] ?? ($_COOKIE[$langCookieName] ?? 'it');
$stringsFile = __DIR__ . "/strings/{$currentLang}.php";
if (!file_exists($stringsFile)) {
    $stringsFile = __DIR__ . "/strings/it.php"; // fallback to Italian
}
$L = include $stringsFile;

function t(string $key): string {
    global $L;
    return $L[$key] ?? $key;
}

function current_lang(): string {
    global $langCookieName;
    return $_SESSION['lang'] ?? ($_COOKIE[$langCookieName] ?? 'it');
}
