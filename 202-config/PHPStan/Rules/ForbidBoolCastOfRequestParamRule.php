<?php

declare(strict_types=1);

namespace Prosper202\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\Cast\Bool_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids reading a request value as a flag by casting it in the API.
 *
 * CLAUDE.md #4: a sync job's flags were `(bool) ($payload['force_update'] ??
 * false)`. (bool) makes every non-empty string true, "false" and "no"
 * included, so `"force_update": "false"` queued a job that overwrote the
 * target's differing records, and `"skip_errors": "false"` one that carried
 * on past the errors it was sent to stop at. filter_var() with
 * FILTER_VALIDATE_BOOL and no FILTER_NULL_ON_FAILURE is the same loss the
 * other way: "abc" is false, a typo read as "no". A flag is read with
 * Api\V3\Support\RequestFlag::param(), which takes true/false, 1/0 and their
 * strings and answers a 422 naming the field for anything else (or with
 * filter_var(…, FILTER_NULL_ON_FAILURE) and a refusal of the null, as the
 * app-postback filters do).
 *
 * Reported, in files under the tree's `api/` directory: (bool), (boolean) and
 * boolval() of an element of $params, $queryParams or $payload (the router's
 * names for the query string and the decoded body) or of a request
 * superglobal ($_GET, $_POST, $_REQUEST, $_COOKIE), at any depth and with or
 * without a `?? default` after it; and filter_var() of one with
 * FILTER_VALIDATE_BOOL or FILTER_VALIDATE_BOOLEAN whose flags do not name
 * FILTER_NULL_ON_FAILURE.
 *
 * Unlike the numeric-cast rule this one reads $payload: the CRUD base has no
 * boolean field type, so no hook receives a body flag it already validated.
 * A value a handler has itself checked is_bool() is read with `=== true`
 * (AdAttributionKitProtocol's did-win), which says it was checked.
 * Not seen: a value copied into another variable first, or held in an array
 * with another name — the name is the whole heuristic, as for the sibling
 * rules.
 *
 * @implements Rule<Node>
 */
final class ForbidBoolCastOfRequestParamRule implements Rule
{
    /** Variables that hold a request's query string, decoded body or raw input. */
    private const REQUEST_ARRAYS = ['params', 'queryParams', 'payload', '_GET', '_POST', '_REQUEST', '_COOKIE'];

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $read = self::flagReadOf($node);
        if ($read === null || !self::inApiTree($scope->getFile())) {
            return [];
        }
        [$subject, $how] = $read;
        $name = self::requestParameter($subject);
        if ($name === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Request value %s is read as a flag with %s, which reads "false" and "no" as true '
                . '(filter_var without FILTER_NULL_ON_FAILURE reads "abc" as false). Read it with '
                . 'Api\V3\Support\RequestFlag::param(), which answers a 422 naming the field. (CLAUDE.md #4)',
                $name,
                $how
            ))
                ->identifier('prosper202.boolCastOfRequestParam')
                ->line($subject->getStartLine())
                ->build(),
        ];
    }

    /** @return array{Expr, string}|null the value read as a flag and how */
    private static function flagReadOf(Node $node): ?array
    {
        if ($node instanceof Bool_) {
            return [$node->expr, '(bool)'];
        }
        if (!$node instanceof FuncCall || !$node->name instanceof Name || $node->getArgs() === []) {
            return null;
        }
        $function = $node->name->toLowerString();
        $args = $node->getArgs();
        if ($function === 'boolval') {
            return [$args[0]->value, 'boolval()'];
        }
        if ($function !== 'filter_var' || !isset($args[1]) || !self::names($args[1]->value, ['FILTER_VALIDATE_BOOL', 'FILTER_VALIDATE_BOOLEAN'])) {
            return null;
        }
        if (isset($args[2]) && self::names($args[2]->value, ['FILTER_NULL_ON_FAILURE'])) {
            return null;
        }

        return [$args[0]->value, 'filter_var(FILTER_VALIDATE_BOOL) without FILTER_NULL_ON_FAILURE'];
    }

    /**
     * Whether the expression names one of the constants anywhere inside it
     * (a flags bitmask, or an options array's 'flags').
     *
     * @param list<string> $constants
     */
    private static function names(Expr $expr, array $constants): bool
    {
        $found = (new NodeFinder())->findFirst(
            $expr,
            static fn (Node $n): bool => $n instanceof ConstFetch
                && in_array(ltrim($n->name->toString(), '\\'), $constants, true)
        );

        return $found !== null;
    }

    /**
     * "$payload['dry_run']" when the expression is an element of a request
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
     * api with a 202-config/ beside it (as the sibling rules read it).
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
