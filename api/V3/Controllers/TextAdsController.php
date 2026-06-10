<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;

class TextAdsController extends Controller
{
    protected function tableName(): string { return '202_text_ads'; }
    protected function primaryKey(): string { return 'text_ad_id'; }
    protected function deletedColumn(): ?string { return 'text_ad_deleted'; }

    protected function fields(): array
    {
        return [
            'text_ad_name'        => ['type' => 's', 'required' => true, 'max_length' => 100],
            'text_ad_headline'    => ['type' => 's', 'required' => true, 'max_length' => 100],
            'text_ad_description' => ['type' => 's', 'required' => true, 'max_length' => 100],
            'text_ad_display_url' => ['type' => 's', 'required' => true, 'max_length' => 100],
            'aff_campaign_id'     => ['type' => 'i', 'default' => 0],
            'landing_page_id'     => ['type' => 'i', 'default' => 0],
            'text_ad_type'        => ['type' => 'i', 'default' => 0],
        ];
    }

    #[\Override]
    protected function beforeCreate(array $payload): array
    {
        return [
            'text_ad_time' => ['type' => 'i', 'value' => time()],
        ];
    }
}
