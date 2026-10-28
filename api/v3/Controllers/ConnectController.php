<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Support\AiConnect;
use Api\V3\Support\PayloadKeys;
use Api\V3\Support\ServerStateStore;

/**
 * POST /connect/token: Tracking202's MCP server exchanging a one-time AI
 * connection approval for the connection's key (Api\V3\Support\AiConnect).
 * Answered before authentication: the code and its verifier are the
 * credential.
 */
final class ConnectController
{
    public function __construct(
        private readonly \mysqli $db,
        private readonly ServerStateStore $store,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: int, 1: array<string, mixed>} status and body
     */
    public function token(array $payload): array
    {
        PayloadKeys::refuseUnknown($payload, ['code', 'code_verifier'], 'an AI connection approval');
        return AiConnect::exchange($this->db, $this->store, $payload);
    }
}
