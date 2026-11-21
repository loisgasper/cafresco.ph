<?php
declare(strict_types=1);

const STAFF_TRANSITIONS = [
    'pending_payment' => ['cancelled'],
    'payment_submitted' => ['confirmed', 'rejected', 'pending_payment', 'cancelled'],
    'pending_confirmation' => ['confirmed', 'rejected', 'cancelled'],
    'confirmed' => ['preparing', 'ready', 'cancelled'],
    'preparing' => ['ready', 'cancelled'],
    'ready' => ['completed', 'out_for_delivery', 'cancelled'],
    'out_for_delivery' => ['completed'],
];

function admin_dashboard(): array
{
    require_staff();
    expire_stale_orders();
    $today = date('Y-m-d 00:00:00');
    $counts = [];
    foreach (qall('SELECT status, COUNT(*) c FROM orders WHERE status IN ("' . implode('","', OPEN_STATUSES) . '") GROUP BY status') as $r) $counts[$r['status']] = (int)$r['c'];
    $sales = q1('SELECT COUNT(*) n, COALESCE(SUM(total),0) s FROM orders WHERE status = "completed" AND created_at >= ?', [$today]);
    $week = qall('SELECT DATE(created_at) d, COUNT(*) n, SUM(total) s FROM orders WHERE status = "completed" AND created_at >= ? GROUP BY DATE(created_at) ORDER BY d', [date('Y-m-d', strtotime('-6 days'))]);
    $top = qall('SELECT oi.name, SUM(oi.qty) q FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.status = "completed" AND o.created_at >= ? GROUP BY oi.name ORDER BY q DESC LIMIT 5', [date('Y-m-d', strtotime('-29 days'))]);
    return [
        'open_counts' => $counts,
        'today_orders' => (int)$sales['n'], 'today_sales' => (float)$sales['s'],
        'week' => $week, 'top_items' => $top,
        'riders_online' => (int)q1('SELECT COUNT(*) c FROM rider_profiles WHERE is_online = 1 AND approval = "approved"')['c'],
        'pending_riders' => (int)q1('SELECT COUNT(*) c FROM rider_profiles WHERE approval = "pending"')['c'],
        'store_open' => setting('store_open', '1') === '1',
    ];
}

/** Polled by the admin panel for live alerts. */
function admin_feed(): array
{
    require_staff();
    expire_stale_orders();
    $since = (int)($_GET['since'] ?? 0);
    $items = $since > 0
        ? qall('SELECT id, order_id, title, body, created_at FROM notifications WHERE audience = "staff" AND id > ? ORDER BY id LIMIT 50', [$since])
        : [];
    $last = (int)(q1('SELECT MAX(id) m FROM notifications WHERE audience = "staff"')['m'] ?? 0);
    $needs = (int)q1('SELECT COUNT(*) c FROM orders WHERE status IN ("payment_submitted","pending_confirmation")')['c'];
    return ['items' => $items, 'last_id' => $last, 'needs_action' => $needs];
}

function admin_orders(): array
{
    require_staff();
    expire_stale_orders();
    $where = [];
    $params = [];
    $status = $_GET['status'] ?? 'open';
    if ($status === 'open') $where[] = 'o.status IN ("' . implode('","', OPEN_STATUSES) . '")';
    elseif ($status !== 'all') { $where[] = 'o.status = ?'; $params[] = $status; }
    if (!empty($_GET['q'])) {
        $where[] = '(o.code LIKE ? OR u.name LIKE ? OR u.mobile LIKE ? OR o.gcash_ref LIKE ?)';
        $like = '%' . $_GET['q'] . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if (!empty($_GET['date'])) { $where[] = 'DATE(o.created_at) = ?'; $params[] = $_GET['date']; }
    $sql = 'SELECT o.id, o.code, o.status, o.fulfillment, o.payment_method, o.total, o.created_at, o.pickup_time, o.rider_id, o.gcash_ref,
        u.name AS customer_name, u.mobile AS customer_mobile,
        (SELECT GROUP_CONCAT(CONCAT(oi.qty, "× ", oi.name, IFNULL(CONCAT(" (", oi.variant_name, ")"), "")) SEPARATOR ", ") FROM order_items oi WHERE oi.order_id = o.id) AS summary
        FROM orders o JOIN users u ON u.id = o.user_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY o.id DESC LIMIT 200';
    $rows = qall($sql, $params);
    foreach ($rows as &$r) { $r['status_label'] = ORDER_STATUS_LABELS[$r['status']]; $r['total'] = (float)$r['total']; $r['id'] = (int)$r['id']; }
    return ['orders' => $rows];
}

function admin_order_get(array $p): array
{
    require_staff();
    $o = order_full((int)$p['id'], true);
    if (!$o) fail(404, 'Order not found');
    $o['next_statuses'] = STAFF_TRANSITIONS[$o['status']] ?? [];
    $o['customer_mobile_verified'] = (bool)q1('SELECT mobile_verified_at FROM users WHERE id = ?', [$o['user_id']])['mobile_verified_at'];
    return ['order' => $o];
}

function admin_order_status(array $p): array
{
    $s = require_staff();
    $o = q1('SELECT * FROM orders WHERE id = ?', [(int)$p['id']]);
    if (!$o) fail(404, 'Order not found');
    $to = (string)in('status', '');
    if (!in_array($to, STAFF_TRANSITIONS[$o['status']] ?? [], true)) fail(409, 'That status change is not allowed from "' . ORDER_STATUS_LABELS[$o['status']] . '".');
    if ($to === 'out_for_delivery' && !$o['rider_id'] && $o['fulfillment'] === 'partner_rider') fail(409, 'No partner rider has accepted this order yet.');
    $note = str_in('note', 300) ?: null;
    if ($to === 'pending_payment') $note = $note ?? 'Payment could not be verified. Please upload a clearer screenshot.';
    set_order_status((int)$o['id'], $to, $note, (int)$s['id']);
    if (in('verify_mobile')) q('UPDATE users SET mobile_verified_at = COALESCE(mobile_verified_at, ?) WHERE id = ?', [now(), $o['user_id']]);
    return admin_order_get($p);
}

function admin_order_note(array $p): array
{
    require_staff();
    q('UPDATE orders SET staff_note = ? WHERE id = ?', [str_in('staff_note', 500) ?: null, (int)$p['id']]);
    return admin_order_get($p);
}

// ---------- Catalog ----------

function admin_catalog(): array
{
    require_staff();
    $products = qall('SELECT * FROM products ORDER BY sort, name');
    $variants = qall('SELECT * FROM product_variants ORDER BY sort, price');
    $links = qall('SELECT * FROM product_addon_groups');
    $vb = []; foreach ($variants as $v) $vb[$v['product_id']][] = $v;
    $lb = []; foreach ($links as $l) $lb[$l['product_id']][] = (int)$l['group_id'];
    foreach ($products as &$p) { $p['variants'] = $vb[$p['id']] ?? []; $p['addon_group_ids'] = $lb[$p['id']] ?? []; }
    unset($p);
    $groups = qall('SELECT * FROM addon_groups ORDER BY name');
    $addons = qall('SELECT * FROM addons ORDER BY sort, name');
    $ab = []; foreach ($addons as $a) $ab[$a['group_id']][] = $a;
    foreach ($groups as &$g) $g['addons'] = $ab[$g['id']] ?? [];
    return ['categories' => qall('SELECT * FROM categories ORDER BY sort, name'), 'products' => $products, 'addon_groups' => $groups];
}

function admin_category_save(): array
{
    require_staff();
    $id = (int)in('id', 0);
    $vals = [str_in('name', 80, true), (int)in('sort', 0), in('active', true) ? 1 : 0];
    if ($id) q('UPDATE categories SET name = ?, sort = ?, active = ? WHERE id = ?', [...$vals, $id]);
    else q('INSERT INTO categories (name, sort, active) VALUES (?, ?, ?)', $vals);
    return admin_catalog();
}

function admin_category_delete(array $p): array
{
    require_staff();
    q('DELETE FROM categories WHERE id = ?', [(int)$p['id']]);
    return admin_catalog();
}

function admin_product_save(): array
{
    require_staff();
    $id = (int)in('id', 0);
    $cat = (int)in('category_id', 0) ?: null;
    $price = money(in('base_price', 0));
    if ($price < 0) fail(422, 'Price cannot be negative.');
    $vals = [$cat, str_in('name', 120, true), str_in('description', 500) ?: null, str_in('image_url', 500) ?: null, $price,
        in('active', true) ? 1 : 0, in('sold_out', false) ? 1 : 0, in('featured', false) ? 1 : 0, (int)in('sort', 0)];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($id) {
            if (!q1('SELECT id FROM products WHERE id = ?', [$id])) fail(404, 'Product not found');
            q('UPDATE products SET category_id = ?, name = ?, description = ?, image_url = ?, base_price = ?, active = ?, sold_out = ?, featured = ?, sort = ? WHERE id = ?', [...$vals, $id]);
        } else {
            q('INSERT INTO products (category_id, name, description, image_url, base_price, active, sold_out, featured, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', $vals);
            $id = (int)$pdo->lastInsertId();
        }
        $keep = [];
        foreach ((array)in('variants', []) as $i => $v) {
            $vn = mb_substr(trim((string)($v['name'] ?? '')), 0, 80);
            if ($vn === '') continue;
            $vp = money($v['price'] ?? 0);
            $vid = (int)($v['id'] ?? 0);
            $act = !empty($v['active']) || !array_key_exists('active', $v) ? 1 : 0;
            if ($vid && q1('SELECT id FROM product_variants WHERE id = ? AND product_id = ?', [$vid, $id])) {
                q('UPDATE product_variants SET name = ?, price = ?, active = ?, sort = ? WHERE id = ?', [$vn, $vp, $act, $i, $vid]);
            } else {
                q('INSERT INTO product_variants (product_id, name, price, active, sort) VALUES (?, ?, ?, ?, ?)', [$id, $vn, $vp, $act, $i]);
                $vid = (int)$pdo->lastInsertId();
            }
            $keep[] = $vid;
        }
        q('DELETE FROM product_variants WHERE product_id = ?' . ($keep ? ' AND id NOT IN (' . implode(',', $keep) . ')' : ''), [$id]);
        q('DELETE FROM product_addon_groups WHERE product_id = ?', [$id]);
        foreach (array_unique(array_map('intval', (array)in('addon_group_ids', []))) as $gid) {
            if ($gid > 0) q('INSERT IGNORE INTO product_addon_groups (product_id, group_id) SELECT ?, id FROM addon_groups WHERE id = ?', [$id, $gid]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return admin_catalog();
}

function admin_product_delete(array $p): array
{
    require_staff();
    q('DELETE FROM products WHERE id = ?', [(int)$p['id']]);
    return admin_catalog();
}

function admin_product_toggle(array $p): array
{
    require_staff();
    $field = in('field') === 'sold_out' ? 'sold_out' : 'active';
    q("UPDATE products SET $field = 1 - $field WHERE id = ?", [(int)$p['id']]);
    return admin_catalog();
}

function admin_addon_group_save(): array
{
    require_staff();
    $id = (int)in('id', 0);
    $min = max(0, (int)in('min_select', 0));
    $max = max(1, (int)in('max_select', 5));
    if ($min > $max) fail(422, 'Minimum cannot be more than maximum.');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($id) q('UPDATE addon_groups SET name = ?, min_select = ?, max_select = ? WHERE id = ?', [str_in('name', 80, true), $min, $max, $id]);
        else { q('INSERT INTO addon_groups (name, min_select, max_select) VALUES (?, ?, ?)', [str_in('name', 80, true), $min, $max]); $id = (int)$pdo->lastInsertId(); }
        $keep = [];
        foreach ((array)in('addons', []) as $i => $a) {
            $an = mb_substr(trim((string)($a['name'] ?? '')), 0, 80);
            if ($an === '') continue;
            $aid = (int)($a['id'] ?? 0);
            $act = !empty($a['active']) || !array_key_exists('active', $a) ? 1 : 0;
            if ($aid && q1('SELECT id FROM addons WHERE id = ? AND group_id = ?', [$aid, $id])) {
                q('UPDATE addons SET name = ?, price = ?, active = ?, sort = ? WHERE id = ?', [$an, money($a['price'] ?? 0), $act, $i, $aid]);
            } else {
                q('INSERT INTO addons (group_id, name, price, active, sort) VALUES (?, ?, ?, ?, ?)', [$id, $an, money($a['price'] ?? 0), $act, $i]);
                $aid = (int)$pdo->lastInsertId();
            }
            $keep[] = $aid;
        }
        q('DELETE FROM addons WHERE group_id = ?' . ($keep ? ' AND id NOT IN (' . implode(',', $keep) . ')' : ''), [$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return admin_catalog();
}

function admin_addon_group_delete(array $p): array
{
    require_staff();
    q('DELETE FROM addon_groups WHERE id = ?', [(int)$p['id']]);
    return admin_catalog();
}

function admin_discounts(): array
{
    require_staff();
    return ['discounts' => qall('SELECT * FROM discounts ORDER BY active DESC, id DESC')];
}

function admin_discount_save(): array
{
    require_staff();
    $id = (int)in('id', 0);
    $type = in('type') === 'fixed' ? 'fixed' : 'percent';
    $value = money(in('value', 0));
    if ($value <= 0 || ($type === 'percent' && $value > 100)) fail(422, 'Enter a valid discount value.');
    $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string)in('code', ''))) ?: null;
    $dt = fn($k) => ($v = trim((string)in($k, ''))) !== '' && strtotime($v) ? date('Y-m-d H:i:s', strtotime($v)) : null;
    $nullInt = fn($k) => ($v = in($k)) === null || $v === '' ? null : max(0, (int)$v);
    $vals = [str_in('name', 80, true), $code, $type, $value, money(in('min_subtotal', 0)), $dt('starts_at'), $dt('ends_at'),
        $nullInt('max_uses'), $nullInt('per_user_limit'), in('active', true) ? 1 : 0];
    if ($code && q1('SELECT id FROM discounts WHERE code = ? AND id <> ?', [$code, $id])) fail(409, 'That code already exists.');
    if ($id) q('UPDATE discounts SET name = ?, code = ?, type = ?, value = ?, min_subtotal = ?, starts_at = ?, ends_at = ?, max_uses = ?, per_user_limit = ?, active = ? WHERE id = ?', [...$vals, $id]);
    else q('INSERT INTO discounts (name, code, type, value, min_subtotal, starts_at, ends_at, max_uses, per_user_limit, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $vals);
    return admin_discounts();
}

function admin_discount_delete(array $p): array
{
    require_staff();
    q('DELETE FROM discounts WHERE id = ?', [(int)$p['id']]);
    return admin_discounts();
}

function admin_upload(): array
{
    require_staff();
    $name = store_upload('file', 'pub');
    return ['url' => 'api/files/public/' . $name];
}

// ---------- People ----------

function admin_customers(): array
{
    require_staff();
    $params = [];
    $where = 'u.role = "customer"';
    if (!empty($_GET['q'])) {
        $where .= ' AND (u.name LIKE ? OR u.email LIKE ? OR u.mobile LIKE ?)';
        $like = '%' . $_GET['q'] . '%';
        $params = [$like, $like, $like];
    }
    $rows = qall("SELECT u.id, u.name, u.email, u.mobile, u.status, u.points_balance, u.email_verified_at, u.mobile_verified_at, u.staff_note, u.created_at,
        (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id AND o.status = 'completed') AS completed_orders,
        (SELECT COALESCE(SUM(total),0) FROM orders o WHERE o.user_id = u.id AND o.status = 'completed') AS lifetime_spend
        FROM users u WHERE $where ORDER BY u.id DESC LIMIT 300", $params);
    return ['customers' => $rows];
}

function admin_customer_update(array $p): array
{
    $s = require_staff();
    $id = (int)$p['id'];
    $u = q1('SELECT * FROM users WHERE id = ? AND role IN ("customer","rider")', [$id]);
    if (!$u) fail(404, 'Customer not found');
    if (($st = in('status')) !== null) q('UPDATE users SET status = ? WHERE id = ?', [$st === 'blocked' ? 'blocked' : 'active', $id]);
    if (in('verify_mobile')) q('UPDATE users SET mobile_verified_at = COALESCE(mobile_verified_at, ?) WHERE id = ?', [now(), $id]);
    if (in('verify_email')) q('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?', [now(), $id]);
    if (in('staff_note') !== null) q('UPDATE users SET staff_note = ? WHERE id = ?', [str_in('staff_note', 500) ?: null, $id]);
    $adj = (int)in('points_adjust', 0);
    if ($adj !== 0) {
        if ((int)$u['points_balance'] + $adj < 0) fail(422, 'Points cannot go below zero.');
        q('UPDATE users SET points_balance = points_balance + ? WHERE id = ?', [$adj, $id]);
        q('INSERT INTO points_ledger (user_id, delta, reason) VALUES (?, ?, ?)', [$id, $adj, 'Adjusted by ' . $s['name'] . ': ' . (str_in('points_reason', 80) ?: 'manual')]);
    }
    return admin_customers();
}

function admin_riders(): array
{
    require_staff();
    // Riders who have not opened the app for 30 minutes are treated as offline.
    q('UPDATE rider_profiles SET is_online = 0 WHERE is_online = 1 AND (last_seen_at IS NULL OR last_seen_at < ?)', [date('Y-m-d H:i:s', time() - 1800)]);
    return [
        'companies' => qall('SELECT c.*, (SELECT COUNT(*) FROM rider_profiles rp WHERE rp.company_id = c.id AND rp.approval = "approved") AS rider_count FROM rider_companies c ORDER BY c.name'),
        'riders' => qall('SELECT u.id, u.name, u.email, u.mobile, u.status, u.created_at, rp.company_id, rp.vehicle, rp.plate_no, rp.approval, rp.is_online, rp.last_seen_at,
            (SELECT COUNT(*) FROM orders o WHERE o.rider_id = u.id AND o.status = "completed") AS deliveries
            FROM users u JOIN rider_profiles rp ON rp.user_id = u.id ORDER BY rp.approval = "pending" DESC, u.name'),
    ];
}

function admin_rider_update(array $p): array
{
    require_staff();
    $id = (int)$p['id'];
    if (!q1('SELECT user_id FROM rider_profiles WHERE user_id = ?', [$id])) fail(404, 'Rider not found');
    if (($a = in('approval')) && in_array($a, ['pending', 'approved', 'rejected'], true)) {
        q('UPDATE rider_profiles SET approval = ?, is_online = IF(? = 1, is_online, 0) WHERE user_id = ?', [$a, $a === 'approved' ? 1 : 0, $id]);
        notify_user($id, $a === 'approved' ? 'You are approved!' : 'Rider application update',
            $a === 'approved' ? 'You can now go online and receive Cafresco pickup jobs.' : 'Your rider application is ' . $a . '.');
    }
    if (($c = (int)in('company_id', 0)) > 0) q('UPDATE rider_profiles SET company_id = ? WHERE user_id = ?', [$c, $id]);
    if (in('force_offline')) q('UPDATE rider_profiles SET is_online = 0 WHERE user_id = ?', [$id]);
    if (($st = in('status')) !== null) q('UPDATE users SET status = ? WHERE id = ?', [$st === 'blocked' ? 'blocked' : 'active', $id]);
    return admin_riders();
}

function admin_rider_company_save(): array
{
    require_staff();
    $id = (int)in('id', 0);
    $vals = [str_in('name', 120, true), str_in('contact', 120) ?: null, in('active', true) ? 1 : 0];
    if ($id) q('UPDATE rider_companies SET name = ?, contact = ?, active = ? WHERE id = ?', [...$vals, $id]);
    else q('INSERT INTO rider_companies (name, contact, active) VALUES (?, ?, ?)', $vals);
    return admin_riders();
}

function admin_staff(): array
{
    require_admin();
    return ['staff' => qall('SELECT id, name, email, mobile, role, status, last_login_at FROM users WHERE role IN ("staff","admin") ORDER BY role, name')];
}

function admin_staff_save(): array
{
    $me = require_admin();
    $id = (int)in('id', 0);
    $role = in('role') === 'admin' ? 'admin' : 'staff';
    if ($id) {
        if ($id === (int)$me['id'] && (in('status') === 'blocked' || $role !== 'admin')) fail(422, 'You cannot demote or block yourself.');
        q('UPDATE users SET role = ?, status = ? WHERE id = ? AND role IN ("staff","admin")', [$role, in('status') === 'blocked' ? 'blocked' : 'active', $id]);
    } else {
        $email = strtolower(str_in('email', 190, true));
        $mobile = normalize_mobile(str_in('mobile', 20, true));
        $pw = (string)in('password', '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !$mobile) fail(422, 'Valid email and PH mobile required.');
        validate_password($pw);
        if (q1('SELECT id FROM users WHERE email = ? OR mobile = ?', [$email, $mobile])) fail(409, 'Email or mobile already used.');
        q('INSERT INTO users (role, name, email, mobile, password_hash, email_verified_at, mobile_verified_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$role, str_in('name', 120, true), $email, $mobile, password_hash($pw, PASSWORD_DEFAULT), now(), now()]);
    }
    return admin_staff();
}

// ---------- Settings ----------

const EDITABLE_SETTINGS = ['store_name', 'store_open', 'store_hours', 'store_address', 'store_phone', 'facebook_url', 'gcash_name', 'gcash_number', 'gcash_qr',
    'payment_window_minutes', 'points_per_peso', 'peso_per_point', 'min_points_redeem', 'max_redeem_percent', 'new_account_order_cap', 'max_open_orders',
    'allow_pay_on_pickup', 'pay_on_pickup_min_completed', 'require_mobile_verification', 'min_prep_minutes', 'loyverse_token', 'banner_text'];

function admin_settings(): array
{
    require_staff();
    $out = [];
    foreach (EDITABLE_SETTINGS as $k) $out[$k] = setting($k, '');
    $out['loyverse_token'] = $out['loyverse_token'] ? '••••' . substr($out['loyverse_token'], -4) : '';
    $out['_sms_enabled'] = sms_enabled();
    return ['settings' => $out];
}

function admin_settings_save(): array
{
    $u = require_staff();
    foreach (EDITABLE_SETTINGS as $k) {
        if (!array_key_exists($k, input())) continue;
        $v = trim((string)in($k));
        if ($k === 'loyverse_token' && ($u['role'] !== 'admin' || str_starts_with($v, '••••'))) continue;
        if ($u['role'] !== 'admin' && $k !== 'store_open') continue; // staff can only open/close the store
        set_setting($k, mb_substr($v, 0, 1000));
    }
    return admin_settings();
}
