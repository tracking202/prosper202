<?php

declare(strict_types=1);

namespace Prosper202\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Checks that a literal bind_param() type string has one character per bound
 * value, and that every character is a valid type.
 *
 * CLAUDE.md #7: bind_param type string mismatches. mysqli raises
 * ArgumentCountError when the counts disagree, so the failure lands at
 * runtime on whichever query happened to be edited — usually the one whose
 * parameter list just grew. Counting is exactly the kind of bookkeeping a
 * machine should do.
 *
 * Only literal type strings are checked; a computed one (built with str_repeat
 * for a variable-length IN clause, say) is skipped rather than guessed at.
 *
 * @implements Rule<Node\Expr\MethodCall>
 */
final class BindParamArityRule implements Rule
{
    private const VALID_TYPES = ['i', 'd', 's', 'b'];

    public function getNodeType(): string
    {
        return Node\Expr\MethodCall::class;
    }

    /**
     * @param Node\Expr\MethodCall $node
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Identifier || $node->name->toLowerString() !== 'bind_param') {
            return [];
        }

        $args = $node->getArgs();
        if ($args === []) {
            return [];
        }
        // A spread means the value count is not known statically.
        foreach ($args as $arg) {
            if ($arg->unpack) {
                return [];
            }
        }

        $first = $args[0]->value;
        if (!$first instanceof Node\Scalar\String_) {
            return []; // computed type string — nothing to count against
        }

        $types = $first->value;
        $valueCount = count($args) - 1;
        $errors = [];

        foreach (str_split($types) as $index => $char) {
            if (!in_array($char, self::VALID_TYPES, true)) {
                $errors[] = RuleErrorBuilder::message(sprintf(
                    'bind_param() type string "%s" contains %s at position %d; valid types are i, d, s and b. (CLAUDE.md #7)',
                    $types,
                    $char === ' ' ? 'a space' : sprintf('"%s"', $char),
                    $index + 1
                ))
                    ->identifier('prosper202.bindParamTypes')
                    ->build();
            }
        }

        if (strlen($types) !== $valueCount) {
            $errors[] = RuleErrorBuilder::message(sprintf(
                'bind_param() has %d type character%s ("%s") but %d bound value%s. '
                . 'mysqli raises ArgumentCountError when these disagree. (CLAUDE.md #7)',
                strlen($types),
                strlen($types) === 1 ? '' : 's',
                $types,
                $valueCount,
                $valueCount === 1 ? '' : 's'
            ))
                ->identifier('prosper202.bindParamArity')
                ->build();
        }

        return $errors;
    }
}
