<?php

declare(strict_types=1);

namespace Prosper202\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\Cast\Double;
use PhpParser\Node\Expr\Cast\Int_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids casting a query parameter to a number in the API.
 *
 * CLAUDE.md #4: the lists read `max(1, min(500, (int) ($params['limit'] ??
 * 50)))`. (int) keeps a string's leading digits and makes anything else 0,
 * and the clamp then made 0 the minimum: `limit=abc` answered one row,
 * `limit=1000` 500 rows that read as all of them, `months=abc` one cohort
 * month, and `updated_since=2026-10-01` the 2026th second of 1970. A
 * whole number is read with Api\V3\Support\QueryInt::param() or required(),
 * which answer a 422 naming the parameter and its range.
 *
 * Reported, in files under the tree's `api/` directory: (int), (integer),
 * (float), (double), intval() and floatval() of an element of $params or
 * $queryParams (the router's names for the query string) or of a request
 * superglobal ($_GET, $_POST, $_REQUEST, $_COOKIE), at any depth and with or
 * without a `?? default` after it.
 *
 * Not reported: an element of $payload. The decoded body has the same
 * problem, but the CRUD hooks receive the body under that name after the base
 * class has already validated every field (beforeCreate(), beforeUpdate()),
 * so the name does not say whether the value was read; the hand-read bodies
 * were swept by hand (StrictIntegerBodyFieldsTest) and the entry in CLAUDE.md
 * says what this rule does not cover. Not seen either: a value copied into
 * another variable first.
 *
 * @implements Rule<Node>
 */
final class ForbidNumericCastOfRequestParamRule implements Rule
{
    /** Variables that hold a request's query string or raw input. */
    private const REQUEST_ARRAYS = ['params', 'queryParams', '_GET', '_POST', '_REQUEST', '_COOKIE'];

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $cast = self::castOf($node);
        if ($cast === null || !self::inApiTree($scope->getFile())) {
            return [];
        }
        [$subject, $how] = $cast;
        $name = self::requestParameter($subject);
        if ($name === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Request parameter %s is read with %s, which makes "abc" 0 and "12abc" 12. '
                . 'Read it with Api\V3\Support\QueryInt::param() or required(), which answer a 422 '
                . 'naming the parameter and its range. (CLAUDE.md #4)',
                $name,
                $how
            ))
                ->identifier('prosper202.numericCastOfRequestParam')
                ->line($subject->getStartLine())
                ->build(),
        ];
    }

    /** @return array{Expr, string}|null the cast expression and how it is cast */
    private static function castOf(Node $node): ?array
    {
        if ($node instanceof Int_) {
            return [$node->expr, '(int)'];
        }
        if ($node instanceof Double) {
            return [$node->expr, '(float)'];
        }
        if ($node instanceof FuncCall && $node->name instanceof Name && $node->getArgs() !== []) {
            $function = $node->name->toLowerString();
            if (in_array($function, ['intval', 'floatval', 'doubleval'], true)) {
                return [$node->getArgs()[0]->value, $function . '()'];
            }
        }

        return null;
    }

    /**
     * "$params['limit']" when the expression is an element of a request
     * array (through `?? default`), else null.
     */
    private static function requestParameter(Expr $expr): ?string
    {
        while ($expr instanceof Coalesce) {
            $expr = $expr->left;
        }
        if (!$expr instanceof ArrayDimFetch) {
            return null;
        }
        $keys = [];
        $root = $expr;
        while ($root instanceof ArrayDimFetch) {
            $dim = $root->dim;
            $keys[] = $dim instanceof String_ ? "['" . $dim->value . "']" : ($dim instanceof LNumber ? '[' . $dim->value . ']' : '[…]');
            $root = $root->var;
        }
        if (!$root instanceof Variable || !is_string($root->name) || !in_array($root->name, self::REQUEST_ARRAYS, true)) {
            return null;
        }

        return '$' . $root->name . implode('', array_reverse($keys));
    }

    /** @var array<string, bool> directory => whether it is inside an api/ tree */
    private static array $apiDirs = [];

    /**
     * Inside the `api/` directory of a Prosper202 tree: an ancestor named
     * api with a 202-config/ beside it (ForbidFalsyRequestParamTestRule
     * reads it the same way).
     */
    private static function inApiTree(string $file): bool
    {
        $start = dirname(str_replace('\\', '/', $file));
        if (isset(self::$apiDirs[$start])) {
            return self::$apiDirs[$start];
        }
        $found = false;
        for ($dir = $start; $dir !== dirname($dir); $dir = dirname($dir)) {
            if (basename($dir) === 'api' && is_dir(dirname($dir) . '/202-config')) {
                $found = true;
                break;
            }
        }

        return self::$apiDirs[$start] = $found;
    }
}
