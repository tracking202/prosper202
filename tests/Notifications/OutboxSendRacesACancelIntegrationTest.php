<?php

declare(strict_types=1);

namespace Tests\Notifications;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Notifications\NotificationOutbox;
use Tests\Apps\Android\AndroidDatabase;

/**
 * A send in flight while its row is cancelled (NotificationStatus): the
 * worker claimed a retrying retraction, and before the traffic source
 * answered the outcome was revived, which cancels the retraction and
 * records the correction that restores the value.
 *
 * The window is widened by the sender itself: the worker's fetch runs the
 * revival on a second connection before it answers, so the cancel always
 * lands between the claim and the worker's mark.
 *
 * @group integration
 */
final class OutboxSendRacesACancelIntegrationTest extends TestCase
{
    use AndroidDatabase;

    private const CONV = 1000;
    private const PIXEL = 124;
    private const FIX = 'https://ts.example/fix?v=[[p202_goal_value]]&k=[[p202_notification]]';
    private const RETRACTION = 'https://ts.example/fix?v=0.00&k=retraction';
    private const CORRECTION = 'https://ts.example/fix?v=4.00&k=correction';

    private ?\mysqli $other = null;

    protected function tearDown(): void
    {
        $this->other?->close();
        $this->other = null;
    }

    private function outbox(\mysqli $db, callable $fetch): NotificationOutbox
    {
        return new NotificationOutbox(new Connection($db), fn (): int => $this->clock, $fetch,
            static fn (int $pixel, int $destination): ?string => self::FIX);
    }

    /** A second session, as another request or cron run holds. */
    private function otherSession(): \mysqli
    {
        if ($this->other === null) {
            $this->other = new \mysqli(
                (string) getenv('P202_TEST_DB_HOST'),
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        }

        return $this->other;
    }

    /** The outcome was announced and then retired: its retraction is queued, after $attempts failed tries. */
    private function retractionRetrying(int $attempts): void
    {
        $this->click(100);
        self::fixture('DELETE FROM 202_ppc_account_pixels WHERE ppc_account_id = 70 AND pixel_type_id = 4');
        self::fixture('INSERT INTO 202_ppc_account_pixels SET pixel_id=' . self::PIXEL . ", ppc_account_id=70, pixel_type_id=4, pixel_code='https://ts.example/pb?s=[[subid]]'");
        self::fixture('INSERT INTO 202_conversion_logs SET conv_id = ' . self::CONV . ", click_id = 100, campaign_id = 30, user_id = 1, click_payout = 4.00000,
            click_time = 1, conv_time = 1, source = 'goal', dedupe_key = 'goal:1:1:1:e1', payable = 1");
        $outbox = $this->outbox(self::$db, static fn (string $u): bool => true);
        self::assertSame(1, $outbox->queueReached(1, self::CONV, 100, 'Level 3', '4.00000', 'goal:1:1', 'tx'));
        self::assertSame(1, $outbox->sendDue(10)['sent']);
        $outbox->onReplaced(1, self::CONV, null);
        self::fixture("UPDATE 202_notification_pending SET attempts = $attempts WHERE kind = 'retraction'");
        self::assertSame(['reached:sent:1', "retraction:pending:$attempts"], self::rowsNow());
    }

    /** @return list<string> kind:status:attempts, in id order */
    private static function rowsNow(): array
    {
        return array_map(
            static fn (array $r): string => $r['kind'] . ':' . $r['status'] . ':' . $r['attempts'],
            self::$db->query('SELECT kind, status, attempts FROM 202_notification_pending ORDER BY notification_id')->fetch_all(MYSQLI_ASSOC)
        );
    }

    /** The outcome counts again, decided by another request while the worker waits for the traffic source. */
    private function reviveElsewhere(): void
    {
        $conn = new Connection($this->otherSession());
        $outbox = new NotificationOutbox($conn, fn (): int => $this->clock, static fn (string $u): bool => true,
            static fn (int $pixel, int $destination): ?string => self::FIX);
        $conn->transaction(static function () use ($outbox): void {
            $outbox->onRevived(1, self::CONV);
        });
    }

    public function testARetractionCancelledWhileItsSendWasInFlightIsNeverSentAgain(): void
    {
        $this->retractionRetrying(1);
        $sent = [];
        $worker = $this->outbox(self::$db, function (string $url) use (&$sent): bool {
            $sent[] = $url;
            if ($url === self::RETRACTION && count(array_keys($sent, self::RETRACTION, true)) === 1) {
                $this->reviveElsewhere();

                return false;
            }

            return true;
        });

        $this->clock += NotificationOutbox::backoff(1);
        $worker->sendDue(10);
        $afterTheRace = self::rowsNow()[1];

        // The cron's next runs, a minute apart: the correction goes out, and
        // the retraction's own backoff ends after it.
        for ($run = 0; $run < 4; $run++) {
            $this->clock += 60;
            $worker->sendDue(10);
        }
        self::assertSame([self::RETRACTION, self::CORRECTION], $sent,
            'the retraction is not sent after the correction that restores the value');
        self::assertSame('retraction:cancelled:2', $afterTheRace, 'the failed send does not put a cancelled retraction back in the queue');
        self::assertSame(['reached:sent:1', 'retraction:cancelled:2', 'correction:sent:1'], self::rowsNow());
    }

    public function testACancelledRetractionWhoseLastAttemptFailsStaysCancelled(): void
    {
        $this->retractionRetrying(NotificationOutbox::MAX_ATTEMPTS - 1);
        $worker = $this->outbox(self::$db, function (string $url): bool {
            if ($url === self::RETRACTION) {
                $this->reviveElsewhere();

                return false;
            }

            return true;
        });
        $this->clock += NotificationOutbox::backoff(NotificationOutbox::MAX_ATTEMPTS);
        $worker->sendDue(10);
        self::assertSame('retraction:cancelled:' . NotificationOutbox::MAX_ATTEMPTS, self::rowsNow()[1]);
    }

    /** The network has it: the row says so, whatever was decided meanwhile (CLAUDE.md #13). */
    public function testASendThatLandedWhileItsRowWasCancelledIsRecordedSent(): void
    {
        $this->retractionRetrying(1);
        $worker = $this->outbox(self::$db, function (string $url): bool {
            if ($url === self::RETRACTION) {
                $this->reviveElsewhere();
            }

            return true;
        });
        $this->clock += NotificationOutbox::backoff(1);
        $worker->sendDue(10);
        self::assertSame('retraction:sent:2', self::rowsNow()[1]);
        self::assertSame((string) $this->clock, self::$db->query("SELECT sent_at FROM 202_notification_pending WHERE kind = 'retraction'")->fetch_row()[0]);

        $this->clock += NotificationOutbox::backoff(1);
        $worker->sendDue(10);
        self::assertSame(['reached:sent:1', 'retraction:sent:2', 'correction:sent:1'], self::rowsNow(), 'and the correction follows it');
    }

    /**
     * While the retraction's send may still be in flight its answer is not
     * known, and a correction sent now could land before it: the traffic
     * source would end at 0.
     */
    public function testTheCorrectionWaitsUntilTheRetractionInFlightHasEnded(): void
    {
        $this->retractionRetrying(1);
        $meanwhile = null;
        $worker = $this->outbox(self::$db, function (string $url) use (&$meanwhile): bool {
            if ($url === self::RETRACTION) {
                $this->reviveElsewhere();
                // Another cron run, before this send has an answer.
                $sent = [];
                $this->outbox($this->otherSession(), static function (string $u) use (&$sent): bool {
                    $sent[] = $u;

                    return true;
                })->sendDue(10);
                $meanwhile = $sent;
            }

            return true;
        });
        $this->clock += NotificationOutbox::backoff(1);
        $worker->sendDue(10);
        self::assertSame([], $meanwhile, 'the correction is not sent while the retraction is in flight');
        self::assertSame('correction:pending:0', self::rowsNow()[2]);

        $this->clock += NotificationOutbox::backoff(1);
        self::assertSame(1, $worker->sendDue(10)['sent'], 'and goes out once that send must have ended');
        self::assertSame('correction:sent:1', self::rowsNow()[2]);
    }

    /** A retraction that was not claimed lately has no send in flight: its correction is due at once. */
    public function testACorrectionForARetractionBackingOffIsDueAtOnce(): void
    {
        $this->retractionRetrying(1);
        $worker = $this->outbox(self::$db, static fn (string $url): bool => $url !== self::RETRACTION);
        $this->clock += NotificationOutbox::backoff(1);
        $worker->sendDue(10);
        self::assertSame('retraction:pending:2', self::rowsNow()[1]);

        $this->clock += NotificationOutbox::backoff(1);
        $this->reviveElsewhere();
        self::assertSame(1, $worker->sendDue(10)['sent']);
        self::assertSame(['reached:sent:1', 'retraction:cancelled:2', 'correction:sent:1'], self::rowsNow());
    }
}
