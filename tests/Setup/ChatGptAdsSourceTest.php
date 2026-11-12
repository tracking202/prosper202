<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Setup\ChatGptAdsSource;

/**
 * The pair Get Links and GET /trackers/{id}/url hand out for a tracker on the
 * ChatGPT Ads source (ChatGptAdsSource::links()), and what makes a source
 * that one.
 */
final class ChatGptAdsSourceTest extends TestCase
{
    private const BASE = 'https://track.example.com/';
    private const TEMPLATE = 'utm_source=chatgpt&utm_medium=paid&utm_campaign={campaign_id}&utm_content={ad_id}'
        . '&campaign_id={campaign_id}&ad_id={ad_id}&oppref={oppref}';

    /** What TrackingLinkVariables::query() writes for the source's two variables and no token. */
    private const SOURCE_VARIABLES = '&campaign_id={campaign_id}&ad_id={ad_id}&t202kw=';

    public function testTheTemplateIsTheOneAdsManagerTakes(): void
    {
        self::assertSame(self::TEMPLATE, ChatGptAdsSource::TEMPLATE);
        self::assertSame(
            ['campaign_id' => '{campaign_id}', 'ad_id' => '{ad_id}'],
            array_column(ChatGptAdsSource::VARIABLES, 'placeholder', 'parameter')
        );
    }

    public function testALandingPageIsTheDestinationAndTheTemplateCarriesTheTracker(): void
    {
        $links = ChatGptAdsSource::links(self::BASE, '8837291', '', self::SOURCE_VARIABLES, ' https://shop.example.com/sale?ref=1 ', false);

        self::assertSame('landing_page', $links['destination']);
        self::assertSame('https://shop.example.com/sale?ref=1', $links['destination_url']);
        self::assertSame(self::TEMPLATE . '&t202id=8837291&t202kw=', $links['tracking_template']);
    }

    public function testADirectLinkIsTheDestinationWithNoMacroAndTheTemplateHasNoTracker(): void
    {
        $links = ChatGptAdsSource::links(self::BASE, '8837291', '', self::SOURCE_VARIABLES, '', false);

        self::assertSame('tracking_link', $links['destination']);
        self::assertSame('https://track.example.com/tracking202/redirect/dl.php?t202id=8837291', $links['destination_url']);
        self::assertSame(self::TEMPLATE, $links['tracking_template']);
    }

    public function testARedirectorGoesThroughRtr(): void
    {
        $links = ChatGptAdsSource::links('https://track.example.com/p202', '5', '', self::SOURCE_VARIABLES, '', true);

        self::assertSame('https://track.example.com/p202/tracking202/redirect/rtr.php?t202id=5', $links['destination_url']);
    }

    public function testAPageWithNoHostFallsBackToTheTrackingLink(): void
    {
        $links = ChatGptAdsSource::links(self::BASE, '5', '', self::SOURCE_VARIABLES, '/no-host', false);

        self::assertSame('tracking_link', $links['destination']);
        self::assertStringNotContainsString('t202id', $links['tracking_template']);
    }

    public function testThePublisherTravelsWithTheTracker(): void
    {
        $page = ChatGptAdsSource::links(self::BASE, '5', '&t202pubid=77', self::SOURCE_VARIABLES, 'https://shop.example.com/', false);
        $link = ChatGptAdsSource::links(self::BASE, '5', '&t202pubid=77', self::SOURCE_VARIABLES, '', false);

        self::assertSame(self::TEMPLATE . '&t202id=5&t202pubid=77&t202kw=', $page['tracking_template']);
        self::assertSame(self::BASE . 'tracking202/redirect/dl.php?t202id=5&t202pubid=77', $link['destination_url']);
        self::assertSame(self::TEMPLATE, $link['tracking_template']);
    }

    /** The link's other variables and tokens are kept; what the template sets is not written twice. */
    public function testTheLinksOwnVariablesFollowWithoutRepeatingTheTemplate(): void
    {
        $variables = '&campaign_id={campaign_id}&ad_id={ad_id}&placement=chat&c1=spring&utm_source=other&t202kw=shoes';
        $links = ChatGptAdsSource::links(self::BASE, '5', '', $variables, '', false);

        self::assertSame(self::TEMPLATE . '&placement=chat&c1=spring&t202kw=shoes', $links['tracking_template']);
        parse_str($links['tracking_template'], $query);
        self::assertSame('chatgpt', $query['utm_source']);
    }

    /** A placeholder the user changed on Traffic Sources reaches the template; OpenAI's macros stay on the utm values. */
    public function testTheSourcesOwnPlaceholdersGiveTheirParameters(): void
    {
        $links = ChatGptAdsSource::links(self::BASE, '5', '', '&campaign_id={campaignid}&ad_id={ad_id}&t202kw=', '', false);

        self::assertSame(
            'utm_source=chatgpt&utm_medium=paid&utm_campaign={campaign_id}&utm_content={ad_id}&campaign_id={campaignid}&ad_id={ad_id}&oppref={oppref}',
            $links['tracking_template']
        );
        $none = ChatGptAdsSource::links(self::BASE, '5', '', '&t202kw=', '', false);
        self::assertSame(self::TEMPLATE, $none['tracking_template'], 'a source without the variables keeps the macros');
    }

    public function testASourceIsTheChatGptOneOnlyByItsStoredPlatform(): void
    {
        self::assertTrue(ChatGptAdsSource::isSourceRow(['ppc_network_name' => 'Anything', 'ppc_network_platform' => 'chatgpt_ads']));
        self::assertFalse(ChatGptAdsSource::isSourceRow(['ppc_network_name' => 'ChatGPT Ads', 'ppc_network_platform' => '']));
        self::assertFalse(ChatGptAdsSource::isSourceRow(['ppc_network_name' => 'ChatGPT Ads']));
        self::assertFalse(ChatGptAdsSource::isSourceRow(['ppc_network_platform' => 'CHATGPT_ADS']));
    }
}
