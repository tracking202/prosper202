<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;

class TrackersController extends Controller
{
    protected function tableName(): string { return '202_trackers'; }
    protected function primaryKey(): string { return 'tracker_id'; }

    protected function fields(): array
    {
        return [
            'aff_campaign_id'  => ['type' => 'i', 'required' => true],
            'ppc_account_id'   => ['type' => 'i', 'required' => false],
            'text_ad_id'       => ['type' => 'i', 'required' => false],
            'landing_page_id'  => ['type' => 'i', 'required' => false],
            'rotator_id'       => ['type' => 'i', 'required' => false],
            'click_cpc'        => ['type' => 'd', 'required' => false],
            'click_cpa'        => ['type' => 'd', 'required' => false],
            'click_cloaking'   => ['type' => 'i', 'required' => false],
            'tracker_id_public'=> ['type' => 'i', 'readonly' => true],
        ];
    }

    public function create(array $payload): array
    {
        // Generate public ID and timestamp
        $publicId = random_int(10000000, 999999999);
        $now = time();

        $fields = $this->fields();
        $columns = ['tracker_id_public', 'tracker_time'];
        $placeholders = ['?', '?'];
        $binds = [$publicId, $now];
        $types = 'ii';

        foreach ($fields as $col => $def) {
            if (($def['required'] ?? false) && !isset($payload[$col])) {
                throw new \RuntimeException("Missing required field: $col", 422);
            }
        }

        foreach ($fields as $col => $def) {
            if ($def['readonly'] ?? false) {
                continue;
            }
            if (isset($payload[$col])) {
                $columns[] = $col;
                $placeholders[] = '?';
                $binds[] = $payload[$col];
                $types .= $def['type'];
            }
        }

        $columns[] = 'user_id';
        $placeholders[] = '?';
        $binds[] = $this->userId;
        $types .= 'i';

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->tableName(),
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$binds);

        if (!$stmt->execute()) {
            throw new \RuntimeException('Insert failed', 500);
        }

        $id = $stmt->insert_id;
        $stmt->close();

        return $this->get($id);
    }

    public function getTrackingUrl(int $id): array
    {
        $tracker = $this->get($id);
        $row = $tracker['data'];

        $baseUrl = $this->getBaseUrl();
        $publicId = $row['tracker_id_public'];

        return [
            'data' => [
                'tracker_id' => $id,
                'tracker_id_public' => $publicId,
                'direct_url' => $baseUrl . '/tracking202/redirect/go.php?t202id=' . $publicId,
                'tracking_params' => '?t202id=' . $publicId . '&t202kw={keyword}&c1={c1}&c2={c2}&c3={c3}&c4={c4}',
            ],
        ];
    }

    private function getBaseUrl(): string
    {
        $stmt = $this->db->prepare('SELECT user_tracking_domain FROM 202_users_pref WHERE user_id = ? LIMIT 1');
        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return rtrim($row['user_tracking_domain'] ?? '', '/');
    }
}
