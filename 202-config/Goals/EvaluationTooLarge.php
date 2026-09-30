<?php

declare(strict_types=1);

namespace Prosper202\Goals;

/**
 * An evaluation given an outcome budget (GoalEvaluator::evaluateAll()'s
 * $maxOutcomes) reached more outcomes than it allows. Thrown as soon as the
 * budget is passed, so a what-if request (`POST /goals/evaluate`) cannot
 * make the server build an arbitrarily large answer.
 */
final class EvaluationTooLarge extends \RuntimeException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct('the evaluation reaches more than ' . $limit . ' outcomes');
    }
}
