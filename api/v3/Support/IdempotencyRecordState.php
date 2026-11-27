<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Prosper202\Lifecycle\Moves;
use Prosper202\Lifecycle\State;

/**
 * What an Idempotency-Key's record says of its request. No field stores the
 * state: it is which fields the record holds, read by ofRecord() and written
 * by the builders here and nowhere else. The values are names for logs.
 *
 * A claim past its TTL is still `claimed`: a retry is answered that the
 * outcome is unknown, and the request holding the claim, which knows, may
 * still record or release it.
 */
enum IdempotencyRecordState: string implements State
{
    use Moves;

    /** No record. */
    case Free = 'free';
    /** A request holds the key and has not finished. */
    case Claimed = 'claimed';
    /** The write landed and no response was recorded: spent, with nothing to replay. */
    case Indeterminate = 'indeterminate';
    /** The response every later request with this key is answered. */
    case Committed = 'committed';

    public function next(): array
    {
        return match ($this) {
            // Committed without a claim: a caller that reads, runs and then
            // records (sync job creation).
            self::Free => [self::Claimed, self::Committed],
            // Free again only when the request failed with nothing written.
            self::Claimed => [self::Free, self::Indeterminate, self::Committed],
            self::Indeterminate, self::Committed => [],
        };
    }

    public static function initial(): array
    {
        return [self::Free];
    }

    /**
     * A record that is there and says none of this is read as spent, never
     * as free: free is the one reading that runs the request again.
     */
    public static function ofRecord(mixed $item): self
    {
        if ($item === null) {
            return self::Free;
        }
        if (!is_array($item)) {
            return self::Indeterminate;
        }
        if (is_array($item['response'] ?? null)) {
            return self::Committed;
        }
        if (($item['indeterminate'] ?? false) === true) {
            return self::Indeterminate;
        }

        return self::claimedAt($item) > 0 ? self::Claimed : self::Indeterminate;
    }

    /** @param array<mixed> $item */
    public static function claimedAt(array $item): int
    {
        $at = $item['claimed_at'] ?? 0;

        return is_int($at) ? $at : 0;
    }

    /**
     * @param array<mixed> $item
     * @return ?array<mixed>
     */
    public static function responseOf(array $item): ?array
    {
        $response = $item['response'] ?? null;

        return is_array($response) ? $response : null;
    }

    /** The request a record was written for; '' when it names none. */
    public static function fingerprintOf(mixed $item): string
    {
        $fingerprint = is_array($item) ? ($item['fingerprint'] ?? '') : '';

        return is_string($fingerprint) ? $fingerprint : '';
    }

    /** @return array<string, mixed> a record in `claimed` */
    public static function claim(string $fingerprint, int $now): array
    {
        return ['stored_at' => $now, 'claimed_at' => $now, 'fingerprint' => $fingerprint];
    }

    /**
     * @param array<mixed> $claim
     * @return array<mixed> the claim, in `indeterminate`
     */
    public static function marked(array $claim): array
    {
        $claim['indeterminate'] = true;

        return $claim;
    }

    /**
     * @param array<mixed> $response
     * @return array<string, mixed> a record in `committed`
     */
    public static function committed(array $response, string $fingerprint, int $now): array
    {
        return ['stored_at' => $now, 'fingerprint' => $fingerprint, 'response' => $response];
    }
}
