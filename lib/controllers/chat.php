<?php
declare(strict_types=1);

/*
 * Support chat between customers/riders and the cafe. One thread per user, polled (no paid services).
 */

function chat_message_shape(array $m): array
{
    return [
        'id' => (int)$m['id'], 'body' => $m['body'], 'from_staff' => (bool)$m['from_staff'],
        'staff_name' => $m['from_staff'] ? ($m['sender_name'] ?? 'Cafresco') : null,
        'created_at' => $m['created_at'],
    ];
}

function chat_messages(int $userId, int $after): array
{
    $rows = qall('SELECT m.*, s.name AS sender_name FROM support_messages m LEFT JOIN users s ON s.id = m.sender_id
        WHERE m.user_id = ? AND m.id > ? ORDER BY m.id DESC LIMIT 100', [$userId, $after]);
    return array_map('chat_message_shape', array_reverse($rows));
}

function chat_body(): string
{
    $body = trim(preg_replace("/\r\n?/", "\n", (string)in('body', '')));
    if ($body === '') fail(422, 'Type a message first.');
    if (mb_strlen($body) > 2000) fail(422, 'Message is too long (2000 characters max).');
    return $body;
}

/** Customer or rider: read my thread. Marks the cafe's replies as seen. */
function chat_get(): array
{
    $u = require_user();
    if (!in_array($u['role'], ['customer', 'rider'], true)) fail(403, 'Staff use the admin inbox.');
    if (!empty($_GET['peek'])) return ['unread' => chat_unread_for((int)$u['id'])];
    $after = (int)($_GET['after'] ?? 0);
    // Customers see replies from "Cafresco", not the staff member's personal name.
    $msgs = array_map(fn($m) => $m['from_staff'] ? ['staff_name' => 'Cafresco'] + $m : $m, chat_messages((int)$u['id'], $after));
    q('UPDATE support_messages SET seen_at = ? WHERE user_id = ? AND from_staff = 1 AND seen_at IS NULL', [now(), $u['id']]);
    return ['messages' => $msgs, 'facebook_url' => setting('facebook_url', '')];
}

function chat_unread_for(int $userId): int
{
    return (int)q1('SELECT COUNT(*) c FROM support_messages WHERE user_id = ? AND from_staff = 1 AND seen_at IS NULL', [$userId])['c'];
}

function chat_send(): array
{
    $u = require_user();
    if (!in_array($u['role'], ['customer', 'rider'], true)) fail(403, 'Staff use the admin inbox.');
    rate_limit('chat:' . $u['id'], 20, 600);
    $body = chat_body();
    $first = !q1('SELECT id FROM support_messages WHERE user_id = ? AND from_staff = 0 AND created_at > ? LIMIT 1', [$u['id'], date('Y-m-d H:i:s', time() - 1800)]);
    q('INSERT INTO support_messages (user_id, sender_id, from_staff, body) VALUES (?, ?, 0, ?)', [$u['id'], $u['id'], $body]);
    // One staff alert per conversation burst, not per message.
    if ($first) notify_staff('New chat from ' . $u['name'], mb_strimwidth($body, 0, 120, '…'));
    return chat_get();
}

/** Admin inbox: one row per user with the latest message and unread count. */
function admin_chats(): array
{
    require_staff();
    $rows = qall('SELECT t.user_id, t.last_id, t.unread, u.name, u.role, u.mobile, u.facebook_url, u.instagram_url, u.trusted, m.body AS last_body, m.from_staff AS last_from_staff, m.created_at AS last_at
        FROM (SELECT user_id, MAX(id) last_id, SUM(from_staff = 0 AND seen_at IS NULL) unread FROM support_messages GROUP BY user_id) t
        JOIN users u ON u.id = t.user_id JOIN support_messages m ON m.id = t.last_id
        ORDER BY t.unread > 0 DESC, t.last_id DESC LIMIT 200');
    foreach ($rows as &$r) {
        foreach (['user_id', 'last_id', 'unread'] as $k) $r[$k] = (int)$r[$k];
        $r['last_from_staff'] = (bool)$r['last_from_staff'];
        $r['trusted'] = (bool)$r['trusted'];
    }
    return ['threads' => $rows, 'unread' => admin_chat_unread()];
}

function admin_chat_unread(): int
{
    return (int)(q1('SELECT COUNT(*) c FROM support_messages WHERE from_staff = 0 AND seen_at IS NULL')['c'] ?? 0);
}

function admin_chat_user(int $id): array
{
    $u = q1('SELECT id, name, role, email, mobile, facebook_url, instagram_url, trusted, created_at FROM users WHERE id = ? AND role IN ("customer","rider")', [$id]);
    if (!$u) fail(404, 'User not found.');
    $u['id'] = (int)$u['id'];
    $u['trusted'] = (bool)$u['trusted'];
    $u['completed_orders'] = (int)q1('SELECT COUNT(*) c FROM orders WHERE user_id = ? AND status = "completed"', [$id])['c'];
    return $u;
}

function admin_chat_get(array $p): array
{
    require_staff();
    $id = (int)$p['id'];
    $user = admin_chat_user($id);
    $msgs = chat_messages($id, (int)($_GET['after'] ?? 0));
    q('UPDATE support_messages SET seen_at = ? WHERE user_id = ? AND from_staff = 0 AND seen_at IS NULL', [now(), $id]);
    return ['user' => $user, 'messages' => $msgs];
}

function admin_chat_send(array $p): array
{
    $s = require_staff();
    $id = (int)$p['id'];
    admin_chat_user($id);
    $body = chat_body();
    q('INSERT INTO support_messages (user_id, sender_id, from_staff, body) VALUES (?, ?, 1, ?)', [$id, $s['id'], $body]);
    notify_user($id, 'Message from Cafresco', mb_strimwidth($body, 0, 160, '…'));
    return admin_chat_get($p);
}
