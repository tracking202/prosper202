<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

use InvalidArgumentException;

/**
 * The one builder and reader of a ledger row's `source_ref`: what generated
 * the row, as a reference the breakdown resolves into a name and a link.
 *
 * Each kind has its own prefix and every component after it is an integer
 * or a fixed-width hex digest, so no two kinds can produce the same string
 * and a reference can be read back exactly (CLAUDE.md error pattern #17).
 * A stored value this cannot read is reported as `unknown` with the raw
 * text, never guessed into a kind.
 */
final class SourceRef
{
    public const GOAL = 'goal';
    public const UPLOAD_BATCH = 'upload_batch';
    public const CONVERSION = 'conversion';
    public const API_KEY = 'api_key';
    public const UNKNOWN = 'unknown';

    /** Hex digits of the key digest kept in an API key reference. */
    private const KEY_DIGEST_LENGTH = 16;

    private function __construct()
    {
    }

    /** A goal outcome's row: the goal and the version that decided it. */
    public static function goal(int $goalId, int $version): string
    {
        return 'goal:' . self::positive($goalId, 'goal id') . ':' . self::positive($version, 'goal version');
    }

    /** A revenue-upload line's row: the upload it belongs to. */
    public static function uploadBatch(int $batchId): string
    {
        return 'batch:' . self::positive($batchId, 'upload batch id');
    }

    /** A reversal's row: the conversion it reverses. */
    public static function conversion(int $convId): string
    {
        return 'conv:' . self::positive($convId, 'conversion id');
    }

    /**
     * A row written through the API: the key that wrote it, as a digest.
     *
     * The key itself is a secret, and the breakdown is read by anyone with
     * conversions read scope, so the reference carries a truncated SHA-256
     * of it — enough to tell an account's keys apart and to find the key
     * among the account's own, never enough to use.
     */
    public static function apiKey(string $apiKey): string
    {
        if ($apiKey === '') {
            throw new InvalidArgumentException('an API key reference needs the key');
        }

        return 'apikey:' . self::keyDigest($apiKey);
    }

    /** The digest an API key reference carries, for matching a stored key against one. */
    public static function keyDigest(string $apiKey): string
    {
        return substr(hash('sha256', $apiKey), 0, self::KEY_DIGEST_LENGTH);
    }

    /**
     * Read a stored reference.
     *
     * @return array{kind: string, id?: int, version?: int, digest?: string, raw: string}|null
     *         null when the row has no reference
     */
    public static function parse(?string $ref): ?array
    {
        if ($ref === null || $ref === '') {
            return null;
        }
        if (preg_match('/^goal:([1-9][0-9]{0,9}):([1-9][0-9]{0,9})$/D', $ref, $m) === 1) {
            return ['kind' => self::GOAL, 'id' => (int) $m[1], 'version' => (int) $m[2], 'raw' => $ref];
        }
        if (preg_match('/^batch:([1-9][0-9]{0,18})$/D', $ref, $m) === 1) {
            return ['kind' => self::UPLOAD_BATCH, 'id' => (int) $m[1], 'raw' => $ref];
        }
        if (preg_match('/^conv:([1-9][0-9]{0,18})$/D', $ref, $m) === 1) {
            return ['kind' => self::CONVERSION, 'id' => (int) $m[1], 'raw' => $ref];
        }
        if (preg_match('/^apikey:([0-9a-f]{' . self::KEY_DIGEST_LENGTH . '})$/D', $ref, $m) === 1) {
            return ['kind' => self::API_KEY, 'digest' => $m[1], 'raw' => $ref];
        }

        return ['kind' => self::UNKNOWN, 'raw' => $ref];
    }

    private static function positive(int $value, string $what): int
    {
        if ($value <= 0) {
            throw new InvalidArgumentException($what . ' must be a positive integer, got ' . $value);
        }

        return $value;
    }
}
