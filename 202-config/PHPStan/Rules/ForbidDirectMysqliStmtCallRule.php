<?php

declare(strict_types=1);

namespace Prosper202\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;

/**
 * Forbids direct calls to mysqli_stmt::execute() and mysqli_stmt::bind_param().
 *
 * CLAUDE.md #1: Unchecked return values — $stmt->execute() can return false
 * without throwing. Use Connection::execute($stmt) for checked execution.
 *
 * CLAUDE.md #7: bind_param type mismatches — Connection::bind() validates
 * type/value counts and keeps references alive until execute().
 *
 * Read in the spellings that are calls: a method call in any case, a
 * nullsafe one (`$stmt?->execute()`) and the procedural functions
 * (`mysqli_stmt_execute($stmt)`). Not seen: a first-class callable
 * (`$stmt->execute(...)`, which PHPStan hands to rules as its own node) and
 * a callable built from strings (`call_user_func([$stmt, 'execute'])`).
 *
 * @implements Rule<CallLike>
 */
final class ForbidDirectMysqliStmtCallRule implements Rule
{
    /** @var array<string, string> method => error message */
    private const FORBIDDEN_METHODS = [
        'execute' => 'Direct $stmt->execute() bypasses Connection::execute() checked execution. '
            . 'Use $this->conn->execute($stmt) instead. (CLAUDE.md #1)',
        'bind_param' => 'Direct $stmt->bind_param() bypasses Connection::bind() ref safety. '
            . 'Use $this->conn->bind($stmt, $types, $values) instead. (CLAUDE.md #7)',
    ];

    /** @var array<string, string> procedural function => the method it is */
    private const FORBIDDEN_FUNCTIONS = [
        'mysqli_stmt_execute' => 'execute',
        'mysqli_stmt_bind_param' => 'bind_param',
    ];

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @param CallLike $node
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof FuncCall) {
            if (!$node->name instanceof Name) {
                return [];
            }
            $methodName = self::FORBIDDEN_FUNCTIONS[strtolower($node->name->getLast())] ?? null;
            if ($methodName === null) {
                return [];
            }
        } elseif ($node instanceof MethodCall) {
            if (!$node->name instanceof Identifier) {
                return [];
            }
            $methodName = strtolower($node->name->name);
            if (!isset(self::FORBIDDEN_METHODS[$methodName])) {
                return [];
            }
            // PHPStan reads `$stmt?->execute()` as this call too, on a type
            // that keeps the null: the call runs whenever there is a statement.
            $callerType = TypeCombinator::removeNull($scope->getType($node->var));
            if (!(new ObjectType('mysqli_stmt'))->isSuperTypeOf($callerType)->yes()) {
                return [];
            }
        } else {
            return [];
        }

        // Connection::execute() is the checked execute the message points to.
        if ($methodName === 'execute' && self::isConnectionMethod($scope, 'execute')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::FORBIDDEN_METHODS[$methodName])
                ->identifier('prosper202.directStmtCall')
                ->build(),
        ];
    }

    /**
     * Directly in that method's body: a closure or arrow function written
     * there reports the method as its function too, and is not exempt.
     */
    private static function isConnectionMethod(Scope $scope, string $method): bool
    {
        $function = $scope->getFunction();

        return $scope->getClassReflection()?->getName() === 'Prosper202\\Database\\Connection'
            && !$scope->isInAnonymousFunction()
            && $function !== null
            && strtolower($function->getName()) === $method;
    }
}
