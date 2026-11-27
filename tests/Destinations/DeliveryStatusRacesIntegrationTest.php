<?php

declare(strict_types=1);

namespace Tests\Destinations;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;
use Prosper202\Conversion\Ledger\MatchMethod;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use Prosper202\Destinations\DeliveryRows;
use Prosper202\Destinations\DeliveryStatus;
use Prosper202\Destinations\DestinationQueue;
use Prosper202\Destinations\DestinationStore;
use Prosper202\Destinations\Ga4Destination;
use Prosper202\Destinations\SendResult;
use Prosper202\Destinations\WithheldFor;
use Prosper202\Lifecycle\IllegalTransition;
use Tests\Apps\Android\AndroidDatabase;

/**
 * What a sender writes when the row moved while it was sending, against a
 * real server. The window is the send itself: the transport runs the other
 * actor (a delete, a second worker) before it answers, so the sender's last
 * write always meets a row it did not leave.
 *
 * @group integration
 */
final class DeliveryStatusRacesIntegrationTest extends TestCase
{
    use AndroidDatabase {
        setUp as private databaseSetUp;
    }

    /** @var (\Closure(): void)|null run once, inside the first send */
    private ?\Closure $during = null;
    /** @var list<array<string, mixed>> answers the transport gives, in order (then 204) */
    private array $answers = [];
    private int $sends = 0;

    protected function setUp(): void
    {
        $this->databaseSetUp();
        foreach (['202_destinations', '202_destination_deliveries', '202_clicks_advance', '202_clicks_site', '202_site_urls', '202_ips', '202_ips_v6'] as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        self::fixture('DELETE FROM 202_currency_changes WHERE user_id = 1');
        self::fixture('DELETE FROM 202_users WHERE user_id = 1');
        self::fixture("INSERT INTO 202_users SET user_id=1, user_name='store-owner', user_email='owner@example.com', user_active=1, user_deleted=0");
        self::fixture('DELETE FROM 202_users_pref WHERE user_id = 1');
        self::fixture("INSERT INTO 202_users_pref SET user_id=1, user_pref_privacy='disabled', user_account_currency='USD'");
        $this->campaign(31, null);
        $this->during = null;
        $this->answers = [];
        $this->sends = 0;
    }

    private function store(): DestinationStore
    {
        $guard = new WebhookGuard(static fn (string $host): array => ['203.0.113.10'], '203.0.113.0/24');
        $transport = function (array $options): array {
            $this->sends++;
            $answer = array_shift($this->answers) ?? [];
            $during = $this->during;
            $this->during = null;
            if ($during !== null) {
                $during();
            }

            return $answer + ['status' => 204, 'error' => null, 'primary_ip' => '203.0.113.10', 'location' => null, 'body' => ''];
        };

        return new DestinationStore(new Connection(self::$db), [Ga4Destination::TYPE => new Ga4Destination(new WebhookSender($guard, $transport))]);
    }

    private function worker(): DestinationQueue
    {
        return new DestinationQueue(new Connection(self::$db), $this->store(), static fn (string $ip): bool => false);
    }

    /** A destination with one conversion queued for it. */
    private function queued(): int
    {
        $id = (int) $this->store()->create(1, Ga4Destination::TYPE, 'Store GA4', ['measurement_id' => 'G-ABC123'], ['api_secret' => 'Sx9-secretAPIvalue'], time())['destination_id'];
        $this->click(100);
        (new MysqlConversionRepository(new Connection(self::$db)))->record(1, [
            'click_id' => 100, 'payout' => '19.90', 'transaction_id' => 'ORD-1', 'source' => 'api',
        ], MatchMethod::CLICK_ID);
        self::assertSame(DeliveryStatus::Pending->value, self::row($id)['status'], 'the positive control: one row queued');

        return $id;
    }

    /** @return array<string, mixed> */
    private static function row(int $destinationId): array
    {
        $rows = self::$db->query("SELECT status, attempts, last_error, sent_at FROM 202_destination_deliveries WHERE destination_id = $destinationId")->fetch_all(MYSQLI_ASSOC);
        self::assertCount(1, $rows);

        return $rows[0];
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function answersThatDidNotDeliver(): array
    {
        return [
            'asked to try again' => [['status' => 503, 'body' => 'busy']],
            'refused' => [['status' => 400, 'body' => 'no']],
        ];
    }

    /**
     * @dataProvider answersThatDidNotDeliver
     * @param array<string, mixed> $answer
     */
    public function testADeliveryCancelledWhileItWasBeingSentStaysCancelledWhenTheSendDidNotDeliver(array $answer): void
    {
        $id = $this->queued();
        $this->answers = [$answer];
        $deleted = false;
        $this->during = function () use ($id, &$deleted): void {
            $deleted = $this->store()->delete(1, $id, time());
        };

        $out = $this->worker()->sendDue(10, 5, time() + 1);

        self::assertTrue($deleted, 'the destination was deleted inside the send');
        self::assertSame(1, $this->sends);
        $row = self::row($id);
        self::assertSame(DeliveryStatus::Cancelled->value, $row['status'], 'a cancelled row is not put back: nothing would ever read it again');
        self::assertSame('the destination was deleted before this was sent', $row['last_error']);
        self::assertSame(0, array_sum($out), 'the run answers for no row it did not write');
    }

    public function testADeliveryThatReachedThePlatformBeforeItsDestinationWasDeletedIsRecordedAsSent(): void
    {
        $id = $this->queued();
        $this->during = function () use ($id): void {
            self::assertTrue($this->store()->delete(1, $id, time()));
        };
        $now = time() + 1;

        $out = $this->worker()->sendDue(10, 5, $now);

        self::assertSame(1, $out['sent']);
        $row = self::row($id);
        self::assertSame([DeliveryStatus::Sent->value, (string) $now, null], [$row['status'], $row['sent_at'], $row['last_error']]);
    }

    /**
     * A send outlives its claim (the try's backoff), a later run sends the
     * row, and then the first send's answer comes back.
     */
    public function testASentDeliveryIsNotPutBackByASlowerSendOfTheSameRow(): void
    {
        $id = $this->queued();
        $now = time() + 1;
        $this->answers = [['status' => 503, 'body' => 'busy']];
        $second = [];
        $this->during = function () use (&$second, $now): void {
            $second = $this->worker()->sendDue(10, 5, $now + 61);
        };

        $out = $this->worker()->sendDue(10, 5, $now);

        self::assertSame(1, $second['sent'] ?? null, 'the later run claimed the row and sent it');
        self::assertSame(2, $this->sends);
        $row = self::row($id);
        self::assertSame([DeliveryStatus::Sent->value, (string) ($now + 61)], [$row['status'], $row['sent_at']], 'sent is final');
        self::assertSame(0, array_sum($out));
        self::assertSame(0, array_sum($this->worker()->sendDue(10, 5, $now + 99999)), 'and it is not sent a third time');
        self::assertSame(2, $this->sends);
    }

    /** The slower send delivered too: the row stays sent, with the send that wrote it first. */
    public function testTwoSendsOfOneRowThatBothDeliveredLeaveOneSentRow(): void
    {
        $id = $this->queued();
        $now = time() + 1;
        $this->during = function () use ($now): void {
            self::assertSame(1, $this->worker()->sendDue(10, 5, $now + 61)['sent']);
        };

        $out = $this->worker()->sendDue(10, 5, $now);

        self::assertSame([DeliveryStatus::Sent->value, (string) ($now + 61)], [self::row($id)['status'], self::row($id)['sent_at']]);
        self::assertSame(0, array_sum($out));
    }

    /** A wait is not a try, and it is not a way back either. */
    public function testAWaitDoesNotUndoALaterClaim(): void
    {
        $id = $this->queued();
        $now = time() + 1;
        self::fixture("UPDATE 202_destination_deliveries SET attempts = 3 WHERE destination_id = $id");
        $queue = new DeliveryRows(new Connection(self::$db));
        $deliveryId = (int) self::$db->query("SELECT delivery_id FROM 202_destination_deliveries WHERE destination_id = $id")->fetch_row()[0];
        self::assertTrue($queue->claim(1, $deliveryId, 3, $now));
        self::assertFalse($queue->claim(1, $deliveryId, 3, $now), 'a claim is taken once');
        self::assertTrue($queue->claim(1, $deliveryId, 4, $now + 9999), 'the later claim');

        self::assertFalse($queue->wait(1, $deliveryId, 4, new SendResult(false, true, null, 'wait'), $now + 50), 'the first claim is no longer the row\'s');
        self::assertSame('5', self::row($id)['attempts']);
        self::assertTrue($queue->wait(1, $deliveryId, 5, new SendResult(false, true, null, 'wait'), $now + 50));
        self::assertSame(['4', DeliveryStatus::Pending->value], [self::row($id)['attempts'], self::row($id)['status']]);
    }

    public function testWhatASendThatDidNotDeliverWritesIsHeldToItsClaimAndADeliveryIsNot(): void
    {
        $id = $this->queued();
        $now = time() + 1;
        $rows = new DeliveryRows(new Connection(self::$db));
        $deliveryId = (int) self::$db->query("SELECT delivery_id FROM 202_destination_deliveries WHERE destination_id = $id")->fetch_row()[0];
        self::assertTrue($rows->claim(1, $deliveryId, 0, $now));
        self::assertTrue($rows->claim(1, $deliveryId, 1, $now + 9999), 'the claim of a later run');

        self::assertFalse($rows->finish(1, $deliveryId, DeliveryStatus::Failed, 1, 400, 'no', null, $now), 'the first claim no longer holds the row');
        self::assertFalse($rows->retryLater(1, $deliveryId, 1, 503, 'busy', null));
        self::assertSame([DeliveryStatus::Pending->value, '2', null], [self::row($id)['status'], self::row($id)['attempts'], self::row($id)['last_error']]);

        self::assertTrue($rows->retryLater(1, $deliveryId, 2, 503, 'busy', null));
        self::assertTrue($rows->retryLater(1, $deliveryId, 2, 503, 'busy', null), 'the same answer twice changes no row and the claim still holds');
        self::assertTrue($rows->finish(1, $deliveryId, DeliveryStatus::Failed, 2, 400, 'no', null, $now));
        self::assertFalse($rows->finish(1, $deliveryId, DeliveryStatus::Withheld, 2, null, 'withheld: x', null, $now), 'a failed row is not withheld');
        self::assertFalse($rows->finish(1, $deliveryId, DeliveryStatus::Cancelled, null, null, 'gone', null, $now), 'nor cancelled');
        self::assertSame([DeliveryStatus::Failed->value, 'no'], [self::row($id)['status'], self::row($id)['last_error']]);

        self::assertTrue($rows->finish(1, $deliveryId, DeliveryStatus::Sent, 1, 204, '', null, $now + 5), 'the first send reached the platform after all');
        self::assertSame([DeliveryStatus::Sent->value, (string) ($now + 5), null], [self::row($id)['status'], self::row($id)['sent_at'], self::row($id)['last_error']]);
        self::assertSame(0, $rows->resendOne(1, $id, $deliveryId, $now), 'and a sent row is never queued again');
        self::assertSame(0, $rows->cancelForDeletedDestination(1, $id));
        self::assertSame(DeliveryStatus::Sent->value, self::row($id)['status']);
    }

    /** A second connection to the scratch database: the other actor of a race. */
    private static function otherHandle(): \mysqli
    {
        $other = mysqli_connect(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        self::assertInstanceOf(\mysqli::class, $other);

        return $other;
    }

    /**
     * The delete cancels what is pending when it commits; a conversion whose
     * transaction read before that queues its row after, for a destination
     * that is gone. The window is the recording transaction: here it has
     * read (as record() has by the time it queues) before the delete commits
     * on a second connection.
     */
    public function testAConversionQueuedWhileItsDestinationWasDeletedIsCancelledByTheNextRun(): void
    {
        $id = (int) $this->store()->create(1, Ga4Destination::TYPE, 'Store GA4', ['measurement_id' => 'G-ABC123'], ['api_secret' => 'Sx9-secretAPIvalue'], time())['destination_id'];
        $recording = new Connection(self::$db);
        $other = self::otherHandle();
        $deleted = false;

        $queued = $recording->transaction(function () use ($recording, $other, $id, &$deleted): int {
            self::$db->query('SELECT COUNT(*) FROM 202_destinations')->fetch_row();
            $deleted = (new DestinationStore(new Connection($other)))->delete(1, $id, time());

            return (new DestinationQueue($recording))->queueConversion(1, 900, 31, time());
        });
        $other->close();

        self::assertTrue($deleted, 'the delete committed inside the recording transaction');
        self::assertSame(1, $queued, 'the recording transaction still read the destination as live');
        self::assertSame('1', self::$db->query("SELECT COUNT(*) FROM 202_destinations WHERE destination_id = $id AND deleted_at IS NOT NULL")->fetch_row()[0]);
        self::assertSame(DeliveryStatus::Pending->value, self::row($id)['status'], 'the row the delete could not see');

        $out = $this->worker()->sendDue(10, 5, time() + 1);

        self::assertSame(0, $this->sends, 'nothing is sent for a deleted destination');
        self::assertSame([DeliveryStatus::Cancelled->value, DeliveryRows::DESTINATION_DELETED], [self::row($id)['status'], self::row($id)['last_error']]);
        self::assertSame(1, $out['cancelled']);
    }

    /** No race at all: a row the delete left withheld is released to pending when the owner records a currency. */
    public function testAWithheldDeliveryReleasedAfterItsDestinationWasDeletedIsCancelledByTheNextRun(): void
    {
        $id = $this->queued();
        $reason = self::$db->real_escape_string(WithheldFor::NoCurrency->value);
        self::fixture("UPDATE 202_destination_deliveries SET status = 'withheld', last_error = '$reason' WHERE destination_id = $id");
        self::assertTrue($this->store()->delete(1, $id, time()));
        self::assertSame(DeliveryStatus::Withheld->value, self::row($id)['status'], 'a delete cancels only what is pending');
        self::assertSame(1, $this->worker()->releaseHeldForCurrency(1, time()));
        self::assertSame(DeliveryStatus::Pending->value, self::row($id)['status']);

        $out = $this->worker()->sendDue(10, 5, time() + 1);

        self::assertSame(0, $this->sends);
        self::assertSame([DeliveryStatus::Cancelled->value, DeliveryRows::DESTINATION_DELETED], [self::row($id)['status'], self::row($id)['last_error']]);
        self::assertSame(1, $out['cancelled']);
    }

    public function testACancelledDeliveryIsNotQueuedAgain(): void
    {
        $id = $this->queued();
        $rows = new DeliveryRows(new Connection(self::$db));
        $deliveryId = (int) self::$db->query("SELECT delivery_id FROM 202_destination_deliveries WHERE destination_id = $id")->fetch_row()[0];
        self::assertSame(1, $rows->cancelForDeletedDestination(1, $id));
        self::assertSame(0, $rows->cancelForDeletedDestination(1, $id), 'once');

        self::assertSame(0, $rows->resendOne(1, $id, $deliveryId, time()));
        self::assertFalse($rows->claim(1, $deliveryId, 0, time()));
        self::assertSame([DeliveryStatus::Cancelled->value, DeliveryRows::DESTINATION_DELETED], [self::row($id)['status'], self::row($id)['last_error']]);
        try {
            $rows->resendAll(1, $id, DeliveryStatus::Cancelled, 0, 10, time());
            self::fail('a bulk send-again from cancelled is refused');
        } catch (IllegalTransition $e) {
            self::assertStringContainsString('cancelled may not become pending', $e->getMessage());
        }
        $this->expectException(\LogicException::class);
        $rows->finish(1, $deliveryId, DeliveryStatus::Pending, null, null, '', null, time());
    }
}
