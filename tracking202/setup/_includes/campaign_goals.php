<?php

declare(strict_types=1);

/**
 * The web campaign goal editor (plan §2.2, PR 4b): a campaign's own goals —
 * a signup, a sale, an upsell — each on its own event and with its own
 * payout, edited on the campaign's page.
 *
 * Every write goes through the REST controller (GoalsController), so the
 * page and the API refuse the same definitions with the same sentences and
 * create the same versions: a goal edited here and one edited with
 * `p202 goal update` are indistinguishable (error pattern #5). The form
 * covers the common shape; a goal the form cannot show faithfully (a
 * running sum, several conditions, a window from the install) is listed
 * with a note to edit it with the CLI or the API, never silently rewritten.
 *
 * Posted names are all `goal_*`, with `goal_action` saying which of the two
 * forms posted: `save` (add or edit) or `archive`.
 */

use Api\V3\Controllers\GoalsController;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Prosper202\Goals\GoalDefinition;
use Prosper202\Goals\InvalidGoalDefinition;

/** The operators the form offers for its one condition, with their words. */
const P202_GOAL_OPS = [
    'eq' => 'is', 'neq' => 'is not', 'gt' => 'is more than', 'gte' => 'is at least', 'lt' => 'is less than', 'lte' => 'is at most',
];

/**
 * Whether the form can show a goal's definition without losing any of it.
 *
 * The form has one window, counted from the owner's subject: the click for a
 * campaign's goal, the install for an app's (Setup › Mobile Apps, PR 11). A
 * window from the other one is a goal the form cannot show, and is edited
 * with `p202 goal update` instead of being rewritten here.
 *
 * @param array<string, mixed> $def the stored (canonical) definition
 * @param 'click'|'install' $windowFrom what the form's window counts from
 */
function p202_goal_form_fits(array $def, string $windowFrom = 'click'): bool
{
    $trigger = $def['trigger'] ?? [];
    $where = $trigger['where'] ?? [];
    $within = $def['within'] ?? null;

    return isset($trigger['event'])
        && count($where) <= 1
        && ($where === [] || isset(P202_GOAL_OPS[$where[0]['op'] ?? '']))
        && isset($def['threshold']['count'])
        && count($def['after'] ?? []) <= 1
        && ($within === null || ($within['from'] ?? '') === $windowFrom)
        && (($def['value']['type'] ?? '') !== 'from_property' || ($def['value']['prop'] ?? GoalDefinition::REVENUE_PROP) === GoalDefinition::REVENUE_PROP);
}

/**
 * The form's values: what was just posted, the goal being edited, or blank.
 *
 * @param array<string, mixed>|null $goal the goal being edited (GoalsController::get()'s data)
 * @param array<string, mixed>|null $posted
 * @return array<string, string>
 */
function p202_goal_form_values(?array $goal, ?array $posted): array
{
    $keys = ['goal_id', 'goal_name', 'goal_event', 'goal_value', 'goal_amount', 'goal_count', 'goal_repeat', 'goal_repeat_max',
        'goal_within_days', 'goal_after', 'goal_where_prop', 'goal_where_op', 'goal_where_value', 'goal_payout', 'goal_notify'];
    if ($posted !== null) {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = is_string($posted[$k] ?? null) ? (string) $posted[$k] : '';
        }
        return $out;
    }
    $out = array_fill_keys($keys, '');
    $out['goal_value'] = 'fixed';
    $out['goal_count'] = '1';
    $out['goal_repeat'] = 'once';
    $out['goal_where_op'] = 'eq';
    $out['goal_notify'] = '1';
    if ($goal === null || !is_array($goal['definition'] ?? null)) {
        return $out;
    }
    $def = $goal['definition'];
    $where = $def['trigger']['where'][0] ?? null;
    $term = null;
    foreach ($goal['campaigns'] ?? [] as $t) {
        if ((int) $t['campaign_id'] === (int) $goal['scope_id']) {
            $term = $t;
        }
    }
    $value = $def['value'] ?? ['type' => 'none'];

    return [
        'goal_id' => (string) $goal['goal_id'],
        'goal_name' => (string) ($def['name'] ?? ''),
        'goal_event' => (string) ($def['trigger']['event'] ?? ''),
        'goal_value' => match ($value['type'] ?? 'none') { 'fixed' => 'fixed', 'from_property' => 'property', default => 'none' },
        'goal_amount' => ($value['type'] ?? '') === 'fixed' ? (string) $value['amount'] : '',
        'goal_count' => (string) ($def['threshold']['count'] ?? 1),
        'goal_repeat' => ($def['repeat']['mode'] ?? 'once') === 'each' ? 'each' : 'once',
        'goal_repeat_max' => isset($def['repeat']['max']) ? (string) $def['repeat']['max'] : '',
        'goal_within_days' => isset($def['within']['days']) ? (string) $def['within']['days'] : '',
        'goal_after' => isset($def['after'][0]) ? (string) $def['after'][0] : '',
        'goal_where_prop' => $where !== null ? (string) $where['prop'] : '',
        'goal_where_op' => $where !== null ? (string) $where['op'] : 'eq',
        'goal_where_value' => $where !== null ? (is_bool($where['value']) ? ($where['value'] ? 'true' : 'false') : (string) $where['value']) : '',
        'goal_payout' => $term !== null && $term['payout'] !== null ? (string) $term['payout'] : '',
        // An unpaid goal has no term; its box shows the default for when it
        // is paid, which is on.
        'goal_notify' => $term === null || $term['notify_traffic_source'] ? '1' : '',
    ];
}

/**
 * Build a definition from the form. Numbers are read strictly here (a count
 * of "2.5" or "1e3" is refused by name, never cast); everything else is
 * GoalDefinition's to judge, through the controller.
 *
 * @param array<string, string> $v
 * @param 'click'|'install' $windowFrom what the form's window counts from (p202_goal_form_fits())
 * @return array{definition: array<string, mixed>, errors: array<string, string>}
 */
function p202_goal_definition_from_form(array $v, string $windowFrom = 'click'): array
{
    $errors = [];
    $int = static function (string $raw, string $field, string $sentence, int $max) use (&$errors): ?int {
        if (preg_match('/^[1-9]\d{0,4}$/D', $raw) !== 1 || (int) $raw > $max) {
            $errors[$field] = $sentence;
            return null;
        }
        return (int) $raw;
    };

    $count = $int($v['goal_count'] === '' ? '1' : $v['goal_count'], 'goal_count', 'Reached on the Nth event: a whole number from 1 to 10000.', GoalDefinition::MAX_COUNT) ?? 1;
    $repeat = ['mode' => 'once'];
    if ($v['goal_repeat'] === 'each') {
        $repeat = ['mode' => 'each'];
        if ($v['goal_repeat_max'] !== '') {
            $max = $int($v['goal_repeat_max'], 'goal_repeat_max', 'At most: a whole number from 1 to 10000, or empty for no limit.', GoalDefinition::MAX_REPEAT);
            if ($max !== null) {
                $repeat['max'] = $max;
            }
        }
    } elseif ($v['goal_repeat'] !== 'once' && $v['goal_repeat'] !== '') {
        $errors['goal_repeat'] = 'Choose once or every time.';
    }
    $within = null;
    if ($v['goal_within_days'] !== '') {
        $days = $int($v['goal_within_days'], 'goal_within_days', 'Within: a whole number of days from 1 to 3650, or empty for no limit.', GoalDefinition::MAX_DAYS);
        $within = $days === null ? null : ['days' => $days, 'from' => $windowFrom];
    }
    $after = [];
    if ($v['goal_after'] !== '') {
        $afterId = GoalsController::pathId($v['goal_after']);
        if ($afterId === 0) {
            $errors['goal_after'] = 'Choose one of this campaign\'s goals.';
        } else {
            $after[] = $afterId;
        }
    }
    $where = [];
    if ($v['goal_where_prop'] !== '') {
        if (!isset(P202_GOAL_OPS[$v['goal_where_op']])) {
            $errors['goal_where_op'] = 'Choose how to compare.';
        }
        $raw = $v['goal_where_value'];
        $numeric = preg_match('/^-?(?:0|[1-9]\d{0,11})(?:\.\d{1,5})?$/D', $raw) === 1;
        if (in_array($v['goal_where_op'], ['gt', 'gte', 'lt', 'lte'], true) && !$numeric) {
            $errors['goal_where_value'] = 'A comparison like "' . P202_GOAL_OPS[$v['goal_where_op']] . '" needs a number.';
        }
        // A number reads as a number (the event's properties are typed);
        // anything else is compared as text.
        $where[] = ['prop' => $v['goal_where_prop'], 'op' => $v['goal_where_op'], 'value' => $numeric ? (str_contains($raw, '.') ? (float) $raw : (int) $raw) : $raw];
    }
    $value = match ($v['goal_value']) {
        'fixed' => ['type' => 'fixed', 'amount' => $v['goal_amount']],
        'property' => ['type' => 'from_property'],
        'none' => ['type' => 'none'],
        default => null,
    };
    if ($value === null) {
        $errors['goal_value'] = 'Choose what the goal is worth.';
        $value = ['type' => 'none'];
    }
    if ($v['goal_value'] === 'fixed' && GoalDefinition::amountUnits($v['goal_amount']) === null) {
        $errors['goal_amount'] = 'An amount such as 4.00: at most 999999.99999, with at most 5 decimal places.';
    }

    return [
        'definition' => [
            'name' => trim($v['goal_name']),
            'trigger' => ['event' => trim($v['goal_event']), 'where' => $where],
            'threshold' => ['count' => $count],
            'after' => $after,
            'within' => $within,
            'repeat' => $repeat,
            'value' => $value,
        ],
        'errors' => $errors,
    ];
}

/** Where a controller field error belongs on the form. */
function p202_goal_error_field(string $path): string
{
    return match (true) {
        $path === 'definition.name' => 'goal_name',
        str_starts_with($path, 'definition.trigger.where') => 'goal_where_value',
        str_starts_with($path, 'definition.trigger') => 'goal_event',
        str_starts_with($path, 'definition.threshold') => 'goal_count',
        str_starts_with($path, 'definition.after') => 'goal_after',
        str_starts_with($path, 'definition.within') => 'goal_within_days',
        str_starts_with($path, 'definition.repeat') => 'goal_repeat_max',
        str_starts_with($path, 'definition.value') => 'goal_amount',
        $path === 'payout' => 'goal_payout',
        default => 'goal',
    };
}

/**
 * Save the posted goal (add, or edit when goal_id names one of the
 * campaign's own goals). Returns the errors by form field; [] when saved.
 *
 * @param array<string, string> $v the form values (p202_goal_form_values())
 * @return array<string, string>
 */
function p202_goal_save(mysqli $db, int $userId, int $campaignId, array $v): array
{
    $built = p202_goal_definition_from_form($v);
    $errors = $built['errors'];
    $payout = trim($v['goal_payout']);
    if ($payout !== '' && GoalDefinition::amountUnits($payout) === null) {
        $errors['goal_payout'] = 'A payout such as 5.00, or empty to pay the goal\'s own value.';
    }
    // Checked before anything is written: the controller's own parse, so a
    // definition the API would refuse is refused here in the same words.
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

    $paid = $built['definition']['value']['type'] !== 'none' || $payout !== '';
    $notify = $v['goal_notify'] === '1';
    $api = new GoalsController($db, $userId);
    try {
        if ($v['goal_id'] === '') {
            $payload = ['scope' => 'campaign', 'scope_id' => $campaignId, 'definition' => $built['definition'], 'payable' => $paid];
            if ($paid) {
                $payload['notify_traffic_source'] = $notify;
                if ($payout !== '') {
                    $payload['payout'] = $payout;
                }
            }
            $api->create($payload);

            return [];
        }

        $goalId = GoalsController::pathId($v['goal_id']);
        $goal = $api->get($goalId)['data'];
        if ($goal['scope'] !== 'campaign' || (int) $goal['scope_id'] !== $campaignId) {
            return ['goal' => 'That goal is not one of this campaign\'s own goals.'];
        }
        $api->update($goalId, ['definition' => $built['definition']]);
        $attached = false;
        foreach ($goal['campaigns'] as $term) {
            $attached = $attached || (int) $term['campaign_id'] === $campaignId;
        }
        if ($paid) {
            $api->attachCampaign($goalId, $campaignId, ['payout' => $payout !== '' ? $payout : null, 'notify_traffic_source' => $notify]);
        } elseif ($attached) {
            $api->detachCampaign($goalId, $campaignId);
        }

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

/**
 * Archive one of the campaign's own goals. Returns the refusal, or null.
 */
function p202_goal_archive(mysqli $db, int $userId, int $campaignId, string $rawGoalId): ?string
{
    $api = new GoalsController($db, $userId);
    $goalId = GoalsController::pathId($rawGoalId);
    try {
        $goal = $api->get($goalId)['data'];
        if ($goal['scope'] !== 'campaign' || (int) $goal['scope_id'] !== $campaignId) {
            return 'That goal is not one of this campaign\'s own goals.';
        }
        $api->delete($goalId);
    } catch (ConflictException | NotFoundException $e) {
        return $e->getMessage();
    }

    return null;
}

/**
 * The campaign's goals as the page lists them: its own, then the goals of
 * an app or the account it pays for (listed, edited elsewhere).
 *
 * @return array{own: list<array<string, mixed>>, attached: list<array<string, mixed>>}
 */
function p202_goal_list(mysqli $db, int $userId, int $campaignId): array
{
    $api = new GoalsController($db, $userId);
    $own = [];
    foreach ($api->list(['campaign_id' => (string) $campaignId, 'include_archived' => '1', 'limit' => '500'])['data'] as $row) {
        $own[] = $api->get((int) $row['goal_id'])['data'];
    }
    $attached = [];
    $conn = new \Prosper202\Database\Connection($db);
    foreach ((new \Prosper202\Goals\MysqlGoalRepository($conn))->campaignTerms($campaignId) as $goalId => $term) {
        $goal = $api->get((int) $goalId)['data'];
        if ($goal['scope'] !== 'campaign') {
            $attached[] = $goal;
        }
    }

    return ['own' => $own, 'attached' => $attached];
}

/**
 * One line saying what a goal is and what it pays here.
 *
 * @param array<string, mixed> $goal
 * @return array{event: string, worth: string, paid: bool, payout: string|null, notify: bool}
 */
function p202_goal_summary(array $goal, int $campaignId): array
{
    $def = is_array($goal['definition'] ?? null) ? $goal['definition'] : [];
    $term = null;
    foreach ($goal['campaigns'] ?? [] as $t) {
        if ((int) $t['campaign_id'] === $campaignId) {
            $term = $t;
        }
    }
    $event = isset($def['trigger']['install']) ? 'the install' : (string) ($def['trigger']['event'] ?? '?');
    $count = (int) ($def['threshold']['count'] ?? 1);
    if ($count > 1) {
        $event .= ' (the ' . $count . p202_goal_ordinal($count) . ')';
    }
    $value = $def['value'] ?? ['type' => 'none'];
    $worth = match ($value['type'] ?? 'none') {
        'fixed' => '$' . \Prosper202\Goals\TrafficSourceNotifier::money((string) $value['amount']),
        'from_property' => 'the event\'s amount',
        default => 'tracked, not paid',
    };

    return [
        'event' => $event,
        'worth' => $worth,
        'paid' => $term !== null,
        'payout' => $term !== null && $term['payout'] !== null ? '$' . \Prosper202\Goals\TrafficSourceNotifier::money((string) $term['payout']) : null,
        'notify' => $term !== null && $term['notify_traffic_source'],
    ];
}

function p202_goal_ordinal(int $n): string
{
    if ($n % 100 >= 11 && $n % 100 <= 13) {
        return 'th';
    }
    return match ($n % 10) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' };
}
