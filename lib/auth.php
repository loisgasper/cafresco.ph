<?php
declare(strict_types=1);

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('cafresco_sid');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
    session_start();
}

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) return $user;
    start_session();
    $id = $_SESSION['uid'] ?? null;
    $user = $id ? q1('SELECT * FROM users WHERE id = ?', [$id]) : null;
    if ($user && $user['status'] !== 'active') { $user = null; $_SESSION = []; }
    return $user;
}

function login_user(array $u): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    q('UPDATE users SET last_login_at = ? WHERE id = ?', [now(), $u['id']]);
}

function logout_user(): void
{
    start_session();
    $_SESSION = [];
    session_destroy();
}

function require_user(): array
{
    $u = current_user();
    if (!$u) fail(401, 'Please log in first.');
    return $u;
}

function require_role(string ...$roles): array
{
    $u = require_user();
    if (!in_array($u['role'], $roles, true)) fail(403, 'You do not have access to this.');
    return $u;
}

function require_staff(): array { return require_role('staff', 'admin'); }
function require_admin(): array { return require_role('admin'); }

/** Public shape of a user for the frontend. */
function user_public(?array $u): ?array
{
    if (!$u) return null;
    $out = [
        'id' => (int)$u['id'],
        'role' => $u['role'],
        'name' => $u['name'],
        'email' => $u['email'],
        'mobile' => $u['mobile'],
        'email_verified' => $u['email_verified_at'] !== null,
        'mobile_verified' => $u['mobile_verified_at'] !== null,
        'points' => (int)$u['points_balance'],
        'facebook_url' => $u['facebook_url'] ?? null,
        'instagram_url' => $u['instagram_url'] ?? null,
        'trusted' => (bool)($u['trusted'] ?? false),
    ];
    if ($u['role'] === 'rider') {
        $rp = q1('SELECT rp.*, rc.name AS company_name FROM rider_profiles rp JOIN rider_companies rc ON rc.id = rp.company_id WHERE rp.user_id = ?', [$u['id']]);
        $out['rider'] = $rp ? [
            'company_id' => (int)$rp['company_id'],
            'company_name' => $rp['company_name'],
            'approval' => $rp['approval'],
            'is_online' => (bool)$rp['is_online'],
            'vehicle' => $rp['vehicle'],
            'plate_no' => $rp['plate_no'],
        ] : null;
    }
    return $out;
}

/** CSRF defence: state-changing requests must come from our own JS (custom header + same origin). */
function check_csrf(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return;
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'cafresco') fail(403, 'Bad request origin.');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $host = parse_url($origin, PHP_URL_HOST);
        $self = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
        if ($host !== $self) fail(403, 'Bad request origin.');
    }
}

function make_token(int $userId, string $type, int $ttlMinutes, ?string $plain = null): string
{
    $plain = $plain ?? bin2hex(random_bytes(24));
    q('UPDATE verification_tokens SET used_at = ? WHERE user_id = ? AND type = ? AND used_at IS NULL', [now(), $userId, $type]);
    q('INSERT INTO verification_tokens (user_id, type, token_hash, expires_at) VALUES (?, ?, ?, ?)', [
        $userId, $type, hash_hmac('sha256', $plain, (string)config('app_key')), date('Y-m-d H:i:s', time() + $ttlMinutes * 60),
    ]);
    return $plain;
}

/** Check a token/code. Returns user id on success. Counts failed attempts for codes. */
function consume_token(string $type, string $plain, ?int $userId = null): ?int
{
    $hash = hash_hmac('sha256', $plain, (string)config('app_key'));
    if ($userId !== null) {
        $row = q1('SELECT * FROM verification_tokens WHERE user_id = ? AND type = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1', [$userId, $type]);
        if (!$row || strtotime($row['expires_at']) < time() || (int)$row['attempts'] >= 5) return null;
        if (!hash_equals($row['token_hash'], $hash)) {
            q('UPDATE verification_tokens SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
            return null;
        }
    } else {
        $row = q1('SELECT * FROM verification_tokens WHERE token_hash = ? AND type = ? AND used_at IS NULL', [$hash, $type]);
        if (!$row || strtotime($row['expires_at']) < time()) return null;
    }
    q('UPDATE verification_tokens SET used_at = ? WHERE id = ?', [now(), $row['id']]);
    return (int)$row['user_id'];
}
