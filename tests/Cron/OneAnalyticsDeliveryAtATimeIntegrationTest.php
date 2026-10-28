<?php

declare(strict_types=1);

namespace Tests\Cron;

use PHPUnit\Framework\TestCase;
use Prosper202\Cron\CronLock;
use Tests\Messaging\LocalHttpRecorder;
use Tests\Report\ScratchReportDatabase;

/**
 * One analytics delivery at a time, with 202-cronjobs/sync-messaging.php
 * itself run in child processes.
 *
 * sync-messaging.php kept two runs apart by a file's presence
 * (messaging-sync.lock: file_exists(), then touch(), deleted as stale once 600
 * s old): a delivery longer than ten minutes had a second one started beside
 * it, a run that died kept every run out for ten minutes, and nothing kept it
 * apart from index.php's hourly delivery. Two deliveries at once read the same
 * pending rows, so each event went out twice and, while the portal was down,
 * spent two of its five attempts on one outage. Both deliver through
 * MessagingService::deliverPendingExclusive() now, under a CronLock on
 * 202-cronjobs/analytics-delivery.lock (EveryCronRunTakesTheLockTest holds
 * both to it).
 *
 * A run is held mid-delivery by the stand-in portal, which records the run's
 * /track request and answers it only once released. "Past eleven minutes" is
 * every lock file in 202-cronjobs/ set eleven minutes back while a run holds
 * its lock: the old lock's only clock.
 *
 * The runs lock the checkout's own 202-cronjobs/analytics-delivery.lock, as
 * the script does. Needs a 202-config.php (connect.php stops without one;
 * tests/run-integration-suites.sh writes one) and P202_TEST_DB_*: the schema is
 * installed there, 202_version set to this code's and put back, and the
 * messaging tables emptied. tests/live/sync-messaging-lock.sh runs the script
 * beside index.php's hourly delivery.
 *
 * @group integration
 */
final class OneAnalyticsDeliveryAtATimeIntegrationTest extends TestCase
{
    use ScratchReportDatabase;

    private const RUNNER = __DIR__ . '/fixtures/sync-messaging-runner.php';
    private const USER = 990301;
    private const TOKENS = ['bt5z-one', 'bt5z-two', 'bt5z-three'];
    private const BUSY = 'Analytics delivery already running';
    private const DELIVERED = 'Results: 1 delivered, 0 with more pending, 0 with nothing pending, 0 failed';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    private ?LocalHttpRecorder $portal = null;

    private string $dir = '';

    /** @var list<array{0: resource, 1: array<int, resource>}> */
    private array $children = [];

    /** Where the lock file was moved while a directory stands in its place. */
    private ?string $kept = null;

    public static function setUpBeforeClass(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/202-config.php')) {
            self::markTestSkipped(
                'No 202-config.php: connect.php would stop before sync-messaging.php ran.'
                . ' tests/run-integration-suites.sh writes one.'
            );
        }
        if (!self::connectScratch()) {
            return;
        }
        require_once dirname(__DIR__, 2) . '/202-config/version.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        self::emptyMessaging();
        self::q('DELETE FROM 202_users WHERE user_id = ' . self::USER);
        if (self::$versions !== null) {
            self::q('DELETE FROM 202_version');
            foreach (self::$versions as $version) {
                self::row('202_version', ['version' => $version]);
            }
        }
    }

    private static function emptyMessaging(): void
    {
        foreach (['202_messaging_events', '202_messaging_attributes', '202_messaging_sync'] as $table) {
            self::q("DELETE FROM $table");
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::emptyMessaging();
        self::q('DELETE FROM 202_users WHERE user_id = ' . self::USER);
        self::row('202_users', [
            'user_id' => self::USER, 'user_name' => 'bt5z', 'user_pass' => 'x', 'user_email' => 'bt5z@example.com',
            'user_dash_email' => '', 'user_time_register' => 1700000000, 'install_hash' => 'ih_bt5z',
            'user_hash' => '', 'p202_customer_api_key' => 'key-bt5z',
        ]);
        foreach (self::TOKENS as $token) {
            self::row('202_messaging_events', [
                'user_id' => self::USER, 'event_name' => 'event_' . $token, 'client_token' => $token,
                'delivery_status' => 'pending',
            ]);
        }
        self::assertFalse(CronLock::isHeld(self::lockPath()), 'no delivery is running when the test starts');

        $dir = sys_get_temp_dir() . '/p202-delivery-lock-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0700));
        $this->dir = $dir;
        $this->portal = LocalHttpRecorder::start();
    }

    protected function tearDown(): void
    {
        foreach ($this->children as [$proc, $pipes]) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($proc, 9);
            proc_close($proc);
        }
        $this->portal?->stop();
        if ($this->kept !== null) {
            if (is_dir(self::lockPath())) {
                rmdir(self::lockPath());
            }
            if (is_file($this->kept)) {
                rename($this->kept, self::lockPath());
            }
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private static function lockPath(): string
    {
        return CronLock::path(CronLock::ANALYTICS_DELIVERY_FILE);
    }

    /**
     * sync-messaging.php, started now, against the stand-in portal.
     *
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function start(?string $go = null): array
    {
        $cmd = [PHP_BINARY, self::RUNNER];
        if ($go !== null) {
            $cmd[] = $go;
        }
        $env = ['MESSAGING_API_URL' => $this->portal?->url() . '/api/v3/messaging'] + getenv();
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($proc);
        $child = [$proc, $pipes];
        $this->children[] = $child;

        return $child;
    }

    /** @param array{0: resource, 1: array<int, resource>} $child */
    private static function running(array $child): bool
    {
        return proc_get_status($child[0])['running'];
    }

    /**
     * How the run ended: its exit status and what it printed, within ten seconds.
     *
     * @param array{0: resource, 1: array<int, resource>} $child
     * @return array{0: int, 1: string}
     */
    private static function ended(array $child): array
    {
        $deadline = microtime(true) + 10;
        while (($status = proc_get_status($child[0]))['running']) {
            self::assertLessThan($deadline, microtime(true), 'the run ends within ten seconds');
            usleep(10000);
        }
        $out = (string) stream_get_contents($child[1][1]) . (string) stream_get_contents($child[1][2]);

        return [$status['exitcode'], $out];
    }

    /** @param array{0: resource, 1: array<int, resource>} $child */
    private static function turnedAway(array $child, string $which): void
    {
        [$status, $out] = self::ended($child);
        self::assertSame(0, $status, "$which exits 0: $out");
        self::assertStringContainsString(self::BUSY, $out, "$which is turned away");
        self::assertStringContainsString(
            'Analytics delivery already running: another run holds ' . self::lockPath() . '; this run did nothing.',
            $out,
            "$which logs why"
        );
        self::assertStringNotContainsString('Results:', $out, "$which delivers nothing");
    }

    /** @param array{0: resource, 1: array<int, resource>} $child */
    private static function delivered(array $child, string $which, string $results = self::DELIVERED): void
    {
        [$status, $out] = self::ended($child);
        self::assertSame(0, $status, "$which exits 0: $out");
        self::assertStringNotContainsString(self::BUSY, $out, "$which is not turned away");
        self::assertStringContainsString($results, $out, $which);
    }

    /** The portal has $count requests, waiting up to five seconds for them; never more. */
    private function asked(int $count): void
    {
        $deadline = microtime(true) + 5;
        while (count($this->portal?->requests() ?? []) < $count && microtime(true) < $deadline) {
            usleep(20000);
        }
        self::assertCount($count, $this->portal?->requests() ?? [], "the portal is asked $count time(s)");
    }

    /** @return list<list<string>> each /track request's events' client tokens */
    private function sent(): array
    {
        return array_map(
            static fn (array $r): array => array_column(json_decode($r['body'], true)['events'] ?? [], 'client_token'),
            $this->portal?->requests() ?? []
        );
    }

    /** @return list<list<string>> each event: its token, status and attempts */
    private static function events(): array
    {
        $result = self::$db->query('SELECT client_token, delivery_status, sync_attempts FROM 202_messaging_events'
            . ' WHERE user_id = ' . self::USER . ' ORDER BY id');
        self::assertInstanceOf(\mysqli_result::class, $result);

        return $result->fetch_all();
    }

    /** @return list<list<string>> */
    private static function eachSentOnce(): array
    {
        return array_map(static fn (string $t): array => [$t, 'sent', '0'], self::TOKENS);
    }

    public function testOfRunsStartedTogetherOneDeliversAndTheOthersDoNothing(): void
    {
        $go = $this->dir . '/go';
        $this->portal?->hold();
        $runs = [$this->start($go), $this->start($go), $this->start($go)];
        usleep(300000);
        self::assertTrue(touch($go));

        $this->asked(1);
        $deadline = microtime(true) + 10;
        while (count(array_filter($runs, self::running(...))) > 1) {
            self::assertLessThan($deadline, microtime(true), 'all but one of the runs end while one delivers');
            usleep(10000);
        }
        $holder = null;
        foreach ($runs as $i => $run) {
            if (self::running($run)) {
                $holder = $run;
                continue;
            }
            self::turnedAway($run, "run $i");
        }
        self::assertNotNull($holder, 'one run is still delivering');
        $this->asked(1);

        $this->portal?->release();
        self::delivered($holder, 'the run that holds the lock');
        $this->asked(1);
        self::assertSame([self::TOKENS], $this->sent(), 'every event went out once, in one request');
        self::assertSame(self::eachSentOnce(), self::events());
    }

    public function testARunPastElevenMinutesStillKeepsTheNextOut(): void
    {
        $this->portal?->hold();
        $first = $this->start();
        $this->asked(1);
        self::assertTrue(self::running($first), 'the first run is delivering');

        // The old lock deleted its file as stale at this age, and the next run ran beside the first.
        $locks = glob(dirname(self::lockPath()) . '/*.lock') ?: [];
        self::assertNotSame([], $locks, 'the run made its lock file');
        foreach ($locks as $lock) {
            self::assertTrue(touch($lock, time() - 660, time() - 660));
        }
        clearstatcache();
        foreach ($locks as $lock) {
            self::assertLessThan(time() - 600, (int) filemtime($lock), "$lock reads eleven minutes old");
        }

        self::turnedAway($this->start(), 'a run eleven minutes into the first');
        $this->asked(1);
        self::assertContains(self::lockPath(), $locks);
        self::assertTrue(CronLock::isHeld(self::lockPath()), 'the first run holds the lock');

        $this->portal?->release();
        self::delivered($first, 'the first run');
        self::delivered(
            $this->start(),
            'the next run, once the first is done',
            'Results: 0 delivered, 0 with more pending, 0 with nothing pending, 0 failed'
        );
        $this->asked(1);
        self::assertSame([self::TOKENS], $this->sent());
        self::assertSame(self::eachSentOnce(), self::events());
    }

    public function testAKilledRunsLockIsFreeAtOnce(): void
    {
        $this->portal?->hold();
        $killed = $this->start();
        $this->asked(1);
        self::assertTrue(self::running($killed), 'the run is delivering');
        self::assertTrue(proc_terminate($killed[0], 9));
        $deadline = microtime(true) + 10;
        while (self::running($killed)) {
            self::assertLessThan($deadline, microtime(true), 'the killed run is gone');
            usleep(10000);
        }

        $this->portal?->release();
        self::delivered($this->start(), 'the run right after the killed one');
        $this->asked(2);
        self::assertSame([self::TOKENS, self::TOKENS], $this->sent(), 'what the killed run sent and never marked');
        self::assertSame(self::eachSentOnce(), self::events());
        self::assertFileExists(self::lockPath(), 'the file stays; only the lock went');
        self::assertFalse(CronLock::isHeld(self::lockPath()));
    }

    public function testALockThatCannotBeTakenIsAFailureNotBusy(): void
    {
        $this->kept = self::lockPath() . '.kept-' . getmypid();
        if (is_file(self::lockPath())) {
            self::assertTrue(rename(self::lockPath(), $this->kept));
        }
        self::assertTrue(mkdir(self::lockPath()));

        [$status, $out] = self::ended($this->start());
        self::assertSame(1, $status, 'the run exits 1: ' . $out);
        self::assertStringContainsString(
            'Analytics delivery failed: the cron job could not make its lock file ' . self::lockPath()
                . ', so it ran nothing',
            $out
        );
        self::assertStringNotContainsString(self::BUSY, $out, 'it is not read as another run');
        $this->asked(0);
        self::assertSame(
            array_map(static fn (string $t): array => [$t, 'pending', '0'], self::TOKENS),
            self::events(),
            'every event waits for the next run, no attempt spent'
        );
    }
}
