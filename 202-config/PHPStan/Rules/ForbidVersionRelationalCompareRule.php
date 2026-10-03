<?php

declare(strict_types=1);

namespace Prosper202\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\Greater;
use PhpParser\Node\Expr\BinaryOp\GreaterOrEqual;
use PhpParser\Node\Expr\BinaryOp\Smaller;
use PhpParser\Node\Expr\BinaryOp\SmallerOrEqual;
use PhpParser\Node\Expr\BinaryOp\Spaceship;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids <, >, <=, >= and <=> on a value named as a version.
 *
 * CLAUDE.md #23: a version string compared with a relational operator is
 * compared as text, character by character, so "1.9.8" >= "1.9.76" is true
 * and "1.10.0" < "1.9.0" is true. The 1-click upgrade page filtered its
 * "What changed" list with `$logs['version'] >= $version`, which listed the
 * 1.9.8 and 1.9.9 notes as new on every install. version_compare() is the
 * comparison; a version that really is a counter (a goal's version number)
 * is cast to int, which this rule reads as the author saying so.
 *
 * An operand counts as a version when it is a variable, property, constant,
 * array key or called function/method whose name contains "version" — after
 * "conversion" is taken out of it, because this codebase compares conversion
 * counts all day. An operand PHPStan can prove is an int or a float is left
 * alone. A name is a heuristic: a version held in `$v` is not seen, which is
 * the silent direction, and why the entry in CLAUDE.md says what the rule
 * does not cover.
 *
 * @implements Rule<BinaryOp>
 */
final class ForbidVersionRelationalCompareRule implements Rule
{
    public function getNodeType(): string
    {
        return BinaryOp::class;
    }

    /**
     * @param BinaryOp $node
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $relational = $node instanceof Greater
            || $node instanceof GreaterOrEqual
            || $node instanceof Smaller
            || $node instanceof SmallerOrEqual
            || $node instanceof Spaceship;
        if (!$relational) {
            return [];
        }

        foreach ([$node->left, $node->right] as $operand) {
            $name = self::versionName($operand);
            if ($name === null) {
                continue;
            }
            $type = $scope->getType($operand);
            if ($type->isInteger()->yes() || $type->isFloat()->yes()) {
                continue;
            }

            return [
                RuleErrorBuilder::message(sprintf(
                    'Version %s compared with %s compares as text ("1.9.8" >= "1.9.76" is true). '
                    . 'Use version_compare(), or cast to int if it is a counter. (CLAUDE.md #23)',
                    $name,
                    $node->getOperatorSigil()
                ))
                    ->identifier('prosper202.versionRelationalCompare')
                    ->build(),
            ];
        }

        return [];
    }

    /** The operand's name when it names a version, else null. */
    private static function versionName(Expr $expr): ?string
    {
        $property = $expr instanceof Expr\PropertyFetch || $expr instanceof Expr\NullsafePropertyFetch;
        $call = $expr instanceof Expr\MethodCall
            || $expr instanceof Expr\NullsafeMethodCall
            || $expr instanceof Expr\StaticCall;
        $name = match (true) {
            $expr instanceof Expr\Variable && is_string($expr->name)
                => '$' . $expr->name,
            $expr instanceof Expr\ArrayDimFetch && $expr->dim instanceof String_
                => "['" . $expr->dim->value . "']",
            $property && $expr->name instanceof Identifier
                => '->' . $expr->name->toString(),
            $expr instanceof Expr\StaticPropertyFetch && $expr->name instanceof Identifier
                => '::$' . $expr->name->toString(),
            $expr instanceof Expr\ConstFetch
                => $expr->name->toString(),
            $expr instanceof Expr\ClassConstFetch && $expr->name instanceof Identifier
                => '::' . $expr->name->toString(),
            $expr instanceof Expr\FuncCall && $expr->name instanceof Name
                => $expr->name->toString() . '()',
            $call && $expr->name instanceof Identifier
                => $expr->name->toString() . '()',
            default => null,
        };
        if ($name === null) {
            return null;
        }

        return str_contains(str_replace('conversion', '', strtolower($name)), 'version') ? $name : null;
    }
}
