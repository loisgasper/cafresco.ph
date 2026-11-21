<?php
declare(strict_types=1);

function notify_user(int $userId, string $title, string $body = '', ?int $orderId = null): void
{
    q('INSERT INTO notifications (user_id, audience, order_id, title, body) VALUES (?, "user", ?, ?, ?)', [$userId, $orderId, $title, $body]);
}

function notify_staff(string $title, string $body = '', ?int $orderId = null): void
{
    q('INSERT INTO notifications (user_id, audience, order_id, title, body) VALUES (NULL, "staff", ?, ?, ?)', [$orderId, $title, $body]);
}

/** Notify every approved rider who is currently online. Returns how many were notified. */
function notify_online_riders(string $title, string $body, int $orderId): int
{
    $riders = qall('SELECT rp.user_id FROM rider_profiles rp JOIN users u ON u.id = rp.user_id
        WHERE rp.approval = "approved" AND rp.is_online = 1 AND u.status = "active" AND rp.last_seen_at >= ?', [date('Y-m-d H:i:s', time() - 1800)]);
    foreach ($riders as $r) notify_user((int)$r['user_id'], $title, $body, $orderId);
    return count($riders);
}
