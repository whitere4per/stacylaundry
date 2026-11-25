<?php
require_once __DIR__ . '/../app/helpers.php';
ensureLogin();
$page = $_GET['page'] ?? 'dashboard';
$allowed = ['dashboard','customers','orders','payments','notifications','reports'];
if (!in_array($page, $allowed, true)) {
    http_response_code(404);
    renderLayout('Page not found', '', '<div class="empty-state"><h3>This page was not found.</h3><p>Choose a section from the navigation menu to continue.</p><a class="btn" href="index.php">Go to dashboard</a></div>');
    exit;
}
ob_start();
require __DIR__ . '/../pages/' . $page . '.php';
$content = ob_get_clean();
$titles = [
    'dashboard' => 'Administrative Dashboard',
    'customers' => 'Customer Profiles',
    'orders' => 'Order Management',
    'payments' => 'Payments',
    'notifications' => 'Order Tracking & Notifications',
    'reports' => 'Reports',
];
renderLayout($titles[$page], $page, $content);
