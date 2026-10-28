<?php

declare(strict_types=1);

namespace Api\V3;

/**
 * A 403 for a scope the key lacks. The dispatcher answers it through
 * Support\ScopeRefusal, which names the scope and what grants it; a role's
 * 403 stays a plain AuthException.
 */
final class InsufficientScopeException extends AuthException
{
    /**
     * @param string $requiredScope the token the request needed, e.g. `text-ads:write`
     * @param string[] $keyScopes the key's own scope tokens
     */
    public function __construct(
        public readonly string $requiredScope,
        public readonly array $keyScopes,
        string $message
    ) {
        parent::__construct($message, 403);
    }
}
