<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\AdPlatforms\OpenAiAds\TrackingTemplate;

/**
 * Setup › Destinations › ChatGPT Ads › campaigns (p202_ad_campaigns_view()):
 * push template is offered to every campaign whose template is not the
 * generic one, including one holding the generic template from before it
 * left oppref to OpenAI.
 */
final class AdCampaignsPanelTest extends TestCase
{
    private const OLD_GENERIC = 'utm_source=chatgpt&utm_medium=paid&utm_campaign={campaign_id}&utm_content={ad_id}'
        . '&campaign_id={campaign_id}&ad_id={ad_id}&oppref={oppref}';

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-ui.php';
        require_once dirname(__DIR__, 2) . '/tracking202/setup/_includes/setup_ui.php';
        require_once dirname(__DIR__, 2) . '/tracking202/setup/_includes/ad_campaigns_panel.php';
    }

    private static function row(string $html, string $id): string
    {
        self::assertSame(1, preg_match('#<tr id="c-' . $id . '">.*?</tr>#s', $html, $m), $html);

        return $m[0];
    }

    public function testTheOldGenericTemplateIsOfferedTheNewOne(): void
    {
        $campaign = static fn (string $id, ?string $template): array => [
            'id' => $id, 'name' => $id, 'status' => 'paused', 'serving_issues' => [], 'budget' => [],
            'query_string_template' => $template, 'conversion_event_setting_ids' => [],
        ];
        $html = p202_ad_campaigns_view(
            ['connection_id' => 3, 'account' => ['id' => 'acct_1', 'name' => 'Shop', 'currency' => 'USD', 'timezone' => 'UTC']],
            [$campaign('cmpn_1', self::OLD_GENERIC), $campaign('cmpn_2', TrackingTemplate::default()), $campaign('cmpn_3', null)],
            null,
            null,
            null,
            null,
            null,
            '/tracking202/setup/destinations.php',
            'tok'
        );

        self::assertNotSame(self::OLD_GENERIC, TrackingTemplate::default());
        $old = self::row($html, 'cmpn_1');
        self::assertStringContainsString('its own', $old);
        self::assertStringContainsString('push template', $old);
        $current = self::row($html, 'cmpn_2');
        self::assertStringContainsString('the generic one', $current);
        self::assertStringNotContainsString('push template', $current);
        self::assertStringContainsString('push template', self::row($html, 'cmpn_3'));
    }
}
