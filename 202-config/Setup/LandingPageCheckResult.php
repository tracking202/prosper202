<?php

declare(strict_types=1);

namespace Prosper202\Setup;

/**
 * What LandingPageCheck found on a landing page: a status, and the sentence
 * Setup › Get LP Code shows for it.
 */
final class LandingPageCheckResult
{
    /** The current code (landing.php?lpip=) for this landing page. */
    public const PRESENT = 'present';
    /** Only the paid 1.9.56 code (landing.js?lpip=) for this landing page, which still loads. */
    public const LEGACY = 'legacy';
    /** This landing page's code, and another landing page's beside it. */
    public const MIXED = 'mixed';
    /** This landing page's id, but loaded from a host other than this install's tracking domain. */
    public const OTHER_TRACKER = 'other_tracker';
    /** Only another landing page's code. */
    public const WRONG_ID = 'wrong_id';
    /** This landing page's code, but only inside an HTML comment. */
    public const COMMENTED = 'commented';
    public const ABSENT = 'absent';
    /** The page could not be fetched, or the fetch was refused. */
    public const ERROR = 'error';

    /**
     * @param list<string> $otherIds the other landing pages' ids the page carries code for
     */
    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly array $otherIds = [],
    ) {
    }

    /** The flash kind (p202_flash()) the status is shown as. */
    public function flashKind(): string
    {
        return match ($this->status) {
            self::PRESENT, self::LEGACY => 'ok',
            self::MIXED, self::OTHER_TRACKER => 'warn',
            default => 'bad',
        };
    }
}
