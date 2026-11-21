<?php
declare(strict_types=1);

function rider_companies_public(): array
{
    return ['companies' => qall('SELECT id, name FROM rider_companies WHERE active = 1 ORDER BY name')];
}

function rider_register(): array
{
    $companyId = (int)in('company_id', 0);
    if (!q1('SELECT id FROM rider_companies WHERE id = ? AND active = 1', [$companyId])) fail(422, 'Please choose your rider company.');
    $u = create_account('rider');
    q('INSERT INTO rider_profiles (user_id, company_id, vehicle, plate_no) VALUES (?, ?, ?, ?)',
        [$u['id'], $companyId, str_in('vehicle', 60) ?: null, strtoupper(str_in('plate_no', 20)) ?: null]);
    notify_staff('New rider registration', $u['name'] . ' wants to join as a partner rider.');
    login_user($u);
    return ['user' => user_public($u)];
}

function require_approved_rider(): array
{
    $u = require_role('rider');
    $rp = q1('SELECT * FROM rider_profiles WHERE user_id = ?', [$u['id']]);
    if (!$rp || $rp['approval'] !== 'approved') fail(403, 'Your rider account is waiting for approval from Cafresco.');
    q('UPDATE rider_profiles SET last_seen_at = ? WHERE user_id = ?', [now(), $u['id']]);
    return $u + ['rider_profile' => $rp];
}

function rider_online(): array
{
    $u = require_approved_rider();
    q('UPDATE rider_profiles SET is_online = ? WHERE user_id = ?', [in('online') ? 1 : 0, $u['id']]);
    return ['user' => user_public($u)];
}

function km_between(float $a1, float $o1, float $a2, float $o2): float
{
    $r = 6371.0;
    $dLat = deg2rad($a2 - $a1); $dLng = deg2rad($o2 - $o1);
    $h = sin($dLat / 2) ** 2 + cos(deg2rad($a1)) * cos(deg2rad($a2)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($h)));
}

function rider_job_shape(array $o, bool $mine): array
{
    $items = qall('SELECT name, variant_name, qty FROM order_items WHERE order_id = ?', [$o['id']]);
    $accepted = $mine && $o['rider_quote_status'] === 'accepted';
    $sLat = setting('store_lat', ''); $sLng = setting('store_lng', '');
    $km = (is_numeric($sLat) && is_numeric($sLng) && $o['delivery_lat'] !== null)
        ? round(km_between((float)$sLat, (float)$sLng, (float)$o['delivery_lat'], (float)$o['delivery_lng']), 1) : null;
    return [
        'id' => (int)$o['id'], 'code' => $o['code'], 'status' => $o['status'], 'status_label' => ORDER_STATUS_LABELS[$o['status']],
        'total' => (float)$o['total'], 'payment_method' => $o['payment_method'],
        'collect_from_customer' => $o['payment_method'] === 'pay_on_pickup',
        'pickup_time' => $o['pickup_time'], 'created_at' => $o['created_at'],
        'items' => $items,
        'quote_status' => $mine ? $o['rider_quote_status'] : null,
        'rider_fee' => $mine && $o['rider_fee'] !== null ? (float)$o['rider_fee'] : null,
        // Riders need the drop-off to price the trip; name, phone and note stay hidden until the customer accepts the rate.
        'delivery_address' => $o['delivery_address'],
        'distance_km' => $km,
        'delivery_lat' => $accepted && $o['delivery_lat'] !== null ? (float)$o['delivery_lat'] : null,
        'delivery_lng' => $accepted && $o['delivery_lng'] !== null ? (float)$o['delivery_lng'] : null,
        'customer_name' => $accepted ? $o['customer_name'] : null,
        'customer_mobile' => $accepted ? $o['customer_mobile'] : null,
        'note' => $accepted ? $o['customer_note'] : null,
    ];
}

function rider_jobs(): array
{
    $u = require_approved_rider();
    $sel = 'SELECT o.*, u.name AS customer_name, u.mobile AS customer_mobile FROM orders o JOIN users u ON u.id = o.user_id';
    $available = $u['rider_profile']['is_online']
        ? qall("$sel WHERE o.fulfillment = 'partner_rider' AND o.rider_id IS NULL AND o.status IN ('confirmed','preparing','ready')
            AND NOT FIND_IN_SET(?, IFNULL(o.declined_riders, '')) ORDER BY o.id", [(string)$u['id']])
        : [];
    $mine = qall("$sel WHERE o.rider_id = ? AND (o.status IN ('confirmed','preparing','ready','out_for_delivery') OR o.updated_at > ?) ORDER BY o.id DESC LIMIT 30",
        [$u['id'], date('Y-m-d H:i:s', time() - 86400)]);
    $company = q1('SELECT name, base_rate, rate_note FROM rider_companies WHERE id = ?', [$u['rider_profile']['company_id']]);
    return [
        'online' => (bool)$u['rider_profile']['is_online'],
        'base_rate' => $company && $company['base_rate'] !== null ? (float)$company['base_rate'] : null,
        'rate_note' => $company['rate_note'] ?? null,
        'available' => array_map(fn($o) => rider_job_shape($o, false), $available),
        'mine' => array_map(fn($o) => rider_job_shape($o, true), $mine),
    ];
}

/** Rider offers to take the job at their delivery rate. The customer then accepts or declines. */
function rider_accept(array $p): array
{
    $u = require_approved_rider();
    if (!$u['rider_profile']['is_online']) fail(409, 'Go online first to accept jobs.');
    $active = (int)q1("SELECT COUNT(*) c FROM orders WHERE rider_id = ? AND status IN ('confirmed','preparing','ready','out_for_delivery')", [$u['id']])['c'];
    if ($active >= 3) fail(409, 'Finish your current jobs first (max 3 at a time).');
    $rp = $u['rider_profile'];
    $company = q1('SELECT name, base_rate FROM rider_companies WHERE id = ?', [$rp['company_id']]);
    $fee = in('fee');
    if (!is_numeric($fee) || (float)$fee <= 0 || (float)$fee > 3000) fail(422, 'Enter your delivery rate in pesos.');
    $fee = round((float)$fee, 2);
    if ($company['base_rate'] !== null && $fee < (float)$company['base_rate']) fail(422, 'Your rate cannot be lower than your company starting rate of PHP ' . number_format((float)$company['base_rate'], 2) . '.');
    $ok = q("UPDATE orders SET rider_id = ?, rider_fee = ?, rider_quote_status = 'pending', rider_quote_at = ?
        WHERE id = ? AND rider_id IS NULL AND fulfillment = 'partner_rider' AND status IN ('confirmed','preparing','ready')
        AND NOT FIND_IN_SET(?, IFNULL(declined_riders, ''))",
        [$u['id'], $fee, now(), (int)$p['id'], (string)$u['id']])->rowCount();
    if (!$ok) fail(409, 'Another rider already took this job.');
    $o = q1('SELECT * FROM orders WHERE id = ?', [(int)$p['id']]);
    $f = number_format($fee, 2);
    log_order_event((int)$o['id'], 'rider_quote', "{$u['name']} ({$company['name']}) offered PHP $f delivery", (int)$u['id']);
    notify_user((int)$o['user_id'], 'Rider offer: PHP ' . $f, "Order {$o['code']}: {$u['name']} of {$company['name']} can deliver for PHP $f. Open your order to accept or decline.", (int)$o['id']);
    notify_staff('Rider offer on ' . $o['code'], "{$u['name']} ({$company['name']}) · PHP $f · waiting for customer", (int)$o['id']);
    return rider_jobs();
}

/** Rider takes back an offer the customer has not answered yet. */
function rider_withdraw(array $p): array
{
    $u = require_approved_rider();
    $o = rider_owned($u, (int)$p['id']);
    if ($o['rider_quote_status'] !== 'pending') fail(409, 'The customer already accepted your rate. Message Cafresco if you cannot continue.');
    q("UPDATE orders SET rider_id = NULL, rider_fee = NULL, rider_quote_status = NULL, rider_quote_at = NULL WHERE id = ? AND rider_id = ? AND rider_quote_status = 'pending'", [$o['id'], $u['id']]);
    log_order_event((int)$o['id'], 'rider_withdrew', $u['name'] . ' withdrew the offer', (int)$u['id']);
    notify_user((int)$o['user_id'], 'Still looking for a rider', "Order {$o['code']}: the rider withdrew the offer. We are looking for another rider, this may take some time.", (int)$o['id']);
    notify_online_riders('Pickup job available', "Order {$o['code']} needs a rider. Send your rate.", (int)$o['id'], [(int)$u['id']]);
    return rider_jobs();
}

function rider_owned(array $u, int $id): array
{
    $o = q1('SELECT * FROM orders WHERE id = ? AND rider_id = ?', [$id, $u['id']]);
    if (!$o) fail(404, 'Job not found.');
    return $o;
}

function rider_pickup(array $p): array
{
    $u = require_approved_rider();
    $o = rider_owned($u, (int)$p['id']);
    if ($o['rider_quote_status'] !== 'accepted') fail(409, 'Wait for the customer to accept your rate first.');
    if ($o['status'] !== 'ready') fail(409, 'The order is not ready yet. The cafe will mark it ready.');
    set_order_status((int)$o['id'], 'out_for_delivery', 'Picked up by ' . $u['name'], (int)$u['id']);
    return rider_jobs();
}

function rider_deliver(array $p): array
{
    $u = require_approved_rider();
    $o = rider_owned($u, (int)$p['id']);
    if ($o['status'] !== 'out_for_delivery') fail(409, 'Pick up the order first.');
    set_order_status((int)$o['id'], 'completed', 'Delivered by ' . $u['name'], (int)$u['id']);
    notify_staff('Delivered ' . $o['code'], 'by ' . $u['name'], (int)$o['id']);
    return rider_jobs();
}
