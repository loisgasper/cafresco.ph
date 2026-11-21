<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
foreach (glob(dirname(__DIR__) . '/lib/controllers/*.php') as $f) require $f;

$routes = [
    // public
    ['GET',  'config',                       'public_config'],
    ['GET',  'menu',                         'menu'],
    ['GET',  'files/public/{name}',          'file_public'],
    ['GET',  'rider/companies',              'rider_companies_public'],
    ['POST', 'setup',                        'setup_install'],
    ['POST', 'setup/seed-menu',              'setup_seed_menu'],
    ['POST', 'setup/fill-descriptions',      'setup_fill_descriptions'],
    ['POST', 'setup/normalize-menu',         'setup_normalize_menu'],
    // auth
    ['GET',  'auth/me',                      'auth_me'],
    ['POST', 'auth/register',                'auth_register'],
    ['POST', 'auth/login',                   'auth_login'],
    ['POST', 'auth/logout',                  'auth_logout'],
    ['POST', 'auth/verify-email',            'auth_verify_email'],
    ['POST', 'auth/resend-email',            'auth_resend_email'],
    ['POST', 'auth/send-mobile-otp',         'auth_send_mobile_otp'],
    ['POST', 'auth/verify-mobile',           'auth_verify_mobile'],
    ['POST', 'auth/forgot',                  'auth_forgot'],
    ['POST', 'auth/reset',                   'auth_reset'],
    ['POST', 'auth/profile',                 'auth_profile'],
    // customer
    ['POST', 'cart/quote',                   'cart_quote'],
    ['POST', 'orders',                       'order_create'],
    ['GET',  'orders',                       'order_list'],
    ['GET',  'orders/{id}',                  'order_get'],
    ['POST', 'orders/{id}/payment',          'order_payment'],
    ['POST', 'orders/{id}/cancel',           'order_cancel'],
    ['GET',  'files/proof/{id}',             'file_proof'],
    ['GET',  'points',                       'points_history'],
    ['GET',  'notifications',                'notifications_list'],
    ['POST', 'notifications/read',           'notifications_read'],
    // rider
    ['POST', 'rider/register',               'rider_register'],
    ['POST', 'rider/online',                 'rider_online'],
    ['GET',  'rider/jobs',                   'rider_jobs'],
    ['POST', 'rider/jobs/{id}/accept',       'rider_accept'],
    ['POST', 'rider/jobs/{id}/pickup',       'rider_pickup'],
    ['POST', 'rider/jobs/{id}/deliver',      'rider_deliver'],
    // admin / staff
    ['GET',  'admin/dashboard',              'admin_dashboard'],
    ['GET',  'admin/feed',                   'admin_feed'],
    ['GET',  'admin/orders',                 'admin_orders'],
    ['GET',  'admin/orders/{id}',            'admin_order_get'],
    ['POST', 'admin/orders/{id}/status',     'admin_order_status'],
    ['POST', 'admin/orders/{id}/note',       'admin_order_note'],
    ['GET',  'admin/catalog',                'admin_catalog'],
    ['POST', 'admin/categories',             'admin_category_save'],
    ['POST', 'admin/categories/{id}/delete', 'admin_category_delete'],
    ['POST', 'admin/products',               'admin_product_save'],
    ['POST', 'admin/products/{id}/delete',   'admin_product_delete'],
    ['POST', 'admin/products/{id}/toggle',   'admin_product_toggle'],
    ['POST', 'admin/addon-groups',           'admin_addon_group_save'],
    ['POST', 'admin/addon-groups/{id}/delete','admin_addon_group_delete'],
    ['GET',  'admin/discounts',              'admin_discounts'],
    ['POST', 'admin/discounts',              'admin_discount_save'],
    ['POST', 'admin/discounts/{id}/delete',  'admin_discount_delete'],
    ['POST', 'admin/upload',                 'admin_upload'],
    ['GET',  'admin/customers',              'admin_customers'],
    ['POST', 'admin/customers/{id}',         'admin_customer_update'],
    ['GET',  'admin/riders',                 'admin_riders'],
    ['POST', 'admin/riders/{id}',            'admin_rider_update'],
    ['POST', 'admin/rider-companies',        'admin_rider_company_save'],
    ['GET',  'admin/staff',                  'admin_staff'],
    ['POST', 'admin/staff',                  'admin_staff_save'],
    ['GET',  'admin/settings',               'admin_settings'],
    ['POST', 'admin/settings',               'admin_settings_save'],
    ['POST', 'admin/loyverse/sync',          'admin_loyverse_sync'],
];

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = $_GET['r'] ?? '';
    if ($path === '') {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = preg_replace('#^.*?/api/?#', '', $uri);
    }
    $path = trim($path, '/');
    if ($path === 'index.php') $path = '';
    check_csrf();
    foreach ($routes as [$m, $pattern, $handler]) {
        if ($m !== $method) continue;
        $re = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        if (preg_match($re, $path, $mm)) {
            $params = array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY);
            $result = $handler($params);
            json_out($result ?? ['ok' => true]);
        }
    }
    fail(404, 'Not found');
} catch (HttpError $e) {
    json_out(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('[cafresco] ' . $e);
    json_out(['error' => config('debug') ? $e->getMessage() : 'Something went wrong. Please try again.'], 500);
}
