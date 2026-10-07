<?php

declare(strict_types=1);

namespace Prosper202\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\BinaryOp\LogicalXor;
use PhpParser\Node\Expr\BinaryOp\NotEqual;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Empty_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\While_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids testing a request parameter by its truthiness in the API.
 *
 * CLAUDE.md #4: `!empty($params['period'])` was LtvController's test for
 * "a period was given", and empty('0') is true, so period=0 named no period
 * and the read answered 200 over the default window, as though that were the
 * window asked for. The same test made campaign_id=0 on GET /conversions no
 * filter (every campaign's conversions), cursor=0 page one, and a
 * customer_ref of "0" on POST /conversions no customer. Presence is
 * isset()/array_key_exists() plus an explicit comparison with whatever
 * means "none" ('' or null); a value is then read, or refused naming it.
 *
 * Reported, in files under the tree's `api/` directory, when the tested expression
 * is an element of $params or $payload (the router's names for the query
 * string and the decoded body) or of a request superglobal ($_GET, $_POST,
 * $_REQUEST, $_COOKIE), at any depth and with or without a `?? default`
 * after it:
 *
 *  - empty(…) and !empty(…);
 *  - `… ?: default` and the condition of `… ? a : b`;
 *  - `!…`;
 *  - the whole condition of if, elseif, while, do-while and for;
 *  - an operand of &&, ||, and, or, xor;
 *  - a loose comparison with true, false, 0, '0' or '' (`== false`).
 *
 * Not reported: an explicit (bool) cast or boolval(), which is how a flag
 * says it is a flag ('0' is false there, and meant to be). Not seen: a value
 * copied into another variable first, or passed through a function (trim(),
 * a cast other than (bool)) before the test, or held in an array with
 * another name — the name is the whole heuristic, and the entry in CLAUDE.md
 * says what this rule does not cover.
 *
 * @implements Rule<Node>
 */
final class ForbidFalsyRequestParamTestRule implements Rule
{
    /** Variables that hold a request's parameters. */
    private const REQUEST_ARRAYS = ['params', 'payload', '_GET', '_POST', '_REQUEST', '_COOKIE'];

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!self::inApiTree($scope->getFile())) {
            return [];
        }

        $errors = [];
        foreach (self::testedExpressions($node) as [$subject, $how]) {
            $name = self::requestParameter($subject);
            if ($name === null) {
                continue;
            }
            $errors[] = RuleErrorBuilder::message(sprintf(
                'Request parameter %s is tested with %s, which reads "0" as absent '
                . '(empty("0") is true). Test presence with isset()/array_key_exists() and compare with '
                . "'' explicitly, then read the value or refuse it naming the parameter; "
                . 'a flag is (bool) or filter_var(FILTER_VALIDATE_BOOL). (CLAUDE.md #4)',
                $name,
                $how
            ))
                ->identifier('prosper202.falsyRequestParam')
                ->line($subject->getStartLine())
                ->build();
        }

        return $errors;
    }

    /**
     * The expressions this node reads as a boolean, each with how it does.
     *
     * @return list<array{Expr, string}>
     */
    private static function testedExpressions(Node $node): array
    {
        if ($node instanceof Empty_) {
            return [[$node->expr, 'empty()']];
        }
        if ($node instanceof BooleanNot) {
            return [[$node->expr, '!']];
        }
        if ($node instanceof Ternary) {
            return [[$node->cond, $node->if === null ? '?:' : 'a ternary condition']];
        }
        if ($node instanceof If_ || $node instanceof ElseIf_) {
            return [[$node->cond, 'an if condition']];
        }
        if ($node instanceof While_ || $node instanceof Do_) {
            return [[$node->cond, 'a loop condition']];
        }
        if ($node instanceof For_) {
            // The last condition expression decides; check them all.
            return array_map(static fn (Expr $c): array => [$c, 'a loop condition'], $node->cond);
        }
        if ($node instanceof BooleanAnd || $node instanceof BooleanOr
            || $node instanceof LogicalAnd || $node instanceof LogicalOr || $node instanceof LogicalXor
        ) {
            $sigil = $node->getOperatorSigil();

            return [[$node->left, $sigil], [$node->right, $sigil]];
        }
        if ($node instanceof Equal || $node instanceof NotEqual) {
            $sigil = $node->getOperatorSigil();
            $out = [];
            if (self::isFalsyOrTrueLiteral($node->right)) {
                $out[] = [$node->left, $sigil . ' ' . self::literalText($node->right)];
            }
            if (self::isFalsyOrTrueLiteral($node->left)) {
                $out[] = [$node->right, $sigil . ' ' . self::literalText($node->left)];
            }

            return $out;
        }

        return [];
    }

    /** A literal a loose comparison makes "0" equal to, or unequal to absence. */
    private static function isFalsyOrTrueLiteral(Expr $expr): bool
    {
        if ($expr instanceof ConstFetch) {
            return in_array($expr->name->toLowerString(), ['true', 'false'], true);
        }
        if ($expr instanceof LNumber) {
            return $expr->value === 0;
        }
        if ($expr instanceof String_) {
            return $expr->value === '' || $expr->value === '0';
        }

        return false;
    }

    private static function literalText(Expr $expr): string
    {
        if ($expr instanceof ConstFetch) {
            return $expr->name->toLowerString();
        }
        if ($expr instanceof LNumber) {
            return '0';
        }

        return $expr instanceof String_ ? "'" . $expr->value . "'" : '…';
    }

    /**
     * "$params['period']" when the expression is an element of a request
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
     * api with a 202-config/ beside it. A substring test for "/api/" would
     * take in every file of a checkout that happens to live under some
     * /srv/api/ directory.
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
