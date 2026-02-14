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
            'text_ad_name'        => ['type' => 's', 'required' => true],
            'text_ad_headline'    => ['type' => 's', 'required' => false],
            'text_ad_description' => ['type' => 's', 'required' => false],
            'text_ad_display_url' => ['type' => 's', 'required' => false],
            'aff_campaign_id'     => ['type' => 'i', 'required' => false],
            'landing_page_id'     => ['type' => 'i', 'required' => false],
            'text_ad_type'        => ['type' => 'i', 'required' => false],
        ];
    }
}
