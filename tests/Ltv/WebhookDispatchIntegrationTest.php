<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Ltv\MysqlWebhookRepository;
use Prosper202\Ltv\WebhookDispatcher;

/**
 * The LTV webhook dispatcher on a real server, with a receiver on loopback
 * (LocalWebhookReceiver): a queued event delivered once, signed; a
 * receiver's failure recorded on its row with its retry, and retried; the
 * run's deadline; a slow receiver held to one try per run; a refusal or a
 * failed lookup failing its own delivery only; the lock, and the claim
 * under runs the lock does not see; and the expiry of deliveries nothing
 * sent for a week.
 *
 * The dispatcher reads every account's deliveries, so the two tables are
 * emptied for each test.
 *
 * @group integration
 */
final class WebhookDispatchIntegrationTest extends TestCase
{
    private const USER = 990801;
    private const OTHER = 990802;

    private static ?\mysqli $db = null;
    private static ?LocalWebhookReceiver $receiver = null;

    /** What the dispatcher logged in this test. */
    private string $log = '';
    private string $errorLogBefore = '';

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) {'
                . ' return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        if (!$db) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        $db->query("SET SESSION sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,"
            . "NO_ENGINE_SUBSTITUTION'");
        self::$db = $db;
        self::$receiver = LocalWebhookReceiver::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$receiver?->stop();
        self::$receiver = null;
        if (self::$db !== null) {
            self::$db->query('TRUNCATE TABLE 202_ltv_webhooks');
            self::$db->query('TRUNCATE TABLE 202_ltv_webhook_deliveries');
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::$db->query('TRUNCATE TABLE 202_ltv_webhooks');
        self::$db->query('TRUNCATE TABLE 202_ltv_webhook_deliveries');
        self::receiver()->forget();
        $log = tempnam(sys_get_temp_dir(), 'p202-ltv-dispatch-log');
        self::assertIsString($log);
        $this->log = $log;
        $this->errorLogBefore = (string) ini_get('error_log');
        ini_set('error_log', $log);
    }

    protected function tearDown(): void
    {
        if ($this->log !== '') {
            ini_set('error_log', $this->errorLogBefore);
            if (is_file($this->log)) {
                unlink($this->log);
            }
        }
    }

    private static function receiver(): LocalWebhookReceiver
    {
        self::assertNotNull(self::$receiver);

        return self::$receiver;
    }

    private static function conn(): Connection
    {
        self::assertNotNull(self::$db);

        return new Connection(self::$db);
    }

    private static function repo(): MysqlWebhookRepository
    {
        return new MysqlWebhookRepository(self::conn());
    }

    /** @return callable(array<string, mixed>): array{0: bool, 1: ?int, 2: string} */
    private static function toReceiver(): callable
    {
        $base = self::receiver()->base();

        return static fn (array $delivery): array => LocalWebhookReceiver::send($base, $delivery);
    }

    /** @return array{delivered: int, failed: int, expired: int, deferred: int, taken: int, more: bool} */
    private static function dispatch(?callable $send = null, int $seconds = 30, ?callable $clock = null): array
    {
        $report = WebhookDispatcher::runExclusive(self::conn(), time() + $seconds, $send ?? self::toReceiver(), $clock);
        self::assertNotNull($report, 'no other run holds the lock');

        return $report;
    }

    /** @return array{webhookId: int, secret: string} */
    private static function hook(string $url, int $user = self::USER): array
    {
        // A receiver URL resolves nowhere, so it is written as the API's
        // create() writes it, past the well-formedness check alone.
        return self::repo()->create($user, $url, ['revenue.recorded']);
    }

    private static function queue(int $count = 1, int $user = self::USER): void
    {
        for ($i = 0; $i < $count; $i++) {
            self::repo()->enqueue($user, 'revenue.recorded', ['revenue_event_id' => 1000 + $i, 'amount' => 49.0]);
        }
    }

    /** @return list<array<string, string|null>> */
    private static function deliveries(): array
    {
        $result = self::$db->query('SELECT * FROM 202_ltv_webhook_deliveries ORDER BY delivery_id');
        self::assertInstanceOf(\mysqli_result::class, $result);

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    private static function q(string $sql): void
    {
        self::assertTrue(self::$db->query($sql), self::$db->error . ' in ' . $sql);
    }

    public function testAQueuedEventIsDeliveredOnceSignedAndRecorded(): void
    {
        $hook = self::hook(LocalWebhookReceiver::url(200));
        self::queue();
        [$queued] = self::deliveries();

        $report = self::dispatch();

        self::assertSame(1, $report['delivered'], WebhookDispatcher::summary($report));
        self::assertSame(0, $report['failed']);
        $requests = self::receiver()->requests();
        self::assertCount(1, $requests);
        self::assertSame($queued['delivery_id'], $requests[0]['delivery']);
        self::assertSame('revenue.recorded', $requests[0]['event']);
        self::assertSame($queued['payload'], $requests[0]['body']);
        $signature = 'sha256=' . hash_hmac('sha256', $requests[0]['body'], $hook['secret']);
        self::assertSame($signature, $requests[0]['signature']);
        $body = json_decode($requests[0]['body'], true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(1000, $body['data']['revenue_event_id']);

        [$row] = self::deliveries();
        self::assertSame('delivered', $row['status']);
        self::assertSame('1', $row['attempts']);
        self::assertSame('200', $row['last_status_code']);
        self::assertSame('answered 200', $row['last_response_body']);

        $again = self::dispatch();
        self::assertFalse(WebhookDispatcher::didWork($again), WebhookDispatcher::summary($again));
        self::assertCount(1, self::receiver()->requests(), 'a delivered event is not sent again');
    }

    public function testAReceiverFailureIsRecordedWithItsRetryAndRetried(): void
    {
        self::hook(LocalWebhookReceiver::url(200));
        self::queue();
        self::receiver()->down(true);

        $before = time();
        $report = self::dispatch();

        self::assertSame(1, $report['failed'], WebhookDispatcher::summary($report));
        [$row] = self::deliveries();
        self::assertSame('pending', $row['status']);
        self::assertSame('1', $row['attempts']);
        self::assertSame('503', $row['last_status_code']);
        self::assertSame('receiver down', $row['last_response_body']);
        $logged = (string) file_get_contents($this->log);
        self::assertStringContainsString(
            'ltv_webhooks: delivery ' . $row['delivery_id'] . ' to webhook ' . $row['webhook_id']
                . ' failed (status 503)',
            $logged
        );
        self::assertStringNotContainsString('revenue_event_id', $logged, 'ids and the status only, never the payload');
        $retryAt = (int) $row['next_attempt_at'];
        self::assertGreaterThanOrEqual($before + 120, $retryAt, 'the first retry is 2 minutes on');
        self::assertLessThanOrEqual(time() + 120, $retryAt);
        $api = self::repo()->deliveries(self::USER, (int) $row['webhook_id'], 25);
        self::assertSame('503', (string) $api[0]['last_status_code'], 'the log the API serves has it');
        self::assertSame((string) $retryAt, (string) $api[0]['next_attempt_at']);

        self::receiver()->down(false);
        $early = self::dispatch();
        self::assertFalse(WebhookDispatcher::didWork($early), 'not before its retry is due');
        self::assertCount(1, self::receiver()->requests());

        self::q('UPDATE 202_ltv_webhook_deliveries SET next_attempt_at = ' . (time() - 1));
        $retry = self::dispatch();
        self::assertSame(1, $retry['delivered'], WebhookDispatcher::summary($retry));
        [$row] = self::deliveries();
        self::assertSame('delivered', $row['status']);
        self::assertSame('2', $row['attempts']);
        self::assertCount(2, self::receiver()->requests());
    }

    /**
     * A receiver's answer is any bytes; the log keeps the first 1,000 of it
     * as text under a strict sql_mode too, where a body that is not UTF-8 (or
     * is cut mid-character) was refused, the outcome went unrecorded and the
     * delivery would be sent again once its claim lapsed.
     */
    public function testAReceiversAnswerIsLoggedWhateverItsBytes(): void
    {
        self::hook(LocalWebhookReceiver::url(500) . '/bytes');
        self::queue();

        $report = self::dispatch();

        self::assertSame(1, $report['failed'], WebhookDispatcher::summary($report));
        [$row] = self::deliveries();
        self::assertSame('pending/1', $row['status'] . '/' . $row['attempts']);
        self::assertSame('??' . str_repeat('é', 499), $row['last_response_body']);
    }

    public function testTheLastAttemptFailsTheDeliveryAndMarksTheWebhookDead(): void
    {
        $hook = self::hook(LocalWebhookReceiver::url(500));
        self::queue();
        self::q('UPDATE 202_ltv_webhook_deliveries SET attempts = ' . (MysqlWebhookRepository::MAX_ATTEMPTS - 1));

        $report = self::dispatch();

        self::assertSame(1, $report['failed']);
        [$row] = self::deliveries();
        self::assertSame('failed', $row['status']);
        self::assertSame((string) MysqlWebhookRepository::MAX_ATTEMPTS, $row['attempts']);
        self::assertSame('500', $row['last_status_code']);
        self::assertSame('dead', self::repo()->get(self::USER, $hook['webhookId'])['status'] ?? null);
    }

    /**
     * The deadline is asked before each delivery: a receiver that takes a
     * second a request is sent to for about the run's budget, and the rest
     * wait, due and untried, for the next run.
     */
    public function testARunStopsAtItsDeadline(): void
    {
        self::hook(LocalWebhookReceiver::url(200, 1000));
        self::queue(6);

        $started = microtime(true);
        $report = self::dispatch(null, 2);
        $took = microtime(true) - $started;

        self::assertTrue($report['more'], WebhookDispatcher::summary($report));
        self::assertGreaterThanOrEqual(1, $report['delivered']);
        self::assertLessThanOrEqual(3, $report['delivered'], WebhookDispatcher::summary($report));
        self::assertLessThan(4.5, $took, 'the budget and the one delivery begun before its end');
        $rows = self::deliveries();
        $untried = array_filter(
            $rows,
            static fn (array $r): bool => $r['status'] === 'pending' && $r['attempts'] === '0'
        );
        self::assertCount(6 - $report['delivered'], $untried, 'the rest wait untried');
        foreach ($untried as $row) {
            self::assertLessThanOrEqual(time(), (int) $row['next_attempt_at'], 'and due');
        }

        $next = self::dispatch();
        self::assertSame(6 - $report['delivered'], $next['delivered'], WebhookDispatcher::summary($next));
    }

    /** At least one delivery a run, whatever its deadline; then none past it. */
    public function testARunPastItsDeadlineSendsOne(): void
    {
        self::hook(LocalWebhookReceiver::url(200));
        self::queue(3);
        $now = time();

        $clock = static fn (): int => $now;
        $report = WebhookDispatcher::runExclusive(self::conn(), $now - 10, self::toReceiver(), $clock);

        self::assertNotNull($report);
        self::assertSame(1, $report['delivered']);
        self::assertTrue($report['more']);
        self::assertCount(1, self::receiver()->requests());
    }

    /**
     * A receiver that is slow to fail is sent to once a run, and its other
     * deliveries wait, due and untried, for the next: the budget goes to
     * every other webhook. One that fails at once is tried for each delivery,
     * as before.
     */
    public function testASlowFailingWebhookIsSentToOncePerRun(): void
    {
        $slow = self::hook(LocalWebhookReceiver::url(500, 2100));
        $fast = self::hook(LocalWebhookReceiver::url(500), self::OTHER);
        $healthy = self::hook(LocalWebhookReceiver::url(200), self::OTHER);
        self::queue(3);
        self::repo()->enqueue(self::OTHER, 'revenue.recorded', ['revenue_event_id' => 1]);
        self::repo()->enqueue(self::OTHER, 'revenue.recorded', ['revenue_event_id' => 2]);

        $report = self::dispatch();

        self::assertSame(2, $report['deferred'], WebhookDispatcher::summary($report));
        self::assertSame(2, $report['delivered'], 'the healthy webhook was sent to');
        self::assertSame(3, $report['failed'], 'the slow one once, the fast one each time');
        $byHook = [];
        foreach (self::deliveries() as $row) {
            $byHook[(int) $row['webhook_id']][] = $row['status'] . '/' . $row['attempts'];
        }
        self::assertSame(['pending/1', 'pending/0', 'pending/0'], $byHook[$slow['webhookId']]);
        self::assertSame(['pending/1', 'pending/1'], $byHook[$fast['webhookId']]);
        self::assertSame(['delivered/1', 'delivered/1'], $byHook[$healthy['webhookId']]);
    }

    /**
     * More of a slow webhook's deliveries than one read holds: the run steps
     * past them to the deliveries after them, rather than reading the same
     * deferred rows again until its deadline.
     */
    public function testDeferredDeliveriesBeyondOneReadAreSteppedPast(): void
    {
        $slow = self::hook(LocalWebhookReceiver::url(500, 2100));
        $healthy = self::hook(LocalWebhookReceiver::url(200), self::OTHER);
        self::queue(WebhookDispatcher::BATCH + 50);
        self::repo()->enqueue(self::OTHER, 'revenue.recorded', ['revenue_event_id' => 1]);

        $started = microtime(true);
        $report = self::dispatch(null, 20);

        self::assertLessThan(8, microtime(true) - $started, WebhookDispatcher::summary($report));
        self::assertFalse($report['more']);
        self::assertSame(WebhookDispatcher::BATCH + 49, $report['deferred']);
        self::assertSame(1, $report['delivered']);
        $sent = array_count_values(array_column(self::receiver()->requests(), 'path'));
        self::assertSame(['/hook/500/2100' => 1, '/hook/200/0' => 1], $sent);
        $last = self::deliveries()[WebhookDispatcher::BATCH + 50];
        self::assertSame((string) $healthy['webhookId'], $last['webhook_id']);
        self::assertSame((string) $slow['webhookId'], self::deliveries()[0]['webhook_id']);
    }

    /**
     * A webhook disabled or dead after its deliveries were queued is sent
     * nothing; each delivery records why and is retried as before, and the
     * deliveries of other webhooks go on.
     */
    public function testAWebhookNoLongerActiveIsSentNothing(): void
    {
        $off = self::hook(LocalWebhookReceiver::url(200));
        self::hook(LocalWebhookReceiver::url(200), self::OTHER);
        self::queue();
        self::repo()->enqueue(self::OTHER, 'revenue.recorded', ['revenue_event_id' => 1]);
        self::q("UPDATE 202_ltv_webhooks SET status = 'disabled' WHERE webhook_id = " . $off['webhookId']);

        $report = self::dispatch();

        self::assertSame(1, $report['failed'], WebhookDispatcher::summary($report));
        self::assertSame(1, $report['delivered']);
        [$row] = self::deliveries();
        self::assertSame('pending/1', $row['status'] . '/' . $row['attempts']);
        self::assertSame('endpoint not active', $row['last_response_body']);
        self::assertCount(1, self::receiver()->requests());
    }

    /**
     * Through the real POST: a URL the guard refuses and a host that does not
     * resolve each fail their own delivery, recorded with the reason, and the
     * run goes on to the next (CLAUDE.md #24). So does a send that throws.
     */
    public function testARefusedOrUnresolvableDeliveryFailsOnlyItself(): void
    {
        // Stored before the write boundary checked an address literal, or a
        // name that resolves to loopback by the time it is sent.
        self::q('INSERT INTO 202_ltv_webhooks SET user_id = ' . self::USER . ", webhook_url = 'https://127.0.0.1/hook',"
            . " webhook_secret = 'loopback-secret', subscribed_events = '', status = 'active',"
            . ' created_at = 0, updated_at = 0');
        $loopback = ['webhookId' => (int) self::$db->insert_id];
        $nowhere = self::hook('https://p202-no-such-host.invalid/hook');
        $throws = self::hook('https://throws.example.com/hook');
        $healthy = self::hook(LocalWebhookReceiver::url(200));
        self::queue();
        $base = self::receiver()->base();
        $send = static function (array $d) use ($base): array {
            $url = (string) $d['webhook_url'];
            if ($url === 'https://throws.example.com/hook') {
                throw new \RuntimeException('the send broke');
            }

            return str_starts_with($url, LocalWebhookReceiver::HOST)
                ? LocalWebhookReceiver::send($base, $d)
                : WebhookDispatcher::post($d);
        };

        $report = self::dispatch($send);

        self::assertSame(1, $report['delivered'], WebhookDispatcher::summary($report));
        self::assertSame(3, $report['failed']);
        $byHook = [];
        foreach (self::deliveries() as $row) {
            $byHook[(int) $row['webhook_id']] = $row;
        }
        self::assertSame('pending', $byHook[$loopback['webhookId']]['status']);
        self::assertSame('1', $byHook[$loopback['webhookId']]['attempts']);
        self::assertNull($byHook[$loopback['webhookId']]['last_status_code']);
        self::assertSame(
            'blocked: webhook_url resolves to a private or reserved address',
            $byHook[$loopback['webhookId']]['last_response_body']
        );
        $nowhereRow = $byHook[$nowhere['webhookId']];
        self::assertSame('blocked: webhook_url host does not resolve', $nowhereRow['last_response_body']);
        $throwsRow = $byHook[$throws['webhookId']];
        self::assertSame('pending/1', $throwsRow['status'] . '/' . $throwsRow['attempts']);
        self::assertSame('error: the send broke', $throwsRow['last_response_body']);
        self::assertSame('delivered', $byHook[$healthy['webhookId']]['status']);
        self::assertCount(1, self::receiver()->requests());
    }

    public function testTheLockKeepsASecondRunOut(): void
    {
        self::hook(LocalWebhookReceiver::url(200));
        self::queue();
        $other = self::connect();
        $name = WebhookDispatcher::lockName(self::conn());
        $held = $other->query("SELECT GET_LOCK('" . $other->real_escape_string($name) . "', 0)");
        self::assertInstanceOf(\mysqli_result::class, $held);
        self::assertSame('1', (string) $held->fetch_row()[0]);
        try {
            self::assertNull(WebhookDispatcher::runExclusive(self::conn(), time() + 30, self::toReceiver()));
        } finally {
            $other->close();
        }
        self::assertSame([], self::receiver()->requests());
        self::assertSame('0', self::deliveries()[0]['attempts']);

        self::assertSame(1, self::dispatch()['delivered'], 'the lock goes with the session that held it');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function overlapModes(): iterable
    {
        yield 'under the lock, as the cron runs it' => ['exclusive'];
        yield 'without it: the claim alone' => ['unlocked'];
    }

    /**
     * Two runs started together over a receiver that takes a fifth of a
     * second a request: every delivery reaches it once.
     *
     * @dataProvider overlapModes
     */
    public function testOverlappingRunsSendEachDeliveryOnce(string $mode): void
    {
        self::hook(LocalWebhookReceiver::url(200, 200));
        self::queue(12);
        $startAt = microtime(true) + 2.0;

        $runs = [];
        foreach ([1, 2] as $n) {
            $proc = proc_open(
                [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/dispatch-runner.php',
                    $mode, self::receiver()->base(), '30', sprintf('%.3F', $startAt)],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            self::assertIsResource($proc);
            $runs[$n] = [$proc, $pipes];
        }
        $reports = [];
        foreach ($runs as $n => [$proc, $pipes]) {
            $out = trim((string) stream_get_contents($pipes[1]));
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($proc), "run $n: $out $err");
            $reports[$n] = json_decode($out, true, 4, JSON_THROW_ON_ERROR);
        }

        $sent = array_map(static fn (array $r): ?string => $r['delivery'], self::receiver()->requests());
        sort($sent);
        $ids = array_map(static fn (array $r): ?string => $r['delivery_id'], self::deliveries());
        self::assertSame($ids, $sent, 'each delivery once: ' . json_encode($reports));
        foreach (self::deliveries() as $row) {
            self::assertSame('delivered/1', $row['status'] . '/' . $row['attempts']);
        }
        if ($mode === 'exclusive') {
            self::assertContains(null, $reports, 'one run found the lock held');
        } else {
            self::assertSame(12, $reports[1]['delivered'] + $reports[2]['delivered']);
            $taken = $reports[1]['taken'] + $reports[2]['taken'];
            self::assertGreaterThan(0, $taken, 'the runs met: ' . json_encode($reports));
        }
    }

    /**
     * A delivery still waiting a week after it was queued is not sent: it is
     * failed with the reason in its log, its webhook left active. The rest are
     * sent, oldest first; one a run has claimed is left to that run.
     */
    public function testAnUnsentDeliveryAWeekOldExpiresWithItsReason(): void
    {
        $hook = self::hook(LocalWebhookReceiver::url(200));
        self::queue(3);
        [$old, $recent, $claimed] = self::deliveries();
        $weekAgo = time() - WebhookDispatcher::MAX_AGE_SECONDS;
        self::q("UPDATE 202_ltv_webhook_deliveries SET created_at = $weekAgo - 60, next_attempt_at = $weekAgo - 60"
            . " WHERE delivery_id = {$old['delivery_id']}");
        self::q("UPDATE 202_ltv_webhook_deliveries SET created_at = $weekAgo + 3600, next_attempt_at = $weekAgo + 3600"
            . " WHERE delivery_id = {$recent['delivery_id']}");
        self::q("UPDATE 202_ltv_webhook_deliveries SET created_at = $weekAgo - 60, next_attempt_at = " . (time() + 300)
            . " WHERE delivery_id = {$claimed['delivery_id']}");

        $report = self::dispatch();

        self::assertSame(1, $report['expired'], WebhookDispatcher::summary($report));
        self::assertSame(1, $report['delivered']);
        $rows = self::deliveries();
        self::assertSame('failed', $rows[0]['status']);
        self::assertSame('0', $rows[0]['attempts'], 'never tried');
        self::assertNull($rows[0]['last_status_code']);
        self::assertSame(WebhookDispatcher::EXPIRED_REASON, $rows[0]['last_response_body']);
        self::assertSame('delivered', $rows[1]['status']);
        self::assertSame('pending', $rows[2]['status'], 'in flight in another run');
        self::assertSame([$recent['delivery_id']], array_column(self::receiver()->requests(), 'delivery'));
        self::assertSame('active', self::repo()->get(self::USER, $hook['webhookId'])['status'] ?? null);
        self::assertStringContainsString('7 days', WebhookDispatcher::EXPIRED_REASON);
        self::assertSame(7 * 86400, WebhookDispatcher::MAX_AGE_SECONDS);
    }

    /**
     * An upgrade's backlog is expired a thousand at a time; a run whose
     * deadline comes first sends nothing, since what is left to expire is
     * the oldest due, and the next run goes on.
     */
    public function testABacklogIsExpiredBeforeAnythingIsSent(): void
    {
        $hook = self::hook(LocalWebhookReceiver::url(200));
        $old = time() - WebhookDispatcher::MAX_AGE_SECONDS - 86400 * 90;
        self::q('INSERT INTO 202_ltv_webhook_deliveries (webhook_id, user_id, event_name, payload, status, attempts,'
            . ' next_attempt_at, created_at, updated_at)'
            . ' SELECT ' . $hook['webhookId'] . ', ' . self::USER
            . ", 'revenue.recorded', '{}', 'pending', 0, $old + seq, $old + seq, $old"
            . ' FROM (SELECT a.n * 100 + b.n * 10 + c.n AS seq FROM'
            . ' (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6'
            . ' UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12'
            . ' UNION SELECT 13 UNION SELECT 14) a,'
            . ' (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6'
            . ' UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b,'
            . ' (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6'
            . ' UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) c) s');
        self::queue();
        self::assertCount(1501, self::deliveries());
        $now = time();

        $first = WebhookDispatcher::runExclusive(self::conn(), $now - 1, self::toReceiver(), static fn (): int => $now);
        self::assertNotNull($first);
        self::assertSame(WebhookDispatcher::EXPIRE_BATCH, $first['expired'], WebhookDispatcher::summary($first));
        self::assertSame(0, $first['delivered']);
        self::assertTrue($first['more']);
        self::assertSame([], self::receiver()->requests());

        $second = self::dispatch();
        self::assertSame(500, $second['expired'], WebhookDispatcher::summary($second));
        self::assertSame(1, $second['delivered']);
        self::assertCount(1, self::receiver()->requests());
    }

    private static function connect(): \mysqli
    {
        return new \mysqli(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
    }
}
