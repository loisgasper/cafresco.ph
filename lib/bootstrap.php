<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

function config(?string $key = null, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $candidates = array_filter([
            getenv('CAFRESCO_CONFIG') ?: null,
            dirname(APP_ROOT) . '/cafresco-config.php', // outside public_html on Hostinger (preferred)
            APP_ROOT . '/.private/config.php',           // fallback inside public_html, blocked by .htaccess
            APP_ROOT . '/config.local.php',              // local development only (gitignored)
        ]);
        $cfg = [];
        foreach ($candidates as $file) {
            if (is_file($file)) { $cfg = require $file; break; }
        }
        if (!$cfg) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'App is not configured yet. See README: create cafresco-config.php outside public_html.']);
            exit;
        }
    }
    if ($key === null) return $cfg;
    $val = $cfg;
    foreach (explode('.', $key) as $part) {
        if (!is_array($val) || !array_key_exists($part, $val)) return $default;
        $val = $val[$part];
    }
    return $val;
}

date_default_timezone_set(config('timezone', 'Asia/Manila'));

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            config('db.host', '127.0.0.1'), (int)config('db.port', 3306), config('db.name'));
        $pdo = new PDO($dsn, config('db.user'), config('db.pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Pin the connection collation to match the tables (MariaDB 11 defaults differ and break comparisons).
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function q1(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}
function qall(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}
function now(): string { return date('Y-m-d H:i:s'); }

class HttpError extends Exception
{
    public int $status;
    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}
function fail(int $status, string $message): never { throw new HttpError($status, $message); }

function json_out($data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function input(): array
{
    static $data = null;
    if ($data === null) {
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($ct, 'application/json')) {
            $data = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
        } else {
            $data = $_POST;
        }
    }
    return $data;
}
function in(string $key, $default = null)
{
    $d = input();
    return array_key_exists($key, $d) ? $d[$key] : $default;
}
function str_in(string $key, int $max = 255, bool $required = false): string
{
    $v = trim((string)in($key, ''));
    if ($required && $v === '') fail(422, "Missing field: $key");
    return mb_substr($v, 0, $max);
}

function &settings_cache(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (qall('SELECT k, v FROM settings') as $r) $cache[$r['k']] = $r['v'];
    }
    return $cache;
}
function setting(string $k, $default = null)
{
    $v = settings_cache()[$k] ?? null;
    return ($v === null || $v === '') && $default !== null ? $default : $v;
}
function set_setting(string $k, ?string $v): void
{
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
    $c = &settings_cache();
    $c[$k] = $v;
}

function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

/** Fixed-window rate limiter backed by MySQL. */
function rate_limit(string $bucket, int $max, int $windowSeconds): void
{
    $key = substr($bucket, 0, 190);
    $nowTs = time();
    $row = q1('SELECT window_start, hits FROM rate_limits WHERE rl_key = ?', [$key]);
    if (!$row || $nowTs - (int)$row['window_start'] >= $windowSeconds) {
        q('REPLACE INTO rate_limits (rl_key, window_start, hits) VALUES (?, ?, 1)', [$key, $nowTs]);
        return;
    }
    if ((int)$row['hits'] >= $max) fail(429, 'Too many attempts. Please wait a few minutes and try again.');
    q('UPDATE rate_limits SET hits = hits + 1 WHERE rl_key = ?', [$key]);
}

function normalize_mobile(string $m): ?string
{
    $d = preg_replace('/\D+/', '', $m);
    if (preg_match('/^09\d{9}$/', $d)) return '+63' . substr($d, 1);
    if (preg_match('/^639\d{9}$/', $d)) return '+' . $d;
    if (preg_match('/^9\d{9}$/', $d)) return '+63' . $d;
    return null;
}

function money($n): float { return round((float)$n, 2); }

function app_url(string $path = ''): string
{
    return rtrim((string)config('app_url', ''), '/') . '/' . ltrim($path, '/');
}

function upload_dir(): string
{
    $dir = config('upload_dir') ?: dirname(APP_ROOT) . '/cafresco-uploads';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return rtrim($dir, '/');
}

/** Validate an uploaded image and store it with a random name. Returns the stored file name. */
function store_upload(string $field, string $prefix, int $maxBytes = 5_000_000): string
{
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail(422, 'Please attach an image.');
    if ($f['size'] > $maxBytes) fail(422, 'Image is too large (max 5 MB).');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext || @getimagesize($f['tmp_name']) === false) fail(422, 'Only JPG, PNG or WEBP images are allowed.');
    $name = $prefix . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], upload_dir() . '/' . $name)) fail(500, 'Could not save the file.');
    return $name;
}

function send_upload(string $name): never
{
    $name = basename($name);
    $path = upload_dir() . '/' . $name;
    if ($name === '' || !is_file($path)) fail(404, 'File not found');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

require __DIR__ . '/auth.php';
require __DIR__ . '/mailer.php';
require __DIR__ . '/sms.php';
require __DIR__ . '/notify.php';
require __DIR__ . '/orders.php';
