<?php

declare(strict_types=1);

namespace Tests\Click;

use Api\V3\Controllers\TrackersController;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\TrackingBaseUrl;
use Prosper202\Click\TrackingLinkVariables;

/**
 * The parts of a tracking link the API now builds the way Get Links does:
 * the base (domain, scheme, install path) and the variable query.
 */
final class TrackingLinkPartsTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function testAnEmptyDomainFallsBackToThisServerWithItsPort(): void
    {
        $server = ['SERVER_NAME' => 'p202.local', 'SERVER_PORT' => '8098', 'DOCUMENT_ROOT' => self::root()];
        self::assertSame('http://p202.local:8098/', TrackingBaseUrl::build('', $server, self::root()));
        $server['SERVER_PORT'] = '80';
        self::assertSame('http://p202.local/', TrackingBaseUrl::build('', $server, self::root()));
    }

    public function testAHostOnlyDomainGetsTheRequestsScheme(): void
    {
        $server = ['SERVER_NAME' => 'internal', 'SERVER_PORT' => '443', 'HTTPS' => 'on', 'DOCUMENT_ROOT' => self::root()];
        self::assertSame('https://track.example.com/', TrackingBaseUrl::build('track.example.com', $server, self::root()));
        unset($server['HTTPS']);
        $server['SERVER_PORT'] = '80';
        self::assertSame('http://track.example.com/', TrackingBaseUrl::build('track.example.com', $server, self::root()));
    }

    public function testADomainStoredWithASchemeKeepsIt(): void
    {
        $server = ['SERVER_NAME' => 'internal', 'HTTPS' => 'on', 'DOCUMENT_ROOT' => self::root()];
        self::assertSame('http://127.0.0.1:8098/', TrackingBaseUrl::build('http://127.0.0.1:8098/', $server, self::root()));
    }

    public function testAnInstallInASubdirectoryKeepsTheDirectory(): void
    {
        $server = ['SERVER_NAME' => 'x', 'DOCUMENT_ROOT' => dirname(self::root())];
        self::assertSame('/' . basename(self::root()) . '/', TrackingBaseUrl::installPath($server, self::root()));
        self::assertSame('/', TrackingBaseUrl::installPath(['DOCUMENT_ROOT' => '/nonexistent'], self::root()), 'unknown: the root');
    }

    public function testVariablesFollowGetLinksRules(): void
    {
        self::assertSame('&t202kw=', TrackingLinkVariables::query([], []), 't202kw is always written');
        $custom = [
            ['parameter' => 'adid', 'placeholder' => '{ad_id}'],
            ['parameter' => 't202kw', 'placeholder' => '{keyword}'],
            ['parameter' => 'c2', 'placeholder' => '{placement}'],
        ];
        self::assertSame('&adid={ad_id}&c2={placement}&t202kw={keyword}', TrackingLinkVariables::query($custom, []));
        self::assertSame('&adid={ad_id}&c1=fb&c2={placement}&t202kw=kw', TrackingLinkVariables::query($custom, ['c1' => 'fb', 't202kw' => 'kw']));
        self::assertNull(TrackingLinkVariables::problem('{keyword}'));
        foreach (['a&b', 'a#b', 'a?b', 'a b', "a\nb"] as $bad) {
            self::assertNotNull(TrackingLinkVariables::problem($bad), $bad);
        }
    }

    public function testVariablesGoBeforeALandingPagesFragment(): void
    {
        $url = TrackersController::buildDirectUrl('https://trk.example.com/', 555, [
            'landing_page_id' => 1, 'landing_page_url' => 'https://lp.example.com/a?x=1#top',
        ], '&t202kw=kw');
        self::assertSame('https://lp.example.com/a?x=1&t202id=555&t202kw=kw#top', $url);
        self::assertSame(
            'https://trk.example.com/sub/tracking202/redirect/dl.php?t202id=555&t202kw=',
            TrackersController::buildDirectUrl('https://trk.example.com/sub/', 555, ['landing_page_id' => 0], '&t202kw=')
        );
    }
}
