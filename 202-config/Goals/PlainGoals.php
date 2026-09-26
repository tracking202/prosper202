<?php

declare(strict_types=1);

namespace Prosper202\Goals;

use Prosper202\Database\Connection;

/**
 * The plain event goal an owner already has for an event, or a new one.
 *
 * Setup › Mobile Apps asks for an event name and a revenue when it maps a
 * SKAN conversion value, because that is what an operator knows; an
 * encoding names a goal (plan §4.5). This is the one place that turns the
 * first into the second, so the page never asks for a goal it can find
 * (UI standard: the app decides what it can), and two rules for one event
 * share one goal rather than minting a goal per rule. The goal editor is
 * PR 11; until then these goals are tracked (`value: none`) and the rule's
 * revenue is the encoding's revenue_override.
 */
final class PlainGoals
{
    private MysqlGoalRepository $goals;

    public function __construct(Connection $conn, ?MysqlGoalRepository $goals = null)
    {
        $this->goals = $goals ?? new MysqlGoalRepository($conn);
    }

    /**
     * @throws InvalidGoalDefinition when the event name is not one
     * @throws GoalEngineException NOT_FOUND for an owner that is not the user's; CONFLICT when no name is free
     */
    public function forEvent(int $userId, GoalScope $scope, int $scopeId, string $eventName, int $now): int
    {
        $definition = GoalDefinition::parse(['name' => $eventName, 'trigger' => ['event' => $eventName]]);
        if ($scope === GoalScope::REGISTRATION && $this->goals->registration($userId, $scopeId) === null) {
            throw new GoalEngineException('App registration ' . $scopeId . ' not found', GoalEngineException::NOT_FOUND);
        }
        if ($scope === GoalScope::CAMPAIGN && $this->goals->campaign($userId, $scopeId) === null) {
            throw new GoalEngineException('Campaign ' . $scopeId . ' not found', GoalEngineException::NOT_FOUND);
        }

        $offset = 0;
        do {
            $page = $this->goals->list($userId, ['scope' => $scope->value, 'scope_id' => $scopeId], $offset, 500);
            foreach ($page['rows'] as $row) {
                $current = $this->goals->version((int) $row['goal_id'], (int) $row['current_version']);
                if ($current === null) {
                    continue;
                }
                try {
                    $existing = GoalDefinition::fromJson($current['definition'], (int) $row['goal_id']);
                } catch (InvalidGoalDefinition) {
                    continue;
                }
                if ($existing->isPlainEvent() && $existing->triggerEvent === $eventName) {
                    return (int) $row['goal_id'];
                }
            }
            $offset += 500;
        } while ($offset < $page['total']);

        foreach ([$eventName, $eventName . ' (event)'] as $name) {
            if (!$this->goals->nameTaken($userId, $scope, $scopeId, $name, null)) {
                $named = GoalDefinition::parse(['name' => $name, 'trigger' => ['event' => $eventName]]);

                return $this->goals->create($userId, $scope, $scopeId, $named, $now);
            }
        }
        throw new GoalEngineException(
            'Goals named "' . $eventName . '" and "' . $eventName . ' (event)" already exist and neither is a plain "' . $eventName
            . '" event goal; rename one, or map the value to a goal with PUT /apps/skan-encodings/{id}.',
            GoalEngineException::CONFLICT
        );
    }

    /**
     * What a rule list shows for each goal an encoding names: its name, the
     * event it waits for when it is a plain event goal, and its fixed value.
     *
     * @param list<int> $goalIds
     * @return array<int, array{name: string, event: string|null, fixed_units: int|null}>
     */
    public function describe(int $userId, array $goalIds): array
    {
        $out = [];
        foreach (array_unique($goalIds) as $goalId) {
            $goal = $this->goals->find($userId, $goalId);
            if ($goal === null) {
                continue;
            }
            $current = $this->goals->version($goalId, (int) $goal['current_version']);
            $event = null;
            $fixed = null;
            if ($current !== null) {
                try {
                    $definition = GoalDefinition::fromJson($current['definition'], $goalId);
                    $event = $definition->triggerEvent;
                    $fixed = $definition->valueType === 'fixed' ? $definition->valueUnits : null;
                } catch (InvalidGoalDefinition) {
                    // shown by name only
                }
            }
            $out[$goalId] = ['name' => (string) $goal['name'], 'event' => $event, 'fixed_units' => $fixed];
        }

        return $out;
    }
}
