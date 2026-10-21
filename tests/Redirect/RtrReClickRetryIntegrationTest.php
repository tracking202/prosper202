<?php

declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Click\RotatorRoute;
use Prosper202\Database\Connection;

/**
 * rtr.php's re-click write runs once more when it loses a deadlock
 * (rtr_record_visit(), lifted out of rtr.php byte for byte, with the real
 * p202WithHeldCacheWrites() and setCache() lifted from functions-shared.php
 * under other names, held inside each try as rtr.php holds it: that shape is
 * HeldCacheWritesTest's).
 *
 * Two re-clicks of different clicks deadlock on a partitioned 202_clicks
 * (each lock holds a gap of the current week's partition the other inserts
 * into), and the loser was answered with the error page and its visit lost.
 * Here the deadlock is made with a lock any server takes the same way: a
 * second process holds the click's 202_clicks_advance row, which the
 * re-click upserts after locking the click, and then asks for the click's
 * lock; the server rolls the re-click back as the lighter of the two.
 *
 * @group integration
 */
final class RtrReClickRetryIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990115;
    private const CLICK = 99011501;

    /** @var list<array{string, mixed, mixed}> */
    public static array $written = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../Config/fixtures/lift-function.php';
        if (!function_exists('p202RtrRetryTest_record')) {
            $shared = __DIR__ . '/../../202-config/functions-shared.php';
            $names = ['setCache(', 'p202WithHeldCacheWrites(', 'rtr_record_visit('];
            $renamed = ['p202RtrRetryTest_setCache(', 'p202RtrRetryTest_withHeld(', 'p202RtrRetryTest_record('];
            foreach (
                [[$shared, 'setCache'], [$shared, 'p202WithHeldCacheWrites'],
                [__DIR__ . '/../../tracking202/redirect/rtr.php', 'rtr_record_visit']] as [$path, $name]
            ) {
                eval(str_replace($names, $renamed, lift_function($path, $name)));
            }
        }
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
        }
    }

    protected function setUp(): void
    {
        self::$written = [];
        $GLOBALS['whatCache'] = 'memcached';
        $GLOBALS['memcache'] = new class {
            public function set(string $key, mixed $value, mixed $exp): bool
            {
                RtrReClickRetryIntegrationTest::$written[] = [$key, $value, $exp];

                return true;
            }
        };
        $GLOBALS['p202HeldCacheWrites'] = null;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['whatCache'], $GLOBALS['memcache'], $GLOBALS['p202HeldCacheWrites']);
    }

    private static function cleanUp(): void
    {
        $tables = ['202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_rotator',
            '202_clicks_record', '202_clicks_site', '202_click_latest_visits', '202_attribution_rollup_dirty_clicks'];
        foreach ($tables as $table) {
            self::$db->query("DELETE FROM $table WHERE click_id = " . self::CLICK);
        }
        self::$db->query('DELETE FROM 202_dirty_hours WHERE user_id = ' . self::USER);
    }

    private static function deadlock(): \mysqli_sql_exception
    {
        return new \mysqli_sql_exception('Deadlock found when trying to get lock; try restarting transaction', 1213);
    }

    public function testAReClickThatLosesADeadlockIsWrittenOnTheSecondTry(): void
    {
        $db = self::requireScratch();
        self::cleanUp();
        $first = time() - 8 * 86400;
        $u = self::USER;
        $c = self::CLICK;
        self::q("INSERT INTO 202_clicks SET click_id = $c, user_id = $u, aff_campaign_id = 0, landing_page_id = 0,"
            . " ppc_account_id = 3, click_cpc = 0.25, click_payout = 1, click_lead = 0, click_time = $first,"
            . ' rotator_id = 7, rule_id = 9');
        self::q("INSERT INTO 202_clicks_spy SET click_id = $c, user_id = $u, aff_campaign_id = 0, landing_page_id = 0,"
            . " ppc_account_id = 3, click_cpc = 0.25, click_payout = 1, click_lead = 0, click_time = $first");
        self::q("INSERT INTO 202_clicks_advance SET click_id = $c, text_ad_id = 0, keyword_id = 5, ip_id = 23,"
            . ' country_id = 0, region_id = 0, isp_id = 0, city_id = 0, platform_id = 0, browser_id = 0,'
            . ' device_id = 0');
        self::q("INSERT INTO 202_clicks_rotator SET click_id = $c, rotator_id = 7, rule_id = 8, rule_redirect_id = 9");

        $holder = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/re-click-lock-holder.php', (string) $c, (string) $u, '1500'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            getenv()
        );
        self::assertIsResource($holder);
        $held = fgets($pipes[1]);
        if ($held !== "held\n") {
            self::fail('the other side holds nothing: ' . $held . stream_get_contents($pipes[2]));
        }

        $repo = new MysqlClickRepository(new Connection($db));
        $record = new ClickRecord(
            clickId: $c,
            userId: $u,
            clickTime: time(),
            affCampaignId: 14,
            ppcAccountId: 13,
            clickCpc: '0.75',
            clickPayout: '1.25',
            keywordId: 6,
            ipId: 23,
            recordsCampaignRows: false,
            rotator: new RotatorRoute(17, 18, 19),
        );
        $failures = [];
        $resets = 0;
        p202RtrRetryTest_record(
            // Closures, not arrow functions: an arrow function would hand the
            // inner closure its own copy of $failures (CLAUDE.md #8).
            static function () use ($repo, $record, &$failures): void {
                p202RtrRetryTest_withHeld(static function () use ($repo, $record, &$failures): void {
                    try {
                        $repo->recordReClick($record, static fn (): int => 52);
                    } catch (\Throwable $e) {
                        $failures[] = $e;
                        throw $e;
                    }
                });
            },
            true,
            static function () use (&$resets): void {
                $resets++;
            }
        );
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($holder);

        self::assertSame("locked\ndone\n", $out, 'the other side got the lock once the re-click lost: ' . $err);
        self::assertCount(1, $failures, 'one lost try');
        self::assertTrue(Connection::isMysqlError($failures[0], 1213, 'Deadlock found'), $failures[0]->getMessage());
        self::assertSame(1, $resets, 'the redirect URL put back before the second try');
        $visits = $db->query("SELECT ppc_account_id, click_cpc FROM 202_clicks WHERE click_id = $c"
            . ' ORDER BY click_time');
        self::assertSame(
            [['3', '0.25000'], ['3', '0.25000']],
            $visits->fetch_all(MYSQLI_NUM),
            'the re-click is stored, once, with the first visit\'s source and cost'
        );
    }

    /** @return iterable<string, array{bool, int}> */
    public static function deadlockedWrites(): iterable
    {
        yield 'a re-click: tried twice' => [true, 2];
        yield 'a new click: no lock of an older click, so not run again' => [false, 1];
    }

    /** @dataProvider deadlockedWrites */
    public function testADeadlockOnEveryTryIsTheCallers(bool $reClick, int $expectedTries): void
    {
        $tries = 0;
        $resets = 0;
        try {
            p202RtrRetryTest_record(
                static function () use (&$tries): void {
                    $tries++;
                    throw self::deadlock();
                },
                $reClick,
                static function () use (&$resets): void {
                    $resets++;
                }
            );
            self::fail('the deadlock reaches rtr.php\'s error page');
        } catch (\mysqli_sql_exception $e) {
            self::assertSame(1213, $e->getCode());
        }
        self::assertSame([$expectedTries, $expectedTries - 1], [$tries, $resets]);
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function otherFailures(): iterable
    {
        yield 'a lock wait timeout' => [
            new \mysqli_sql_exception('Lock wait timeout exceeded; try restarting transaction', 1205),
        ];
        yield 'a duplicate key' => [
            new \mysqli_sql_exception("Duplicate entry 'Deadlock found' for key 'PRIMARY'", 1062),
        ];
        yield 'a refused record' => [new \LogicException('A re-click names click 5, which is not account 6\'s')];
    }

    /** @dataProvider otherFailures */
    public function testAnyOtherFailureIsNotRunAgain(\Throwable $failure): void
    {
        $tries = 0;
        $resets = 0;
        try {
            p202RtrRetryTest_record(
                static function () use (&$tries, $failure): void {
                    $tries++;
                    throw $failure;
                },
                true,
                static function () use (&$resets): void {
                    $resets++;
                }
            );
            self::fail('the failure reaches the caller');
        } catch (\Throwable $e) {
            self::assertSame($failure, $e);
        }
        self::assertSame([1, 0], [$tries, $resets]);
    }

    /** The lookups' cache writes held inside each try, as rtr.php holds them. */
    public function testTheLostTrysCacheWritesAreDropped(): void
    {
        $tries = 0;
        p202RtrRetryTest_record(
            static function () use (&$tries): void {
                p202RtrRetryTest_withHeld(static function () use (&$tries): void {
                    $tries++;
                    p202RtrRetryTest_setCache('url-id-try-' . $tries, 70 + $tries, 604800);
                    if ($tries === 1) {
                        throw self::deadlock();
                    }
                });
            },
            true,
            static function (): void {
            }
        );

        self::assertSame([['url-id-try-2', 72, 604800]], self::$written);
    }
}
