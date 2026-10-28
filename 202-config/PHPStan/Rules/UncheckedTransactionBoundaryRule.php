<?php

declare(strict_types=1);

namespace Prosper202\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * Reports a mysqli transaction boundary whose result is discarded:
 *
 *     $db->begin_transaction();      $db->commit();      $db->autocommit(false);
 *     mysqli_begin_transaction($db); mysqli_commit($db); mysqli_autocommit($db, false);
 *
 * CLAUDE.md #1. Under connect.php's mysqli_report(MYSQLI_REPORT_STRICT) these
 * return false on failure instead of throwing. An ignored false from
 * begin_transaction() leaves the connection in autocommit, so every statement
 * in the "transaction" lands individually and the rollback in the failure path
 * undoes nothing; an ignored false from commit() reports success for work that
 * was never made durable.
 *
 * Only whole statements are flagged -- `if (!$db->commit())`, `$ok = ...`,
 * `return ...` all use the value. rollback() is deliberately not covered: it
 * runs from failure paths that already hold the root cause.
 *
 * Method calls are checked only when the receiver's type is known to be
 * mysqli, so a wrapper class that happens to expose commit() is not caught.
 * tests/Api/V3/UncheckedTransactionBoundaryTest is the inference-blind
 * complement for untyped legacy receivers.
 *
 * @implements Rule<Expression>
 */
final class UncheckedTransactionBoundaryRule implements Rule
{
    private const METHODS = ['begin_transaction', 'commit', 'autocommit'];
    private const FUNCTIONS = ['mysqli_begin_transaction', 'mysqli_commit', 'mysqli_autocommit'];

    public function getNodeType(): string
    {
        return Expression::class;
    }

    /**
     * @param Expression $node
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $expr = $node->expr;

        if ($expr instanceof MethodCall) {
            if (!$expr->name instanceof Identifier || !in_array($expr->name->name, self::METHODS, true)) {
                return [];
            }
            if (!(new ObjectType('mysqli'))->isSuperTypeOf($scope->getType($expr->var))->yes()) {
                return [];
            }
            $call = '$db->' . $expr->name->name . '()';
        } elseif ($expr instanceof FuncCall) {
            if (!$expr->name instanceof Name || !in_array($expr->name->toLowerString(), self::FUNCTIONS, true)) {
                return [];
            }
            $call = $expr->name->toLowerString() . '()';
        } else {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'The result of %s is discarded. Under MYSQLI_REPORT_STRICT it returns false on failure, '
                . 'leaving the connection in autocommit (or reporting an unwritten commit as success). '
                . 'Check it, or use Connection::transaction() / StatementHelpers::transaction(). (CLAUDE.md #1)',
                $call
            ))
                ->identifier('prosper202.uncheckedTransactionBoundary')
                ->build(),
        ];
    }
}
