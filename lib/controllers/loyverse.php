<?php
declare(strict_types=1);

/**
 * Optional one-way import from Loyverse (categories, items, variants, modifiers).
 * Uses the Loyverse API token saved in Settings. Re-running updates matched rows (by loyverse_id)
 * and never deletes products you created in this app.
 */
function loyverse_get(string $path, string $token): array
{
    $all = [];
    $cursor = null;
    $key = null;
    do {
        $url = 'https://api.loyverse.com/v1.0/' . $path . '?limit=250' . ($cursor ? '&cursor=' . urlencode($cursor) : '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 401) fail(422, 'Loyverse rejected the API token. Check it in Settings.');
        if ($code !== 200) fail(502, "Loyverse API error ($code).");
        $data = json_decode((string)$res, true) ?: [];
        $key = $key ?? $path;
        $all = array_merge($all, $data[$key] ?? []);
        $cursor = $data['cursor'] ?? null;
    } while ($cursor);
    return $all;
}

function admin_loyverse_sync(): array
{
    require_admin();
    $token = (string)setting('loyverse_token', '');
    if ($token === '') fail(422, 'Add your Loyverse API token in Settings first.');
    @set_time_limit(120);

    $cats = loyverse_get('categories', $token);
    $mods = loyverse_get('modifiers', $token);
    $items = loyverse_get('items', $token);

    $stats = ['categories' => 0, 'products' => 0, 'variants' => 0, 'addon_groups' => 0];
    $catMap = [];
    foreach ($cats as $c) {
        q('INSERT INTO categories (name, loyverse_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)', [mb_substr($c['name'], 0, 80), $c['id']]);
        $catMap[$c['id']] = (int)q1('SELECT id FROM categories WHERE loyverse_id = ?', [$c['id']])['id'];
        $stats['categories']++;
    }
    $groupMap = [];
    foreach ($mods as $m) {
        q('INSERT INTO addon_groups (name, min_select, max_select, loyverse_id) VALUES (?, 0, 5, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)', [mb_substr($m['name'], 0, 80), $m['id']]);
        $gid = (int)q1('SELECT id FROM addon_groups WHERE loyverse_id = ?', [$m['id']])['id'];
        $groupMap[$m['id']] = $gid;
        foreach ($m['modifier_options'] ?? [] as $i => $opt) {
            q('INSERT INTO addons (group_id, name, price, sort, loyverse_id) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), price = VALUES(price)',
                [$gid, mb_substr($opt['name'], 0, 80), money($opt['price'] ?? 0), $i, $opt['id']]);
        }
        $stats['addon_groups']++;
    }
    foreach ($items as $it) {
        if (!empty($it['deleted_at'])) continue;
        $variants = $it['variants'] ?? [];
        $price = 0.0;
        foreach ($variants as $v) { $vp = loyverse_variant_price($v); if ($vp > 0) { $price = $price ? min($price, $vp) : $vp; } }
        q('INSERT INTO products (category_id, name, description, image_url, base_price, loyverse_id) VALUES (?, ?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), name = VALUES(name), base_price = VALUES(base_price),
           image_url = COALESCE(VALUES(image_url), image_url), description = COALESCE(VALUES(description), description)', [
            $catMap[$it['category_id'] ?? ''] ?? null, mb_substr($it['item_name'], 0, 120), $it['description'] ? mb_substr(strip_tags($it['description']), 0, 500) : null,
            $it['image_url'] ?? null, $price, $it['id'],
        ]);
        $pid = (int)q1('SELECT id FROM products WHERE loyverse_id = ?', [$it['id']])['id'];
        $stats['products']++;
        if (count($variants) > 1) {
            foreach ($variants as $i => $v) {
                $vname = trim(implode(' / ', array_filter([$v['option1_value'] ?? null, $v['option2_value'] ?? null, $v['option3_value'] ?? null]))) ?: ('Option ' . ($i + 1));
                q('INSERT INTO product_variants (product_id, name, price, sort, loyverse_id) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), price = VALUES(price)',
                    [$pid, mb_substr($vname, 0, 80), loyverse_variant_price($v), $i, $v['variant_id']]);
                $stats['variants']++;
            }
        }
        foreach ($it['modifier_ids'] ?? [] as $mid) {
            if (isset($groupMap[$mid])) q('INSERT IGNORE INTO product_addon_groups (product_id, group_id) VALUES (?, ?)', [$pid, $groupMap[$mid]]);
        }
    }
    set_setting('loyverse_last_sync', now());
    return ['ok' => true, 'stats' => $stats];
}

function loyverse_variant_price(array $v): float
{
    if (isset($v['default_price']) && $v['default_price'] !== null) return money($v['default_price']);
    foreach ($v['stores'] ?? [] as $s) if (isset($s['price']) && $s['price'] !== null) return money($s['price']);
    return 0.0;
}
