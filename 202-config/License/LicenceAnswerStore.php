<?php

declare(strict_types=1);

namespace Prosper202\License;

/**
 * The durable record of ClickServer's last answer for a key, per question
 * ('valid' for the key, 'paid' for the Pro licence), which the grace period
 * reads (ShellAccessCache::validWithinGrace()). Keys are named by their
 * SHA-256, never stored as sent.
 */
interface LicenceAnswerStore
{
    /**
     * The last answer to $question for the key whose hash is $keyHash.
     *
     * @return array{bool, int}|null [yes, unix time it came], or null when none was recorded
     * @throws \RuntimeException when the record cannot be read
     */
    public function lastAnswer(string $keyHash, string $question): ?array;

    /** @throws \RuntimeException when the answer cannot be recorded */
    public function saveAnswer(string $keyHash, string $question, bool $yes, int $at): void;
}
