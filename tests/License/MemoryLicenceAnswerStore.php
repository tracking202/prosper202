<?php

declare(strict_types=1);

namespace Tests\License;

use Prosper202\License\LicenceAnswerStore;

/** A LicenceAnswerStore held in memory, for the licence gates' tests. */
final class MemoryLicenceAnswerStore implements LicenceAnswerStore
{
    /** @var array<string, array<string, array{bool, int}>> answers by key hash, then question */
    public array $answers = [];

    public bool $unreadable = false;

    public function lastAnswer(string $keyHash, string $question): ?array
    {
        if ($this->unreadable) {
            throw new \RuntimeException('the record could not be read');
        }
        return $this->answers[$keyHash][$question] ?? null;
    }

    public function saveAnswer(string $keyHash, string $question, bool $yes, int $at): void
    {
        $this->answers[$keyHash][$question] = [$yes, $at];
    }
}
