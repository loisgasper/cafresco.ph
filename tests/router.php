<?php
// Local dev server router: php -S 127.0.0.1:8080 tests/router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/api/')) {
    $_GET['r'] = substr($path, 5);
    require __DIR__ . '/../api/index.php';
    return true;
}
if (preg_match('#^/(lib|sql|tests)/#', $path)) { http_response_code(403); return true; }
if ($path === '/admin' || $path === '/rider') { header('Location: ' . $path . '/'); return true; }
return false;
