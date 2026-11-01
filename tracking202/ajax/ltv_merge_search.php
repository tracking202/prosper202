<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');

AUTH::require_user();
AUTH::require_permissions('access_to_campaign_data'); // LTV shows customers' revenue

/**
 * JSON type-ahead search behind the merge picker modal (ltv_merge_modal.php).
 * Read-only, session-authed. Returns up to 8 candidates for the requested
 * entity, shaped uniformly for the picker: {id, label, sub, meta}.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/ltv_helpers.php';

$userId = (int) $_SESSION['user_id'];

// What the dialog sends, read exactly: anything else is answered as refused
// (422, with the sentence the dialog shows), never as "no matches".
try {
    $entity = p202_ltv_posted_choice(
        $_POST['entity'] ?? null,
        ['customer' => 'customer', 'company' => 'company'],
        'customer',
        'The merge dialog searches customers or companies.'
    );
    $q = trim(p202_ltv_posted_text($_POST['q'] ?? null, 'q'));
    $exclude = p202_ltv_posted_id($_POST['exclude'] ?? null, 'Pick the record to merge into from the list.');
} catch (\RuntimeException $refused) {
    http_response_code(422);
    echo json_encode(['results' => [], 'error' => $refused->getMessage()]);
    return;
}

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
