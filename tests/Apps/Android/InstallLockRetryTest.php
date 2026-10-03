<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use PHPUnit\Framework\TestCase;

/**
 * A device request that loses its lock twice is told to retry.
 *
 * The install and events intakes retry a deadlock or lock-wait timeout
 * once. The second one used to escape as an exception — a bare 500 — for a
 * transaction that had rolled back, so nothing said the retry was safe. A
 * trigger here raises errno 1213 on every attempt, so both attempts lose.
 *
 * @group integration
 */
final class InstallLockRetryTest extends TestCase
{
    use AndroidDatabase;

    private const U1 = '11111111-1111-4111-8111-111111111111';

    private static function deadlockOn(string $table): void
    {
        self::$db->query('DROP TRIGGER IF EXISTS p202_plant');
        self::assertTrue(self::$db->query(
            "CREATE TRIGGER p202_plant BEFORE INSERT ON $table FOR EACH ROW
             SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'Deadlock found when trying to get lock (planted)'"
        ));
    }

    public function testAnInstallThatLosesItsLockTwiceIsA503WithRetryAfterAndStoresNothing(): void
    {
        $this->click(100);
        self::deadlockOn('202_app_installs');
        $log = ini_set('error_log', sys_get_temp_dir() . '/p202-rv1-lock-retry.log');
        try {
            $r = $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
        } finally {
            ini_set('error_log', $log === false ? '' : $log);
            self::$db->query('DROP TRIGGER IF EXISTS p202_plant');
        }
        self::assertSame(503, $r['status']);
        self::assertSame('5', $r['headers']['Retry-After'] ?? null);
        self::assertSame(0, self::rows('202_app_installs'));

        // And the retry it was told to make records the install.
        self::assertSame(200, $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)))['status']);
        self::assertSame(1, self::rows('202_app_installs'));
    }

    public function testEventsThatLoseTheirLockTwiceAreA503WithRetryAfterAndStoreNothing(): void
    {
        $this->click(100);
        self::assertSame(200, $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)))['status']);
        self::deadlockOn('202_goal_events');
        $log = ini_set('error_log', sys_get_temp_dir() . '/p202-rv1-lock-retry.log');
        try {
            $r = $this->events(self::U1, [['event_id' => 'e1', 'name' => 'level_reached', 'occurred_at' => self::CLICK_TIME + 100]]);
        } finally {
            ini_set('error_log', $log === false ? '' : $log);
            self::$db->query('DROP TRIGGER IF EXISTS p202_plant');
        }
        self::assertSame(503, $r['status']);
        self::assertSame('5', $r['headers']['Retry-After'] ?? null);
        self::assertSame(0, self::rows('202_goal_events'));
        self::assertSame(200, $this->events(self::U1, [['event_id' => 'e1', 'name' => 'level_reached', 'occurred_at' => self::CLICK_TIME + 100]])['status']);
    }
}
