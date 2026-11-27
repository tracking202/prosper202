<?php

declare(strict_types=1);

namespace Tests\Notifications;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Lifecycle\IllegalTransition;
use Prosper202\Notifications\NotificationOutbox;
use Prosper202\Notifications\NotificationStatus;
use Tests\Apps\Android\AndroidDatabase;

/**
 * NotificationStatus against a real server: its SQL and PHP forms of one
 * question give one answer, and the outbox's writers ask it.
 *
 * @group integration
 */
final class NotificationStatusIntegrationTest extends TestCase
{
    use AndroidDatabase;

    private const CONV = 1000;

    private function outbox(?\mysqli $db = null): NotificationOutbox
    {
        return new NotificationOutbox(new Connection($db ?? self::$db), fn (): int => $this->clock, static fn (string $u): bool => true,
            static fn (int $pixel, int $destination): ?string => null);
    }

    private function row(int $pixel, string $status, int $attempts, string $kind = 'reached'): void
    {
        self::fixture('INSERT INTO 202_notification_pending SET user_id = 1, conv_id = ' . self::CONV . ", pixel_id = $pixel, kind = '$kind',
            status = '$status', url = 'https://ts.example/pb', attempts = $attempts, next_attempt_at = 1, created_at = 1");
    }

    /** @return list<string> kind:status:attempts, in id order */
    private static function rowsNow(): array
    {
        return array_map(
            static fn (array $r): string => $r['kind'] . ':' . $r['status'] . ':' . $r['attempts'],
            self::$db->query('SELECT kind, status, attempts FROM 202_notification_pending ORDER BY notification_id')->fetch_all(MYSQLI_ASSOC)
        );
    }

    /** The previews count in SQL what onReplaced() decides in PHP: each state, attempted and not, alone in the table. */
    public function testTheSqlCountOfARetirementIsThePhpDecision(): void
    {
        foreach (NotificationStatus::cases() as $state) {
            foreach ([0, 1, 5] as $attempts) {
                self::$db->query('TRUNCATE TABLE 202_notification_pending');
                $this->row(1, $state->value, $attempts);
                $fate = $state->reachedOnRetirement($attempts);
                $expected = [
                    'cancel' => $fate === NotificationStatus::RETIRED_CANCEL ? '1' : '0',
                    'retract' => $fate === NotificationStatus::RETIRED_RETRACT ? '1' : '0',
                ];
                self::assertSame(
                    $expected,
                    self::$db->query('SELECT ' . NotificationStatus::retirementCountsSql() . ' FROM 202_notification_pending')->fetch_assoc(),
                    $state->value . ' after ' . $attempts
                );
                self::assertSame(
                    $expected,
                    self::$db->query('SELECT ' . NotificationStatus::retirementCountsSql('n.') . ' FROM 202_notification_pending n')->fetch_assoc(),
                    $state->value . ' after ' . $attempts . ', aliased'
                );
            }
        }
    }

    /** And onReplaced() does what the count says, for every state at once. */
    public function testRetiringAnOutcomeSettlesEachReachedRowAsCounted(): void
    {
        $pixel = 1;
        foreach (NotificationStatus::cases() as $state) {
            foreach ([0, 2] as $attempts) {
                $this->row($pixel++, $state->value, $attempts);
            }
        }
        $counted = self::$db->query('SELECT ' . NotificationStatus::retirementCountsSql() . ' FROM 202_notification_pending')->fetch_assoc();

        $conn = new Connection(self::$db);
        $outbox = $this->outbox();
        $conn->transaction(static function () use ($outbox): void {
            $outbox->onReplaced(1, self::CONV, null);
        });

        self::assertSame((int) $counted['retract'], self::rows('202_notification_pending', "kind = 'retraction'"));
        self::assertSame(
            (int) $counted['cancel'],
            self::rows('202_notification_pending', "kind = 'reached' AND status = 'cancelled' AND last_error LIKE 'the outcome was retired%'")
        );
        self::assertSame(['cancel' => '1', 'retract' => '8'], $counted);
    }

    public function testANotificationIsCreatedOnlyInAnInitialState(): void
    {
        $insert = new \ReflectionMethod(NotificationOutbox::class, 'insert');
        $insert->setAccessible(true);
        foreach (NotificationStatus::cases() as $state) {
            $args = [1, self::CONV, 1, 0, NotificationOutbox::KIND_REACHED, 0, $state, 'https://ts.example/pb', null, $this->clock];
            if (in_array($state, NotificationStatus::initial(), true)) {
                self::assertSame(1, $insert->invoke($this->outbox(), ...$args));
                self::$db->query('TRUNCATE TABLE 202_notification_pending');
                continue;
            }
            try {
                $insert->invoke($this->outbox(), ...$args);
                self::fail('created ' . $state->value);
            } catch (\LogicException $e) {
                self::assertStringContainsString($state->value, $e->getMessage());
            }
        }
        self::assertSame(0, self::rows('202_notification_pending'));
    }

    public function testAMoveLeavesOnlyTheStateAndAttemptCountItWasReadIn(): void
    {
        $this->row(1, 'pending', 2);
        $id = (int) self::$db->query('SELECT notification_id FROM 202_notification_pending')->fetch_row()[0];
        $move = new \ReflectionMethod(NotificationOutbox::class, 'move');
        $move->setAccessible(true);
        $outbox = $this->outbox();

        self::assertFalse($move->invoke($outbox, $id, NotificationStatus::Pending, 1, NotificationStatus::Failed, 'x'), 'claimed again since');
        self::assertFalse($move->invoke($outbox, $id, NotificationStatus::Suppressed, 2, NotificationStatus::Cancelled, 'x'), 'not the state it is in');
        self::assertSame(['reached:pending:2'], self::rowsNow());
        self::assertTrue($move->invoke($outbox, $id, NotificationStatus::Pending, 2, NotificationStatus::Cancelled, 'x'));
        self::assertSame(['reached:cancelled:2'], self::rowsNow());

        $this->expectException(IllegalTransition::class);
        $move->invoke($outbox, $id, NotificationStatus::Cancelled, 2, NotificationStatus::Failed, 'x');
    }

    /**
     * A caller that holds no transaction holds no lock: a worker can claim
     * the row between the retirement's read and its cancel. The cancel then
     * does not land, and the row is taken back like any that may have gone out.
     */
    public function testAReachedClaimedBetweenItsReadAndItsCancelIsRetractedNotCancelled(): void
    {
        $this->row(1, 'pending', 0);
        $hooked = new class ((string) getenv('P202_TEST_DB_HOST'), (string) (getenv('P202_TEST_DB_USER') ?: 'root'), (string) (getenv('P202_TEST_DB_PASS') ?: ''), (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'), (int) (getenv('P202_TEST_DB_PORT') ?: 3306)) extends \mysqli {
            /** @var (callable(): void)|null */
            public $beforeTheCancel = null;

            public function prepare(string $query): \mysqli_stmt|false
            {
                if ($this->beforeTheCancel !== null && str_starts_with($query, 'UPDATE 202_notification_pending SET status = ?')) {
                    $hook = $this->beforeTheCancel;
                    $this->beforeTheCancel = null;
                    $hook();
                }

                return parent::prepare($query);
            }
        };
        $claims = 0;
        $hooked->beforeTheCancel = static function () use (&$claims): void {
            self::$db->query("UPDATE 202_notification_pending SET attempts = 1 WHERE status = 'pending' AND attempts = 0");
            $claims = self::$db->affected_rows;
        };
        try {
            $this->outbox($hooked)->onReplaced(1, self::CONV, null);
        } finally {
            $hooked->close();
        }
        self::assertSame(1, $claims, 'the claim landed in the window');
        self::assertSame(['reached:pending:1', 'retraction:suppressed:0'], self::rowsNow());
    }

    /** One row with a status no case names does not refuse its conversion's write: it is read as one that may have gone out, and logged. */
    public function testAStoredStatusNoCaseNamesIsLoggedAndReadAsOneThatMayHaveGoneOut(): void
    {
        $this->row(1, 'bogus', 0);
        $this->row(2, 'pending', 0);
        $log = (string) tempnam(sys_get_temp_dir(), 'p202-outbox-log');
        $before = ini_set('error_log', $log);
        try {
            $conn = new Connection(self::$db);
            $outbox = $this->outbox();
            $conn->transaction(static function () use ($outbox): void {
                $outbox->onReplaced(1, self::CONV, null);
                $outbox->onAnnouncedBefore(1, self::CONV + 1, [self::CONV]);
            });
            $logged = (string) file_get_contents($log);
        } finally {
            ini_set('error_log', (string) $before);
            unlink($log);
        }
        self::assertSame(['reached:bogus:0', 'reached:cancelled:0', 'retraction:suppressed:0', 'correction:suppressed:0'], self::rowsNow(),
            'retracted at its destination, and the next row for the outcome is a correction there');
        self::assertStringContainsString('holds a status that is not a state (bogus)', $logged);
    }
}
