<?php

declare(strict_types=1);

namespace Prosper202\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids a two-sided assertion whose operands are the same expression.
 *
 * CLAUDE.md "Verify your assumptions": a check that cannot fail is not a
 * check. `$this->assertSame('verified-only', 'verified-only');` shipped as
 * the attribution report suite's ONLY test of the verified-only default —
 * it was meant to read the report's meta.trusted and instead compared a
 * literal with itself, so the suite stayed green against any regression of
 * the behaviour it claimed to cover. A human reviewer caught it; that is
 * exactly the bookkeeping a machine should do.
 *
 * Only operands whose two evaluations provably agree are reported:
 * scalars, true/false/null and other constant fetches, class constants,
 * array literals built from those, concatenations of those, and the same
 * plain variable on both sides. Anything else — a method call, a property
 * fetch, an interpolated string, a magic constant — is left alone, because
 * two evaluations of it can legitimately differ.
 *
 * Matching is by method name, deliberately. Requiring the receiver to
 * resolve to PHPUnit\Framework\TestCase would make the rule fall silent in
 * exactly the environments where symbols do not resolve (a partial vendor,
 * an analysis run without dev dependencies), and the names covered here are
 * PHPUnit's own — a `bind`-style collision with an unrelated API is not a
 * realistic risk for `assertEqualsCanonicalizing`.
 *
 * Known gap: an operand repeated as a subscript of the same variable
 * (`$report['meta']['trusted']` twice) is provably identical too, but a
 * dimension fetch can run ArrayAccess::offsetGet and a property fetch can
 * run __get, so neither is included here.
 *
 * @implements Rule<Node\Expr\CallLike>
 */
final class VacuousAssertionRule implements Rule
{
    /**
     * PHPUnit assertions that compare their first two arguments with each
     * other. Every one of them has a fixed outcome when both operands are
     * the same expression — always true for the positive forms, always
     * false for the negated ones. Both are defects.
     *
     * @var string[] lowercased method names
     */
    private const COMPARING_ASSERTIONS = [
        'assertsame',
        'assertnotsame',
        'assertequals',
        'assertnotequals',
        'assertequalscanonicalizing',
        'assertnotequalscanonicalizing',
        'assertequalsignoringcase',
        'assertnotequalsignoringcase',
        'assertequalswithdelta',
        'assertnotequalswithdelta',
        'assertgreaterthan',
        'assertgreaterthanorequal',
        'assertlessthan',
        'assertlessthanorequal',
        'assertsamesize',
        'assertnotsamesize',
        'assertstringcontainsstring',
        'assertstringnotcontainsstring',
        'assertstringcontainsstringignoringcase',
        'assertstringnotcontainsstringignoringcase',
        'assertstringstartswith',
        'assertstringstartsnotwith',
        'assertstringendswith',
        'assertstringendsnotwith',
    ];

    public function getNodeType(): string
    {
        // CallLike so `$this->assertSame(...)`, `self::assertSame(...)` and
        // `Assert::assertSame(...)` are all seen; the suites use all three.
        return Node\Expr\CallLike::class;
    }

    /**
     * @param Node\Expr\CallLike $node
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof Node\Expr\MethodCall && !$node instanceof Node\Expr\StaticCall) {
            return [];
        }
        if (!$node->name instanceof Node\Identifier) {
            return [];
        }
        if (!in_array($node->name->toLowerString(), self::COMPARING_ASSERTIONS, true)) {
            return [];
        }

        $args = $node->getArgs();
        if (count($args) < 2) {
            return [];
        }
        // A named or spread argument breaks the positional reading; the two
        // compared operands are then not necessarily args 0 and 1.
        foreach ($args as $arg) {
            if ($arg->unpack || $arg->name !== null) {
                return [];
            }
        }

        $left = $this->identityOf($args[0]->value);
        if ($left === null || $left !== $this->identityOf($args[1]->value)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s() compares %s with itself, so it passes or fails regardless of the code under test. '
                . 'Assert the value the test actually produces. (CLAUDE.md "Verify your assumptions")',
                $node->name->toString(),
                $args[0]->value instanceof Node\Expr\Variable ? 'the same variable' : 'a constant expression'
            ))
                ->identifier('prosper202.vacuousAssertion')
                ->build(),
        ];
    }

    /**
     * A canonical string for an expression whose repeated evaluation is
     * guaranteed to produce the same value, or null when that guarantee
     * does not hold.
     *
     * Two expressions with the same non-null identity are interchangeable,
     * so comparing one against the other decides nothing.
     */
    private function identityOf(Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Scalar\String_) {
            // Quoting style is not part of the value: 'a' and "a" are the
            // same string, and comparing them is just as vacuous.
            return 'str:' . $expr->value;
        }

        if ($expr instanceof Node\Scalar) {
            // Int_/LNumber and Float_/DNumber, by class so 1 and 1.0 stay
            // distinct (assertSame(1, 1.0) genuinely fails). Every other
            // Scalar — interpolated strings, magic constants — has no plain
            // int/float/string value and is not treated as fixed.
            if (isset($expr->value) && (is_int($expr->value) || is_float($expr->value))) {
                return get_class($expr) . ':' . var_export($expr->value, true);
            }
            return null;
        }

        if ($expr instanceof Node\Expr\ConstFetch) {
            $name = $expr->name->toLowerString();
            if ($name === 'true' || $name === 'false' || $name === 'null') {
                return 'const:' . $name;
            }
            return 'const:' . $expr->name->toCodeString();
        }

        if ($expr instanceof Node\Expr\ClassConstFetch) {
            if (!$expr->class instanceof Node\Name || !$expr->name instanceof Node\Identifier) {
                return null;
            }
            return 'classconst:' . $expr->class->toCodeString() . '::' . $expr->name->toString();
        }

        if ($expr instanceof Node\Expr\UnaryMinus || $expr instanceof Node\Expr\UnaryPlus) {
            $inner = $this->identityOf($expr->expr);
            return $inner === null ? null : 'unary' . ($expr instanceof Node\Expr\UnaryMinus ? '-' : '+') . '(' . $inner . ')';
        }

        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            $leftPart = $this->identityOf($expr->left);
            $rightPart = $this->identityOf($expr->right);
            if ($leftPart === null || $rightPart === null) {
                return null;
            }
            return 'concat(' . $leftPart . ',' . $rightPart . ')';
        }

        if ($expr instanceof Node\Expr\Array_) {
            $parts = [];
            foreach ($expr->items as $item) {
                if ($item === null || $item->unpack || $item->byRef) {
                    return null;
                }
                $value = $this->identityOf($item->value);
                if ($value === null) {
                    return null;
                }
                $key = '';
                if ($item->key !== null) {
                    $key = $this->identityOf($item->key);
                    if ($key === null) {
                        return null;
                    }
                }
                $parts[] = $key . '=>' . $value;
            }
            return 'array[' . implode(',', $parts) . ']';
        }

        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            // Reading the same variable twice in one argument list cannot
            // yield two different values, so this is as fixed as a literal.
            return 'var:$' . $expr->name;
        }

        return null;
    }
}
