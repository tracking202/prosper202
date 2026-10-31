<?php

declare(strict_types=1);

namespace Prosper202\OfflineConversion;

/**
 * A scheduled fetch URL of an offline conversion file, as stored, without
 * its key (FeedStore keeps only the key's hash).
 */
final class Feed
{
    public function __construct(
        public readonly int $feedId,
        public readonly int $userId,
        public readonly Format $format,
        public readonly int $days,
        public readonly ?string $conversionName,
        public readonly ?Consent $adUserData,
        public readonly ?Consent $adPersonalization,
        public readonly int $createdAt,
        public readonly int $keyCreatedAt,
        public readonly ?int $lastFetchedAt,
        public readonly ?int $lastFetchRows,
    ) {
    }

    /** What a fetch at $now writes. */
    public function options(int $now): ExportOptions
    {
        return new ExportOptions(
            $this->format,
            $this->days,
            $now,
            null,
            null,
            $this->conversionName,
            $this->adUserData,
            $this->adPersonalization
        );
    }
}
