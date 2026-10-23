<?php

declare(strict_types=1);

/**
 * An app's own goals on Setup › Mobile Apps (plan §5.5, §5.6, PR 11): the
 * steps after the install — a tutorial, a level, a purchase — each on its own
 * event, a funnel through `after`, windows counted from the install.
 *
 * The form is 4b's campaign goal form (campaign_goals.php), type-preserving
 * the same way: the common shape is edited here, and a goal the form cannot
 * show faithfully — a running sum, several conditions, a window from the
 * click (which no device can reach) — is listed with `p202 goal update <id>`
 * rather than silently rewritten. What differs is the owner: an app goal
 * belongs to its registration (`scope: registration`), carries no payout
 * (campaigns attach the goals they pay for), and its window counts from
 * the install.
 *
 * Every write goes through GoalsController, so the page and the API refuse
 * the same definitions with the same sentences.
 */

use Api\V3\Controllers\GoalsController;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Prosper202\Goals\GoalDefinition;
use Prosper202\Goals\InvalidGoalDefinition;

require_once __DIR__ . '/campaign_goals.php';

/**
 * Save the posted app goal: add, or edit when goal_id names one of this
 * app's own goals. Returns the errors by form field; [] when saved.
 *
 * @param array<string, string> $v the form values (p202_goal_form_values())
 * @return array<string, string>
 */
function p202_app_goal_save(mysqli $db, int $userId, int $registrationId, array $v): array
{
    $built = p202_goal_definition_from_form($v, 'install');
    $errors = $built['errors'];
    try {
        GoalDefinition::parse($built['definition'], $v['goal_id'] !== '' ? (int) $v['goal_id'] : null);
    } catch (InvalidGoalDefinition $e) {
        foreach ($e->errors() as $path => $message) {
            $errors[p202_goal_error_field('definition.' . $path)] ??= $message;
        }
    }
    if ($errors !== []) {
        return $errors;
    }

    $api = new GoalsController($db, $userId);
    try {
        if ($v['goal_id'] === '') {
            $api->create(['scope' => 'registration', 'scope_id' => $registrationId, 'definition' => $built['definition']]);

            return [];
        }
        $goalId = GoalsController::pathId($v['goal_id']);
        $goal = $api->get($goalId)['data'];
        if ($goal['scope'] !== 'registration' || (int) $goal['scope_id'] !== $registrationId) {
            return ['goal' => 'That goal is not one of this app\'s own goals.'];
        }
        if (!is_array($goal['definition']) || !p202_goal_form_fits($goal['definition'], 'install')) {
            // The form cannot show it, so it cannot have edited it: saving
            // would rewrite the parts it does not carry.
            return ['goal' => 'This goal has parts the form cannot show; edit it with p202 goal update ' . $goalId . '.'];
        }
        $api->update($goalId, ['definition' => $built['definition']]);

        return [];
    } catch (ValidationException $e) {
        $out = [];
        foreach ($e->getFieldErrors() as $path => $message) {
            $out[p202_goal_error_field((string) $path)] ??= (string) $message;
        }
        return $out !== [] ? $out : ['goal' => $e->getMessage()];
    } catch (ConflictException $e) {
        return str_contains($e->getMessage(), 'already exists') ? ['goal_name' => $e->getMessage()] : ['goal' => $e->getMessage()];
    } catch (NotFoundException $e) {
        return ['goal' => $e->getMessage()];
    }
}

/** Archive one of the app's own goals. Returns the refusal, or null. */
function p202_app_goal_archive(mysqli $db, int $userId, int $registrationId, string $rawGoalId): ?string
{
    $api = new GoalsController($db, $userId);
    $goalId = GoalsController::pathId($rawGoalId);
    try {
        $goal = $api->get($goalId)['data'];
        if ($goal['scope'] !== 'registration' || (int) $goal['scope_id'] !== $registrationId) {
            return 'That goal is not one of this app\'s own goals.';
        }
        if ($goal['builtin'] !== null) {
            return 'The install goal is built in: every install reaches it. Stop paying for it on a campaign instead.';
        }
        $api->delete($goalId);
    } catch (ConflictException | NotFoundException $e) {
        return $e->getMessage();
    }

    return null;
}

/**
 * The app's own goals (archived included) and the account-wide ones, as the
 * API lists them.
 *
 * @return array{own: list<array<string, mixed>>, account: list<array<string, mixed>>}
 */
function p202_app_goal_list(mysqli $db, int $userId, int $registrationId): array
{
    $api = new GoalsController($db, $userId);

    return [
        'own' => p202_app_goal_funnel_order($api->list(['registration_id' => (string) $registrationId, 'include_archived' => '1', 'limit' => '500'])['data']),
        'account' => $api->list(['scope' => 'account', 'limit' => '500'])['data'],
    ];
}

/**
 * Goals in funnel order: the install goal first, then each goal after the
 * goals it waits for (`after`), ties by id. A goal whose prerequisite is
 * not in the list (archived, or another owner's) is placed as if it had
 * none, and a cycle — which the API refuses to create — cannot hang it.
 *
 * @param list<array<string, mixed>> $goals
 * @return list<array<string, mixed>>
 */
function p202_app_goal_funnel_order(array $goals): array
{
    $byId = [];
    foreach ($goals as $goal) {
        $byId[(int) $goal['goal_id']] = $goal;
    }
    ksort($byId);
    $depth = [];
    $depthOf = static function (int $id, array $seen = []) use (&$depthOf, &$depth, $byId): int {
        if (isset($depth[$id])) {
            return $depth[$id];
        }
        if (isset($seen[$id])) {
            return 0;
        }
        $seen[$id] = true;
        $d = ($byId[$id]['builtin'] ?? null) === 'install' ? 0 : 1;
        foreach ((array) ($byId[$id]['definition']['after'] ?? []) as $after) {
            if (isset($byId[(int) $after])) {
                $d = max($d, $depthOf((int) $after, $seen) + 1);
            }
        }
        return $depth[$id] = $d;
    };
    $rows = [];
    foreach (array_keys($byId) as $id) {
        $rows[] = [$depthOf($id), $id];
    }
    sort($rows);

    return array_map(static fn (array $r): array => $byId[$r[1]], $rows);
}

/**
 * One line saying what an app goal waits for.
 *
 * @param array<string, mixed> $goal
 * @param array<int, string> $names goal id => name, for `after`
 */
function p202_app_goal_summary(array $goal, array $names): string
{
    $def = is_array($goal['definition'] ?? null) ? $goal['definition'] : [];
    if (isset($def['trigger']['install'])) {
        return 'every install that is not refuted';
    }
    $parts = ['on ' . (string) ($def['trigger']['event'] ?? '?')];
    $count = (int) ($def['threshold']['count'] ?? 1);
    if (isset($def['threshold']['sum'])) {
        $parts[] = 'a running sum';
    } elseif ($count > 1) {
        $parts[] = 'the ' . $count . p202_goal_ordinal($count);
    }
    $after = [];
    foreach ((array) ($def['after'] ?? []) as $id) {
        $after[] = $names[(int) $id] ?? ('goal ' . (int) $id);
    }
    if ($after !== []) {
        $parts[] = 'after ' . implode(', ', $after);
    }
    if (isset($def['within']['days'])) {
        $parts[] = 'within ' . (int) $def['within']['days'] . ' ' . ((int) $def['within']['days'] === 1 ? 'day' : 'days') . ' of the ' . (string) ($def['within']['from'] ?? 'install');
    }
    $value = $def['value'] ?? ['type' => 'none'];
    $parts[] = match ($value['type'] ?? 'none') {
        'fixed' => 'worth ' . \Prosper202\Goals\TrafficSourceNotifier::money((string) $value['amount']),
        'from_property' => 'worth the event\'s amount',
        default => 'tracked',
    };

    return implode(' · ', $parts);
}
