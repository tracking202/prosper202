<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;

class CampaignsController extends Controller
{
    protected function tableName(): string { return '202_aff_campaigns'; }
    protected function primaryKey(): string { return 'aff_campaign_id'; }
    protected function deletedColumn(): ?string { return 'aff_campaign_deleted'; }

    protected function fields(): array
    {
        return [
            'aff_campaign_name'            => ['type' => 's', 'required' => true, 'max_length' => 255],
            'aff_campaign_url'             => ['type' => 's', 'required' => true, 'max_length' => 2048],
            'aff_campaign_url_2'           => ['type' => 's', 'max_length' => 2048],
            'aff_campaign_url_3'           => ['type' => 's', 'max_length' => 2048],
            'aff_campaign_url_4'           => ['type' => 's', 'max_length' => 2048],
            'aff_campaign_url_5'           => ['type' => 's', 'max_length' => 2048],
            'aff_campaign_payout'          => ['type' => 'd'],
            'aff_campaign_currency'        => ['type' => 's', 'max_length' => 5],
            'aff_campaign_foreign_payout'  => ['type' => 'd'],
            'aff_network_id'               => ['type' => 'i'],
            'aff_campaign_cloaking'        => ['type' => 'i'],
            'aff_campaign_rotate'          => ['type' => 'i'],
        ];
    }

    protected function beforeCreate(array $payload): array
    {
        return [
            'aff_campaign_time'      => ['type' => 'i', 'value' => time()],
            'aff_campaign_id_public' => ['type' => 'i', 'value' => random_int(1_000_000, 99_999_999)],
        ];
    }
}
