<?php

declare(strict_types=1);

namespace Tests\OfflineConversion;

use PHPUnit\Framework\TestCase;
use Prosper202\OfflineConversion\LegacyGoogleFeed;
use Prosper202\OfflineConversion\LegacyGoogleFeedFetches;

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

    public function testTheServedScriptAnswersBeforeItTouchesTheDatabaseAndChecksNoCredential(): void
    {
        $root = dirname(__DIR__, 2);
        $script = (string) file_get_contents($root . '/' . self::SCRIPT);
        self::assertStringNotContainsString('PHP_AUTH', $script);
        $refuse = strpos($script, 'LegacyGoogleFeed::refuse(');
        $config = strpos($script, "/202-config.php';");
        $note = strpos($script, 'LegacyGoogleFeed::note(');
        self::assertIsInt($refuse);
        self::assertIsInt($config);
        self::assertIsInt($note);
        // The 410 is sent with only the autoloader loaded, so it stands when the database is down;
        // the configuration (the database) comes after it, and only for a fetch that names a schedule.
        self::assertSame(1, preg_match_all('~\b(?:require|include)(?:_once)?\b~', substr($script, 0, $refuse)));
        self::assertLessThan($config, $refuse);
        self::assertLessThan($note, $config);
        self::assertStringContainsString('LegacyGoogleFeedFetches::schedule($_GET) !== null', $script);
    }

    public function testAScheduleIsReadFromTheOldAddressStrictly(): void
    {
        self::assertSame(
            ['campaign_id' => 7, 'conversion_name' => 'Purchase'],
            LegacyGoogleFeedFetches::schedule(['cn' => 'Purchase', 'ac' => '7'])
        );
        self::assertSame(
            ['campaign_id' => 0, 'conversion_name' => 'Lead, "A"'],
            LegacyGoogleFeedFetches::schedule(['cn' => 'Lead, "A"', 'ac' => '--', 'other' => 'x'])
        );
        $unreadable = [
            [], ['cn' => 'Purchase'], ['ac' => '7'], ['cn' => '', 'ac' => '7'], ['cn' => ['Purchase'], 'ac' => '7'],
            ['cn' => 'Purchase', 'ac' => ['7']], ['cn' => 'Purchase', 'ac' => '0'], ['cn' => 'Purchase', 'ac' => '07'],
            ['cn' => 'Purchase', 'ac' => '12abc'], ['cn' => 'Purchase', 'ac' => '-1'], ['cn' => 'Purchase', 'ac' => '1.5'],
            ['cn' => 'Purchase', 'ac' => '1234567890'], ['cn' => 'Purchase', 'ac' => ''], ['cn' => 'Purchase', 'ac' => '---'],
            ['cn' => '=SUM(A1)', 'ac' => '7'], ['cn' => " Purchase", 'ac' => '7'], ['cn' => "Pur\nchase", 'ac' => '7'],
            ['cn' => str_repeat('a', 101), 'ac' => '7'],
        ];
        foreach ($unreadable as $query) {
            self::assertNull(LegacyGoogleFeedFetches::schedule($query), json_encode($query) ?: '');
        }
    }

    public function testAnUnreadableFetchIsNotNotedAndAsksNoDatabase(): void
    {
        // No schedule: nothing is asked of the connection, so none is needed.
        LegacyGoogleFeed::note(null, ['cn' => 'Purchase', 'ac' => 'abc'], 1_800_000_000);
        $this->addToAssertionCount(1);
    }
}
