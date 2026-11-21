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

function rider_job_shape(array $o, bool $mine): array
{
    $items = qall('SELECT name, variant_name, qty FROM order_items WHERE order_id = ?', [$o['id']]);
    return [
        'id' => (int)$o['id'], 'code' => $o['code'], 'status' => $o['status'], 'status_label' => ORDER_STATUS_LABELS[$o['status']],
        'total' => (float)$o['total'], 'payment_method' => $o['payment_method'],
        'collect_from_customer' => $o['payment_method'] === 'pay_on_pickup',
        'pickup_time' => $o['pickup_time'], 'created_at' => $o['created_at'],
        'items' => $items,
        // Customer contact and address only once the rider owns the job.
        'delivery_address' => $mine ? $o['delivery_address'] : null,
        'customer_name' => $mine ? $o['customer_name'] : null,
        'customer_mobile' => $mine ? $o['customer_mobile'] : null,
        'note' => $mine ? $o['customer_note'] : null,
    ];
}

function rider_jobs(): array
{
    $u = require_approved_rider();
    $sel = 'SELECT o.*, u.name AS customer_name, u.mobile AS customer_mobile FROM orders o JOIN users u ON u.id = o.user_id';
    $available = $u['rider_profile']['is_online']
        ? qall("$sel WHERE o.fulfillment = 'partner_rider' AND o.rider_id IS NULL AND o.status IN ('confirmed','preparing','ready') ORDER BY o.id")
        : [];
    $mine = qall("$sel WHERE o.rider_id = ? AND (o.status IN ('confirmed','preparing','ready','out_for_delivery') OR o.updated_at > ?) ORDER BY o.id DESC LIMIT 30",
        [$u['id'], date('Y-m-d H:i:s', time() - 86400)]);
    return [
        'online' => (bool)$u['rider_profile']['is_online'],
        'available' => array_map(fn($o) => rider_job_shape($o, false), $available),
        'mine' => array_map(fn($o) => rider_job_shape($o, true), $mine),
    ];
}

function rider_accept(array $p): array
{
    $u = require_approved_rider();
    if (!$u['rider_profile']['is_online']) fail(409, 'Go online first to accept jobs.');
    $active = (int)q1("SELECT COUNT(*) c FROM orders WHERE rider_id = ? AND status IN ('confirmed','preparing','ready','out_for_delivery')", [$u['id']])['c'];
    if ($active >= 3) fail(409, 'Finish your current jobs first (max 3 at a time).');
    $ok = q("UPDATE orders SET rider_id = ? WHERE id = ? AND rider_id IS NULL AND fulfillment = 'partner_rider' AND status IN ('confirmed','preparing','ready')",
        [$u['id'], (int)$p['id']])->rowCount();
    if (!$ok) fail(409, 'Another rider already took this job.');
    $o = q1('SELECT * FROM orders WHERE id = ?', [(int)$p['id']]);
    $rp = $u['rider_profile'];
    $company = q1('SELECT name FROM rider_companies WHERE id = ?', [$rp['company_id']])['name'] ?? '';
    log_order_event((int)$o['id'], 'rider_assigned', "{$u['name']} ($company)", (int)$u['id']);
    $desc = "{$u['name']} of $company" . ($rp['plate_no'] ? ", plate {$rp['plate_no']}" : '') . " · {$u['mobile']}";
    notify_user((int)$o['user_id'], 'Rider assigned', "Order {$o['code']}: $desc", (int)$o['id']);
    notify_staff('Rider accepted ' . $o['code'], $desc, (int)$o['id']);
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
