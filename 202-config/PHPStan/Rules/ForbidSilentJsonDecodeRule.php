<?php

declare(strict_types=1);

namespace Prosper202\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids json_decode(...) ?? [] pattern.
 *
 * CLAUDE.md #4: Silent data loss on malformed input — malformed JSON must
 * produce explicit errors, not be silently replaced with empty arrays.
 *
 * @implements Rule<Coalesce>
 */
final class ForbidSilentJsonDecodeRule implements Rule
{
    public function getNodeType(): string
    {
        return Coalesce::class;
    }

    /**
     * @param Coalesce $node
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // Check if left side is json_decode(...)
        if (!$node->left instanceof FuncCall) {
            return [];
        }

        if (!$node->left->name instanceof Name) {
            return [];
        }

        if ($node->left->name->toLowerString() !== 'json_decode') {
            return [];
        }

        // Check if right side is an empty array []
        if (!$node->right instanceof Array_ || count($node->right->items) !== 0) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'json_decode(...) ?? [] silently discards malformed JSON. '
                . 'Validate JSON and throw on parse errors instead. (CLAUDE.md #4)'
            )
                ->identifier('prosper202.silentJsonDecode')
                ->build(),
        ];
    }
}
