<?php

declare(strict_types=1);

namespace Prosper202\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

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
 * Covers the raw `$stmt->bind_param($types, ...)` and both project wrappers,
 * which is where nearly all of the v3 API binds: ForbidDirectMysqliStmtCallRule
 * actively pushes code toward a wrapper, so a rule that only saw bind_param
 * would watch the shrinking half of the codebase.
 *
 * The two wrappers do NOT share a shape, and treating them alike produces
 * false positives on every Connection call site:
 *   - Api\V3\Controller::bind(stmt, types, mixed ...$values) — variadic
 *   - Prosper202\Database\Connection::bind(stmt, types, array $values) — one array
 * So the receiver's type decides how values are counted, and an unrecognised
 * `bind` is skipped rather than guessed at.
 *
 * Only literal type strings are checked; a computed one (built with str_repeat
 * for a variable-length IN clause, say) is skipped rather than guessed at.
 *
 * @implements Rule<Node\Expr\CallLike>
 */
final class BindParamArityRule implements Rule
{
    private const VALID_TYPES = ['i', 'd', 's', 'b'];

    private function receiverType(Node\Expr\MethodCall|Node\Expr\StaticCall $node, Scope $scope): ?Type
    {
        if ($node instanceof Node\Expr\MethodCall) {
            return $scope->getType($node->var);
        }
        if ($node->class instanceof Node\Name) {
            return new ObjectType($scope->resolveName($node->class));
        }
        return null;
    }

    /**
     * How this particular bind() takes its values: 'variadic' or 'array'.
     *
     * Asked of the callee's own signature rather than a hardcoded class list.
     * The codebase has eleven bind() methods — ten variadic, one taking an
     * array — and a list would drift the moment a twelfth appears. An
     * unrecognised shape returns null and is left alone: `bind` is a common
     * method name and a rule that guesses produces false positives on every
     * call site it misreads.
     */
    private static function wrapperShape(?Type $receiver, Scope $scope): ?string
    {
        if ($receiver === null || !$receiver->hasMethod('bind')->yes()) {
            return null;
        }
        $variants = $receiver->getMethod('bind', $scope)->getVariants();
        if (count($variants) !== 1) {
            return null;
        }
        $params = $variants[0]->getParameters();
        // (statement, types, values) — anything else is somebody else's bind().
        if (count($params) !== 3 || !$params[1]->getType()->isString()->yes()) {
            return null;
        }
        return $params[2]->isVariadic() ? 'variadic' : 'array';
    }

    public function getNodeType(): string
    {
        // CallLike so `$stmt->bind_param(...)`, `$this->bind(...)` and
        // `self::bind(...)` are all seen; Auth and AUTH call theirs statically.
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
        $method = $node->name->toLowerString();

        if ($method !== 'bind_param' && $method !== 'bind') {
            return [];
        }

        // bind_param($types, ...$values) puts the type string first; every
        // wrapper puts the statement first.
        $typeArgIndex = $method === 'bind_param' ? 0 : 1;
        $valuesAreOneArray = false;

        if ($method === 'bind') {
            $shape = self::wrapperShape($this->receiverType($node, $scope), $scope);
            if ($shape === null) {
                return []; // not a bind() shaped like ours; nothing to count
            }
            $valuesAreOneArray = $shape === 'array';
        }

        $args = $node->getArgs();
        if (count($args) <= $typeArgIndex) {
            return [];
        }
        // A spread means the value count is not known statically.
        foreach ($args as $arg) {
            if ($arg->unpack) {
                return [];
            }
        }

        $typeArg = $args[$typeArgIndex]->value;
        if (!$typeArg instanceof Node\Scalar\String_) {
            return []; // computed type string — nothing to count against
        }

        $types = $typeArg->value;
        $label = $method === 'bind' ? 'bind()' : 'bind_param()';

        if ($valuesAreOneArray) {
            $valuesArg = $args[$typeArgIndex + 1]->value ?? null;
            if (!$valuesArg instanceof Node\Expr\Array_) {
                return []; // values built elsewhere — nothing to count
            }
            foreach ($valuesArg->items as $item) {
                if ($item === null || $item->unpack) {
                    return [];
                }
            }
            $valueCount = count($valuesArg->items);
        } else {
            $valueCount = count($args) - ($typeArgIndex + 1);
        }
        $errors = [];

        foreach (str_split($types) as $index => $char) {
            if (!in_array($char, self::VALID_TYPES, true)) {
                $errors[] = RuleErrorBuilder::message(sprintf(
                    '%s type string "%s" contains %s at position %d; valid types are i, d, s and b. (CLAUDE.md #7)',
                    $label,
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
                '%s has %d type character%s ("%s") but %d bound value%s. '
                . 'mysqli raises ArgumentCountError when these disagree. (CLAUDE.md #7)',
                $label,
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
