<?php

declare(strict_types=1);

namespace Tests\OfflineConversion;

use PHPUnit\Framework\TestCase;
use Prosper202\OfflineConversion\LegacyGoogleFeed;

/**
 * Paid 1.9.56's scheduled Google Ads feed answers 410 Gone naming where its
 * replacement is made, whatever credentials Google Ads sends.
 */
final class LegacyGoogleFeedTest extends TestCase
{
    private const SCRIPT = 'tracking202/static/feeds/google/offlineconversions/index.php';

    public function testTheSetupPathFollowsTheInstallsDirectory(): void
    {
        $setup = 'tracking202/setup/offline_conversions.php';
        self::assertSame('/' . $setup, LegacyGoogleFeed::setupPath('/' . self::SCRIPT));
        self::assertSame('/p202/' . $setup, LegacyGoogleFeed::setupPath('/p202/' . self::SCRIPT));
        self::assertSame('/' . $setup, LegacyGoogleFeed::setupPath('/elsewhere/index.php'));
        self::assertSame('/' . $setup, LegacyGoogleFeed::setupPath(''));
    }

    public function testTheBodySaysWhereTheNewFetchUrlIsMade(): void
    {
        $body = LegacyGoogleFeed::body('/p202/tracking202/setup/offline_conversions.php');
        self::assertStringContainsString('retired', $body);
        self::assertStringContainsString(
            'Setup > Offline Conversions (/p202/tracking202/setup/offline_conversions.php)',
            $body
        );
        self::assertStringContainsString('Uploads > Schedules', $body);
        // Not a CSV Google Ads could import as an empty file.
        self::assertStringNotContainsString('Google Click ID', $body);
        self::assertSame(410, LegacyGoogleFeed::STATUS);
    }

    public function testTheServedScriptRefusesWithNoDatabaseOrCredentialCheck(): void
    {
        $root = dirname(__DIR__, 2);
        $script = (string) file_get_contents($root . '/' . self::SCRIPT);
        self::assertStringContainsString('LegacyGoogleFeed::refuse(', $script);
        self::assertStringNotContainsString('PHP_AUTH', $script);
        // Only the autoloader: no database, so the 410 stands when the database is down.
        self::assertSame(1, preg_match_all('~\b(?:require|include)(?:_once)?\b~', $script));
        self::assertStringContainsString("/vendor/autoload.php'", $script);
    }
}
