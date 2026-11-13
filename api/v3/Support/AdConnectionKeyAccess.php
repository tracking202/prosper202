<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Auth;
use Api\V3\AuthException;

/**
 * What may handle an ad connection's key: connect (paste it), replace it,
 * delete the connection, and run setup, which makes a pixel, a Conversions
 * API key and event settings with it. A key with full access (`*`) or
 * global `write` may; an area scope may not, whatever its area, so an AI
 * connection's key (AiConnect: area scopes only) reads connections and
 * changes campaigns but never touches a key.
 */
final class AdConnectionKeyAccess
{
    public static function require(Auth $auth): void
    {
        if ($auth->hasFullScope() || in_array('write', $auth->scopes(), true)) {
            return;
        }

        throw new AuthException('Connecting an ad account, replacing or deleting its key, and setup need an API key with full access or write scope: an area scope (an AI connection\'s key has only those) reads connections and changes campaigns, never a key.', 403);
    }
}
