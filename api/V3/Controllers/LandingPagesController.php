<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;

class LandingPagesController extends Controller
{
    protected function tableName(): string { return '202_landing_pages'; }
    protected function primaryKey(): string { return 'landing_page_id'; }
    protected function deletedColumn(): ?string { return 'landing_page_deleted'; }

    protected function fields(): array
    {
        return [
            'landing_page_url'      => ['type' => 's', 'required' => true, 'max_length' => 2048],
            'aff_campaign_id'       => ['type' => 'i', 'required' => true],
            'landing_page_nickname' => ['type' => 's', 'max_length' => 255],
            'leave_behind_page_url' => ['type' => 's', 'max_length' => 2048],
            'landing_page_type'     => ['type' => 'i'],
        ];
    }
}
