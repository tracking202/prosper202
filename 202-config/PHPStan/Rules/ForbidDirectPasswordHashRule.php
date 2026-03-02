<?php

declare(strict_types=1);

namespace Prosper202\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids direct calls to password_hash() and use of PASSWORD_BCRYPT.
 *
 * CLAUDE.md #5: Inconsistent security patterns — use hash_user_pass()
 * from functions-auth.php which uses PASSWORD_DEFAULT and keeps hashing
 * policy centralized.
 *
 * @implements Rule<FuncCall>
 */
final class ForbidDirectPasswordHashRule implements Rule
{
    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @param FuncCall $node
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Name) {
            return [];
        }

        $functionName = $node->name->toLowerString();

        if ($functionName === 'password_hash') {
            return [
                RuleErrorBuilder::message(
                    'Direct password_hash() bypasses hash_user_pass(). '
                    . 'Use hash_user_pass() from functions-auth.php for consistent hashing. (CLAUDE.md #5)'
                )
                    ->identifier('prosper202.directPasswordHash')
                    ->build(),
            ];
        }

        return [];
    }
}
