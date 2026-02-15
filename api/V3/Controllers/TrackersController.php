<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;
use Api\V3\Exception\NotFoundException;

class TrackersController extends Controller
{
    protected function tableName(): string { return '202_trackers'; }
    protected function primaryKey(): string { return 'tracker_id'; }

    protected function fields(): array
    {
        return [
            'aff_campaign_id'   => ['type' => 'i', 'required' => true],
            'ppc_account_id'    => ['type' => 'i'],
            'text_ad_id'        => ['type' => 'i'],
            'landing_page_id'   => ['type' => 'i'],
            'rotator_id'        => ['type' => 'i'],
            'click_cpc'         => ['type' => 'd'],
            'click_cpa'         => ['type' => 'd'],
            'click_cloaking'    => ['type' => 'i'],
            'tracker_id_public' => ['type' => 'i', 'readonly' => true],
        ];
    }

    protected function beforeCreate(array $payload): array
    {
        return [
            'tracker_id_public' => ['type' => 'i', 'value' => random_int(10_000_000, 999_999_999)],
            'tracker_time'      => ['type' => 'i', 'value' => time()],
        ];
    }

    public function getTrackingUrl(int $id): array
    {
        $tracker = $this->get($id);
        $row = $tracker['data'];
        $publicId = $row['tracker_id_public'];
        $baseUrl = $this->getBaseUrl();

        return [
            'data' => [
                'tracker_id'        => $id,
                'tracker_id_public' => $publicId,
                'direct_url'        => $baseUrl . '/tracking202/redirect/go.php?t202id=' . $publicId,
                'tracking_params'   => '?t202id=' . $publicId . '&t202kw={keyword}&c1={c1}&c2={c2}&c3={c3}&c4={c4}',
            ],
        ];
    }

    private function getBaseUrl(): string
    {
        $stmt = $this->prepare('SELECT user_tracking_domain FROM 202_users_pref WHERE user_id = ? LIMIT 1');
        $stmt->bind_param('i', $this->userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \Api\V3\Exception\DatabaseException('Failed to query tracking domain');
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || empty($row['user_tracking_domain'])) {
            throw new NotFoundException('Tracking domain not configured');
        }
        return rtrim($row['user_tracking_domain'], '/');
    }
}
