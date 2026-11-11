<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\CampaignUrls;

final class CampaignUrlsTest extends TestCase
{
    private const URLS = [
        'aff_campaign_url' => 'https://one.example/',
        'aff_campaign_url_2' => 'https://two.example/',
        'aff_campaign_url_3' => '',
        'aff_campaign_url_4' => null,
        'aff_campaign_url_5' => 'https://five.example/',
    ];

    public function testACampaignThatDoesNotRotateUsesItsFirstUrlAlone(): void
    {
        $off = ['aff_campaign_rotate' => '0'] + self::URLS;
        self::assertSame(['https://one.example/'], CampaignUrls::inRotation($off));
        self::assertSame(['https://one.example/'], CampaignUrls::inRotation(self::URLS));
    }

    public function testARotatingCampaignUsesEachUrlItHasSet(): void
    {
        self::assertSame(
            ['https://one.example/', 'https://two.example/', 'https://five.example/'],
            CampaignUrls::inRotation(['aff_campaign_rotate' => '1'] + self::URLS)
        );
    }

    /** One list for the redirect and for the places a link's own destination may take. */
    public function testTheRedirectTakesItsUrlsFromTheSameList(): void
    {
        $connect2 = (string) file_get_contents(dirname(__DIR__, 2) . '/202-config/connect2.php');
        $start = strpos($connect2, "\nfunction rotateTrackerUrl(");
        self::assertIsInt($start);
        $body = substr($connect2, $start, (int) strpos($connect2, "\n}\n", $start) - $start);
        self::assertStringContainsString('CampaignUrls::inRotation($tracker_row)', $body);
        self::assertStringNotContainsString('aff_campaign_url_', $body);
    }
}
