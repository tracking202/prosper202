<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');

AUTH::require_user();

/**
 * JSON type-ahead search behind the merge picker modal (ltv_merge_modal.php).
 * Read-only, session-authed. Returns up to 8 candidates for the requested
 * entity, shaped uniformly for the picker: {id, label, sub, meta}.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

$userId = (int) $_SESSION['user_id'];
$entity = (string) ($_POST['entity'] ?? 'customer');
$q = trim((string) ($_POST['q'] ?? ''));
$exclude = (int) ($_POST['exclude'] ?? 0);

$results = [];

try {
    if ($q !== '') {
        $conn = new \Prosper202\Database\Connection($db);

        if ($entity === 'company') {
            $companies = new \Prosper202\Ltv\MysqlCompanyRepository($conn);
            foreach ($companies->search($userId, $q, $exclude, 8) as $row) {
                $results[] = [
                    'id' => (int) $row['company_id'],
                    'label' => (string) $row['name'],
                    'sub' => trim(implode(' · ', array_filter([
                        (string) ($row['domain'] ?? ''),
                        '#' . (int) $row['company_id'],
                    ]))),
                    'meta' => number_format((int) ($row['contacts'] ?? 0)) . ' contact' . (((int) ($row['contacts'] ?? 0)) === 1 ? '' : 's'),
                ];
            }
        } else {
            // Reuses the customer list's tested search (LIKE-escaped, scoped,
            // merged records already excluded by the query scope).
            $ltv = new \Prosper202\Ltv\MysqlLtvRepository($conn);
            $found = $ltv->customers(new \Prosper202\Ltv\LtvQuery($userId), 'total_revenue', 'DESC', 12, 0, $q);
            foreach ($found['rows'] as $row) {
                $id = (int) $row['customer_id'];
                if ($id === $exclude || (string) ($row['status'] ?? '') === 'anonymized') {
                    continue;
                }
                $name = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
                $results[] = [
                    'id' => $id,
                    'label' => $name !== '' ? $name : (string) ($row['primary_ref'] ?? ('#' . $id)),
                    'sub' => trim(implode(' · ', array_filter([
                        (string) ($row['email'] ?? ''),
                        (string) ($row['company'] ?? ''),
                        '#' . $id,
                    ]))),
                    'meta' => '$' . number_format((float) ($row['total_revenue'] ?? 0), 2)
                        . ' · ' . (int) ($row['order_count'] ?? 0) . ' order' . (((int) ($row['order_count'] ?? 0)) === 1 ? '' : 's'),
                ];
                if (count($results) >= 8) {
                    break;
                }
            }
        }
    }
} catch (\Throwable $e) {
    error_log('ltv_merge_search: ' . $e->getMessage());
    $results = []; // the picker renders "no matches" — never a broken modal
}

echo json_encode(['results' => $results]);
