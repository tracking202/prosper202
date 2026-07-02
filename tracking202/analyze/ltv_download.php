<?php

declare(strict_types=1);
$rootPath = dirname(__DIR__, 2);
include_once $rootPath . '/202-config/connect.php';

AUTH::require_user();
AUTH::set_timezone($_SESSION['user_timezone']);

//grab user time range preference
$time = grab_timeframe();
$userId = (int) $_SESSION['user_id'];

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="customer_ltv.xls"');
header('Pragma: no-cache');
header('Expires: 0');

$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

try {
    $conn = new \Prosper202\Database\Connection($db);
    $ltv = new \Prosper202\Ltv\MysqlLtvRepository($conn);
    $query = new \Prosper202\Ltv\LtvQuery($userId, (int) $time['from'], (int) $time['to']);
    $customers = $ltv->customers($query, 'total_revenue', 'DESC', 10000, 0);
} catch (\Throwable $e) {
    error_log('ltv_download: ' . $e->getMessage());
    die('Customer LTV data is unavailable. Run the database upgrade to install the LTV tables.');
}

echo '<table border="1"><tr>'
    . '<th>Customer Ref</th><th>First Name</th><th>Last Name</th><th>Email</th><th>Company</th>'
    . '<th>Country</th><th>First Seen</th><th>Last Activity</th>'
    . '<th>Orders</th><th>Revenue</th><th>Refunded</th><th>Active Subscriptions</th><th>MRR</th>'
    . '</tr>';

foreach ($customers['rows'] as $c) {
    echo '<tr>'
        . '<td>' . $esc($c['primary_ref'] ?? '') . '</td>'
        . '<td>' . $esc($c['first_name'] ?? '') . '</td>'
        . '<td>' . $esc($c['last_name'] ?? '') . '</td>'
        . '<td>' . $esc($c['email'] ?? '') . '</td>'
        . '<td>' . $esc($c['company'] ?? '') . '</td>'
        . '<td>' . $esc($c['country'] ?? '') . '</td>'
        . '<td>' . date('Y-m-d', (int) ($c['first_seen_time'] ?? 0)) . '</td>'
        . '<td>' . date('Y-m-d', (int) ($c['last_activity_time'] ?? 0)) . '</td>'
        . '<td>' . (int) ($c['order_count'] ?? 0) . '</td>'
        . '<td>' . number_format((float) ($c['total_revenue'] ?? 0), 2, '.', '') . '</td>'
        . '<td>' . number_format((float) ($c['refunded_amount'] ?? 0), 2, '.', '') . '</td>'
        . '<td>' . (int) ($c['active_subscription_count'] ?? 0) . '</td>'
        . '<td>' . number_format((float) ($c['mrr'] ?? 0), 2, '.', '') . '</td>'
        . '</tr>';
}

echo '</table>';
