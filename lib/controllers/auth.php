<?php
declare(strict_types=1);

function public_config(): array
{
    return [
        'store_name' => setting('store_name', 'Cafresco'),
        'store_open' => setting('store_open', '1') === '1',
        'store_hours' => setting('store_hours', ''),
        'store_address' => setting('store_address', ''),
        'store_phone' => setting('store_phone', ''),
        'facebook_url' => setting('facebook_url', ''),
        'gcash_name' => setting('gcash_name', ''),
        'gcash_number' => setting('gcash_number', ''),
        'gcash_qr' => setting('gcash_qr', ''),
        'payment_window_minutes' => (int)setting('payment_window_minutes', 30),
        'points_per_peso' => (float)setting('points_per_peso', 0.02),
        'peso_per_point' => (float)setting('peso_per_point', 1),
        'min_points_redeem' => (int)setting('min_points_redeem', 20),
        'max_redeem_percent' => (int)setting('max_redeem_percent', 50),
        'allow_pay_on_pickup' => setting('allow_pay_on_pickup', '1') === '1',
        'min_prep_minutes' => (int)setting('min_prep_minutes', 15),
        'banner_text' => setting('banner_text', ''),
        'sms_enabled' => sms_enabled(),
        'require_mobile_verification' => require_mobile_verification(),
    ];
}

function require_mobile_verification(): bool
{
    return sms_enabled() && setting('require_mobile_verification', '0') === '1';
}

function auth_me(): array
{
    return ['user' => user_public(current_user())];
}

function validate_password(string $pw): void
{
    if (strlen($pw) < 8) fail(422, 'Password must be at least 8 characters.');
}

/** Shared by customer and rider registration. */
function create_account(string $role): array
{
    rate_limit('register:' . client_ip(), 5, 3600);
    $name = str_in('name', 120, true);
    $email = strtolower(str_in('email', 190, true));
    $mobileRaw = str_in('mobile', 20, true);
    $pw = (string)in('password', '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail(422, 'Please enter a valid email address.');
    $mobile = normalize_mobile($mobileRaw);
    if (!$mobile) fail(422, 'Please enter a valid PH mobile number (09XXXXXXXXX).');
    validate_password($pw);
    if (q1('SELECT id FROM users WHERE email = ?', [$email])) fail(409, 'An account with this email already exists.');
    if (q1('SELECT id FROM users WHERE mobile = ?', [$mobile])) fail(409, 'An account with this mobile number already exists.');
    q('INSERT INTO users (role, name, email, mobile, password_hash) VALUES (?, ?, ?, ?, ?)',
        [$role, $name, $email, $mobile, password_hash($pw, PASSWORD_DEFAULT)]);
    $u = q1('SELECT * FROM users WHERE id = ?', [db()->lastInsertId()]);
    send_verification_email($u);
    return $u;
}

function auth_register(): array
{
    $u = create_account('customer');
    login_user($u);
    return ['user' => user_public($u)];
}

function send_verification_email(array $u): void
{
    $token = make_token((int)$u['id'], 'email', 60 * 24);
    $link = app_url('#/verify-email/' . $token);
    send_mail($u['email'], 'Verify your Cafresco account',
        "Hi {$u['name']},\n\nPlease confirm your email to start ordering:\n$link\n\nThis link expires in 24 hours. If you did not sign up, ignore this email.",
        "<p>Hi " . htmlspecialchars($u['name']) . ",</p><p>Please confirm your email to start ordering.</p>"
        . "<p><a href=\"$link\" style=\"display:inline-block;background:#111;color:#fff;padding:12px 20px;text-decoration:none;font-weight:700\">VERIFY EMAIL</a></p>"
        . "<p style=\"font-size:12px;color:#555\">This link expires in 24 hours. If you did not sign up, ignore this email.</p>");
}

function auth_login(): array
{
    $id = strtolower(str_in('login', 190, true));
    rate_limit('login:' . client_ip(), 10, 600);
    rate_limit('login-id:' . $id, 8, 900);
    $mobile = normalize_mobile($id);
    $u = q1('SELECT * FROM users WHERE email = ? OR mobile = ?', [$id, $mobile ?? '-']);
    if (!$u || !password_verify((string)in('password', ''), $u['password_hash'])) fail(401, 'Wrong email/mobile or password.');
    if ($u['status'] !== 'active') fail(403, 'This account is blocked. Please message Cafresco on Facebook.');
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string)in('password'), PASSWORD_DEFAULT), $u['id']]);
    }
    login_user($u);
    return ['user' => user_public($u)];
}

function auth_logout(): array
{
    logout_user();
    return ['ok' => true];
}

function auth_verify_email(): array
{
    rate_limit('verify:' . client_ip(), 20, 600);
    $uid = consume_token('email', str_in('token', 100, true));
    if (!$uid) fail(422, 'This link is invalid or has expired. Log in and request a new one.');
    q('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?', [now(), $uid]);
    return ['ok' => true, 'user' => user_public(current_user() && (int)current_user()['id'] === $uid ? q1('SELECT * FROM users WHERE id = ?', [$uid]) : null)];
}

function auth_resend_email(): array
{
    $u = require_user();
    if ($u['email_verified_at']) return ['ok' => true];
    rate_limit('resend:' . $u['id'], 3, 3600);
    send_verification_email($u);
    return ['ok' => true];
}

function auth_send_mobile_otp(): array
{
    $u = require_user();
    if (!sms_enabled()) fail(400, 'SMS verification is not available yet. The cafe will verify your number on your first order.');
    if ($u['mobile_verified_at']) return ['ok' => true];
    rate_limit('otp:' . $u['id'], 3, 3600);
    rate_limit('otp-ip:' . client_ip(), 6, 3600);
    $code = (string)random_int(100000, 999999);
    make_token((int)$u['id'], 'mobile', 10, $code);
    if (!send_sms($u['mobile'], "Your Cafresco code is $code. It expires in 10 minutes. Never share this code.")) fail(502, 'Could not send the SMS. Please try again later.');
    return ['ok' => true];
}

function auth_verify_mobile(): array
{
    $u = require_user();
    rate_limit('otp-check:' . $u['id'], 10, 900);
    if (!consume_token('mobile', str_in('code', 10, true), (int)$u['id'])) fail(422, 'Wrong or expired code.');
    q('UPDATE users SET mobile_verified_at = ? WHERE id = ?', [now(), $u['id']]);
    return ['user' => user_public(q1('SELECT * FROM users WHERE id = ?', [$u['id']]))];
}

function auth_forgot(): array
{
    rate_limit('forgot:' . client_ip(), 5, 3600);
    $email = strtolower(str_in('email', 190, true));
    $u = q1('SELECT * FROM users WHERE email = ? AND status = "active"', [$email]);
    if ($u) {
        $token = make_token((int)$u['id'], 'reset', 60);
        $link = app_url('#/reset/' . $token);
        send_mail($u['email'], 'Reset your Cafresco password', "Hi {$u['name']},\n\nReset your password here (valid for 1 hour):\n$link\n\nIf you did not ask for this, ignore this email.");
    }
    return ['ok' => true]; // same answer either way so emails cannot be probed
}

function auth_reset(): array
{
    rate_limit('reset:' . client_ip(), 10, 3600);
    $pw = (string)in('password', '');
    validate_password($pw);
    $uid = consume_token('reset', str_in('token', 100, true));
    if (!$uid) fail(422, 'This reset link is invalid or has expired.');
    // Clicking the emailed link proves the email address too.
    q('UPDATE users SET password_hash = ?, email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), now(), $uid]);
    return ['ok' => true];
}

function auth_profile(): array
{
    $u = require_user();
    $name = str_in('name', 120, true);
    q('UPDATE users SET name = ? WHERE id = ?', [$name, $u['id']]);
    $new = (string)in('new_password', '');
    if ($new !== '') {
        if (!password_verify((string)in('current_password', ''), $u['password_hash'])) fail(422, 'Current password is wrong.');
        validate_password($new);
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
    }
    return ['user' => user_public(q1('SELECT * FROM users WHERE id = ?', [$u['id']]))];
}

function notifications_list(): array
{
    $u = require_user();
    $since = (int)($_GET['since'] ?? 0);
    $rows = qall('SELECT id, order_id, title, body, read_at, created_at FROM notifications WHERE user_id = ? AND id > ? ORDER BY id DESC LIMIT 30', [$u['id'], $since]);
    $unread = (int)q1('SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND read_at IS NULL', [$u['id']])['c'];
    return ['items' => $rows, 'unread' => $unread];
}

function notifications_read(): array
{
    $u = require_user();
    q('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [now(), $u['id']]);
    return ['ok' => true];
}

/**
 * One-time installer: creates tables and the first admin.
 * Protected by setup_token from the server config; refuses once an admin exists.
 */
function setup_install(): array
{
    require_setup_token();
    $sql = file_get_contents(APP_ROOT . '/sql/schema.sql');
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $stmt) {
        if (!preg_match('/^(--[^\n]*\n\s*)*$/', $stmt)) db()->exec($stmt);
    }
    run_migrations();
    if (q1('SELECT id FROM users WHERE role = "admin" LIMIT 1')) return ['ok' => true, 'message' => 'Schema is up to date. An admin already exists.'];
    $email = strtolower(str_in('email', 190, true));
    $mobile = normalize_mobile(str_in('mobile', 20, true));
    $pw = (string)in('password', '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !$mobile) fail(422, 'Valid email and mobile required.');
    validate_password($pw);
    q('INSERT INTO users (role, name, email, mobile, password_hash, email_verified_at, mobile_verified_at) VALUES ("admin", ?, ?, ?, ?, ?, ?)',
        [str_in('name', 120) ?: 'Cafresco Admin', $email, $mobile, password_hash($pw, PASSWORD_DEFAULT), now(), now()]);
    return ['ok' => true, 'message' => 'Installed. You can now log in at /admin.'];
}

/** Small additive migrations for databases created by an older schema.sql. */
function run_migrations(): void
{
    $has = fn(string $table, string $col) => (bool)q1('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $col]);
    if (!$has('products', 'featured')) db()->exec('ALTER TABLE products ADD COLUMN featured TINYINT(1) NOT NULL DEFAULT 0 AFTER sold_out');
}

function require_setup_token(): void
{
    $token = (string)config('setup_token', '');
    if ($token === '' || !hash_equals($token, (string)in('setup_token', ''))) fail(403, 'Invalid setup token.');
}

/** Load the starter Cafresco menu. Only runs while the menu is still empty. */
function setup_seed_menu(): array
{
    require_setup_token();
    run_migrations();
    if ((int)q1('SELECT COUNT(*) c FROM products')['c'] > 0) fail(409, 'The menu already has products. Nothing was changed.');
    $menu = require APP_ROOT . '/sql/seed_menu.php';
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO addon_groups (name, min_select, max_select) VALUES (?, 0, 6)', [$menu['addons']['name']]);
        $gid = (int)$pdo->lastInsertId();
        foreach ($menu['addons']['items'] as $i => [$n, $p]) q('INSERT INTO addons (group_id, name, price, sort) VALUES (?, ?, ?, ?)', [$gid, $n, $p, $i]);
        $count = 0;
        foreach ($menu['categories'] as $ci => $cat) {
            q('INSERT INTO categories (name, sort) VALUES (?, ?)', [$cat['name'], $ci]);
            $cid = (int)$pdo->lastInsertId();
            foreach ($cat['items'] as $pi => [$name, $hot, $iced, $fav, $desc]) {
                q('INSERT INTO products (category_id, name, description, base_price, featured, sort) VALUES (?, ?, ?, ?, ?, ?)', [$cid, $name, $desc, $iced ?? $hot, $fav ? 1 : 0, $pi]);
                $pid = (int)$pdo->lastInsertId();
                $vs = 0;
                if ($hot !== null) q('INSERT INTO product_variants (product_id, name, price, sort) VALUES (?, "Hot", ?, ?)', [$pid, $hot, $vs++]);
                if ($iced !== null) q('INSERT INTO product_variants (product_id, name, price, sort) VALUES (?, "Iced 16oz", ?, ?)', [$pid, $iced, $vs++]);
                if (!empty($cat['addons'])) q('INSERT INTO product_addon_groups (product_id, group_id) VALUES (?, ?)', [$pid, $gid]);
                $count++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'message' => "Loaded $count drinks."];
}

/**
 * Fill in short descriptions (and the customer-favorite flag) for products that have no description yet,
 * matched by name against the starter menu. Never overwrites a description someone already wrote.
 */
function setup_fill_descriptions(): array
{
    require_setup_token();
    run_migrations();
    $menu = require APP_ROOT . '/sql/seed_menu.php';
    $norm = fn(string $n) => preg_replace('/[^a-z0-9]+/', '', strtolower($n));
    $map = [];
    foreach ($menu['categories'] as $cat) foreach ($cat['items'] as [$name, , , $fav, $desc]) $map[$norm($name)] = [$fav, $desc];
    foreach ($menu['extra'] as $name => [$fav, $desc]) $map[$norm($name)] = [$fav, $desc];
    $filled = 0; $missing = [];
    foreach (qall('SELECT id, name, description FROM products') as $p) {
        if (trim((string)$p['description']) !== '') continue;
        $hit = $map[$norm($p['name'])] ?? null;
        if (!$hit) { $missing[] = $p['name']; continue; }
        q('UPDATE products SET description = ?, featured = GREATEST(featured, ?) WHERE id = ?', [$hit[1], $hit[0] ? 1 : 0, $p['id']]);
        $filled++;
    }
    return ['ok' => true, 'filled' => $filled, 'no_match' => $missing];
}
