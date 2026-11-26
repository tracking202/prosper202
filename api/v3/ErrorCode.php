<?php

declare(strict_types=1);

namespace Api\V3;

/**
 * The stable `code` every v3 error answer carries: a program decides on it,
 * never on the message. The list is documented in docs/openapi.yaml (the
 * Error schema's enum and the Errors table), and ErrorCodesAreDocumentedTest
 * holds the two equal. A code is added here, never renamed or removed.
 */
final class ErrorCode
{
    public const BAD_REQUEST = 'bad_request';
    public const UNAUTHORIZED = 'unauthorized';
    public const LICENCE = 'licence';
    public const FORBIDDEN = 'forbidden';
    public const NOT_FOUND = 'not_found';
    public const METHOD_NOT_ALLOWED = 'method_not_allowed';
    public const CONFLICT = 'conflict';
    public const PAYLOAD_TOO_LARGE = 'payload_too_large';
    public const VALIDATION_FAILED = 'validation_failed';
    public const RATE_LIMITED = 'rate_limited';
    public const CLIENT_ERROR = 'client_error';
    public const SERVER_ERROR = 'server_error';
    public const UPSTREAM_UNAVAILABLE = 'upstream_unavailable';
    public const SERVICE_UNAVAILABLE = 'service_unavailable';

    public const INVALID_JSON = 'invalid_json';
    public const UNSUPPORTED_API_VERSION = 'unsupported_api_version';
    public const DRY_RUN_UNSUPPORTED = 'dry_run_unsupported';
    public const NOTHING_TO_UPDATE = 'nothing_to_update';
    public const VERSION_CONFLICT = 'version_conflict';
    public const ALREADY_EXISTS = 'already_exists';
    public const NAME_TAKEN = 'name_taken';
    public const IDEMPOTENCY_KEY_REUSED = 'idempotency_key_reused';
    public const IDEMPOTENCY_IN_FLIGHT = 'idempotency_in_flight';
    public const IDEMPOTENCY_INDETERMINATE = 'idempotency_indeterminate';
    public const WRITE_COMMITTED = 'write_committed';
    public const UPGRADE_PENDING = 'upgrade_pending';
    public const INSUFFICIENT_SCOPE = 'insufficient_scope';
    public const INVALID_REQUEST = 'invalid_request';
    public const INVALID_GRANT = 'invalid_grant';
    public const SYNC_URL_REFUSED = 'sync_url_refused';
    public const SYNC_URL_UNRESOLVED = 'sync_url_unresolved';
    public const SYNC_LIMIT = 'sync_limit';
    public const SYNC_KEY_UNREADABLE = 'sync_key_unreadable';
    public const SYNC_REMOTE_FAILED = 'sync_remote_failed';

    /** The code an answer with this status carries when nothing more specific was said. */
    private const BY_STATUS = [
        400 => self::BAD_REQUEST,
        401 => self::UNAUTHORIZED,
        402 => self::LICENCE,
        403 => self::FORBIDDEN,
        404 => self::NOT_FOUND,
        405 => self::METHOD_NOT_ALLOWED,
        409 => self::CONFLICT,
        413 => self::PAYLOAD_TOO_LARGE,
        422 => self::VALIDATION_FAILED,
        429 => self::RATE_LIMITED,
        500 => self::SERVER_ERROR,
        502 => self::UPSTREAM_UNAVAILABLE,
        503 => self::SERVICE_UNAVAILABLE,
    ];

    /** @return list<string> every code, in declaration order */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants(\ReflectionClassConstant::IS_PUBLIC));
    }

    public static function isKnown(string $code): bool
    {
        return in_array($code, self::all(), true);
    }

    public static function forStatus(int $status): string
    {
        return self::BY_STATUS[$status] ?? ($status >= 500 ? self::SERVER_ERROR : self::CLIENT_ERROR);
    }

    /**
     * The code an error body answers with: its own when registered, else the
     * status's. An unregistered code is a bug here, logged and not served,
     * since a client could not have been told what it means.
     *
     * @param array<string, mixed> $body
     */
    public static function forBody(array $body, int $status): string
    {
        $code = $body['code'] ?? null;
        if (is_string($code) && self::isKnown($code)) {
            return $code;
        }
        if ($code !== null) {
            error_log('API v3: error code ' . var_export($code, true) . ' is not in ErrorCode; answered '
                . self::forStatus($status) . ' instead');
        }

        return self::forStatus($status);
    }
}
