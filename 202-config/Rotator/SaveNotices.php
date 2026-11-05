<?php

declare(strict_types=1);

namespace Prosper202\Rotator;

/**
 * The Slack notices of Setup › Redirectors' save (tracking202/ajax/rotator.php),
 * each an event name and its fields; the page adds the user who saved.
 */
final class SaveNotices
{
    private function __construct()
    {
    }

    /**
     * The notices of a save, from the redirector as its lock found it and as written.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param callable(string, mixed): ?string $names
     * @return list<array{string, array<string, mixed>}>
     */
    public static function of(array $before, array $after, callable $names): array
    {
        $rotator = (string) $after['name'];
        $default = static function (array $r) use ($names): ?array {
            if (!empty($r['default_campaign'])) {
                return ['Campaign', (string) $r['default_campaign'], $names('campaign', $r['default_campaign'])];
            }
            if (!empty($r['default_url'])) {
                return ['URL', (string) $r['default_url'], (string) $r['default_url']];
            }
            if (!empty($r['default_lp'])) {
                return ['Landing Page', (string) $r['default_lp'], $names('lp', $r['default_lp'])];
            }
            return !empty($r['auto_monetizer']) ? ['Auto Monetizer', '', null] : null;
        };
        $notices = [];
        $was = $default($before);
        $now = $default($after);
        if ($now !== null && $was === null) {
            $notices[] = ['rotator_defaults_added', ['name' => $rotator, 'default_type' => $now[0], 'default_value' => $now[2]]];
        } elseif ($now !== null && [$was[0], $was[1]] !== [$now[0], $now[1]]) {
            $notices[] = $was[0] === 'Auto Monetizer'
                ? ['rotator_defaults_changed_from_monetizer', ['name' => $rotator, 'default_to_type' => $now[0], 'default_to_value' => $now[2]]]
                : ['rotator_defaults_changed', ['name' => $rotator, 'default_from_type' => $was[0], 'default_from_value' => $was[2],
                    'default_to_type' => $now[0], 'default_to_value' => $now[2]]];
        }

        $held = [];
        $heldCriteria = [];
        foreach ((array) $before['rules'] as $rule) {
            $held[(int) $rule['id']] = $rule;
            foreach ((array) $rule['criteria'] as $c) {
                $heldCriteria[(int) $c['id']] = $c + ['rule_name' => $rule['rule_name']];
            }
        }
        $kept = [];
        $keptCriteria = [];
        $state = static fn (mixed $status): string => (int) $status === 1 ? 'active' : 'inactive';
        $words = static fn (array $c): string => $c['type'] . ' ' . $c['statement'] . ' ' . $c['value'];
        foreach ((array) $after['rules'] as $rule) {
            $name = (string) $rule['rule_name'];
            $old = $held[(int) $rule['id']] ?? null;
            $kept[(int) $rule['id']] = true;
            if ($old === null) {
                $notices[] = ['rotator_rule_created', ['rotator' => $rotator, 'rule' => $name]];
            } else {
                if ((string) $old['rule_name'] !== $name) {
                    $notices[] = ['rotator_rule_name_changed', ['rotator' => $rotator, 'old_name' => $old['rule_name'], 'new_name' => $name]];
                }
                if ($state($old['status']) !== $state($rule['status'])) {
                    $notices[] = ['rotator_rule_status_changed', ['rotator' => $rotator, 'rule' => $name,
                        'old_status' => $state($old['status']), 'new_status' => $state($rule['status'])]];
                }
            }
            foreach ((array) $rule['criteria'] as $c) {
                $keptCriteria[(int) $c['id']] = true;
                if (!isset($heldCriteria[(int) $c['id']])) {
                    $notices[] = ['rotator_rules_criteria_created', ['rotator' => $rotator, 'rule' => $name, 'criteria' => $words($c)]];
                }
            }
        }
        foreach ($held as $id => $rule) {
            if (!isset($kept[$id])) {
                $notices[] = ['rotator_rule_deleted', ['rotator' => $rotator, 'rule' => $rule['rule_name']]];
            }
        }
        foreach ($heldCriteria as $id => $c) {
            if (!isset($keptCriteria[$id])) {
                $notices[] = ['rotator_rules_criteria_deleted', ['rotator' => $rotator, 'rule' => $c['rule_name'], 'criteria' => $words($c)]];
            }
        }

        return $notices;
    }
}
