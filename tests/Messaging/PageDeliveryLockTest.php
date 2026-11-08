<?php

declare(strict_types=1);

namespace Tests\Messaging;

use PHPUnit\Framework\TestCase;
use Prosper202\Cron\CronLock;

require_once __DIR__ . '/../../202-config/Messaging/MessagingService.class.php';

/**
 * A page's analytics delivery (track.php) is kept apart from the cron's and
 * from another page's (J5TK): run beside either, the same pending rows were
 * read and sent twice. The locks are held by a child process here, as a
 * cron run or another page's request would hold them.
 */
final class PageDeliveryLockTest extends TestCase
{
    private string $dir = '';

    /** @var list<array{0: resource, 1: array<int, resource>}> */
    private array $holders = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/p202-page-delivery-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->dir, 0700));
    }

    protected function tearDown(): void
    {
        foreach ($this->holders as [$proc, $pipes]) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($proc, 9);
            proc_close($proc);
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function deliveryLock(): string
    {
        return $this->dir . '/' . CronLock::ANALYTICS_DELIVERY_FILE;
    }

    /** $path locked by another process for $seconds, from when this returns. */
    private function holdElsewhere(string $path, float $seconds): void
    {
        $code = '$h = fopen($argv[1], "c"); flock($h, LOCK_EX); echo "held\n"; usleep((int) ($argv[2] * 1e6));';
        $proc = proc_open(
            [PHP_BINARY, '-r', $code, $path, (string) $seconds],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($proc);
        $this->holders[] = [$proc, $pipes];
        self::assertSame("held\n", fgets($pipes[1]), 'the other process holds ' . basename($path));
    }

    private function service(): \MessagingService
    {
        // Every statement fails: a sync() that runs is SYNC_FAILED, not SYNC_BUSY.
        return new \MessagingService($this->createMock(\mysqli::class), 7, ['install_hash' => 'h', 'api_key' => 'k', 'user_id' => 7]);
    }

    public function testAPageDoesNotDeliverWhileTheCronDelivers(): void
    {
        $this->holdElsewhere($this->deliveryLock(), 30);
        self::assertSame(\MessagingService::SYNC_BUSY, $this->service()->syncExclusive($this->deliveryLock()));
    }

    public function testAPageDoesNotDeliverBesideAnotherPage(): void
    {
        $this->holdElsewhere(\MessagingService::pageLockPath($this->deliveryLock()), 30);
        self::assertSame(\MessagingService::SYNC_BUSY, $this->service()->syncExclusive($this->deliveryLock()));
    }

    public function testWithNeitherHeldThePageSyncsAndLetsGoOfItsLock(): void
    {
        self::assertNotSame(\MessagingService::SYNC_BUSY, $this->service()->syncExclusive($this->deliveryLock()),
            'the positive control: nothing else delivering, the page goes on to sync()');
        self::assertNotNull(CronLock::take(\MessagingService::pageLockPath($this->deliveryLock())),
            'the page lock is let go when the sync returns');
    }

    public function testTheCronWaitsOutAShortHolderAndGivesUpOnALongOne(): void
    {
        $page = \MessagingService::pageLockPath($this->deliveryLock());
        $this->holdElsewhere($page, 1.0);
        $started = microtime(true);
        $lock = CronLock::takeWithin($page, 5);
        self::assertNotNull($lock, 'a page delivery that ends within the wait is waited for');
        self::assertGreaterThan(0.5, microtime(true) - $started, 'it waited for the holder, not beside it');
        unset($lock);

        $this->holdElsewhere($page, 30);
        $started = microtime(true);
        self::assertNull(CronLock::takeWithin($page, 0.5), 'a holder past the wait leaves the lock unheld by the cron');
        self::assertLessThan(3, microtime(true) - $started, 'and the wait is bounded');
    }
}
