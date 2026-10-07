<?php

declare(strict_types=1);

namespace Prosper202\Click;

/**
 * The traffic source's custom variables a click reads, from the tracker row
 * the click path selects: `parameters` and `ppc_variable_ids`, the two
 * GROUP_CONCATs over 202_ppc_network_variables, in the same order.
 *
 * Three rows mean "no variables": a tracker whose source has none (the LEFT
 * JOIN gives NULL), a landing-page click with no tracker at all (no t202id,
 * or one that names none: the keys are not in the row), and a source whose
 * list is empty. The landing-page recorders read the missing keys directly —
 * an "Undefined array key" warning on every tracker-less click — and dl.php
 * tested them with `!empty()`, which also reads a single variable named "0"
 * as none.
 */
final class TrackerVariables
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $trackerRow
     * @return list<array{string, int}> each variable's parameter and its ppc_variable_id
     */
    public static function pairs(array $trackerRow): array
    {
        $parameters = $trackerRow['parameters'] ?? null;
        if ($parameters === null || $parameters === '') {
            return [];
        }
        $ids = explode(',', (string) ($trackerRow['ppc_variable_ids'] ?? ''));
        $pairs = [];
        foreach (explode(',', (string) $parameters) as $key => $parameter) {
            if ($parameter === '') {
                continue;
            }
            $pairs[] = [$parameter, (int) ($ids[$key] ?? 0)];
        }

        return $pairs;
    }
}
