<?php
declare(strict_types=1);

const ORDER_STATUS_LABELS = [
    'pending_payment' => 'Waiting for GCash payment',
    'payment_submitted' => 'Payment submitted, checking',
    'pending_confirmation' => 'Waiting for cafe confirmation',
    'confirmed' => 'Confirmed',
    'preparing' => 'Preparing',
    'ready' => 'Ready for pickup',
    'out_for_delivery' => 'On the way',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'rejected' => 'Rejected',
];
const OPEN_STATUSES = ['pending_payment', 'payment_submitted', 'pending_confirmation', 'confirmed', 'preparing', 'ready', 'out_for_delivery'];

/**
 * Price a cart from the database (never trust client prices).
 * Each item: {product_id, variant_id?, addon_ids?: int[], qty, note?}
 */
function price_cart(array $items): array
{
    if (!$items) fail(422, 'Your cart is empty.');
    if (count($items) > 40) fail(422, 'Too many items in one order.');
    $lines = [];
    $subtotal = 0.0;
    foreach ($items as $it) {
        $pid = (int)($it['product_id'] ?? 0);
        $qty = (int)($it['qty'] ?? 1);
        if ($qty < 1 || $qty > 50) fail(422, 'Invalid quantity.');
        $p = q1('SELECT * FROM products WHERE id = ? AND active = 1', [$pid]);
        if (!$p) fail(422, 'An item in your cart is no longer available.');
        if ($p['sold_out']) fail(422, "{$p['name']} is sold out right now.");

        $unit = (float)$p['base_price'];
        $variantName = null;
        $variantId = null;
        $variants = qall('SELECT * FROM product_variants WHERE product_id = ? AND active = 1', [$pid]);
        if ($variants) {
            $vid = (int)($it['variant_id'] ?? 0);
            $v = null;
            foreach ($variants as $cand) if ((int)$cand['id'] === $vid) $v = $cand;
            if (!$v) fail(422, "Please choose an option for {$p['name']}.");
            $unit = (float)$v['price'];
            $variantName = $v['name'];
            $variantId = (int)$v['id'];
        }

        $addonIds = array_values(array_unique(array_map('intval', (array)($it['addon_ids'] ?? []))));
        $groups = qall('SELECT g.* FROM addon_groups g JOIN product_addon_groups pag ON pag.group_id = g.id WHERE pag.product_id = ?', [$pid]);
        $chosen = [];
        $countByGroup = [];
        if ($addonIds) {
            $place = implode(',', array_fill(0, count($addonIds), '?'));
            $rows = qall("SELECT a.* FROM addons a JOIN product_addon_groups pag ON pag.group_id = a.group_id
                WHERE pag.product_id = ? AND a.active = 1 AND a.id IN ($place)", array_merge([$pid], $addonIds));
            if (count($rows) !== count($addonIds)) fail(422, "Some add-ons for {$p['name']} are not available.");
            foreach ($rows as $a) {
                $chosen[] = ['id' => (int)$a['id'], 'name' => $a['name'], 'price' => money($a['price'])];
                $unit += (float)$a['price'];
                $countByGroup[$a['group_id']] = ($countByGroup[$a['group_id']] ?? 0) + 1;
            }
        }
        foreach ($groups as $g) {
            $n = $countByGroup[$g['id']] ?? 0;
            if ($n < (int)$g['min_select']) fail(422, "Please choose {$g['name']} for {$p['name']}.");
            if ($n > (int)$g['max_select']) fail(422, "Too many {$g['name']} choices for {$p['name']}.");
        }

        $line = money($unit * $qty);
        $subtotal += $line;
        $lines[] = [
            'product_id' => $pid, 'variant_id' => $variantId, 'name' => $p['name'], 'variant_name' => $variantName,
            'unit_price' => money($unit), 'qty' => $qty, 'addons' => $chosen,
            'note' => mb_substr(trim((string)($it['note'] ?? '')), 0, 200) ?: null, 'line_total' => $line,
        ];
    }
    return ['lines' => $lines, 'subtotal' => money($subtotal)];
}

function discount_valid(array $d, float $subtotal, ?int $userId): ?string
{
    $t = time();
    if (!$d['active']) return 'This code is not active.';
    if ($d['starts_at'] && strtotime($d['starts_at']) > $t) return 'This promo has not started yet.';
    if ($d['ends_at'] && strtotime($d['ends_at']) < $t) return 'This promo has ended.';
    if ($d['max_uses'] !== null && (int)$d['uses'] >= (int)$d['max_uses']) return 'This promo has been fully used.';
    if ($subtotal < (float)$d['min_subtotal']) return 'Minimum order for this promo is PHP ' . number_format((float)$d['min_subtotal'], 2) . '.';
    if ($userId && $d['per_user_limit'] !== null) {
        $used = (int)q1('SELECT COUNT(*) c FROM orders WHERE user_id = ? AND discount_id = ? AND status NOT IN ("cancelled","rejected")', [$userId, $d['id']])['c'];
        if ($used >= (int)$d['per_user_limit']) return 'You have already used this promo.';
    }
    return null;
}

function discount_amount(array $d, float $subtotal): float
{
    $amt = $d['type'] === 'percent' ? $subtotal * ((float)$d['value'] / 100) : (float)$d['value'];
    return money(min($amt, $subtotal));
}

/** Resolve a discount: an entered code, otherwise the best automatic (no-code) promo. */
function resolve_discount(?string $code, float $subtotal, ?int $userId): array
{
    if ($code) {
        $d = q1('SELECT * FROM discounts WHERE code = ?', [strtoupper($code)]);
        if (!$d) fail(422, 'Promo code not found.');
        if ($err = discount_valid($d, $subtotal, $userId)) fail(422, $err);
        return ['discount' => $d, 'amount' => discount_amount($d, $subtotal)];
    }
    $best = null; $bestAmt = 0.0;
    foreach (qall('SELECT * FROM discounts WHERE code IS NULL AND active = 1') as $d) {
        if (discount_valid($d, $subtotal, $userId)) continue;
        $a = discount_amount($d, $subtotal);
        if ($a > $bestAmt) { $best = $d; $bestAmt = $a; }
    }
    return ['discount' => $best, 'amount' => $bestAmt];
}

function points_redeem_value(int $points, float $afterDiscount, int $balance): float
{
    if ($points <= 0) return 0.0;
    if ($points > $balance) fail(422, 'You do not have enough points.');
    if ($points < (int)setting('min_points_redeem', 20)) fail(422, 'Minimum points to redeem is ' . setting('min_points_redeem', 20) . '.');
    $value = money($points * (float)setting('peso_per_point', 1));
    $cap = money($afterDiscount * ((float)setting('max_redeem_percent', 50) / 100));
    if ($value > $cap) fail(422, 'Points can cover up to ' . setting('max_redeem_percent', 50) . '% of your order (max ' . floor($cap / max(0.01, (float)setting('peso_per_point', 1))) . ' points).');
    return $value;
}

function new_order_code(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $c = 'CF-';
        for ($i = 0; $i < 6; $i++) $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    } while (q1('SELECT id FROM orders WHERE code = ?', [$c]));
    return $c;
}

function log_order_event(int $orderId, string $status, ?string $note, ?int $actorId): void
{
    q('INSERT INTO order_events (order_id, status, note, actor_id) VALUES (?, ?, ?, ?)', [$orderId, $status, $note, $actorId]);
}

/** Cancel unpaid GCash orders past the payment window and return their points. */
function expire_stale_orders(): void
{
    $mins = max(5, (int)setting('payment_window_minutes', 30));
    $stale = qall('SELECT id FROM orders WHERE status = "pending_payment" AND created_at < ?', [date('Y-m-d H:i:s', time() - $mins * 60)]);
    foreach ($stale as $o) set_order_status((int)$o['id'], 'cancelled', 'Auto-cancelled: no payment received in time.', null);
}

/** Apply a status change with side effects (points, notifications, rider broadcast). */
function set_order_status(int $orderId, string $status, ?string $note, ?int $actorId): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $o = q1('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if (!$o) fail(404, 'Order not found.');
        $prev = $o['status'];
        if ($prev === $status) { $pdo->commit(); return $o; }
        if (in_array($prev, ['completed', 'cancelled', 'rejected'], true)) fail(409, 'This order is already closed.');

        q('UPDATE orders SET status = ? WHERE id = ?', [$status, $orderId]);
        log_order_event($orderId, $status, $note, $actorId);
        $uid = (int)$o['user_id'];

        if (in_array($status, ['cancelled', 'rejected'], true)) {
            if ((int)$o['points_redeemed'] > 0) {
                q('UPDATE users SET points_balance = points_balance + ? WHERE id = ?', [$o['points_redeemed'], $uid]);
                q('INSERT INTO points_ledger (user_id, order_id, delta, reason) VALUES (?, ?, ?, ?)', [$uid, $orderId, (int)$o['points_redeemed'], 'Refund for ' . $o['code']]);
            }
            if ($o['discount_id']) q('UPDATE discounts SET uses = GREATEST(uses - 1, 0) WHERE id = ?', [$o['discount_id']]);
        }
        if ($status === 'completed') {
            $earn = (int)floor((float)$o['total'] * (float)setting('points_per_peso', 0.02));
            if ($earn > 0) {
                q('UPDATE users SET points_balance = points_balance + ? WHERE id = ?', [$earn, $uid]);
                q('UPDATE orders SET points_earned = ? WHERE id = ?', [$earn, $orderId]);
                q('INSERT INTO points_ledger (user_id, order_id, delta, reason) VALUES (?, ?, ?, ?)', [$uid, $orderId, $earn, 'Earned on ' . $o['code']]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $label = ORDER_STATUS_LABELS[$status] ?? $status;
    $msg = "Order {$o['code']}: $label" . ($note ? " ($note)" : '');
    notify_user((int)$o['user_id'], $label, $msg, $orderId);
    if (in_array($status, ['confirmed', 'ready', 'completed', 'rejected', 'cancelled'], true)) {
        $cust = q1('SELECT email, name FROM users WHERE id = ?', [$o['user_id']]);
        if ($cust) send_mail($cust['email'], "Cafresco order {$o['code']}: $label", "Hi {$cust['name']},\n\n$msg\n\nTrack it here: " . app_url('#/orders/' . $orderId));
    }
    if ($status === 'confirmed' && $o['fulfillment'] === 'partner_rider' && !$o['rider_id']) {
        notify_online_riders('New pickup job', "Order {$o['code']} needs a rider. Total PHP " . number_format((float)$o['total'], 2), $orderId);
    }
    if ($o['rider_id'] && in_array($status, ['ready', 'cancelled', 'rejected'], true)) {
        notify_user((int)$o['rider_id'], $label, $msg, $orderId);
    }
    if ($status === 'cancelled' && $actorId === (int)$o['user_id']) notify_staff('Order cancelled by customer', $msg, $orderId);
    return q1('SELECT * FROM orders WHERE id = ?', [$orderId]);
}

function order_full(int $id, bool $forStaff = false): ?array
{
    $o = q1('SELECT o.*, u.name AS customer_name, u.mobile AS customer_mobile, u.email AS customer_email
        FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ?', [$id]);
    if (!$o) return null;
    $items = qall('SELECT * FROM order_items WHERE order_id = ?', [$id]);
    foreach ($items as &$it) {
        $it['addons'] = json_decode($it['addons_json'] ?: '[]', true);
        unset($it['addons_json']);
        foreach (['unit_price', 'line_total'] as $k) $it[$k] = (float)$it[$k];
        $it['qty'] = (int)$it['qty'];
    }
    unset($it);
    $o['items'] = $items;
    $o['events'] = qall('SELECT status, note, created_at FROM order_events WHERE order_id = ? ORDER BY id', [$id]);
    $o['status_label'] = ORDER_STATUS_LABELS[$o['status']] ?? $o['status'];
    $o['has_proof'] = (bool)$o['proof_file'];
    unset($o['proof_file']);
    foreach (['subtotal', 'discount_total', 'points_value', 'total'] as $k) $o[$k] = (float)$o[$k];
    foreach (['id', 'user_id', 'points_redeemed', 'points_earned'] as $k) $o[$k] = (int)$o[$k];
    $o['rider_fee'] = $o['rider_fee'] !== null ? (float)$o['rider_fee'] : null;
    $o['declined_count'] = count(array_filter(explode(',', (string)$o['declined_riders'])));
    unset($o['declined_riders']);
    foreach (['delivery_lat', 'delivery_lng'] as $k) $o[$k] = $o[$k] !== null ? (float)$o[$k] : null;
    if ($o['rider_id']) {
        $r = q1('SELECT u.name, u.mobile, rp.plate_no, rp.vehicle, rc.name AS company FROM users u
            JOIN rider_profiles rp ON rp.user_id = u.id JOIN rider_companies rc ON rc.id = rp.company_id WHERE u.id = ?', [$o['rider_id']]);
        // Until the customer accepts, show who is offering but not their phone number.
        if ($r && $o['rider_quote_status'] !== 'accepted' && !$forStaff) $r['mobile'] = null;
        $o['rider'] = $r;
    }
    if ($forStaff) {
        $o['customer_completed_orders'] = (int)q1('SELECT COUNT(*) c FROM orders WHERE user_id = ? AND status = "completed"', [$o['user_id']])['c'];
        $o['duplicate_ref'] = $o['gcash_ref'] ? (bool)q1('SELECT id FROM orders WHERE gcash_ref = ? AND id <> ? LIMIT 1', [$o['gcash_ref'], $id]) : false;
    } else {
        unset($o['staff_note']);
    }
    return $o;
}
