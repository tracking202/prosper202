<?php

declare(strict_types=1);

namespace Prosper202\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids an arrow function reading a variable its enclosing closure captured
 * by reference.
 *
 * CLAUDE.md #8: assumed closure, reference, and capture semantics. A closure
 * written `function () use (&$x)` shares $x with its caller, so a later write
 * to $x is visible inside it. An arrow function defined *within* that closure
 * does NOT inherit the binding — `fn() => $x` captures the value at the
 * moment the arrow function is created and never sees a later write.
 *
 * The mix reads as if the reference propagates, which is how the staged-write
 * dispatcher shipped broken: route handlers registered as arrow functions
 * inside a `use (&$payload)` group kept the payload captured at registration,
 * so applying a staged change ran with the wrong body while the audit record
 * still displayed the reviewed one.
 *
 * The fix is almost never to convert the arrow function: it is to stop
 * sharing state by reference and pass the value in explicitly (a factory
 * parameter, an argument, or a mutable object whose identity is stable).
 *
 * @implements Rule<Node\Expr\Closure>
 */
final class ForbidArrowFnByRefCaptureRule implements Rule
{
    public function getNodeType(): string
    {
        return Node\Expr\Closure::class;
    }

    /**
     * @param Node\Expr\Closure $node
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $byRef = [];
        foreach ($node->uses as $use) {
            if ($use->byRef && $use->var instanceof Node\Expr\Variable && is_string($use->var->name)) {
                $byRef[$use->var->name] = true;
            }
        }
        if ($byRef === []) {
            return [];
        }

        $finder = new NodeFinder();
        $errors = [];
        $seen = [];

        foreach ($finder->findInstanceOf($node->stmts, Node\Expr\ArrowFunction::class) as $arrow) {
            /** @var Node\Expr\ArrowFunction $arrow */
            // A nested closure that re-captures by reference is fine — the
            // binding does propagate there — so only arrow functions count.
            foreach ($finder->findInstanceOf([$arrow->expr], Node\Expr\Variable::class) as $variable) {
                /** @var Node\Expr\Variable $variable */
                if (!is_string($variable->name) || !isset($byRef[$variable->name])) {
                    continue;
                }
                $key = $arrow->getStartLine() . ':' . $variable->name;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $errors[] = RuleErrorBuilder::message(sprintf(
                    'Arrow function reads $%s, which the enclosing closure (line %d) captured by reference. '
                    . 'Arrow functions capture by value at definition time, so this never sees a later write '
                    . 'through that reference. Pass the value in explicitly instead of sharing it by reference. '
                    . '(CLAUDE.md #8)',
                    $variable->name,
                    $node->getStartLine()
                ))
                    ->identifier('prosper202.arrowFnByRefCapture')
                    ->line($arrow->getStartLine())
                    ->build();
            }
        }

        return $errors;
    }
}
