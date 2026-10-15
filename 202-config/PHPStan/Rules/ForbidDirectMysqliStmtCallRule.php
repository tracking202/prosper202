<?php

declare(strict_types=1);

namespace Prosper202\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * Forbids direct calls to mysqli_stmt::execute() and mysqli_stmt::bind_param().
 *
 * CLAUDE.md #1: Unchecked return values — $stmt->execute() can return false
 * without throwing. Use Connection::execute($stmt) for checked execution.
 *
 * CLAUDE.md #7: bind_param type mismatches — Connection::bind() validates
 * type/value counts and keeps references alive until execute().
 *
 * @implements Rule<MethodCall>
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

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @param MethodCall $node
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }

        $methodName = $node->name->name;

        if (!isset(self::FORBIDDEN_METHODS[$methodName])) {
            return [];
        }

        $callerType = $scope->getType($node->var);
        $mysqliStmtType = new ObjectType('mysqli_stmt');

        if (!$mysqliStmtType->isSuperTypeOf($callerType)->yes()) {
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

    private static function isConnectionMethod(Scope $scope, string $method): bool
    {
        $function = $scope->getFunction();

        return $scope->getClassReflection()?->getName() === 'Prosper202\\Database\\Connection'
            && $function !== null
            && strtolower($function->getName()) === $method;
    }
}
