<?php
declare(strict_types=1);

function menu(): array
{
    $cats = qall('SELECT id, name FROM categories WHERE active = 1 ORDER BY sort, name');
    $products = qall('SELECT id, category_id, name, description, image_url, base_price, sold_out FROM products WHERE active = 1 ORDER BY sort, name');
    $variants = qall('SELECT id, product_id, name, price FROM product_variants WHERE active = 1 ORDER BY sort, price');
    $groups = qall('SELECT g.id, g.name, g.min_select, g.max_select, pag.product_id FROM addon_groups g JOIN product_addon_groups pag ON pag.group_id = g.id');
    $addons = qall('SELECT id, group_id, name, price FROM addons WHERE active = 1 ORDER BY sort, name');

    $addonsByGroup = [];
    foreach ($addons as $a) $addonsByGroup[$a['group_id']][] = ['id' => (int)$a['id'], 'name' => $a['name'], 'price' => (float)$a['price']];
    $varByProd = [];
    foreach ($variants as $v) $varByProd[$v['product_id']][] = ['id' => (int)$v['id'], 'name' => $v['name'], 'price' => (float)$v['price']];
    $groupsByProd = [];
    foreach ($groups as $g) {
        if (empty($addonsByGroup[$g['id']])) continue;
        $groupsByProd[$g['product_id']][] = ['id' => (int)$g['id'], 'name' => $g['name'], 'min' => (int)$g['min_select'], 'max' => (int)$g['max_select'], 'addons' => $addonsByGroup[$g['id']]];
    }
    $out = [];
    foreach ($products as $p) {
        $out[] = [
            'id' => (int)$p['id'], 'category_id' => $p['category_id'] ? (int)$p['category_id'] : null,
            'name' => $p['name'], 'description' => $p['description'], 'image_url' => $p['image_url'],
            'price' => (float)$p['base_price'], 'sold_out' => (bool)$p['sold_out'],
            'variants' => $varByProd[$p['id']] ?? [], 'addon_groups' => $groupsByProd[$p['id']] ?? [],
        ];
    }
    $autoPromos = qall('SELECT name, type, value, min_subtotal FROM discounts WHERE code IS NULL AND active = 1
        AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at >= NOW())');
    return ['categories' => $cats, 'products' => $out, 'promos' => $autoPromos];
}

function file_public(array $p): never
{
    $name = basename($p['name']);
    if (!str_starts_with($name, 'pub_')) fail(404, 'File not found');
    send_upload($name);
}

function file_proof(array $p): never
{
    $u = require_user();
    $o = q1('SELECT user_id, proof_file FROM orders WHERE id = ?', [(int)$p['id']]);
    if (!$o || !$o['proof_file']) fail(404, 'No proof uploaded');
    if ((int)$o['user_id'] !== (int)$u['id'] && !in_array($u['role'], ['staff', 'admin'], true)) fail(403, 'Not allowed');
    send_upload($o['proof_file']);
}

/** Build the full quote used by both the checkout preview and order creation. */
function build_quote(array $u): array
{
    $cart = price_cart((array)in('items', []));
    $code = trim((string)in('discount_code', '')) ?: null;
    $disc = resolve_discount($code, $cart['subtotal'], (int)$u['id']);
    $after = money($cart['subtotal'] - $disc['amount']);
    $points = max(0, (int)in('points', 0));
    $pv = points_redeem_value($points, $after, (int)$u['points_balance']);
    $total = money(max(0, $after - $pv));
    return [
        'lines' => $cart['lines'], 'subtotal' => $cart['subtotal'],
        'discount' => $disc['discount'] ? ['id' => (int)$disc['discount']['id'], 'name' => $disc['discount']['name'], 'code' => $disc['discount']['code']] : null,
        'discount_total' => $disc['amount'], 'points' => $points, 'points_value' => $pv, 'total' => $total,
        'points_to_earn' => (int)floor($total * (float)setting('points_per_peso', 0.02)),
        'max_points_usable' => (int)min((int)$u['points_balance'], floor($after * ((float)setting('max_redeem_percent', 50) / 100) / max(0.01, (float)setting('peso_per_point', 1)))),
    ];
}

function cart_quote(): array
{
    $u = require_user();
    return build_quote($u);
}

function completed_orders(int $uid): int
{
    return (int)q1('SELECT COUNT(*) c FROM orders WHERE user_id = ? AND status = "completed"', [$uid])['c'];
}

function order_create(): array
{
    $u = require_role('customer', 'staff', 'admin');
    rate_limit('order:' . $u['id'], 6, 3600);
    expire_stale_orders();

    if (setting('store_open', '1') !== '1') fail(409, 'Sorry, we are not accepting online orders right now.');
    if (!$u['email_verified_at']) fail(403, 'Please verify your email before ordering.');
    if (require_mobile_verification() && !$u['mobile_verified_at']) fail(403, 'Please verify your mobile number before ordering.');

    $open = (int)q1('SELECT COUNT(*) c FROM orders WHERE user_id = ? AND status IN ("' . implode('","', OPEN_STATUSES) . '")', [$u['id']])['c'];
    if ($open >= (int)setting('max_open_orders', 2)) fail(409, 'You already have orders in progress. Please wait for them to finish first.');

    $fulfillment = (string)in('fulfillment', 'pickup');
    if (!in_array($fulfillment, ['pickup', 'own_rider', 'partner_rider'], true)) fail(422, 'Choose pickup or rider.');
    $payment = (string)in('payment_method', 'gcash');
    if (!in_array($payment, ['gcash', 'pay_on_pickup'], true)) fail(422, 'Choose a payment method.');

    $completed = completed_orders((int)$u['id']);
    if ($payment === 'pay_on_pickup') {
        if (setting('allow_pay_on_pickup', '1') !== '1') fail(422, 'Pay on pickup is not available right now. Please pay with GCash.');
        if ($completed < (int)setting('pay_on_pickup_min_completed', 1)) fail(422, 'Pay on pickup unlocks after your first completed GCash order.');
    }

    $address = str_in('delivery_address', 500);
    if ($fulfillment === 'partner_rider' && mb_strlen($address) < 8) fail(422, 'Please enter the delivery address for the rider.');
    $ownRider = str_in('own_rider_info', 200);

    $pickupTime = null;
    $pt = trim((string)in('pickup_time', ''));
    if ($pt !== '') {
        $ts = strtotime($pt);
        $minTs = time() + (int)setting('min_prep_minutes', 15) * 60 - 60;
        if (!$ts || $ts < $minTs) fail(422, 'Pickup time must be at least ' . setting('min_prep_minutes', 15) . ' minutes from now.');
        if ($ts > time() + 3 * 86400) fail(422, 'Pickup time can be at most 3 days ahead.');
        $pickupTime = date('Y-m-d H:i:s', $ts);
    }

    $quote = build_quote($u);
    $cap = (float)setting('new_account_order_cap', 1500);
    if ($completed === 0 && $cap > 0 && $quote['total'] > $cap) {
        fail(422, 'For your first online order, the limit is PHP ' . number_format($cap, 2) . '. Bigger orders unlock after your first completed order.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($quote['points'] > 0) {
            $ok = q('UPDATE users SET points_balance = points_balance - ? WHERE id = ? AND points_balance >= ?', [$quote['points'], $u['id'], $quote['points']])->rowCount();
            if (!$ok) fail(409, 'Your points balance changed. Please try again.');
        }
        if ($quote['discount']) {
            $ok = q('UPDATE discounts SET uses = uses + 1 WHERE id = ? AND (max_uses IS NULL OR uses < max_uses)', [$quote['discount']['id']])->rowCount();
            if (!$ok) fail(409, 'This promo has just been fully used.');
        }
        $code = new_order_code();
        $status = $payment === 'gcash' ? 'pending_payment' : 'pending_confirmation';
        q('INSERT INTO orders (code, user_id, fulfillment, payment_method, status, subtotal, discount_id, discount_total, points_redeemed, points_value, total, pickup_time, customer_note, delivery_address, own_rider_info)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $code, $u['id'], $fulfillment, $payment, $status, $quote['subtotal'], $quote['discount']['id'] ?? null, $quote['discount_total'],
            $quote['points'], $quote['points_value'], $quote['total'], $pickupTime, str_in('note', 500) ?: null,
            $fulfillment === 'partner_rider' ? $address : null, $fulfillment === 'own_rider' ? ($ownRider ?: null) : null,
        ]);
        $orderId = (int)$pdo->lastInsertId();
        foreach ($quote['lines'] as $l) {
            q('INSERT INTO order_items (order_id, product_id, variant_id, name, variant_name, unit_price, qty, addons_json, note, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $orderId, $l['product_id'], $l['variant_id'], $l['name'], $l['variant_name'], $l['unit_price'], $l['qty'],
                json_encode($l['addons'], JSON_UNESCAPED_UNICODE), $l['note'], $l['line_total'],
            ]);
        }
        if ($quote['points'] > 0) {
            q('INSERT INTO points_ledger (user_id, order_id, delta, reason) VALUES (?, ?, ?, ?)', [$u['id'], $orderId, -$quote['points'], 'Redeemed on ' . $code]);
        }
        log_order_event($orderId, $status, null, (int)$u['id']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    notify_staff('New order ' . $code, $u['name'] . ' · PHP ' . number_format($quote['total'], 2) . ' · ' . str_replace('_', ' ', $fulfillment) . ' · ' . ($payment === 'gcash' ? 'GCash (awaiting proof)' : 'Pay on pickup'), $orderId);
    return ['order' => order_full($orderId)];
}

function order_list(): array
{
    $u = require_user();
    expire_stale_orders();
    $rows = qall('SELECT id, code, status, fulfillment, payment_method, total, created_at FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 50', [$u['id']]);
    foreach ($rows as &$r) { $r['status_label'] = ORDER_STATUS_LABELS[$r['status']]; $r['total'] = (float)$r['total']; $r['id'] = (int)$r['id']; }
    return ['orders' => $rows];
}

function own_order(array $u, int $id): array
{
    $o = order_full($id);
    if (!$o || $o['user_id'] !== (int)$u['id']) fail(404, 'Order not found.');
    return $o;
}

function order_get(array $p): array
{
    $u = require_user();
    expire_stale_orders();
    $o = own_order($u, (int)$p['id']);
    if ($o['status'] === 'pending_payment') {
        $o['pay_before'] = date('c', strtotime($o['created_at']) + (int)setting('payment_window_minutes', 30) * 60);
    }
    return ['order' => $o];
}

function order_payment(array $p): array
{
    $u = require_user();
    rate_limit('proof:' . $u['id'], 10, 3600);
    $o = own_order($u, (int)$p['id']);
    if (!in_array($o['status'], ['pending_payment', 'payment_submitted'], true)) fail(409, 'This order is not waiting for payment.');
    $ref = preg_replace('/\D+/', '', (string)in('gcash_ref', ''));
    if (strlen($ref) < 10 || strlen($ref) > 20) fail(422, 'Please enter the GCash reference number (usually 13 digits).');
    $dup = q1('SELECT id FROM orders WHERE gcash_ref = ? AND id <> ? AND status NOT IN ("cancelled","rejected")', [$ref, $o['id']]);
    if ($dup) fail(422, 'This GCash reference was already used on another order.');
    $file = store_upload('proof', 'proof');
    $old = q1('SELECT proof_file FROM orders WHERE id = ?', [$o['id']])['proof_file'];
    if ($old) @unlink(upload_dir() . '/' . basename($old));
    q('UPDATE orders SET gcash_ref = ?, gcash_sender = ?, proof_file = ? WHERE id = ?', [$ref, str_in('gcash_sender', 120) ?: null, $file, $o['id']]);
    if ($o['status'] === 'pending_payment') set_order_status($o['id'], 'payment_submitted', 'Ref ' . $ref, (int)$u['id']);
    notify_staff('GCash proof received', "Order {$o['code']} · Ref $ref · PHP " . number_format($o['total'], 2), $o['id']);
    return ['order' => order_full($o['id'])];
}

function order_cancel(array $p): array
{
    $u = require_user();
    $o = own_order($u, (int)$p['id']);
    if (!in_array($o['status'], ['pending_payment', 'pending_confirmation'], true)) fail(409, 'This order can no longer be cancelled online. Please message us on Facebook.');
    set_order_status($o['id'], 'cancelled', 'Cancelled by customer', (int)$u['id']);
    return ['order' => order_full($o['id'])];
}

function points_history(): array
{
    $u = require_user();
    return [
        'balance' => (int)$u['points_balance'],
        'history' => qall('SELECT delta, reason, created_at FROM points_ledger WHERE user_id = ? ORDER BY id DESC LIMIT 50', [$u['id']]),
    ];
}
