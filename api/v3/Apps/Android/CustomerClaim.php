<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android;

use Prosper202\Identity\CustomerId;
use Prosper202\Ltv\MysqlCustomerRepository;

/**
 * The `customer` object an app build sends on the install and events
 * bodies (plan §4.3): `{id, type, signature}`, the id the app's user signed
 * in as and the operator server's `cust_sig` over `"<type>:<id>"`
 * (documentation/features/visitor-identity.md).
 *
 * The shape is validated strictly here, like every other field of the two
 * bodies (CLAUDE.md #4): an id the server would not canonicalise, a type
 * outside the vocabulary or a signature that is not 64 hexadecimal
 * characters is a 400 naming the field, never a silently ignored claim.
 * Whether the signature is *valid* is a different question, answered only
 * against the account's linking key (InstallCustomerLink): a well-formed
 * claim with a wrong signature is stored with the body and links nothing.
 *
 * `type` may be absent or null, which means `custom`, as for `cust_type`
 * on a tracking link; the SDKs always send it.
 */
final class CustomerClaim
{
    public const FIELDS = ['id', 'type', 'signature'];
    /** The LTV alias column's width: an id longer than it could not be recorded as an alias. */
    public const MAX_ID_BYTES = 255;

    private function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $signature,
    ) {
    }

    /** `"<type>:<id>"`, what the signature covers. */
    public function canonical(): string
    {
        return $this->type . ':' . $this->id;
    }

    /**
     * Read the claim at `$path` of a decoded body, adding a field error
     * for every bad part. Null when it is absent (or JSON null) or invalid.
     *
     * @param array<string, string> $e
     */
    public static function fromWire(mixed $raw, string $path, array &$e): ?self
    {
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw) || $raw === [] || array_is_list($raw)) {
            $e[$path] = 'must be null or an object {"id", "type", "signature"}: a customer id your server signed';

            return null;
        }
        $before = count($e);
        foreach (array_keys($raw) as $key) {
            if (!in_array((string) $key, self::FIELDS, true)) {
                $e[$path . '.' . $key] = 'is not a customer field (allowed: ' . implode(', ', self::FIELDS) . ')';
            }
        }

        $type = $raw['type'] ?? null;
        $typeName = null;
        if ($type === null) {
            $typeName = 'custom';
        } elseif (is_string($type) && in_array(strtolower(trim($type)), MysqlCustomerRepository::ALIAS_TYPES, true)) {
            $typeName = strtolower(trim($type));
        } else {
            $e[$path . '.type'] = 'must be null or one of ' . implode(', ', MysqlCustomerRepository::ALIAS_TYPES);
        }

        $id = $raw['id'] ?? null;
        $canonical = null;
        if (!is_string($id) || strlen($id) > self::MAX_ID_BYTES) {
            $e[$path . '.id'] = 'is required: the customer id, a string of up to '
                . self::MAX_ID_BYTES . ' bytes';
        } elseif ($typeName !== null) {
            $canonical = CustomerId::canonical($id, $typeName);
            if ($canonical === null) {
                $digest = $typeName === 'email_md5' ? 'MD5' : 'SHA-256';
                $e[$path . '.id'] = $typeName === 'email_md5' || $typeName === 'email_sha256'
                    ? 'must be the ' . $digest . ' digest of the email in hexadecimal, never the email itself'
                    : 'must not be empty';
            }
        }

        $signature = $raw['signature'] ?? null;
        if (!is_string($signature) || preg_match('/^[0-9a-fA-F]{64}$/D', $signature) !== 1) {
            $e[$path . '.signature'] = 'is required: cust_sig, the 64 hexadecimal characters of '
                . 'HMAC-SHA256(linking key, "<type>:<id>")';
        }

        if (count($e) !== $before || $canonical === null || $typeName === null || !is_string($signature)) {
            return null;
        }

        return new self(substr($canonical, strlen($typeName) + 1), $typeName, strtolower($signature));
    }
}
