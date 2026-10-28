<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * Every statement that writes a subscription's row is listed with who tells
 * the account's webhooks: subscription.changed is how a subscriber hears of a
 * status, and the hourly sweep wrote past_due and canceled for every install
 * with no event while each API write queued one. A new writer fails here
 * until it says how its change is announced, or why it is not.
 */
final class SubscriptionWritesAreAnnouncedTest extends TestCase
{
    private const WRITE = '/\b(?:UPDATE|INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|DELETE\s+FROM)\s+'
        . '`?202_subscriptions`?\b/i';

    /** file => [statements, who announces them] */
    private const WRITERS = [
        '202-config/Ltv/MysqlSubscriptionRepository.php' => [
            3,
            'upsert() and recordEvent(); LtvController::upsertSubscription() and subscriptionEvent() queue the event',
        ],
        '202-config/Ltv/LtvMaintenance.php' => [1, 'the sweep queues it in the batch\'s transaction (sweepBatch())'],
        '202-config/User/UserDataPurge.php' => [1, 'the account is deleted, its webhooks with it: nobody to tell'],
    ];

    public function testEveryWriteOfASubscriptionRowIsListed(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $file => $source) {
            $count = SourceScan::countMatches(self::WRITE, $source, $file);
            if ($count > 0) {
                $found[$file] = $count;
            }
        }
        ksort($found);
        $listed = array_map(static fn (array $writer): int => $writer[0], self::WRITERS);
        ksort($listed);
        self::assertSame(
            $listed,
            $found,
            'A statement writes 202_subscriptions where this list does not say who queues subscription.changed for'
                . ' it. Queue it as the API does (LtvController) or the sweep does (in the write\'s transaction),'
                . ' and list the file with how.'
        );
    }

    public function testTheListedWritersQueueTheEvent(): void
    {
        $controller = self::read('api/v3/Controllers/LtvController.php');
        foreach (['upsertSubscription', 'subscriptionEvent'] as $method) {
            self::assertStringContainsString(
                "enqueueEvent('subscription.changed'",
                self::body($controller, $method),
                "LtvController::$method() no longer queues subscription.changed"
            );
        }
        self::assertMatchesRegularExpression(
            '/->enqueue\(\s*\$userId,\s*\'subscription\.changed\'/',
            self::body(self::read('202-config/Ltv/LtvMaintenance.php'), 'sweepBatch'),
            'the sweep no longer queues subscription.changed for what it writes'
        );
    }

    private static function read(string $file): string
    {
        $source = file_get_contents(SourceScan::repoRoot() . '/' . $file);
        self::assertIsString($source, $file);

        return $source;
    }

    /** A method's text, from its declaration to the next one's. */
    private static function body(string $source, string $method): string
    {
        $start = strpos($source, 'function ' . $method . '(');
        self::assertIsInt($start, "no method $method");
        $declaration = '/\n    (?:public|private|protected) (?:static )?function /';
        $next = preg_match($declaration, $source, $m, PREG_OFFSET_CAPTURE, $start + 1);
        self::assertNotFalse($next);

        return substr($source, $start, $next === 1 ? $m[0][1] - $start : null);
    }
}
