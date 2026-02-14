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
            'landing_page_url'        => ['type' => 's', 'required' => true],
            'aff_campaign_id'         => ['type' => 'i', 'required' => true],
            'landing_page_nickname'   => ['type' => 's', 'required' => false],
            'leave_behind_page_url'   => ['type' => 's', 'required' => false],
            'landing_page_type'       => ['type' => 'i', 'required' => false],
        ];
    }
}
