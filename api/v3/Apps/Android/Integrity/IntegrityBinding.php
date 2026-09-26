<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android\Integrity;

use Api\V3\Apps\Android\InstallPayload;

/**
 * How a Play Integrity token is bound to one install (plan §5.6, §5.11).
 *
 * The SDK requests a *standard* integrity token with
 *
 *     requestHash = lower-case hex SHA-256 of the install's canonical body
 *
 * — the body with `integrity_token` removed, keys sorted at every level, no
 * insignificant whitespace, `/` and non-ASCII unescaped (InstallPayload::
 * canonical()). That is the same value PR 5 stores as the install's
 * `body_hash` and compares replays by, so there is one hash of one set of
 * bytes, and the vectors in tests/fixtures/app-sdk-contract/android/
 * integrity.json pin it for the Kotlin SDK.
 *
 * Why this binds: the canonical body contains `install_uuid` (122 random
 * bits the device minted) and every referrer field. Google signs the hash
 * into the verdict, and the server compares it to the hash of the body it
 * stored, so a token lifted from one install and attached to another body —
 * another uuid, another referrer, another click — fails `request_hash`. The
 * token itself cannot be in the hashed bytes (it is derived from them),
 * which is why the canonical form excludes it; resending the same body with
 * a fresh token is a replay of the same install, answered as its duplicate.
 */
final class IntegrityBinding
{
    private function __construct()
    {
    }

    public static function requestHash(InstallPayload $payload): string
    {
        return $payload->fingerprint();
    }

    /** What the device's token is stored as: its SHA-256, never the token. */
    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }
}
