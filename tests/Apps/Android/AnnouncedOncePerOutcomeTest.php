<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Goals\GoalDefinition;
use Prosper202\Goals\GoalEngine;
use Prosper202\Goals\GoalSubject;
use Prosper202\Notifications\NotificationOutbox;

/**
 * Plan §5.7's notification decisions for install subjects, driven through
 * the goal engine's re-evaluation: a traffic source's knowledge is per
 * (subject, goal, n) and per destination, not per ledger row.
 *
 * The scenario is RevivalRestoresTheLedgerTest's funnel A → B on an
 * install: A and B reach and are announced; a re-evaluation makes A stop
 * matching (both retired with no replacement: retractions); another makes
 * A match again. B is *revived* — the same conversion, whose `reached`
 * already went out and must never go out again (decision 1) — and A is a
 * *new* row under a new version for an n its v1 row announced before being
 * retired, so it is a correction there, not a fresh `reached` (decision 2).
 *
 * @group integration
 */
final class AnnouncedOncePerOutcomeTest extends TestCase
{
    use AndroidDatabase;

    private const U = '00000000-0000-4000-8000-0000000000a1';
    private const CORRECTION = 'https://ts.example/fix?sub=[[subid]]&v=[[p202_goal_value]]&was=[[p202_previous_value]]&conv=[[p202_conv_id]]&k=[[p202_notification]]';

    private int $a;
    private int $b;

    /** @param array<string, mixed> $definition */
    private function edit(int $goal, array $definition): void
    {
        $this->clock += 100;
        $this->goals->addVersion(1, $goal, GoalDefinition::parse($definition, $goal), $this->clock);
    }

    /** @return array<string, mixed> */
    private static function purchase(bool $big = false): array
    {
        $trigger = ['event' => 'purchase'];
        if ($big) {
            $trigger['where'] = [['prop' => 'amount', 'op' => 'gte', 'value' => 100]];
        }

        return ['name' => 'Purchase', 'trigger' => $trigger, 'value' => ['type' => 'fixed', 'amount' => 5]];
    }

    private function outbox(bool $correctionUrl): NotificationOutbox
    {
        return new NotificationOutbox(
            new Connection(self::$db),
            fn (): int => $this->clock,
            function (string $url): bool {
                $this->sent[] = $url;

                return true;
            },
            $correctionUrl ? static fn (int $pixel, int $destination): ?string => self::CORRECTION : null
        );
    }

    private function reevaluate(int $goal, NotificationOutbox $outbox): void
    {
        $this->clock += 5;
        (new GoalEngine(new Connection(self::$db), null, null, fn (): int => $this->clock, $outbox))
            ->reevaluate(1, $goal, null, true, 100, 0, GoalSubject::INSTALL);
    }

    /** Install, A (purchase, $5) and B (upsell after A, $3) reached, and both postbacks sent. */
    private function funnelAnnounced(): void
    {
        $this->click(100);
        $this->a = $this->campaignGoal(30, self::purchase());
        $this->b = $this->campaignGoal(30, ['name' => 'Upsell', 'trigger' => ['event' => 'upsell'], 'after' => [$this->a],
            'value' => ['type' => 'fixed', 'amount' => 3]]);
        self::assertSame(200, $this->install(self::body(self::U, 'p202=' . self::tokenFor(100)))['status']);
        $t = self::CLICK_TIME + 100;
        self::assertSame(200, $this->events(self::U, [
            ['event_id' => 'p1', 'name' => 'purchase', 'occurred_at' => $t + 1, 'properties' => ['amount' => 10]],
            ['event_id' => 'u1', 'name' => 'upsell', 'occurred_at' => $t + 2],
        ])['status']);
        self::assertSame(['sent' => 2, 'failed' => 0, 'retrying' => 0], $this->outbox(false)->sendDue(10));
        self::assertSame(['Purchase', 'Upsell'], $this->sentGoals());
        $this->sent = [];
    }

    /** @return list<string> the goal token of every postback sent so far */
    private function sentGoals(): array
    {
        return array_map(static function (string $url): string {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

            return (string) ($q['goal'] ?? $q['k'] ?? '?');
        }, $this->sent);
    }

    private function convOf(int $goal, int $version): int
    {
        return (int) self::$db->query("SELECT conversion_id FROM 202_goal_outcomes WHERE goal_id = $goal AND goal_version = $version")->fetch_assoc()['conversion_id'];
    }

    /** @return list<string> "kind:generation:status" of one conversion's rows, in id order */
    private static function rowsOf(int $convId): array
    {
        return array_map(
            static fn (array $r): string => $r['kind'] . ':' . $r['generation'] . ':' . $r['status'],
            self::$db->query("SELECT kind, generation, status FROM 202_notification_pending WHERE conv_id = $convId ORDER BY notification_id")->fetch_all(MYSQLI_ASSOC)
        );
    }

    /**
     * No correction URL (every installation today): the retractions are
     * stored suppressed — the networks were never told — so when A matches
     * again they are cancelled, B's revival sends nothing, and A's new row
     * sends nothing either: the network still holds both values as it heard
     * them. Nothing at all goes out.
     */
    public function testWithoutACorrectionUrlTheNetworkHearsEachOutcomeOnce(): void
    {
        $this->funnelAnnounced();
        $bConv = $this->convOf($this->b, 1);
        $aV1 = $this->convOf($this->a, 1);

        $this->edit($this->a, self::purchase(true));
        $this->reevaluate($this->a, $this->outbox(false));
        self::assertSame(['reached:0:sent', 'retraction:0:suppressed'], self::rowsOf($aV1));
        self::assertSame(['reached:0:sent', 'retraction:0:suppressed'], self::rowsOf($bConv));

        $this->edit($this->a, self::purchase());
        $this->reevaluate($this->a, $this->outbox(false));
        self::assertSame($bConv, $this->convOf($this->b, 1), 'B was revived, not written again');
        self::assertSame(['reached:0:sent', 'retraction:0:cancelled'], self::rowsOf($bConv),
            'decision 1: a revived row is never announced again; its unsent retraction is cancelled');
        $aV3 = $this->convOf($this->a, 3);
        self::assertSame(['reached:0:cancelled', 'correction:0:suppressed'], self::rowsOf($aV3),
            'decision 2: A v1 announced n=1 before it was retired, so A v3 is a correction, not a fresh reached');

        self::assertSame(['sent' => 0, 'failed' => 0, 'retrying' => 0], $this->outbox(false)->sendDue(10));
        self::assertSame([], $this->sent);
    }

    /**
     * With a correction URL the retractions go out. Then the revival and the
     * new row each owe the network a correction — B back to $3 from 0, A
     * back to $5 from 0 — and never a second `reached`.
     */
    public function testWithACorrectionUrlADeliveredRetractionIsAnsweredByACorrection(): void
    {
        $this->funnelAnnounced();
        $bConv = $this->convOf($this->b, 1);

        $this->edit($this->a, self::purchase(true));
        $this->reevaluate($this->a, $this->outbox(true));
        self::assertSame(['sent' => 2, 'failed' => 0, 'retrying' => 0], $this->outbox(true)->sendDue(10));
        self::assertSame(['retraction', 'retraction'], $this->sentGoals());
        self::assertStringContainsString('v=0&was=5.00000', $this->sent[0]);
        $this->sent = [];

        $this->edit($this->a, self::purchase());
        $this->reevaluate($this->a, $this->outbox(true));
        $aV3 = $this->convOf($this->a, 3);
        self::assertSame(['reached:0:sent', 'retraction:0:sent', 'correction:0:pending'], self::rowsOf($bConv));
        self::assertSame(['reached:0:cancelled', 'correction:0:pending'], self::rowsOf($aV3));

        self::assertSame(['sent' => 2, 'failed' => 0, 'retrying' => 0], $this->outbox(true)->sendDue(10));
        sort($this->sent);
        self::assertSame([
            'https://ts.example/fix?sub=100&v=3.00000&was=0&conv=' . $bConv . '&k=correction',
            'https://ts.example/fix?sub=100&v=5.00000&was=0&conv=' . $aV3 . '&k=correction',
        ], $this->sent, 'each outcome is restored by a correction from 0; neither is announced as newly reached');
    }

    /**
     * Retired and revived twice: each retirement records its own
     * retraction and each revival settles only the open one — the second
     * cycle is not swallowed by the first one's key.
     */
    public function testEveryRetireAndReviveCycleIsRecorded(): void
    {
        $this->funnelAnnounced();
        $bConv = $this->convOf($this->b, 1);
        foreach ([1, 2] as $cycle) {
            $this->edit($this->a, self::purchase(true));
            $this->reevaluate($this->a, $this->outbox(true));
            $this->outbox(true)->sendDue(10);
            $this->edit($this->a, self::purchase());
            $this->reevaluate($this->a, $this->outbox(true));
            $this->outbox(true)->sendDue(10);
        }
        self::assertSame([
            'reached:0:sent',
            'retraction:0:sent', 'correction:0:sent',
            'retraction:1:sent', 'correction:1:sent',
        ], self::rowsOf($bConv));
    }
}
