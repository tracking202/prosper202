<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\ValidationException;

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
            'aff_campaign_id'     => ['type' => 'i', 'default' => 0, 'range' => self::MEDIUMINT_UNSIGNED],
            'landing_page_id'     => ['type' => 'i', 'default' => 0, 'range' => self::MEDIUMINT_UNSIGNED],
            'text_ad_type'        => ['type' => 'i', 'default' => 0, 'range' => self::TINYINT],
        ];
    }

    /**
     * An advanced landing page's ad (text_ad_type 1) names an advanced page,
     * as Setup › Text Ads offers only those: Get Links offers an ad on the
     * advanced page it names, so one naming a simple page was stored with a
     * 201 and never offered. 0 is the CLI's to refuse, as before. An ad
     * stored before this was checked is not refused for being re-sent as it
     * is.
     */
    #[\Override]
    protected function assertLinksOwned(array $clean, ?array $current = null): void
    {
        parent::assertLinksOwned($clean, $current);

        $type = (int) (array_key_exists('text_ad_type', $clean) ? $clean['text_ad_type'] : ($current['text_ad_type'] ?? 0));
        $page = (int) (array_key_exists('landing_page_id', $clean) ? $clean['landing_page_id'] : ($current['landing_page_id'] ?? 0));
        if ($type !== 1 || $page === 0) {
            return;
        }
        if ($current !== null && (int) ($current['text_ad_type'] ?? 0) === 1 && (int) ($current['landing_page_id'] ?? 0) === $page) {
            return;
        }

        $stmt = $this->prepare('SELECT landing_page_type FROM 202_landing_pages WHERE landing_page_id = ? AND user_id = ? AND COALESCE(landing_page_deleted, 0) = 0 LIMIT 1');
        $this->bind($stmt, 'ii', $page, $this->userId);
        $this->execute($stmt, 'Landing page lookup failed');
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new DatabaseException('Landing page lookup failed');
        }
        $row = $result->fetch_row();
        $stmt->close();

        if ($row === null) {
            throw new ValidationException('Validation failed', [
                'landing_page_id' => "landing page $page is not one of yours, or it was removed (GET /landing-pages lists them)",
            ]);
        }
        if ((int) $row[0] !== 1) {
            throw new ValidationException('Validation failed', [
                'landing_page_id' => "landing page $page is a simple landing page: an ad with text_ad_type 1 is offered on an advanced landing page's links, so it names a page with landing_page_type 1 (GET /landing-pages lists them). A campaign's ad is text_ad_type 0 with aff_campaign_id",
            ]);
        }
    }

    #[\Override]
    protected function beforeCreate(array $payload): array
    {
        return [
            'text_ad_time' => ['type' => 'i', 'value' => time()],
        ];
    }
}
