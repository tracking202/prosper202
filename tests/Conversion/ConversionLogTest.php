<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\ConversionLog;
use Prosper202\Conversion\Ledger\ConversionSource;

/**
 * The conversion log page's query reading, row formatting and gates, without
 * a database. ConversionLogIntegrationTest runs the reads.
 */
final class ConversionLogTest extends TestCase
{
    private const ZONE = 'America/New_York';

    /** 2026-03-10 15:00 New York (EDT, the day after the spring change). */
    private const NOW = 1773169200;

    private static function localTime(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone(self::ZONE)))->getTimestamp();
    }

    public function testNoQueryIsTheLast30DaysOfConversionTimeUnfiltered(): void
    {
        $read = ConversionLog::readQuery([], self::ZONE, self::NOW);

        self::assertSame([], $read['errors']);
        self::assertSame(0, $read['page']);
        self::assertSame('last30', $read['form']['range']);
        self::assertSame('conversion', $read['filters']['time_basis']);
        self::assertNull($read['filters']['campaign_id']);
        self::assertNull($read['filters']['click_id']);
        self::assertNull($read['filters']['type']);
        self::assertNull($read['filters']['deleted']);
        // Midnight 30 days before today, to the end of today, in the account's zone.
        self::assertSame(self::localTime('2026-02-08 00:00:00'), $read['filters']['from']);
        self::assertSame(self::localTime('2026-03-10 23:59:59'), $read['filters']['to']);
        self::assertSame('2026-02-08', $read['form']['from']);
        self::assertSame('2026-03-10', $read['form']['to']);
    }

    public function testEveryFilterIsReadWhenValid(): void
    {
        $read = ConversionLog::readQuery([
            'campaign_id' => '12', 'click_id' => ' 9001 ', 'type' => 'postback', 'deleted' => 'yes',
            'time_basis' => 'click', 'range' => 'custom', 'from' => '2026-03-08', 'to' => '2026-03-08', 'offset' => '3',
        ], self::ZONE, self::NOW);

        self::assertSame([], $read['errors']);
        self::assertSame(12, $read['filters']['campaign_id']);
        self::assertSame(9001, $read['filters']['click_id']);
        self::assertSame('postback', $read['filters']['type']);
        self::assertSame('yes', $read['filters']['deleted']);
        self::assertSame('click', $read['filters']['time_basis']);
        self::assertSame(3, $read['page']);
        // The spring-forward day is 23 hours long; the window is that day, not 24h.
        self::assertSame(self::localTime('2026-03-08 00:00:00'), $read['filters']['from']);
        self::assertSame(self::localTime('2026-03-08 23:59:59'), $read['filters']['to']);
        self::assertSame(23 * 3600 - 1, $read['filters']['to'] - $read['filters']['from']);
    }

    public function testAllTimeHasNoBounds(): void
    {
        $read = ConversionLog::readQuery(['range' => 'alltime'], self::ZONE, self::NOW);

        self::assertSame([], $read['errors']);
        self::assertNull($read['filters']['from']);
        self::assertNull($read['filters']['to']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function unreadable(): iterable
    {
        yield 'campaign zero' => [['campaign_id' => '0'], 'campaign_id'];
        yield 'campaign word' => [['campaign_id' => 'spring'], 'campaign_id'];
        yield 'subid with letters' => [['click_id' => '12ab'], 'click_id'];
        yield 'negative subid' => [['click_id' => '-5'], 'click_id'];
        yield 'unknown type' => [['type' => 'fax'], 'type'];
        yield 'deleted maybe' => [['deleted' => 'maybe'], 'deleted'];
        yield 'time basis' => [['time_basis' => 'upload'], 'time_basis'];
        yield 'unknown preset' => [['range' => 'last7d'], 'range'];
        yield 'custom without dates' => [['range' => 'custom'], 'range'];
        yield 'custom not a day' => [['range' => 'custom', 'from' => '2026-02-30', 'to' => '2026-03-01'], 'range'];
        yield 'custom reversed' => [['range' => 'custom', 'from' => '2026-03-05', 'to' => '2026-03-01'], 'range'];
        yield 'page not a number' => [['offset' => 'abc'], 'offset'];
        yield 'negative page' => [['offset' => '-1'], 'offset'];
        yield 'page in exponent form' => [['offset' => '1e3'], 'offset'];
        yield 'page past the cap' => [['offset' => '100001'], 'offset'];
        yield 'subid as a list' => [['click_id' => ['1', '2']], 'click_id'];
        yield 'empty subid list' => [['click_id' => []], 'click_id'];
        yield 'campaign as a list' => [['campaign_id' => ['10']], 'campaign_id'];
        yield 'type as a list' => [['type' => ['postback']], 'type'];
        yield 'deleted as a list' => [['deleted' => ['yes']], 'deleted'];
        yield 'time basis as a list' => [['time_basis' => ['click']], 'time_basis'];
        yield 'range as a list' => [['range' => ['alltime']], 'range'];
        yield 'from as a list' => [['range' => 'custom', 'from' => ['2026-03-01'], 'to' => '2026-03-02'], 'range'];
        yield 'page as a list' => [['offset' => ['2']], 'offset'];
    }

    /**
     * A value that cannot be read is refused by name, never read as "no
     * filter": that would list every conversion as the ones asked for.
     *
     * @param array<string, mixed> $query
     * @dataProvider unreadable
     */
    public function testAnUnreadableValueIsAnErrorNotAWiderList(array $query, string $field): void
    {
        $read = ConversionLog::readQuery($query, self::ZONE, self::NOW);

        self::assertArrayHasKey($field, $read['errors']);
    }

    public function testAListValueIsRefusedAndNotAlsoReadAsAnotherError(): void
    {
        $read = ConversionLog::readQuery(['click_id' => ['1', '2'], 'range' => 'custom'], self::ZONE, self::NOW);

        self::assertSame('Subid takes one value.', $read['errors']['click_id']);
        self::assertNull($read['filters']['click_id']);
        self::assertArrayHasKey('range', $read['errors']);
    }

    public function testAPageWithinTheCapIsRead(): void
    {
        self::assertSame(100000, ConversionLog::readQuery(['offset' => '100000'], self::ZONE, self::NOW)['page']);
        self::assertSame([], ConversionLog::readQuery(['offset' => '0'], self::ZONE, self::NOW)['errors']);
    }

    public function testElapsed(): void
    {
        self::assertSame('0s', ConversionLog::elapsed(1000, 1000));
        self::assertSame('1m 5s', ConversionLog::elapsed(1000, 1065));
        self::assertSame('2h 0m 0s', ConversionLog::elapsed(1000, 1000 + 7200));
        // Longer than a month: days keep counting (the stored text drops the months).
        self::assertSame('40d 1h 0m 1s', ConversionLog::elapsed(1000, 1000 + 40 * 86400 + 3601));
        self::assertNull(ConversionLog::elapsed(0, 1000));
        self::assertNull(ConversionLog::elapsed(2000, 1000));
    }

    public function testTypeIsTheLedgerSourceOrThePreLedgerPixelType(): void
    {
        self::assertSame(ConversionSource::JVZOO, ConversionLog::typeOf('jvzoo', 0, ''));
        self::assertSame(ConversionSource::POSTBACK, ConversionLog::typeOf('', 2, ''));
        self::assertSame(ConversionSource::UNIVERSAL_PIXEL, ConversionLog::typeOf('', 3, ''));
        self::assertSame(ConversionSource::SUBID_UPLOAD, ConversionLog::typeOf('', 0, 'subid-upload'));
        // A source nothing names is unknown, not guessed from the pixel type.
        self::assertNull(ConversionLog::typeOf('fax', 2, ''));
    }

    public function testThePageAndItsTabAskForTheSetupPermission(): void
    {
        $root = dirname(__DIR__, 2);
        $page = (string) file_get_contents($root . '/tracking202/logs/index.php');
        self::assertMatchesRegularExpression(
            "/AUTH::require_user\\(\\);\\s*\\n(?:\\s*\\/\\/[^\\n]*\\n)*\\s*"
                . "AUTH::require_permissions\\('access_to_setup_section'\\);/",
            $page
        );
        // The signed-in account's rows, and the payout masked as every report masks it.
        self::assertStringContainsString("\$userId = (int) \$_SESSION['user_own_id'];", $page);
        self::assertStringContainsString('CampaignDataMask::hidden()', $page);

        $top = (string) file_get_contents($root . '/tracking202/_config/top.php');
        self::assertMatchesRegularExpression(
            "/if \\(\\\$userObj->hasPermission\\('access_to_setup_section'\\)\\) \\{\\s*"
                . "\\\$p202Tabs\\[\\] = \\['ConversionLogsPage', 'tracking202\\/logs\\/'/",
            $top
        );
    }
}
