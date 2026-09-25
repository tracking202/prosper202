<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android\Integrity;

/**
 * IntegrityPolicy's answer: whether the verdict passes, the machine code of
 * the first check it failed (`valid` when none did), a sentence for the
 * operator, and the summary stored in 202_app_installs.integrity_verdict —
 * the fields the checks read, never the token.
 */
final class IntegrityJudgement
{
    /** @param array<string, mixed> $summary */
    public function __construct(
        public readonly bool $valid,
        public readonly string $code,
        public readonly string $reason,
        public readonly array $summary,
    ) {
    }

    /** The summary as stored: JSON, at most 1024 bytes. */
    public function summaryJson(): string
    {
        $json = json_encode($this->summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 1024) {
            $json = json_encode(['code' => $this->code, 'truncated' => true], JSON_THROW_ON_ERROR);
        }

        return $json;
    }
}
