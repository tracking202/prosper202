<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Auth;
use Api\V3\InsufficientScopeException;
use Prosper202\Database\Connection;

/**
 * The answer to a request whose key lacks the scope it needs. A key keeps
 * the scope it was made with, so a release that adds what an AI connection
 * may do leaves every connection approved earlier refused there: the answer
 * names the scope (`required_scope`) and says what grants it, which for a
 * connection is approving it again and for any other key is a new key.
 * The remedy is in the message too, so a reader that knows none of the
 * fields (an older CLI, the hosted MCP server's engine) passes it on.
 */
final class ScopeRefusal
{
    public const CODE = 'insufficient_scope';

    /** key_source: the key an AI app's connection made (AiConnect). */
    public const AI_CONNECTION = 'ai_connection';
    /** key_source: any other key. */
    public const API_KEY = 'api_key';
    /** key_source: the key's row could not be read, so the answer gives both remedies. */
    public const UNKNOWN = 'unknown';

    /**
     * Which kind of key $apiKey (of $userId, who it authenticated) is, read
     * from its label. Asked only once a request is refused, so the requests
     * that pass pay nothing for it; it decides wording alone, so a failure
     * is logged and answered UNKNOWN.
     */
    public static function keySource(\mysqli $db, string $apiKey, int $userId): string
    {
        try {
            // Before 1.9.78's upgrade there is no label, and no connection.
            if (!Auth::apiKeyLabelColumnExists($db)) {
                return self::API_KEY;
            }
            $conn = new Connection($db);
            $stmt = $conn->prepareRead('SELECT label FROM 202_api_keys WHERE api_key = ? AND user_id = ? LIMIT 1');
            $conn->bind($stmt, 'si', [$apiKey, $userId]);
            $row = $conn->fetchOne($stmt);
        } catch (\Throwable $e) {
            error_log('p202: API v3: the refused key\'s label could not be read: ' . $e->getMessage());
            return self::UNKNOWN;
        }
        if ($row === null) {
            // Removed since it authenticated this request.
            return self::UNKNOWN;
        }
        $label = $row['label'] ?? null;
        return AiConnect::isConnectionLabel(is_string($label) ? $label : null)
            ? self::AI_CONNECTION
            : self::API_KEY;
    }

    /**
     * The refusal's message and the fields added to its body. The body keeps
     * `error`, `message` and `status`; the message keeps its first sentence
     * as it was, so a reader matching it still matches.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function answer(InsufficientScopeException $e, string $keySource): array
    {
        $scope = $e->requiredScope;
        $access = AiConnect::accessToApprove($scope, $e->keyScopes);
        $remedy = self::remedy($scope, $keySource, $access);
        $fields = [
            'code' => self::CODE,
            'required_scope' => $scope,
            'key_scope' => implode(',', $e->keyScopes),
            'key_source' => $keySource,
            'remedy' => $remedy,
        ];
        if ($keySource !== self::API_KEY) {
            // The approval page's choice that covers the scope; null when no
            // connection can hold it.
            $fields['connect_access'] = $access;
        }
        return [rtrim($e->getMessage()) . ' ' . $remedy, $fields];
    }

    /** What grants $scope to a key of $keySource. */
    public static function remedy(string $scope, string $keySource, ?string $access): string
    {
        $connection = $access === null
            ? "No AI connection's key can hold '$scope', so connecting again would not add it: do this on the "
                . 'install\'s pages, or with an API key that has the scope.'
            : 'A connection keeps the access it was approved with, and that did not include \'' . $scope
                . '\'. Connect the AI app again and choose "' . AiConnect::ACCESS_LABELS[$access]
                . '" on the approval page.';
        $key = "A key's scope cannot be changed: make a key whose scope includes '$scope' (POST "
            . '/users/{id}/api-keys with a key that holds it, or a full-access key from My Account › Personal '
            . 'Settings › API keys) and use it instead.';
        $relayed = "an AI app's connection through Tracking202's MCP server";
        return match ($keySource) {
            self::AI_CONNECTION => "This key is $relayed. " . $connection,
            self::API_KEY => $key,
            default => "If this key is $relayed: " . $connection . ' Otherwise: ' . $key,
        };
    }
}
