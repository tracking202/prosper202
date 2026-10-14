<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;

/**
 * A rotator click's redirect lookup, through the code rtr.php runs: the
 * writer's transaction, connect2.php's real INDEXES::get_site_url_id() and
 * setCache(), and p202WithHeldCacheWrites() around them, on a real database.
 *
 * The lookup inserts the redirect URL's row and caches its id at once, inside
 * the click's transaction. HeldCacheWritesTest runs the hold alone, and the
 * token test in it pins rtr.php's call; this runs the lookup's own writes
 * through it, so a cache write the lookup makes some other way, or a hold
 * that lets one through, fails here (CI's instance runs no memcached).
 *
 * Run in a child process; see fixtures/held-cache-seam-runner.php.
 *
 * @group integration
 */
final class HeldCacheSeamIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const FIRST_CLICK = 99030201;

    private const TABLES = [
        '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking',
        '202_clicks_rotator', '202_clicks_record', '202_clicks_site',
    ];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    private static function cleanUp(): void
    {
        $range = 'BETWEEN ' . self::FIRST_CLICK . ' AND ' . (self::FIRST_CLICK + 99);
        foreach (self::TABLES as $table) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
        // The URLs and domains the committed runs' lookups kept.
        self::q("DELETE FROM 202_site_urls WHERE site_url_address LIKE 'https://held-%.example/offer?s=%'");
        self::q("DELETE FROM 202_site_domains WHERE site_domain_host LIKE 'held-%.example'");
    }

    public function testACommittedClickCachesItsLookupAfterTheCommit(): void
    {
        $clickId = self::FIRST_CLICK;
        $run = self::write('commit', $clickId);

        self::assertNull($run['failure'], json_encode($run));
        self::assertSame("https://{$run['host']}/offer?s=$clickId", $run['url'], 'the URL filled from the click');
        self::assertSame($run['url'], self::urlOf($run['answered']), 'the id answered names the URL');
        self::assertSame(0, $run['cached_by_then'], 'nothing reached the cache inside the transaction');
        self::assertContains(
            [$run['url_key'], $run['answered']],
            $run['cached'],
            'the URL id is cached once committed'
        );
        self::assertCount(2, $run['cached'], 'the URL id and its domain id: ' . json_encode($run['cached']));
        self::assertSame(1, self::rowCount('202_clicks_site', $clickId));
    }

    public function testARolledBackClickCachesNothing(): void
    {
        $clickId = self::FIRST_CLICK + 1;
        $run = self::write('rollback', $clickId);

        self::assertIsString($run['failure'], 'the refused site row reaches the caller');
        self::assertStringContainsString('click_referer_site_url_id', $run['failure']);
        self::assertGreaterThan(0, $run['answered'], 'the lookup ran and inserted its row');
        self::assertNull(self::urlOf($run['answered']), 'the row the lookup inserted went with the rollback');
        self::assertSame([], $run['cached'], 'no cache entry names the row that was rolled back');
        self::assertSame(0, self::rowCount('202_clicks', $clickId));
    }

    /**
     * Without the hold, the same run leaves the cache naming a row that was
     * never kept: what the test above would see if a write got past.
     */
    public function testWithoutTheHoldTheCacheNamesARowThatIsGone(): void
    {
        $run = self::write('rollback_unheld', self::FIRST_CLICK + 2);

        self::assertIsString($run['failure']);
        self::assertNull(self::urlOf($run['answered']));
        self::assertContains([$run['url_key'], $run['answered']], $run['cached'], json_encode($run));
    }

    /**
     * The lookup's own INSERT refused: the click path's _mysqli_query() never
     * answers false, so no click is committed naming a site URL id of 0 (what
     * insert_id holds after a failed INSERT), and its domain goes too.
     */
    public function testAFailedLookupWritesNoClickAndCachesNothing(): void
    {
        $clickId = self::FIRST_CLICK + 3;
        $run = self::write('lookup_fails', $clickId);

        self::assertIsString($run['failure'], json_encode($run));
        self::assertStringStartsWith(
            'RuntimeException: record_mysql_error(): INSERT INTO 202_site_urls',
            $run['failure']
        );
        self::assertNull($run['answered'], 'the lookup answered no id');
        self::assertSame([], $run['cached']);
        foreach (self::TABLES as $table) {
            self::assertSame(0, self::rowCount($table, $clickId), $table);
        }
        $host = self::$db->real_escape_string($run['host']);
        $result = self::$db->query("SELECT COUNT(*) FROM 202_site_domains WHERE site_domain_host = '$host'");
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);
        self::assertSame(0, (int) $result->fetch_row()[0], 'the domain the lookup inserted went with the rollback');
    }

    /**
     * @return array{host: string, url: string, answered: ?int, url_key: string, cached_by_then: ?int,
     *     cached: list<array{string, mixed}>, failure: ?string}
     */
    private static function write(string $scenario, int $clickId): array
    {
        self::requireScratch();
        // A host of its own each run, so the lookup inserts its rows rather
        // than finding an earlier run's.
        $host = 'held-' . bin2hex(random_bytes(4)) . '.example';
        $proc = proc_open(
            [
                PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0',
                __DIR__ . '/fixtures/held-cache-seam-runner.php', $scenario, (string) $clickId, $host,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($proc);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        self::assertSame(0, $exit, "exit $exit\n$stdout$stderr");
        $run = json_decode($stdout, true);
        self::assertIsArray($run, "$stdout$stderr");

        return ['host' => $host] + $run;
    }

    private static function urlOf(int $siteUrlId): ?string
    {
        $result = self::$db->query("SELECT site_url_address FROM 202_site_urls WHERE site_url_id = $siteUrlId");
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);
        $row = $result->fetch_row();

        return $row === null ? null : (string) $row[0];
    }

    private static function rowCount(string $table, int $clickId): int
    {
        $result = self::$db->query("SELECT COUNT(*) FROM $table WHERE click_id = $clickId");
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);

        return (int) $result->fetch_row()[0];
    }
}
