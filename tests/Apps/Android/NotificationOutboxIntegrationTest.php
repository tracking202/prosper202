<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Notifications\NotificationOutbox;

/**
 * The outbox's delivery unit and its once-per-outcome table, against a real
 * server (plan §5.10, "Notifications" and "Once per outcome").
 *
 * The rows are written through queueReached() and moved through the states
 * a worker leaves them in by UPDATE, so each case reads what onReplaced()
 * and sendDue() do with a given state rather than how the state came about;
 * InstallIntakeIntegrationTest drives the same through the goal engine.
 *
 * @group integration
 */
final class NotificationOutboxIntegrationTest extends TestCase
{
    use AndroidDatabase;

    private const OLD = 1000;
    private const NEW = 2000;
    private const FIX = 'https://ts.example/fix?v=[[p202_goal_value]]&was=[[p202_previous_value]]&k=[[p202_notification]]';

    private function outboxWith(callable $fetch, ?string $correctionUrl = null): NotificationOutbox
    {
        return new NotificationOutbox(new Connection(self::$db), fn (): int => $this->clock, $fetch,
            $correctionUrl === null ? null : static fn (int $pixel, int $destination): ?string => $correctionUrl);
    }

    /** Replace account 70's server pixels with these (pixel id => code). */
    private static function pixels(array $codes): void
    {
        self::fixture('DELETE FROM 202_ppc_account_pixels WHERE ppc_account_id = 70 AND pixel_type_id = 4');
        foreach ($codes as $id => $code) {
            self::fixture("INSERT INTO 202_ppc_account_pixels SET pixel_id=$id, ppc_account_id=70, pixel_type_id=4, pixel_code='" . self::$db->real_escape_string($code) . "'");
        }
    }

    private function queue(int $convId): int
    {
        return $this->outboxWith(static fn (string $u): bool => true)
            ->queueReached(1, $convId, 100, 'Level 3', '4.00000', 'goal:1:1', 'tx-' . $convId);
    }

    /** @return array<int, array{status: string, attempts: int}> reached rows of one conversion, by pixel */
    private static function reachedByPixel(int $convId): array
    {
        $out = [];
        foreach (self::$db->query("SELECT pixel_id, status, attempts FROM 202_notification_pending WHERE conv_id = $convId AND kind = 'reached'")->fetch_all(MYSQLI_ASSOC) as $r) {
            $out[(int) $r['pixel_id']] = ['status' => (string) $r['status'], 'attempts' => (int) $r['attempts']];
        }
        ksort($out);

        return $out;
    }

    /** @return list<int> pixels that have a suppressed row of this kind on this conversion */
    private static function suppressed(int $convId, string $kind): array
    {
        $ids = array_map('intval', array_column(self::$db->query(
            "SELECT pixel_id FROM 202_notification_pending WHERE conv_id = $convId AND kind = '$kind' AND status = 'suppressed' ORDER BY pixel_id"
        )->fetch_all(MYSQLI_ASSOC), 'pixel_id'));

        return $ids;
    }

    public function testEachUrlOfAPixelIsItsOwnRowAndARetryResendsOnlyTheOneThatFailed(): void
    {
        $this->click(100);
        self::pixels([90 => 'https://a.example/pb?s=[[subid]]  https://b.example/pb?s=[[subid]]&v=[[payout]] ']);
        self::assertSame(2, $this->queue(self::OLD), 'one row per URL; the doubled space is not a destination');

        $calls = [];
        $bFails = true;
        $outbox = $this->outboxWith(function (string $url) use (&$calls, &$bFails): bool {
            $calls[] = $url;

            return !(str_starts_with($url, 'https://b.example/') && $bFails);
        });
        self::assertSame(['sent' => 1, 'failed' => 0, 'retrying' => 1], $outbox->sendDue(10));
        $rows = self::$db->query("SELECT destination, url, status, attempts FROM 202_notification_pending WHERE conv_id = " . self::OLD . ' ORDER BY destination')->fetch_all(MYSQLI_ASSOC);
        self::assertSame([
            ['0', 'https://a.example/pb?s=100', 'sent', '1'],
            ['1', 'https://b.example/pb?s=100&v=4.00000', 'pending', '1'],
        ], array_map(static fn (array $r): array => [(string) $r['destination'], $r['url'], $r['status'], (string) $r['attempts']], $rows));

        // Past the backoff, B recovers: only B is fetched again.
        $bFails = false;
        $this->clock += NotificationOutbox::backoff(1);
        self::assertSame(['sent' => 1, 'failed' => 0, 'retrying' => 0], $outbox->sendDue(10));
        self::assertSame(['https://a.example/pb?s=100', 'https://b.example/pb?s=100&v=4.00000', 'https://b.example/pb?s=100&v=4.00000'], $calls,
            'A accepted the conversion once and is never sent it again');
    }

    public function testAnEndpointThatNeverRecoversFailsAloneAfterItsAttempts(): void
    {
        $this->click(100);
        self::pixels([90 => 'https://a.example/1 https://b.example/2']);
        $this->queue(self::OLD);
        $calls = [];
        $outbox = $this->outboxWith(function (string $url) use (&$calls): bool {
            $calls[] = $url;

            return $url === 'https://a.example/1';
        });
        for ($i = 1; $i <= NotificationOutbox::MAX_ATTEMPTS; $i++) {
            $outbox->sendDue(10);
            $this->clock += NotificationOutbox::backoff($i);
        }
        self::assertSame(1, count(array_keys($calls, 'https://a.example/1', true)));
        self::assertSame(NotificationOutbox::MAX_ATTEMPTS, count(array_keys($calls, 'https://b.example/2', true)));
        self::assertSame(['sent', 'failed'], array_column(self::$db->query('SELECT status FROM 202_notification_pending ORDER BY destination')->fetch_all(MYSQLI_ASSOC), 'status'));
    }

    /**
     * Plan §5.10's table, one pixel per cell. The replaced row's reached at
     * a destination is in one of the states below; the replacement's reached
     * there is present (the pixel still exists) or absent (removed between).
     * An "announced" destination may have heard the outcome.
     *
     *   pixel  replaced row's reached      replacement  announced
     *   101    pending, unattempted        present      no
     *   102    pending, unattempted        absent       no
     *   103    pending, attempted          present      yes
     *   104    pending, attempted          absent       yes
     *   105    sent                        present      yes
     *   106    sent                        absent       yes
     *   107    failed                      present      yes
     *   108    failed                      absent       yes
     *   109    cancelled, has correction   present      yes (its predecessor's went out)
     *   110    cancelled, has correction   absent       yes
     *   111    none (pixel added since)    present      no
     *
     * Not announced: the replaced row's reached is cancelled and the
     * replacement's goes out. Announced: the replaced row is left as it is,
     * the replacement's reached is cancelled there and only there, and a
     * correction is recorded suppressed.
     */
    public function testAReplacementIsCancelledOnlyWhereTheReplacedRowWasAnnounced(): void
    {
        $this->setUpReplacedRow();
        self::pixels(array_fill_keys([101, 103, 105, 107, 109, 111], 'https://ts.example/pb?s=[[subid]]&c=[[transactionid]]'));
        self::assertSame(6, $this->queue(self::NEW));

        $this->outboxWith(static fn (string $u): bool => true)->onReplaced(1, self::OLD, self::NEW);

        self::assertSame([
            101 => ['status' => 'cancelled', 'attempts' => 0],
            102 => ['status' => 'cancelled', 'attempts' => 0],
            103 => ['status' => 'pending', 'attempts' => 2],
            104 => ['status' => 'pending', 'attempts' => 2],
            105 => ['status' => 'sent', 'attempts' => 1],
            106 => ['status' => 'sent', 'attempts' => 1],
            107 => ['status' => 'failed', 'attempts' => 8],
            108 => ['status' => 'failed', 'attempts' => 8],
            109 => ['status' => 'cancelled', 'attempts' => 0],
            110 => ['status' => 'cancelled', 'attempts' => 0],
        ], self::reachedByPixel(self::OLD), 'the replaced row: only unattempted reached rows are cancelled; what went out stays as it is');
        self::assertSame([
            101 => ['status' => 'pending', 'attempts' => 0],
            103 => ['status' => 'cancelled', 'attempts' => 0],
            105 => ['status' => 'cancelled', 'attempts' => 0],
            107 => ['status' => 'cancelled', 'attempts' => 0],
            109 => ['status' => 'cancelled', 'attempts' => 0],
            111 => ['status' => 'pending', 'attempts' => 0],
        ], self::reachedByPixel(self::NEW), 'the replacement goes out exactly where the replaced row never did');
        self::assertSame([103, 104, 105, 106, 107, 108, 109, 110], self::suppressed(self::NEW, 'correction'));
        self::assertSame([], self::suppressed(self::OLD, 'retraction'));

        // Every destination hears the outcome once: the worker sends 101 and
        // 111 — and nothing that already went out.
        $sent = [];
        $this->outboxWith(function (string $u) use (&$sent): bool {
            $sent[] = $u;

            return true;
        })->sendDue(50, [self::NEW]);
        self::assertSame(['https://ts.example/pb?s=100&c=tx-2000', 'https://ts.example/pb?s=100&c=tx-2000'], $sent);
    }

    public function testARetiredOutcomeIsRetractedWhereItWasAnnouncedAndCancelledWhereItWasNot(): void
    {
        $this->setUpReplacedRow();
        $this->outboxWith(static fn (string $u): bool => true)->onReplaced(1, self::OLD, null);

        self::assertSame(['cancelled', 'cancelled'], [self::reachedByPixel(self::OLD)[101]['status'], self::reachedByPixel(self::OLD)[102]['status']]);
        self::assertSame([103, 104, 105, 106, 107, 108, 109, 110], self::suppressed(self::OLD, 'retraction'));
    }

    public function testAMultiUrlPixelIsDecidedPerUrl(): void
    {
        $this->click(100);
        self::pixels([90 => 'https://a.example/1 https://b.example/2']);
        $this->queue(self::OLD);
        // A went out; B has not been tried.
        self::$db->query("UPDATE 202_notification_pending SET status = 'sent', attempts = 1 WHERE conv_id = " . self::OLD . ' AND destination = 0');
        $this->queue(self::NEW);

        $this->outboxWith(static fn (string $u): bool => true)->onReplaced(1, self::OLD, self::NEW);
        $rows = self::$db->query('SELECT conv_id, destination, kind, status FROM 202_notification_pending ORDER BY conv_id, kind, destination')->fetch_all(MYSQLI_ASSOC);
        self::assertSame([
            [self::OLD, 0, 'reached', 'sent'],
            [self::OLD, 1, 'reached', 'cancelled'],
            [self::NEW, 0, 'correction', 'suppressed'],
            [self::NEW, 0, 'reached', 'cancelled'],
            [self::NEW, 1, 'reached', 'pending'],
        ], array_map(static fn (array $r): array => [(int) $r['conv_id'], (int) $r['destination'], $r['kind'], $r['status']], $rows));
    }

    public function testAChainOfReplacementsAnnouncesTheOutcomeOnce(): void
    {
        $this->click(100);
        self::pixels([90 => 'https://ts.example/pb?c=[[transactionid]]']);
        $this->queue(1001);
        self::$db->query("UPDATE 202_notification_pending SET status = 'sent', attempts = 1, sent_at = 1 WHERE conv_id = 1001");
        $outbox = $this->outboxWith(static fn (string $u): bool => true);
        $this->queue(1002);
        $outbox->onReplaced(1, 1001, 1002);
        $this->queue(1003);
        $outbox->onReplaced(1, 1002, 1003);
        $outbox->onReplaced(1, 1003, null);

        $rows = self::$db->query('SELECT conv_id, kind, status FROM 202_notification_pending ORDER BY notification_id')->fetch_all(MYSQLI_ASSOC);
        self::assertSame([
            '1001 reached sent',
            '1002 reached cancelled', '1002 correction suppressed',
            '1003 reached cancelled', '1003 correction suppressed',
            '1003 retraction suppressed',
        ], array_map(static fn (array $r): string => $r['conv_id'] . ' ' . $r['kind'] . ' ' . $r['status'], $rows),
            'the second replacement never re-announces what the first one\'s predecessor sent');
    }

    /**
     * Plan §5.7 (1), one destination per state of the revived row's latest
     * retraction there. A retraction that never went out is cancelled and
     * nothing replaces it; one that may have landed is stopped (if it was
     * still retrying) and answered by a correction from 0; a destination
     * whose reached the retirement cancelled unsent hears the reached now.
     * Nothing already sent is sent again.
     *
     *   pixel  reached     retraction                 after the revival
     *   121    sent        pending, unattempted       retraction cancelled
     *   122    sent        suppressed                 retraction cancelled
     *   123    sent        sent                       + correction, pending
     *   124    sent        pending, attempted         retraction cancelled + correction
     *   125    sent        failed                     + correction
     *   126    cancelled   none                       reached pending again
     *   127    sent        sent, then a correction    nothing (settled earlier)
     */
    public function testARevivedRowIsNeverAnnouncedTwiceAndItsRetractionIsSettledPerDestination(): void
    {
        $this->click(100);
        self::conversionRow(self::OLD, '4.00000');
        self::pixels(array_fill_keys(range(121, 127), 'https://ts.example/pb?s=[[subid]]'));
        self::assertSame(7, $this->queue(self::OLD));
        self::$db->query("UPDATE 202_notification_pending SET status = 'sent', attempts = 1, sent_at = 1 WHERE conv_id = " . self::OLD . ' AND pixel_id <> 126');
        $withUrl = $this->outboxWith(static fn (string $u): bool => true, self::FIX);
        $withUrl->onReplaced(1, self::OLD, null);
        self::assertSame(['cancelled'], [self::reachedByPixel(self::OLD)[126]['status']], 'unsent, so cancelled rather than retracted');
        foreach ([
            122 => "status = 'suppressed', url = ''",
            123 => "status = 'sent', attempts = 1",
            124 => "attempts = 2",
            125 => "status = 'failed', attempts = 8",
            127 => "status = 'sent', attempts = 1",
        ] as $pixel => $set) {
            self::$db->query('UPDATE 202_notification_pending SET ' . $set . ' WHERE conv_id = ' . self::OLD . " AND pixel_id = $pixel AND kind = 'retraction'");
        }
        self::fixture('INSERT INTO 202_notification_pending SET user_id = 1, conv_id = ' . self::OLD . ", pixel_id = 127, kind = 'correction',
            status = 'sent', url = 'x', attempts = 1, next_attempt_at = 1, created_at = 1");

        $withUrl->onRevived(1, self::OLD);

        self::assertSame([
            121 => ['reached:sent', 'retraction:cancelled'],
            122 => ['reached:sent', 'retraction:cancelled'],
            123 => ['reached:sent', 'retraction:sent', 'correction:pending'],
            124 => ['reached:sent', 'retraction:cancelled', 'correction:pending'],
            125 => ['reached:sent', 'retraction:failed', 'correction:pending'],
            126 => ['reached:pending'],
            127 => ['reached:sent', 'retraction:sent', 'correction:sent'],
        ], self::byPixel(self::OLD));

        $sent = [];
        $this->outboxWith(function (string $u) use (&$sent): bool {
            $sent[] = $u;

            return true;
        })->sendDue(50);
        self::assertSame([
            'https://ts.example/pb?s=100',
            'https://ts.example/fix?v=4.00000&was=0&k=correction',
            'https://ts.example/fix?v=4.00000&was=0&k=correction',
            'https://ts.example/fix?v=4.00000&was=0&k=correction',
        ], $sent, 'the reached that never went out, and a correction from 0 wherever the retraction may have landed');
    }

    public function testWithoutACorrectionUrlARevivalAfterADeliveredRetractionRecordsTheCorrectionSuppressed(): void
    {
        $this->click(100);
        self::pixels([90 => 'https://ts.example/pb?s=[[subid]]']);
        $this->queue(self::OLD);
        self::$db->query("UPDATE 202_notification_pending SET status = 'sent', attempts = 1 WHERE conv_id = " . self::OLD);
        $outbox = $this->outboxWith(static fn (string $u): bool => true);
        $outbox->onReplaced(1, self::OLD, null);
        // Delivered by whatever carried it (a URL since removed): the
        // network holds 0 now.
        self::$db->query("UPDATE 202_notification_pending SET status = 'sent', attempts = 1 WHERE conv_id = " . self::OLD . " AND kind = 'retraction'");
        $outbox->onRevived(1, self::OLD);
        self::assertSame([90 => ['reached:sent', 'retraction:sent', 'correction:suppressed']], self::byPixel(self::OLD));
        self::assertStringStartsWith('no correction URL', (string) self::$db->query("SELECT last_error FROM 202_notification_pending WHERE kind = 'correction'")->fetch_assoc()['last_error']);
        self::assertSame(['sent' => 0, 'failed' => 0, 'retrying' => 0], $outbox->sendDue(10));
    }

    /**
     * Plan §5.7 (2): a new row for an (subject, goal, n) an earlier row was
     * announced for — here retired long ago, not the row it replaces — is a
     * correction wherever that one was heard, and a fresh reached only where
     * it was not. A correction onReplaced() already recorded on the new row
     * is not recorded twice.
     */
    public function testANewRowIsACorrectionWhereAnEarlierRowForItsNWasAnnounced(): void
    {
        $this->click(100);
        self::conversionRow(self::OLD, '4.00000');
        self::conversionRow(self::NEW, '6.00000');
        self::pixels([131 => 'https://ts.example/pb?s=[[subid]]', 132 => 'https://ts.example/pb?s=[[subid]]', 133 => 'https://ts.example/pb?s=[[subid]]']);
        $this->queue(self::OLD);
        // 131 heard OLD, 132 never did (cancelled unsent), 133 heard OLD and
        // then its delivered retraction.
        self::$db->query("UPDATE 202_notification_pending SET status = 'sent', attempts = 1 WHERE conv_id = " . self::OLD . ' AND pixel_id IN (131, 133)');
        self::$db->query("UPDATE 202_notification_pending SET status = 'cancelled' WHERE conv_id = " . self::OLD . ' AND pixel_id = 132');
        $withUrl = $this->outboxWith(static fn (string $u): bool => true, self::FIX);
        $withUrl->onReplaced(1, self::OLD, null);
        self::$db->query("UPDATE 202_notification_pending SET status = 'sent', attempts = 1 WHERE kind = 'retraction' AND pixel_id = 133");
        self::$db->query("UPDATE 202_notification_pending SET status = 'suppressed', url = '' WHERE kind = 'retraction' AND pixel_id = 131");

        $this->queue(self::NEW);
        self::assertTrue($withUrl->onAnnouncedBefore(1, self::NEW, [self::OLD]));
        self::assertFalse($withUrl->onAnnouncedBefore(1, self::NEW, []), 'no earlier row, nothing withheld');
        self::assertTrue($withUrl->onAnnouncedBefore(1, self::NEW, [self::OLD]), 'asked again: withheld, and nothing recorded twice');

        self::assertSame([
            131 => ['reached:cancelled', 'correction:pending'],
            132 => ['reached:pending'],
            133 => ['reached:cancelled', 'correction:pending'],
        ], self::byPixel(self::NEW));
        self::assertSame([
            'https://ts.example/fix?v=6.00000&was=4.00000&k=correction',
            'https://ts.example/fix?v=6.00000&was=0&k=correction',
        ], array_column(self::$db->query("SELECT url FROM 202_notification_pending WHERE conv_id = " . self::NEW . " AND kind = 'correction' ORDER BY pixel_id")->fetch_all(MYSQLI_ASSOC), 'url'),
            '131 still holds 4.00 (its retraction never went out); 133 was told 0');
    }

    /** @return array<int, list<string>> "kind:status" of one conversion's rows, by pixel, in id order */
    private static function byPixel(int $convId): array
    {
        $out = [];
        foreach (self::$db->query("SELECT pixel_id, kind, status FROM 202_notification_pending WHERE conv_id = $convId ORDER BY notification_id")->fetch_all(MYSQLI_ASSOC) as $r) {
            $out[(int) $r['pixel_id']][] = $r['kind'] . ':' . $r['status'];
        }
        ksort($out);

        return $out;
    }

    /** A ledger row for an amendment to read its value from. */
    private static function conversionRow(int $convId, string $payout): void
    {
        self::fixture("INSERT INTO 202_conversion_logs SET conv_id = $convId, click_id = 100, campaign_id = 30, user_id = 1, click_payout = $payout,
            click_time = 1, conv_time = 1, source = 'goal', dedupe_key = 'goal:1:1:1:e$convId', payable = 1");
    }

    /**
     * The replaced row (conversion OLD) in every state of the table above,
     * one pixel each, with the cancelled-after-a-predecessor cells carrying
     * the correction their own replacement recorded.
     */
    private function setUpReplacedRow(): void
    {
        $this->click(100);
        self::pixels(array_fill_keys(range(101, 110), 'https://ts.example/pb?s=[[subid]]&c=[[transactionid]]'));
        self::assertSame(10, $this->queue(self::OLD));
        foreach ([
            '103, 104' => "status = 'pending', attempts = 2",
            '105, 106' => "status = 'sent', attempts = 1, sent_at = 1",
            '107, 108' => "status = 'failed', attempts = 8",
            '109, 110' => "status = 'cancelled'",
        ] as $pixels => $set) {
            self::$db->query('UPDATE 202_notification_pending SET ' . $set . ' WHERE conv_id = ' . self::OLD . " AND pixel_id IN ($pixels)");
        }
        foreach ([109, 110] as $pixel) {
            self::fixture("INSERT INTO 202_notification_pending SET user_id = 1, conv_id = " . self::OLD . ", pixel_id = $pixel, kind = 'correction',
                status = 'suppressed', url = '', attempts = 0, next_attempt_at = 1, created_at = 1");
        }
    }
}
