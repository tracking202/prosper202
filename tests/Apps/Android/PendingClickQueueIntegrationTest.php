<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use Api\V3\Apps\Android\AndroidIntakeJob;
use Api\V3\Apps\Android\InstallReport;
use Api\V3\Apps\Android\InstallToken;
use Api\V3\Apps\Android\PendingClickSettler;
use Api\V3\Controllers\AppInstallsController;
use PHPUnit\Framework\TestCase;

/**
 * The pending-click settler's queue against a real server: every install a
 * run takes leaves the head of the next run's selection, whatever became of
 * it, so no number of installs that cannot settle (or that are still waiting
 * for their click) holds back one that can; an install whose stored referrer
 * or body can no longer be read is retired at the 24-hour deadline with the
 * reason, and a failure the settler cannot tell is the install's own (a
 * missing key, a database error) is retried and never retired.
 *
 * @group integration
 */
final class PendingClickQueueIntegrationTest extends TestCase
{
    use AndroidDatabase {
        setUp as androidSetUp;
    }

    private const U1 = '00000000-0000-4000-8000-000000000001';

    private const PENDING_INSERT = 'INSERT INTO 202_app_installs
        (user_id, registration_id, install_uuid, body_hash, store, match_state, match_reason, referrer_status,
         referrer_raw, received_at, raw_payload) VALUES ';

    /** @var string|false */
    private $errorLog = false;

    protected function setUp(): void
    {
        $this->androidSetUp();
        // The settler logs every install it fails on; hundreds here.
        $this->errorLog = ini_set('error_log', sys_get_temp_dir() . '/p202-android-queue-it-' . getmypid() . '.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLog === false ? '' : $this->errorLog);
        if (self::$db !== null) {
            self::$db->query('DROP TRIGGER IF EXISTS p202_plant');
        }
    }

    public function testMoreThanABatchOfInstallsThatCannotSettleDoesNotHoldBackOneThatCan(): void
    {
        $batch = self::cronBatch();
        // A batch and one more, all older than the install that can settle,
        // whose tokens were signed with another key (the install-token key
        // was replaced after they arrived).
        self::seedPending($batch + 1, 20000, random_bytes(32), $this->clock - 3600);
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(500)));
        $this->click(500);

        $settler = new PendingClickSettler(self::$db, fn (): int => $this->clock);
        $runs = [];
        for ($run = 1; $run <= 3 && self::installRow(self::U1)['match_state'] === 'pending_click'; $run++) {
            $runs[] = $settler->run($batch);
            $this->clock += 60;
        }
        self::assertSame(
            'attributed',
            self::installRow(self::U1)['match_state'],
            'the install that can settle is reached however many cannot are ahead of it: ' . json_encode($runs)
        );
        self::assertCount(2, $runs, 'each install is tried once before any is tried again');
        self::assertSame(self::report($batch, [], 0, $batch, 0), $runs[0]);
        self::assertSame(
            self::report($batch, ['attributed' => 1], 0, $batch - 1, 0),
            $runs[1],
            'the two the first run did not reach come first, then the ones it tried'
        );
        self::assertSame(
            $batch + 1,
            self::rows('202_app_installs', "match_state = 'pending_click' AND settle_failures > 0"),
            'the ones that cannot settle are still pending, each with its failures counted'
        );
        self::assertSame(1, self::rows('202_conversion_logs'), 'and the one that could is paid');
    }

    public function testInstallsStillWaitingForTheirClickTakeTurns(): void
    {
        $batch = self::cronBatch();
        // Tokens this server signed, for clicks that were never written.
        self::seedPending($batch + 1, 20000, self::key(), $this->clock - 3600);
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(500)));
        $this->click(500);

        $settler = new PendingClickSettler(self::$db, fn (): int => $this->clock);
        self::assertSame(self::report($batch, [], $batch, 0, 0), $settler->run($batch));
        $this->clock += 60;
        self::assertSame(
            ['attributed' => 1],
            $settler->run($batch)['settled'],
            'the second run starts with the installs the first did not reach, not the same waiting ones'
        );
        self::assertSame('attributed', self::installRow(self::U1)['match_state']);
        $failing = self::rows('202_app_installs', 'settle_failures > 0');
        self::assertSame(0, $failing, 'waiting for a click is not a failure');
    }

    public function testAnInstallThatCannotBeReadIsRetiredAtTheDeadlineWithWhy(): void
    {
        $received = $this->clock;
        self::seedPending(2, 20000, random_bytes(32), $received);
        // A body cut off part way through its write.
        $cut = '{"install_uuid": "00000000-0000-4000-8000-0000000000bd", "app_k';
        self::seedRaw('00000000-0000-4000-8000-0000000000bd', $cut, $received);
        // And one that parses, but as no install this version reads.
        self::seedRaw('00000000-0000-4000-8000-0000000000be', '{"app_key": "com.example.summit"}', $received);
        $settler = new PendingClickSettler(self::$db, fn (): int => $this->clock);

        self::assertSame(self::report(4, [], 0, 4, 0), $settler->run());
        self::assertSame([[1, $this->clock]], self::queue(), 'a first failure is tried again on the next run');
        $this->clock += 60;
        self::assertSame(4, $settler->run()['failed']);
        $due = $this->clock + PendingClickSettler::FIRST_BACKOFF;
        self::assertSame([[2, $due]], self::queue(), 'later ones back off');
        $this->clock += 30;
        self::assertSame(0, $settler->run()['examined'], 'and are not taken before they are due');

        $this->clock = $received + 86400 + 5;
        self::assertSame(self::report(4, [], 0, 0, 4), $settler->run());
        $rows = self::$db->query(
            'SELECT install_uuid, match_state, trusted, settled_at, match_reason, settle_failures
             FROM 202_app_installs ORDER BY install_row_id'
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $row) {
            self::assertSame(
                ['bad_token', '0', (string) $this->clock, '2'],
                [
                    $row['match_state'],
                    (string) $row['trusted'],
                    (string) $row['settled_at'],
                    (string) $row['settle_failures'],
                ],
                'retired as the deadline leaves an install whose click never appeared, with its failures kept'
            );
            self::assertStringContainsString('within 24 hours', $row['match_reason']);
            self::assertStringNotContainsString('…', $row['match_reason'], 'the reason fits its column whole');
        }
        self::assertStringContainsString(
            'key now reads it as bad_token (The p202 token\'s signature does not verify',
            $rows[0]['match_reason'],
            'the token that no longer verifies says so'
        );
        self::assertStringContainsString('stored body is not JSON', $rows[2]['match_reason'], 'a body cut off says so');
        self::assertStringContainsString(
            'its stored body no longer reads as an install (install_uuid, referrer, store).',
            $rows[3]['match_reason'],
            'and the body that is no install names what it lacks'
        );
        self::assertSame(0, self::rows('202_goal_subjects'), 'a retired install reaches no goal');
        self::assertSame(self::report(0, [], 0, 0, 0), $settler->run());
    }

    public function testAMissingKeyIsRetriedAndNeverRetires(): void
    {
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(500)));
        $this->click(500);
        $key = self::$db->query(
            "SELECT secret_value FROM 202_deployment_secrets WHERE secret_name = 'android_install_token'"
        )->fetch_assoc()['secret_value'];
        self::fixture("DELETE FROM 202_deployment_secrets WHERE secret_name = 'android_install_token'");
        $settler = new PendingClickSettler(self::$db, fn (): int => $this->clock);
        try {
            $this->clock += 2 * 86400;
            for ($i = 0; $i < 3; $i++) {
                self::assertSame(
                    self::report(1, [], 0, 1, 0),
                    $settler->run(),
                    'no key is the server\'s state, not the install\'s: never a reason to retire it'
                );
                $this->clock += 3600;
            }
            $row = self::installRow(self::U1);
            self::assertSame(['pending_click', '3'], [$row['match_state'], (string) $row['settle_failures']]);
        } finally {
            self::fixture("INSERT IGNORE INTO 202_deployment_secrets
                SET secret_name = 'android_install_token', secret_value = '$key', created_at = 1");
        }
        self::assertSame(['attributed' => 1], $settler->run()['settled'], 'with the key back it settles as it would');
    }

    public function testADatabaseErrorIsRetriedAndNeverRetires(): void
    {
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(500)));
        $this->click(500);
        self::fixture("CREATE TRIGGER p202_plant BEFORE INSERT ON 202_conversion_logs FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'planted: the ledger is unavailable'");
        $settler = new PendingClickSettler(self::$db, fn (): int => $this->clock);
        $this->clock += 2 * 86400;
        self::assertSame(self::report(1, [], 0, 1, 0), $settler->run());
        $row = self::installRow(self::U1);
        self::assertSame(['pending_click', '1'], [$row['match_state'], (string) $row['settle_failures']]);
        self::fixture('DROP TRIGGER p202_plant');
        self::assertSame(['attributed' => 1], $settler->run()['settled']);
        self::assertSame(1, self::rows('202_conversion_logs'));
    }

    public function testInstallsFailingToSettleAreCountedWhereInstallsAreRead(): void
    {
        self::seedPending(2, 20000, random_bytes(32), $this->clock);
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(500)));
        (new PendingClickSettler(self::$db, fn (): int => $this->clock))->run();

        $totals = (new InstallReport(self::$db, 1))->report(['group_by' => 'match-state'])['totals'];
        $counts = [(int) $totals['pending'], (int) $totals['pending_failing']];
        self::assertSame([3, 2], $counts, (string) json_encode($totals));

        $list = (new AppInstallsController(self::$db, 1))->list(5, ['match_state' => 'pending_click']);
        $failures = array_column($list['data'], 'settle_failures', 'install_uuid');
        ksort($failures);
        self::assertSame([0, 1, 1], array_values($failures), 'each install says how often settling it failed in a row');
        self::assertIsInt($list['data'][0]['settle_next_at']);
    }

    /**
     * A run's report, as PendingClickSettler::run() answers it.
     *
     * @param array<string, int> $settled
     * @return array{examined: int, settled: array<string, int>, still_pending: int, failed: int, retired: int}
     */
    private static function report(int $examined, array $settled, int $stillPending, int $failed, int $retired): array
    {
        return [
            'examined' => $examined,
            'settled' => $settled,
            'still_pending' => $stillPending,
            'failed' => $failed,
            'retired' => $retired,
        ];
    }

    /** The batch the cron settles in: AndroidIntakeJob::runExclusive()'s own default. */
    private static function cronBatch(): int
    {
        $limit = array_values(array_filter(
            (new \ReflectionMethod(AndroidIntakeJob::class, 'runExclusive'))->getParameters(),
            static fn (\ReflectionParameter $p): bool => $p->getName() === 'settleLimit'
        ));
        self::assertCount(1, $limit, 'AndroidIntakeJob::runExclusive() takes the settler\'s batch as settleLimit');

        return (int) $limit[0]->getDefaultValue();
    }

    /**
     * $n installs of registration 5 left pending_click, received a second
     * apart from $receivedAt, the i-th naming click $firstClick + i under a
     * token signed with $key.
     */
    private static function seedPending(int $n, int $firstClick, string $key, int $receivedAt): void
    {
        $values = [];
        for ($i = 0; $i < $n; $i++) {
            $uuid = sprintf('%08x-0000-4000-8000-%012d', 0xa0000000 + $firstClick, $i);
            $referrer = 'p202=' . InstallToken::forClick($firstClick + $i, $key);
            $body = (string) json_encode(self::body($uuid, $referrer));
            $values[] = self::pendingValues($uuid, $body, $referrer, $firstClick + $i, $receivedAt + $i);
        }
        foreach (array_chunk($values, 250) as $chunk) {
            self::fixture(self::PENDING_INSERT . implode(', ', $chunk));
        }
    }

    /** One pending_click install whose stored body is $rawPayload as given. */
    private static function seedRaw(string $uuid, string $rawPayload, int $receivedAt): void
    {
        self::fixture(self::PENDING_INSERT . self::pendingValues($uuid, $rawPayload, '', 0, $receivedAt));
    }

    private static function pendingValues(
        string $uuid,
        string $rawPayload,
        string $referrer,
        int $clickId,
        int $receivedAt
    ): string {
        $q = static fn (string $v): string => "'" . self::$db->real_escape_string($v) . "'";
        $reason = 'Click ' . $clickId . ' has not been recorded yet; the install is settled when it is.';

        return '(1, 5, ' . $q($uuid) . ', ' . $q(hash('sha256', $rawPayload)) . ", 'google_play', 'pending_click', "
            . $q($reason) . ", 'ok', " . $q($referrer) . ', ' . $receivedAt . ', ' . $q($rawPayload) . ')';
    }

    /** @return list<array{0: int, 1: int}> the distinct (settle_failures, settle_next_at) of the pending installs */
    private static function queue(): array
    {
        $rows = self::$db->query(
            "SELECT DISTINCT settle_failures, settle_next_at FROM 202_app_installs WHERE match_state = 'pending_click'"
        )->fetch_all(MYSQLI_NUM);

        return array_map(static fn (array $r): array => [(int) $r[0], (int) $r[1]], $rows);
    }
}
