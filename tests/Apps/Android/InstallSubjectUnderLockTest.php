<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use Api\V3\Controllers\AppRegistrationsController;
use PHPUnit\Framework\TestCase;
use Prosper202\Goals\GoalEngine;
use Prosper202\Goals\GoalEvent;

/**
 * An install subject is judged by the install's credit when its lock is
 * taken, not when a caller built it.
 *
 * InstallEventsIntake builds its subject with installSubject() before
 * GoalEngine::ingest() opens the transaction, and reevaluate() builds one
 * per subject before each subject's transaction. An operator toggling
 * accept_test_signals in between re-judges the install and re-credits
 * what it had reached, and commits. The subject in hand still carries the
 * old click (or none): an event it then evaluates writes an outcome with no
 * ledger row for an install that is now credited — a conversion lost for
 * good, the recredit having already run — or a paid ledger row for one that
 * no longer is.
 *
 * Each test builds the subject, commits the policy change in exactly that
 * window, then hands the engine the subject it built.
 *
 * @group integration
 */
final class InstallSubjectUnderLockTest extends TestCase
{
    use AndroidDatabase;

    private const U1 = '11111111-1111-4111-8111-111111111111';

    private function engine(?callable $clock = null): GoalEngine
    {
        return new GoalEngine($this->conn, $this->goals, null, $clock ?? fn (): int => $this->clock);
    }

    private function levelEvent(string $id): GoalEvent
    {
        return new GoalEvent($id, 'level_reached', self::CLICK_TIME + 200, $this->clock, [], null, false, null);
    }

    /** A stored test install on click 100, and the campaign's level goal ($4). */
    private function testInstall(bool $accepted): array
    {
        $this->click(100);
        $level = $this->campaignGoal(30, ['name' => 'Level up', 'trigger' => ['event' => 'level_reached'], 'value' => ['type' => 'fixed', 'amount' => 4]]);
        if ($accepted) {
            self::fixture('UPDATE 202_app_registrations SET accept_test_signals = 1 WHERE registration_id = 5');
        }
        $r = $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100), ['test' => true]));
        self::assertSame('attributed', $r['body']['data']['match']);
        self::assertSame($accepted ? 1 : null, $r['body']['data']['trusted']);

        return [$level, (int) self::installRow(self::U1)['install_row_id']];
    }

    public function testAnEventAfterTrustWasGrantedIsCreditedToTheClick(): void
    {
        [$level, $rowId] = $this->testInstall(false);
        $engine = $this->engine();
        $stale = $engine->installSubject(1, $rowId);
        self::assertNull($stale->clickId, 'built while the install was untrusted');

        (new AppRegistrationsController(self::$db, 1))->update(5, ['accept_test_signals' => 1]);
        self::assertSame('1', (string) self::installRow(self::U1)['trusted'], 'the window: trust granted and committed');

        $this->clock += 5;
        $engine->ingest(1, $stale, [$this->levelEvent('l1')]);

        self::assertSame(
            1,
            self::rows('202_goal_outcomes', "goal_id = $level AND superseded_at IS NULL AND conversion_id IS NOT NULL AND campaign_id = 30"),
            'the level the event reached is paid on the click the install is credited to now'
        );
        self::assertSame(1, self::rows('202_conversion_logs', "click_id = 100 AND source = 'goal' AND deleted = 0"));
        self::assertSame(0, self::rows('202_goal_outcomes', "goal_id = $level AND superseded_at IS NULL AND conversion_id IS NULL"), 'never a click-less outcome of a credited install');
    }

    public function testAnEventAfterTrustWasWithdrawnPaysNothing(): void
    {
        [$level, $rowId] = $this->testInstall(true);
        $engine = $this->engine();
        $stale = $engine->installSubject(1, $rowId);
        self::assertSame(100, $stale->clickId, 'built while the install was trusted');

        (new AppRegistrationsController(self::$db, 1))->update(5, ['accept_test_signals' => 0]);
        self::assertNull(self::installRow(self::U1)['trusted'], 'the window: trust withdrawn and committed');

        $this->clock += 5;
        $engine->ingest(1, $stale, [$this->levelEvent('l1')]);

        self::assertSame(0, self::rows('202_conversion_logs', "click_id = 100 AND source = 'goal' AND deleted = 0"), 'no live row on the click it no longer has');
        self::assertSame(0, self::rows('202_goal_outcomes', "goal_id = $level AND superseded_at IS NULL AND conversion_id IS NOT NULL"));
        self::assertSame(0, self::clickValue(100)['lead']);
    }

    public function testAReEvaluationJudgesEachInstallAsItIsWhenItsLockIsTaken(): void
    {
        [, $rowId] = $this->testInstall(false);
        // A registration goal the campaign pays for, reached while the
        // install is untrusted (a funnel outcome, no ledger row), then given
        // a second version to re-evaluate onto.
        $regGoal = $this->goals->create(1, \Prosper202\Goals\GoalScope::REGISTRATION, 5, \Prosper202\Goals\GoalDefinition::parse(
            ['name' => 'Level (app)', 'trigger' => ['event' => 'level_reached'], 'value' => ['type' => 'fixed', 'amount' => 4]]
        ), 1);
        $this->goals->attach(1, 30, $regGoal, null, true, 1);
        self::assertSame(200, $this->events(self::U1, [['event_id' => 'l1', 'name' => 'level_reached', 'occurred_at' => self::CLICK_TIME + 200]])['status']);
        self::assertSame(1, self::rows('202_goal_outcomes', "goal_id = $regGoal AND superseded_at IS NULL AND conversion_id IS NULL"));
        // Effective after the event was received, so only the re-evaluation
        // (which rebases the subject onto it) can write its outcome — the
        // policy change's own recredit evaluates the event under version 1.
        $this->clock += 100;
        $v2 = $this->goals->addVersion(1, $regGoal, \Prosper202\Goals\GoalDefinition::parse(
            ['name' => 'Level (app)', 'trigger' => ['event' => 'level_reached'], 'value' => ['type' => 'fixed', 'amount' => 5]]
        ), $this->clock)['version'];

        // reevaluate() builds the subject, then opens the subject's
        // transaction, whose first act is to read the clock: that is the
        // window, and the policy change commits in it on a second session.
        $other = mysqli_connect(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        $other->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
        $armed = false;
        $fired = false;
        $engine = $this->engine(function () use (&$armed, &$fired, $other): int {
            if ($armed && !$fired) {
                $fired = true;
                (new AppRegistrationsController($other, 1))->update(5, ['accept_test_signals' => 1]);
            }

            return $this->clock;
        });
        $armed = true;
        try {
            $engine->reevaluate(1, $regGoal, $v2, true, 100, 0, 'install');
        } finally {
            $other->close();
        }
        self::assertTrue($fired, 'the policy change landed inside the window');
        self::assertSame('1', (string) self::installRow(self::U1)['trusted']);

        self::assertSame(
            0,
            self::rows('202_goal_outcomes', "goal_id = $regGoal AND superseded_at IS NULL AND conversion_id IS NULL"),
            'the re-decided outcome is not written click-less for an install that is credited now'
        );
        self::assertSame(1, self::rows('202_goal_outcomes', "goal_id = $regGoal AND subject_id = $rowId AND superseded_at IS NULL AND conversion_id IS NOT NULL"));
        self::assertSame(
            ['lead' => 1, 'payout' => '9.00000'],
            self::clickValue(100),
            'the click accumulates the campaign\'s level ($4, re-credited by the policy change) and the new version\'s $5'
        );
    }
}
