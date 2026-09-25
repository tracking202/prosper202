<?php

declare(strict_types=1);

namespace Prosper202\Goals;

use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Conversion\Ledger\DedupeKey;
use Prosper202\Conversion\Ledger\MysqlConversionLedger;
use Prosper202\Conversion\Ledger\SupersededReason;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use Throwable;

/**
 * The server evaluator (plan §5.5): stores a subject's events, evaluates
 * them against the subject's goals, and writes what was reached — outcome
 * rows always, and ledger rows through the conversion ledger's own writer
 * when the subject has a click. Never a second writer of conversions: every
 * ledger row goes through MysqlConversionRepository::recordInTransaction(),
 * softDeleteInTransaction() or MysqlConversionLedger's goal-row marks, in
 * the same transaction as the events and outcomes that explain it.
 *
 * Per subject, every write takes the subject's lock row first
 * (202_goal_subjects, SELECT … FOR UPDATE), then the click's (inside the
 * ledger writer). Nothing takes them in the other order, so two requests for
 * one subject serialise and cannot deadlock each other.
 *
 * Two evaluation paths, one fold (GoalEvaluator):
 * - an event that sorts after everything stored continues from the stored
 *   progress (the common case, O(goals));
 * - an event that sorts before a stored one is a REPLAY: every event of the
 *   subject is evaluated again in order, and the result is reconciled with
 *   the stored outcomes — new ones written, identical ones untouched, and
 *   one whose n is now reached by a different event superseded
 *   (`replay`), outcome and ledger row alike. Nothing is withdrawn: adding
 *   an event can only add matches.
 *
 * Re-evaluation under a version (reevaluate()) is the explicit form of the
 * same reconciliation: the subject is rebased onto the version, and outcomes
 * the new result does not contain are retired (`reevaluation`), their
 * ledger rows superseded by the replacement or, where there is none,
 * soft-deleted.
 *
 * What an outcome pays is decided here, per the click's campaign
 * (202_campaign_goals), never by the evaluator. A row that is not paid
 * still carries the value it is reported at, so the breakdown can say
 * "$9.00, not paid" (a goal with no value carries 0):
 * - no campaign term for the goal: tracked at the goal's value, not paid;
 * - a term with a payout: payable, that amount;
 * - otherwise the goal's value: fixed → payable, that amount; none →
 *   tracked; from_property → payable only when the event's path may set a
 *   paid value (revenue_trusted) and the value was readable; an untrusted
 *   value is stored on the row but not credited (payable 0), and an
 *   unreadable one is tracked with its note;
 * - an ineligible outcome (no_click / no_install) is never payable.
 */
final class GoalEngine
{
    public const MAX_EVENTS_PER_SUBJECT = 10000;
    /** Re-evaluation handles at most this many subjects per call. */
    public const MAX_SUBJECTS_PER_CALL = 1000;

    private MysqlGoalRepository $goals;
    private MysqlConversionRepository $conversions;

    public function __construct(
        private Connection $conn,
        ?MysqlGoalRepository $goals = null,
        ?MysqlConversionRepository $conversions = null,
        /** @var (callable(): int)|null */
        private $clock = null,
        /** Told about written outcomes after each commit (plan §5.5 "notify traffic source"). */
        private ?OutcomeNotifier $notifier = null,
    ) {
        $this->goals = $goals ?? new MysqlGoalRepository($conn);
        $this->conversions = $conversions ?? new MysqlConversionRepository($conn);
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }

    /**
     * The click subject for a click of this user: its time is the `click`
     * anchor, its campaign decides the goal set and the payouts.
     */
    public function clickSubject(int $userId, int $clickId): GoalSubject
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT click_id, click_time, aff_campaign_id FROM 202_clicks WHERE click_id = ? AND user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$clickId, $userId]);
        $click = $this->conn->fetchOne($stmt);
        if ($click === null) {
            throw new GoalEngineException('Click ' . $clickId . ' not found', GoalEngineException::NOT_FOUND);
        }

        return new GoalSubject(
            GoalSubject::CLICK,
            (int) $click['click_id'],
            (int) $click['click_time'],
            null,
            [],
            (int) $click['click_id'],
            (int) $click['aff_campaign_id'],
        );
    }

    /**
     * The goal set a subject evaluates. A click subject evaluates its
     * campaign's; an install subject (PR 5) adds its registration's and the
     * account's, which is where this grows.
     *
     * @return list<GoalSpec>
     */
    public function specsFor(int $userId, GoalSubject $subject): array
    {
        if ($subject->campaignId === null) {
            return [];
        }

        return $this->goals->specsForCampaign($userId, $subject->campaignId);
    }

    // ─── Ingest ─────────────────────────────────────────────────────

    /**
     * Store a subject's events and evaluate them, in one transaction.
     *
     * An event id already stored with the same content is a duplicate
     * (answered, not re-evaluated); with different content it is refused
     * (EVENT_CONFLICT), and so is the whole batch. A subject holds at most
     * MAX_EVENTS_PER_SUBJECT events (EVENT_CAP).
     *
     * `outcomes` lists every outcome written (see OutcomeNotifier for the
     * shape and `kind`); `notifications` is what the notifier did with them
     * after the commit ([] without a notifier).
     *
     * @param list<GoalEvent> $events
     * @return array{accepted: list<string>, duplicates: list<string>, replayed: bool, outcomes_written: int, outcomes_retired: int, outcomes: list<array<string, mixed>>, notifications: list<array<string, mixed>>}
     */
    public function ingest(int $userId, GoalSubject $subject, array $events): array
    {
        $work = fn (): array => $this->ingestLocked($userId, $subject, $events);
        try {
            $done = $this->conn->transaction($work);
        } catch (Throwable $e) {
            if (!Connection::isRetryableLockError($e)) {
                throw $e;
            }
            $done = $this->conn->transaction($work);
        }
        $result = $done['result'];
        $result['outcomes'] = $done['post']['notices'];
        $result['notifications'] = $this->afterCommit($userId, $subject, $done['post']);

        return $result;
    }

    /**
     * @param list<GoalEvent> $events
     * @return array{result: array{accepted: list<string>, duplicates: list<string>, replayed: bool, outcomes_written: int, outcomes_retired: int}, post: array{ledger: list<array<string, mixed>>, clicks: array<int, true>, notices: list<array<string, mixed>>}}
     */
    private function ingestLocked(int $userId, GoalSubject $subject, array $events): array
    {
        $now = $this->now();
        $post = ['ledger' => [], 'clicks' => [], 'notices' => []];
        $row = $this->lockSubject($userId, $subject, $now);
        $subject = $subject->withRebases(self::decodeRebases($row));

        // Duplicates and conflicts, within the batch and against the store.
        $byId = [];
        $duplicates = [];
        foreach ($events as $event) {
            if (isset($byId[$event->eventId])) {
                if ($byId[$event->eventId]->fingerprint() !== $event->fingerprint()) {
                    throw new GoalEngineException(
                        'Event id "' . $event->eventId . '" appears twice in the request with different content.',
                        GoalEngineException::EVENT_CONFLICT
                    );
                }
                $duplicates[] = $event->eventId;
                continue;
            }
            $byId[$event->eventId] = $event;
        }
        $stored = $this->storedFingerprints($subject, array_keys($byId));
        $new = [];
        foreach ($byId as $id => $event) {
            $id = (string) $id;
            if (isset($stored[$id])) {
                // A time the intake's clock supplied is not part of what the
                // reporter said: a retry a second later is the same event,
                // so it is compared at the stored time.
                $compared = $event->clockedByServer ? $event->withOccurredAt($stored[$id]['occurred_at']) : $event;
                if ($stored[$id]['fingerprint'] !== $compared->fingerprint()) {
                    throw new GoalEngineException(
                        'Event id "' . $id . '" was already recorded for this ' . $subject->type . ' with different content; '
                        . 'an event id names one event. Send a new event id for a different event.',
                        GoalEngineException::EVENT_CONFLICT
                    );
                }
                $duplicates[] = $id;
                continue;
            }
            $new[] = $event;
        }

        $result = ['accepted' => [], 'duplicates' => $duplicates, 'replayed' => false, 'outcomes_written' => 0, 'outcomes_retired' => 0];
        if ($new === []) {
            return ['result' => $result, 'post' => $post];
        }
        $count = (int) $row['event_count'];
        if ($count + count($new) > self::MAX_EVENTS_PER_SUBJECT) {
            throw new GoalEngineException(
                'This ' . $subject->type . ' already holds ' . $count . ' events; a subject holds at most '
                . self::MAX_EVENTS_PER_SUBJECT . ', so ' . count($new) . ' more cannot be recorded.',
                GoalEngineException::EVENT_CAP
            );
        }

        usort($new, [GoalEvent::class, 'compare']);
        foreach ($new as $event) {
            $this->insertEvent($userId, $subject, $event);
            $result['accepted'][] = $event->eventId;
        }

        $last = self::lastEvent($row);
        $replay = $last !== null && GoalEvent::compare($new[0], $last) < 0;
        $newest = $new[count($new) - 1];
        if ($last !== null && GoalEvent::compare($last, $newest) > 0) {
            $newest = $last;
        }

        $specs = $this->specsFor($userId, $subject);
        if ($count === 0 || $replay) {
            $all = $count === 0 ? $new : $this->loadEvents($subject);
            $evaluation = GoalEvaluator::evaluateAll($specs, $subject, $all);
            $plan = $this->plan($userId, $subject, $evaluation->outcomes, null, SupersededReason::REPLAY, false);
            $counts = $this->execute($userId, $subject, $plan, $all, SupersededReason::REPLAY, $now, $post);
            $this->replaceProgress($userId, $subject, $evaluation->state);
            $result['replayed'] = $replay;
        } else {
            $state = $this->loadState($userId, $subject);
            $evaluation = GoalEvaluator::continueFrom($specs, $subject, $state, $new);
            $plan = ['keep' => [], 'write' => $evaluation->outcomes, 'retire' => []];
            $counts = $this->execute($userId, $subject, $plan, $new, SupersededReason::REPLAY, $now, $post);
            $this->upsertProgress($userId, $subject, $evaluation->state);
        }
        $result['outcomes_written'] = $counts['written'];
        $result['outcomes_retired'] = $counts['retired'];

        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_goal_subjects SET event_count = event_count + ?, last_effective_at = ?, last_received_at = ?, last_event_id = ?, updated_at = ?
             WHERE subject_type = ? AND subject_id = ?'
        );
        $this->conn->bind($stmt, 'iiisisi', [
            count($new), $newest->effectiveAt(), $newest->receivedAt, $newest->eventId, $now, $subject->type, $subject->id,
        ]);
        if ($this->conn->executeUpdate($stmt) !== 1) {
            throw new GoalEngineException('the goal subject row was not updated', GoalEngineException::INTEGRITY);
        }

        return ['result' => $result, 'post' => $post];
    }

    // ─── Re-evaluation ──────────────────────────────────────────────

    /**
     * Re-evaluate a goal's subjects under one of its versions (default: the
     * current one). With $apply false nothing is written and the answer is
     * the preview: per subject, the outcome rows that would be retired and
     * written and what happens to each retired row's ledger row. With $apply
     * true each subject is re-evaluated in its own transaction and rebased
     * onto the version, so later events and replays keep evaluating the
     * goal under it.
     *
     * Subjects are the clicks that have events, on the campaigns the goal
     * applies to (its own campaign and those that attach it), in click id
     * order after $after, at most $limit per call; `next_after` continues.
     * Install subjects join with PR 5.
     *
     * @return array<string, mixed>
     */
    public function reevaluate(int $userId, int $goalId, ?int $version, bool $apply, int $limit = 100, int $after = 0): array
    {
        $goal = $this->goals->find($userId, $goalId);
        if ($goal === null) {
            throw new GoalEngineException('Goal ' . $goalId . ' not found', GoalEngineException::NOT_FOUND);
        }
        $version ??= (int) $goal['current_version'];
        $versionRow = $this->goals->version($goalId, $version);
        if ($versionRow === null) {
            throw new GoalEngineException(
                'Goal ' . $goalId . ' has no version ' . $version . '; it has versions 1 to ' . (int) $goal['current_version'] . '.',
                GoalEngineException::INVALID,
                ['version' => 'must be one of the goal\'s versions (1-' . (int) $goal['current_version'] . ')']
            );
        }
        try {
            GoalDefinition::fromJson($versionRow['definition'], $goalId);
        } catch (InvalidGoalDefinition $e) {
            throw new GoalEngineException(
                'Goal ' . $goalId . ' version ' . $version . ' is stored invalid and cannot be applied: ' . $e->getMessage(),
                GoalEngineException::INVALID
            );
        }
        $limit = max(1, min(self::MAX_SUBJECTS_PER_CALL, $limit));

        $campaigns = array_map(static fn (array $r): int => (int) $r['campaign_id'], $this->goals->campaignsForGoal($userId, $goalId));
        if (GoalScope::fromStored($goal['scope']) === GoalScope::CAMPAIGN) {
            $campaigns[] = (int) $goal['scope_id'];
        }
        $campaigns = array_values(array_unique($campaigns));

        $subjectIds = [];
        if ($campaigns !== []) {
            $marks = implode(',', array_fill(0, count($campaigns), '?'));
            $stmt = $this->conn->prepareWrite(
                "SELECT s.subject_id FROM 202_goal_subjects s JOIN 202_clicks c ON c.click_id = s.subject_id
                 WHERE s.subject_type = 'click' AND s.user_id = ? AND s.subject_id > ? AND s.event_count > 0
                   AND c.aff_campaign_id IN ($marks)
                 ORDER BY s.subject_id LIMIT ?"
            );
            $this->conn->bind($stmt, 'ii' . str_repeat('i', count($campaigns)) . 'i', [$userId, $after, ...$campaigns, $limit + 1]);
            $subjectIds = array_map(static fn (array $r): int => (int) $r['subject_id'], $this->conn->fetchAll($stmt));
        }
        $more = count($subjectIds) > $limit;
        $subjectIds = array_slice($subjectIds, 0, $limit);

        $subjects = [];
        foreach ($subjectIds as $clickId) {
            $subject = $this->clickSubject($userId, $clickId);
            if ($apply) {
                $work = fn (): array => $this->reevaluateLocked($userId, $subject, $goalId, $version);
                try {
                    $done = $this->conn->transaction($work);
                } catch (Throwable $e) {
                    if (!Connection::isRetryableLockError($e)) {
                        throw $e;
                    }
                    $done = $this->conn->transaction($work);
                }
                $subjects[] = $done['summary'] + ['notifications' => $this->afterCommit($userId, $subject, $done['post'])];
            } else {
                $subjects[] = $this->reevaluationPlanFor($userId, $subject, $goalId, $version, null)['summary'];
            }
        }

        return [
            'goal_id' => $goalId,
            'version' => $version,
            'applied' => $apply,
            'subjects' => $subjects,
            'totals' => [
                'subjects' => count($subjects),
                'retire' => array_sum(array_map(static fn (array $s): int => count($s['retire']), $subjects)),
                'write' => array_sum(array_map(static fn (array $s): int => count($s['write']), $subjects)),
                'ledger_superseded' => array_sum(array_map(static fn (array $s): int => count(array_filter($s['retire'], static fn (array $r): bool => $r['ledger'] === 'supersede')), $subjects)),
                'ledger_deleted' => array_sum(array_map(static fn (array $s): int => count(array_filter($s['retire'], static fn (array $r): bool => $r['ledger'] === 'delete')), $subjects)),
            ],
            'next_after' => $more && $subjectIds !== [] ? $subjectIds[count($subjectIds) - 1] : null,
        ];
    }

    /** @return array{summary: array<string, mixed>, post: array{ledger: list<array<string, mixed>>, clicks: array<int, true>, notices: list<array<string, mixed>>}} */
    private function reevaluateLocked(int $userId, GoalSubject $subject, int $goalId, int $version): array
    {
        $now = $this->now();
        $post = ['ledger' => [], 'clicks' => [], 'notices' => []];
        $row = $this->lockSubject($userId, $subject, $now);
        $rebases = self::decodeRebases($row);
        $rebases[$goalId] = $version;

        $planned = $this->reevaluationPlanFor($userId, $subject->withRebases($rebases), $goalId, $version, $row);
        $this->execute($userId, $planned['subject'], $planned['plan'], $planned['events'], SupersededReason::REEVALUATION, $now, $post);
        $this->replaceProgress($userId, $planned['subject'], $planned['state']);

        $stmt = $this->conn->prepareWrite('UPDATE 202_goal_subjects SET rebases = ?, updated_at = ? WHERE subject_type = ? AND subject_id = ?');
        ksort($rebases);
        $this->conn->bind($stmt, 'sisi', [json_encode((object) $rebases, JSON_THROW_ON_ERROR), $now, $subject->type, $subject->id]);
        $this->conn->executeUpdate($stmt);

        return ['summary' => $planned['summary'], 'post' => $post];
    }

    /**
     * @param array<string, mixed>|null $lockedRow the subject row when the caller holds its lock
     * @return array{summary: array<string, mixed>, plan: array{keep: array<string, array<string, mixed>>, write: list<Outcome>, retire: list<array{0: array<string, mixed>, 1: string|null}>}, events: list<GoalEvent>, state: EvaluationState, subject: GoalSubject}
     */
    private function reevaluationPlanFor(int $userId, GoalSubject $subject, int $goalId, int $version, ?array $lockedRow): array
    {
        if ($lockedRow === null) {
            // Preview: read the stored rebases without locking, and apply
            // this one, exactly as the apply path will.
            $stmt = $this->conn->prepareWrite('SELECT * FROM 202_goal_subjects WHERE subject_type = ? AND subject_id = ? LIMIT 1');
            $this->conn->bind($stmt, 'si', [$subject->type, $subject->id]);
            $rebases = self::decodeRebases($this->conn->fetchOne($stmt) ?? []);
            $rebases[$goalId] = $version;
            $subject = $subject->withRebases($rebases);
        }
        $events = $this->loadEvents($subject);
        $specs = $this->specsFor($userId, $subject);
        $evaluation = GoalEvaluator::evaluateAll($specs, $subject, $events);
        $mine = array_values(array_filter($evaluation->outcomes, static fn (Outcome $o): bool => $o->goalId === $goalId));
        $plan = $this->plan($userId, $subject, $mine, $goalId, SupersededReason::REEVALUATION, true, $version, $lockedRow !== null);

        $retire = [];
        foreach ($plan['retire'] as [$stored, $replacementKey]) {
            $replacement = $replacementKey !== null ? ($plan['keep'][$replacementKey] ?? null) : null;
            $replacementIsNew = $replacementKey !== null && $replacement === null;
            $ledger = null;
            if ($stored['conversion_id'] !== null) {
                $ledger = $replacementKey !== null && $subject->clickId !== null ? 'supersede' : 'delete';
            }
            $retire[] = [
                'outcome_id' => (int) $stored['outcome_id'],
                'version' => (int) $stored['goal_version'],
                'n' => (int) $stored['n'],
                'event_id' => (string) $stored['event_id'],
                'conversion_id' => $stored['conversion_id'] !== null ? (int) $stored['conversion_id'] : null,
                'replaced_by' => $replacementKey === null ? null : ($replacementIsNew ? 'new' : (int) $replacement['outcome_id']),
                'ledger' => $ledger,
            ];
        }

        return [
            'summary' => [
                'subject_type' => $subject->type,
                'subject_id' => $subject->id,
                'retire' => $retire,
                'write' => array_map(static fn (Outcome $o): array => $o->toArray(), $plan['write']),
                'unchanged' => count($plan['keep']),
            ],
            'plan' => $plan,
            'events' => $events,
            'state' => $evaluation->state,
            'subject' => $subject,
        ];
    }

    // ─── Reconciliation ─────────────────────────────────────────────

    /**
     * Compare a recomputed set of outcomes with the stored live ones.
     *
     * - A recomputed outcome whose (goal, version, n) is stored with the same
     *   event, time and eligibility is kept.
     * - One stored with a different event is written anew, and the stored
     *   row is retired with the new one as its replacement.
     * - With $retireUnmatched (re-evaluation), every other stored row of the
     *   goal is retired too, replaced by the recomputed outcome with the same
     *   n at the target version where there is one.
     *
     * @param list<Outcome> $recomputed
     * @return array{keep: array<string, array<string, mixed>>, write: list<Outcome>, retire: list<array{0: array<string, mixed>, 1: string|null}>}
     */
    private function plan(
        int $userId,
        GoalSubject $subject,
        array $recomputed,
        ?int $onlyGoal,
        SupersededReason $reason,
        bool $retireUnmatched,
        ?int $targetVersion = null,
        bool $lock = true,
    ): array {
        $filters = ['subject_type' => $subject->type, 'subject_id' => $subject->id];
        if ($onlyGoal !== null) {
            $filters['goal_id'] = $onlyGoal;
        }
        $stored = [];
        foreach ($this->goals->liveOutcomes($userId, $filters, self::MAX_EVENTS_PER_SUBJECT * 10, 0, $lock) as $row) {
            $key = $row['goal_id'] . ':' . $row['goal_version'] . ':' . $row['n'];
            if (isset($stored[$key])) {
                throw new GoalEngineException(
                    'outcomes ' . $stored[$key]['outcome_id'] . ' and ' . $row['outcome_id'] . ' are both live for goal '
                    . $row['goal_id'] . ' version ' . $row['goal_version'] . ' n ' . $row['n'] . ' of ' . $subject->type . ' ' . $subject->id,
                    GoalEngineException::INTEGRITY
                );
            }
            $stored[$key] = $row;
        }

        $keep = [];
        $write = [];
        $retire = [];
        $recomputedKeys = [];
        foreach ($recomputed as $o) {
            $key = $o->goalId . ':' . $o->version . ':' . $o->n;
            $recomputedKeys[$key] = $o;
            $s = $stored[$key] ?? null;
            if ($s !== null
                && (string) $s['event_id'] === $o->eventId
                && (int) $s['reached_at'] === $o->reachedAt
                && ($s['ineligible_reason'] ?? null) === $o->ineligibleReason) {
                $keep[$key] = $s;
                continue;
            }
            $write[] = $o;
            if ($s !== null) {
                $retire[] = [$s, $key];
                unset($stored[$key]);
            }
        }

        if ($retireUnmatched) {
            foreach ($stored as $key => $s) {
                if (isset($keep[$key])) {
                    continue;
                }
                $replacement = null;
                $n = (int) $s['n'];
                $goal = (int) $s['goal_id'];
                if ($targetVersion !== null && isset($recomputedKeys[$goal . ':' . $targetVersion . ':' . $n])) {
                    $replacement = $goal . ':' . $targetVersion . ':' . $n;
                } else {
                    foreach ($recomputedKeys as $rk => $o) {
                        if ($o->goalId === $goal && $o->n === $n) {
                            $replacement = $rk;
                        }
                    }
                }
                $retire[] = [$s, $replacement];
            }
        }

        return ['keep' => $keep, 'write' => $write, 'retire' => $retire];
    }

    /**
     * Carry out a plan: write the new outcomes (and their ledger rows), then
     * retire the replaced ones (and supersede or delete theirs).
     *
     * @param array{keep: array<string, array<string, mixed>>, write: list<Outcome>, retire: list<array{0: array<string, mixed>, 1: string|null}>} $plan
     * Each written outcome is also noted in $post['notices'] with the
     * notification decision (OutcomeNotifier). A written outcome is a
     * `replacement`, never announced as newly reached, when it takes the
     * place of a retired one, or when its reaching event reached the same
     * goal in an outcome this plan retires: a replay that shifts n moves an
     * already-announced event to a new n ($5, $10 and a late $1 become $1,
     * $5, $10), and announcing the "new" third outcome would tell the
     * network about the $10 a second time.
     *
     * @param list<GoalEvent> $events
     * @param array{ledger: list<array<string, mixed>>, clicks: array<int, true>, notices: list<array<string, mixed>>} $post
     * @return array{written: int, retired: int}
     */
    private function execute(int $userId, GoalSubject $subject, array $plan, array $events, SupersededReason $reason, int $now, array &$post): array
    {
        $eventsById = [];
        foreach ($events as $event) {
            $eventsById[$event->eventId] = $event;
        }
        $terms = $subject->campaignId !== null ? $this->goals->campaignTerms($subject->campaignId) : [];

        $ids = [];
        foreach ($plan['keep'] as $key => $row) {
            $ids[$key] = ['outcome_id' => (int) $row['outcome_id'], 'conversion_id' => $row['conversion_id'] !== null ? (int) $row['conversion_id'] : null];
        }
        // The outcomes written in place of a retired one: a traffic source
        // may already have been told about the one they replace.
        $replacing = [];
        $retiredEvents = [];
        foreach ($plan['retire'] as [$stored, $replacementKey]) {
            if ($replacementKey !== null) {
                $replacing[$replacementKey] = true;
            }
            $retiredEvents[(int) $stored['goal_id'] . "\0" . (string) $stored['event_id']] = true;
        }
        foreach ($plan['write'] as $o) {
            $event = $eventsById[$o->eventId] ?? null;
            if ($event === null && $o->eventId !== GoalEvent::INSTALL_EVENT_ID) {
                // The reaching event is stored but was not handed in: load it.
                $event = $this->loadEvent($subject, $o->eventId);
            }
            $key = $o->goalId . ':' . $o->version . ':' . $o->n;
            $term = $terms[$o->goalId] ?? null;
            $written = $this->writeOutcome($userId, $subject, $o, $term, $event, $now, $post);
            $ids[$key] = ['outcome_id' => $written['outcome_id'], 'conversion_id' => $written['conversion_id']];
            $announced = isset($replacing[$key]) || isset($retiredEvents[$o->goalId . "\0" . $o->eventId]) || $written['revived'];
            $post['notices'][] = self::notice($o, $written, $term, $subject, $announced);
        }

        $ledger = new MysqlConversionLedger($this->conn);
        foreach ($plan['retire'] as [$stored, $replacementKey]) {
            $replacement = $replacementKey !== null ? ($ids[$replacementKey] ?? null) : null;
            $stmt = $this->conn->prepareWrite(
                'UPDATE 202_goal_outcomes SET superseded_by = ?, superseded_reason = ?, superseded_at = ?
                 WHERE outcome_id = ? AND superseded_at IS NULL'
            );
            $this->conn->bind($stmt, 'isii', [$replacement['outcome_id'] ?? null, $reason->value, $now, (int) $stored['outcome_id']]);
            if ($this->conn->executeUpdate($stmt) !== 1) {
                throw new GoalEngineException('outcome ' . (int) $stored['outcome_id'] . ' could not be retired', GoalEngineException::INTEGRITY);
            }
            if ($stored['conversion_id'] === null) {
                continue;
            }
            $convId = (int) $stored['conversion_id'];
            if ($replacement !== null && $replacement['conversion_id'] !== null) {
                $ledger->supersedeGoalRow($convId, $replacement['conversion_id'], $reason);
                if ($subject->clickId !== null) {
                    $post['clicks'][$subject->clickId] = true;
                }
            } elseif ($reason === SupersededReason::REPLAY) {
                $ledger->supersedeGoalRow($convId, null, $reason);
                if ($subject->clickId !== null) {
                    $post['clicks'][$subject->clickId] = true;
                }
            } else {
                // Retired with nothing in its place: a row cannot be
                // superseded by a row that does not exist, so it is deleted
                // (recomputing the click under its lock).
                $clickId = $this->conversions->softDeleteInTransaction($convId, $userId);
                if ($clickId !== null) {
                    $post['clicks'][$clickId] = true;
                }
            }
        }

        return ['written' => count($plan['write']), 'retired' => count($plan['retire'])];
    }

    /**
     * Write one outcome row and, when the subject has a click, its ledger row.
     *
     * @param array<string, mixed>|null $term the campaign_goals row for this goal
     * @param array{ledger: list<array<string, mixed>>, clicks: array<int, true>, notices: list<array<string, mixed>>} $post
     * @return array{outcome_id: int, conversion_id: int|null, revived: bool, conversion_new: bool, payable: bool, amount_units: int|null, transaction_id: string|null, dedupe_key: string|null}
     */
    private function writeOutcome(int $userId, GoalSubject $subject, Outcome $o, ?array $term, ?GoalEvent $event, int $now, array &$post): array
    {
        [$payable, $amountUnits, $source, $note] = self::payability($o, $term, $event);

        // A retired row for exactly this outcome is revived rather than
        // duplicated: the UNIQUE key names the event, and a re-evaluation
        // can return to an outcome an earlier one retired.
        $find = $this->conn->prepareWrite(
            'SELECT outcome_id, conversion_id, superseded_at FROM 202_goal_outcomes
             WHERE subject_type = ? AND subject_id = ? AND goal_id = ? AND goal_version = ? AND n = ? AND event_id = ? LIMIT 1 FOR UPDATE'
        );
        $this->conn->bind($find, 'siiiis', [$subject->type, $subject->id, $o->goalId, $o->version, $o->n, $o->eventId]);
        $existing = $this->conn->fetchOne($find);
        if ($existing !== null) {
            if ($existing['superseded_at'] === null) {
                throw new GoalEngineException(
                    'outcome ' . (int) $existing['outcome_id'] . ' is live but was not matched by the reconciliation',
                    GoalEngineException::INTEGRITY
                );
            }
            $revive = $this->conn->prepareWrite(
                'UPDATE 202_goal_outcomes SET superseded_by = NULL, superseded_reason = NULL, superseded_at = NULL WHERE outcome_id = ?'
            );
            $this->conn->bind($revive, 'i', [(int) $existing['outcome_id']]);
            $this->conn->executeUpdate($revive);
            $convId = $existing['conversion_id'] !== null ? (int) $existing['conversion_id'] : null;
            if ($convId !== null) {
                (new MysqlConversionLedger($this->conn))->reviveGoalRow($convId);
                if ($subject->clickId !== null) {
                    $post['clicks'][$subject->clickId] = true;
                }
            }

            return [
                'outcome_id' => (int) $existing['outcome_id'], 'conversion_id' => $convId, 'revived' => true, 'conversion_new' => false,
                'payable' => $payable, 'amount_units' => $amountUnits, 'transaction_id' => $event?->transactionId, 'dedupe_key' => null,
            ];
        }

        $insert = $this->conn->prepareWrite(
            'INSERT INTO 202_goal_outcomes
                (user_id, subject_type, subject_id, goal_id, goal_version, n, event_id, reached_at, value, value_source,
                 value_note, ineligible_reason, payable, campaign_id, conversion_id, superseded_by, superseded_reason, superseded_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, ?)'
        );
        $this->conn->bind($insert, 'isiiiisissssiii', [
            $userId, $subject->type, $subject->id, $o->goalId, $o->version, $o->n, $o->eventId, $o->reachedAt,
            $amountUnits === null ? null : Amount::fromUnits($amountUnits),
            $source, $note, $o->ineligibleReason, $payable ? 1 : 0, $subject->campaignId, $now,
        ]);
        $outcomeId = $this->conn->executeInsert($insert);
        if ($outcomeId <= 0) {
            throw new GoalEngineException('the outcome insert returned no id', GoalEngineException::INTEGRITY);
        }

        $convId = null;
        $conversionNew = false;
        $dedupeKey = null;
        if ($subject->clickId !== null) {
            $data = [
                'click_id' => $subject->clickId,
                'source' => ConversionSource::GOAL->value,
                'source_ref' => \Prosper202\Conversion\Ledger\SourceRef::goal($o->goalId, $o->version),
                'event_name' => $event?->name,
                'payable' => $payable,
                'payout' => Amount::fromUnits($payable ? (int) $amountUnits : ($amountUnits ?? 0)),
                'dedupe_key' => DedupeKey::goal($o->goalId, $o->version, $o->n, $o->eventId),
                'conv_time' => $o->reachedAt,
                // A tracked outcome is not a sale: no revenue event, no bridge.
                'skip_ltv' => !$payable,
                'skip_bridge' => !$payable,
            ];
            if ($event?->transactionId !== null) {
                $data['transaction_id'] = $event->transactionId;
            }
            $recorded = $this->conversions->recordInTransaction($userId, $data);
            if (!$recorded['clickFound']) {
                throw new GoalEngineException('click ' . $subject->clickId . ' is gone; its outcome cannot be recorded', GoalEngineException::INTEGRITY);
            }
            $convId = (int) $recorded['convId'];
            if ($convId <= 0) {
                throw new GoalEngineException('the ledger returned no conversion for outcome ' . $outcomeId, GoalEngineException::INTEGRITY);
            }
            if (!$recorded['duplicate']) {
                $post['ledger'][] = $recorded;
                $conversionNew = true;
            }
            $dedupeKey = isset($recorded['dedupeKey']) ? (string) $recorded['dedupeKey'] : null;
            $link = $this->conn->prepareWrite('UPDATE 202_goal_outcomes SET conversion_id = ? WHERE outcome_id = ?');
            $this->conn->bind($link, 'ii', [$convId, $outcomeId]);
            $this->conn->executeUpdate($link);
        }

        return [
            'outcome_id' => $outcomeId, 'conversion_id' => $convId, 'revived' => false, 'conversion_new' => $conversionNew,
            'payable' => $payable, 'amount_units' => $amountUnits, 'transaction_id' => $event?->transactionId, 'dedupe_key' => $dedupeKey,
        ];
    }

    /**
     * What a written outcome is, for the notifier (see OutcomeNotifier for
     * the kinds). Decided here, inside the transaction that knows which
     * rows are replacements, so no caller can announce a replacement as a
     * newly reached outcome.
     *
     * @param array{outcome_id: int, conversion_id: int|null, revived: bool, conversion_new: bool, payable: bool, amount_units: int|null, transaction_id: string|null, dedupe_key: string|null} $written
     * @param array<string, mixed>|null $term
     * @return array<string, mixed>
     */
    private static function notice(Outcome $o, array $written, ?array $term, GoalSubject $subject, bool $replacement): array
    {
        $kind = 'none';
        $reason = null;
        if ($written['payable']) {
            if ($term === null || (int) ($term['notify_traffic_source'] ?? 0) !== 1) {
                $kind = 'off';
            } elseif ($subject->clickId === null) {
                [$kind, $reason] = ['suppressed', 'no_click'];
            } elseif ($replacement || !$written['conversion_new']) {
                [$kind, $reason] = ['suppressed', 'replacement'];
            } else {
                $kind = 'reached';
            }
        }

        return [
            'goal_id' => $o->goalId,
            'version' => $o->version,
            'n' => $o->n,
            'event_id' => $o->eventId,
            'outcome_id' => $written['outcome_id'],
            'conversion_id' => $written['conversion_id'],
            'payable' => $written['payable'],
            'amount' => $written['amount_units'] === null ? null : Amount::fromUnits($written['amount_units']),
            'transaction_id' => $written['transaction_id'],
            'dedupe_key' => $written['dedupe_key'],
            'kind' => $kind,
            'reason' => $reason,
        ];
    }

    /**
     * Whether an outcome pays on this campaign, and how much (see the class
     * docblock).
     *
     * @param array<string, mixed>|null $term
     * @return array{0: bool, 1: int|null, 2: string, 3: string|null} payable, amount units, value source, note
     */
    public static function payability(Outcome $o, ?array $term, ?GoalEvent $event): array
    {
        if (!$o->isEligible()) {
            return [false, $o->valueUnits, $o->valueSource, $o->valueNote];
        }
        if ($term === null) {
            return [false, $o->valueUnits, $o->valueSource, $o->valueNote ?? 'not_payable_on_campaign'];
        }
        if ($term['payout'] !== null && $term['payout'] !== '') {
            return [true, Amount::toUnits((string) $term['payout']), 'payout', null];
        }
        if ($o->valueSource === Outcome::SOURCE_FIXED) {
            return [true, $o->valueUnits, $o->valueSource, null];
        }
        if ($o->valueSource === Outcome::SOURCE_NONE) {
            return [false, null, $o->valueSource, null];
        }
        if ($o->valueUnits === null) {
            return [false, null, $o->valueSource, $o->valueNote];
        }
        if ($event === null || !$event->revenueTrusted) {
            return [false, $o->valueUnits, $o->valueSource, 'untrusted_value'];
        }

        return [true, $o->valueUnits, $o->valueSource, null];
    }

    /**
     * The committed transaction's follow-ups: report rows, bridge events,
     * then the notifier. Nothing here may make the write look failed — it
     * has committed — so a notifier that throws is logged and its notices
     * are reported as `failed` (CLAUDE.md #13: a caller must not retry a
     * write because its announcement failed).
     *
     * @param array{ledger: list<array<string, mixed>>, clicks: array<int, true>, notices: list<array<string, mixed>>} $post
     * @return list<array<string, mixed>> what the notifier did
     */
    private function afterCommit(int $userId, GoalSubject $subject, array $post): array
    {
        foreach ($post['ledger'] as $recorded) {
            $this->conversions->afterRecordInTransaction($userId, $recorded);
            unset($post['clicks'][(int) $recorded['_prepared']['clickId']]);
        }
        foreach (array_keys($post['clicks']) as $clickId) {
            $this->conversions->refreshClickReport($clickId);
        }
        if ($this->notifier === null || $post['notices'] === []) {
            return [];
        }
        try {
            return $this->notifier->notify($userId, $subject, $post['notices']);
        } catch (Throwable $e) {
            error_log('p202 goals: notifying the traffic source for ' . $subject->type . ' ' . $subject->id . ' failed: ' . $e->getMessage());

            return array_map(static fn (array $n): array => [
                'goal_id' => $n['goal_id'], 'n' => $n['n'], 'outcome_id' => $n['outcome_id'], 'kind' => $n['kind'],
                'status' => $n['kind'] === 'reached' ? 'failed' : 'not_sent',
            ], $post['notices']);
        }
    }

    // ─── Subject state ──────────────────────────────────────────────

    /** @return array<string, mixed> the subject row, locked (created on first sight) */
    private function lockSubject(int $userId, GoalSubject $subject, int $now): array
    {
        $insert = $this->conn->prepareWrite(
            'INSERT INTO 202_goal_subjects (subject_type, subject_id, user_id, event_count, created_at, updated_at)
             VALUES (?, ?, ?, 0, ?, ?) ON DUPLICATE KEY UPDATE subject_id = subject_id'
        );
        $this->conn->bind($insert, 'siiii', [$subject->type, $subject->id, $userId, $now, $now]);
        $this->conn->executeUpdate($insert);

        $stmt = $this->conn->prepareWrite('SELECT * FROM 202_goal_subjects WHERE subject_type = ? AND subject_id = ? LIMIT 1 FOR UPDATE');
        $this->conn->bind($stmt, 'si', [$subject->type, $subject->id]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            throw new GoalEngineException('the goal subject row could not be locked', GoalEngineException::INTEGRITY);
        }
        if ((int) $row['user_id'] !== $userId) {
            throw new GoalEngineException(ucfirst($subject->type) . ' ' . $subject->id . ' not found', GoalEngineException::NOT_FOUND);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, int>
     */
    private static function decodeRebases(array $row): array
    {
        $raw = $row['rebases'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new GoalEngineException(
                'goal subject ' . ($row['subject_type'] ?? '?') . ' ' . ($row['subject_id'] ?? '?') . ' has unreadable rebases: ' . (string) $raw,
                GoalEngineException::INTEGRITY
            );
        }
        $out = [];
        foreach ($decoded as $goalId => $version) {
            if (!is_int($version) || (int) $goalId <= 0 || (string) (int) $goalId !== (string) $goalId) {
                throw new GoalEngineException(
                    'goal subject ' . ($row['subject_type'] ?? '?') . ' ' . ($row['subject_id'] ?? '?') . ' has unreadable rebases: ' . (string) $raw,
                    GoalEngineException::INTEGRITY
                );
            }
            $out[(int) $goalId] = $version;
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private static function lastEvent(array $row): ?GoalEvent
    {
        if ((int) ($row['event_count'] ?? 0) === 0 || $row['last_event_id'] === null) {
            return null;
        }
        $effective = (int) $row['last_effective_at'];

        return new GoalEvent((string) $row['last_event_id'], null, $effective, (int) $row['last_received_at'], [], null, false, null);
    }

    /**
     * @param list<string> $eventIds
     * @return array<string, array{fingerprint: string, occurred_at: int}> event id => its stored identity
     */
    private function storedFingerprints(GoalSubject $subject, array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($eventIds, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->conn->prepareWrite(
                "SELECT event_id, fingerprint, occurred_at FROM 202_goal_events WHERE subject_type = ? AND subject_id = ? AND event_id IN ($marks)"
            );
            $this->conn->bind($stmt, 'si' . str_repeat('s', count($chunk)), [$subject->type, $subject->id, ...$chunk]);
            foreach ($this->conn->fetchAll($stmt) as $row) {
                $out[(string) $row['event_id']] = ['fingerprint' => (string) $row['fingerprint'], 'occurred_at' => (int) $row['occurred_at']];
            }
        }

        return $out;
    }

    private function insertEvent(int $userId, GoalSubject $subject, GoalEvent $event): void
    {
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_goal_events
                (subject_type, subject_id, user_id, event_id, name, properties, revenue, currency, revenue_trusted, transaction_id,
                 occurred_at, received_at, fingerprint)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)'
        );
        $this->conn->bind($stmt, 'siissssisiis', [
            $subject->type, $subject->id, $userId, $event->eventId, (string) $event->name,
            json_encode((object) $event->properties, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $event->revenue === null ? null : json_encode($event->revenue, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            $event->revenueTrusted ? 1 : 0, $event->transactionId,
            $event->occurredAt, $event->receivedAt, $event->fingerprint(),
        ]);
        $this->conn->executeUpdate($stmt);
    }

    /** @return list<GoalEvent> */
    private function loadEvents(GoalSubject $subject): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT * FROM 202_goal_events WHERE subject_type = ? AND subject_id = ? ORDER BY event_row_id'
        );
        $this->conn->bind($stmt, 'si', [$subject->type, $subject->id]);

        return array_map([self::class, 'eventFromRow'], $this->conn->fetchAll($stmt));
    }

    private function loadEvent(GoalSubject $subject, string $eventId): GoalEvent
    {
        $stmt = $this->conn->prepareWrite('SELECT * FROM 202_goal_events WHERE subject_type = ? AND subject_id = ? AND event_id = ? LIMIT 1');
        $this->conn->bind($stmt, 'sis', [$subject->type, $subject->id, $eventId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            throw new GoalEngineException('event "' . $eventId . '" of ' . $subject->type . ' ' . $subject->id . ' is not stored', GoalEngineException::INTEGRITY);
        }

        return self::eventFromRow($row);
    }

    /** @param array<string, mixed> $row */
    public static function eventFromRow(array $row): GoalEvent
    {
        $where = 'goal event row ' . (int) $row['event_row_id'];
        $props = json_decode((string) $row['properties'], true);
        if (!is_array($props)) {
            throw new GoalEngineException($where . ' has unreadable properties', GoalEngineException::INTEGRITY);
        }
        $revenue = null;
        if ($row['revenue'] !== null) {
            $revenue = json_decode((string) $row['revenue'], true);
            if (!is_int($revenue) && !is_float($revenue)) {
                throw new GoalEngineException($where . ' has unreadable revenue "' . (string) $row['revenue'] . '"', GoalEngineException::INTEGRITY);
            }
        }

        /** @var array<string, string|int|float|bool> $props */
        return new GoalEvent(
            (string) $row['event_id'],
            (string) $row['name'],
            (int) $row['occurred_at'],
            (int) $row['received_at'],
            $props,
            $revenue,
            (int) $row['revenue_trusted'] === 1,
            $row['transaction_id'] !== null ? (string) $row['transaction_id'] : null,
        );
    }

    private function loadState(int $userId, GoalSubject $subject): EvaluationState
    {
        $state = new EvaluationState();
        $stmt = $this->conn->prepareWrite(
            'SELECT goal_id, goal_version, `count`, `sum`, times_reached, reached_at FROM 202_goal_progress WHERE subject_type = ? AND subject_id = ?'
        );
        $this->conn->bind($stmt, 'si', [$subject->type, $subject->id]);
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $p = &$state->entry((int) $row['goal_id'], (int) $row['goal_version']);
            $p['count'] = (int) $row['count'];
            $p['sum'] = Amount::toUnits((string) $row['sum']);
            $p['times'] = (int) $row['times_reached'];
            $p['reached_at'] = $row['reached_at'] !== null ? (int) $row['reached_at'] : null;
            unset($p);
        }
        foreach ($this->goals->liveOutcomes($userId, ['subject_type' => $subject->type, 'subject_id' => $subject->id], self::MAX_EVENTS_PER_SUBJECT * 10) as $row) {
            if ($row['ineligible_reason'] === null) {
                $state->reached[(int) $row['goal_id']] = true;
            }
        }

        return $state;
    }

    private function replaceProgress(int $userId, GoalSubject $subject, EvaluationState $state): void
    {
        $stmt = $this->conn->prepareWrite('DELETE FROM 202_goal_progress WHERE subject_type = ? AND subject_id = ?');
        $this->conn->bind($stmt, 'si', [$subject->type, $subject->id]);
        $this->conn->executeUpdate($stmt);
        $this->upsertProgress($userId, $subject, $state);
    }

    private function upsertProgress(int $userId, GoalSubject $subject, EvaluationState $state): void
    {
        foreach ($state->progress as $p) {
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_goal_progress (subject_type, subject_id, goal_id, goal_version, user_id, `count`, `sum`, times_reached, reached_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE `count` = VALUES(`count`), `sum` = VALUES(`sum`),
                    times_reached = VALUES(times_reached), reached_at = VALUES(reached_at)'
            );
            $this->conn->bind($stmt, 'siiiiisii', [
                $subject->type, $subject->id, $p['goal_id'], $p['version'], $userId,
                $p['count'], Amount::fromUnits($p['sum']), $p['times'], $p['reached_at'],
            ]);
            $this->conn->executeUpdate($stmt);
        }
    }
}
