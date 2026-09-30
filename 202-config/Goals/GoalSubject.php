<?php

declare(strict_types=1);

namespace Prosper202\Goals;

/**
 * Whose progress is tracked (plan §2.2): a click (web campaigns) or an
 * install (apps). `clickAt` and `installAt` are the anchors `within` windows
 * count from; a subject that lacks the anchor a goal's window names cannot
 * evaluate that window, and its outcomes for the goal are recorded as
 * ineligible (`no_click`, `no_install`) rather than re-based on the other
 * anchor.
 *
 * `rebases` maps a goal id to the version an explicit re-evaluation applied
 * to this subject: every event of the subject is evaluated under at least
 * that version from then on (GoalEvaluator::versionFor()).
 */
final class GoalSubject
{
    public const CLICK = 'click';
    public const INSTALL = 'install';

    /**
     * @param array<int, int> $rebases goal id => version
     */
    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly ?int $clickAt,
        public readonly ?int $installAt,
        public readonly array $rebases = [],
        /** The subject's click, when it has one: where its ledger rows go. */
        public readonly ?int $clickId = null,
        /** The campaign of that click: whose payouts apply. */
        public readonly ?int $campaignId = null,
        /** An install subject's app registration: whose goals it evaluates. */
        public readonly ?int $registrationId = null,
    ) {
        if ($type !== self::CLICK && $type !== self::INSTALL) {
            throw new \InvalidArgumentException('subject type must be click or install, got "' . $type . '"');
        }
        if ($id <= 0) {
            throw new \InvalidArgumentException('subject id must be a positive integer');
        }
    }

    /** @param array<int, int> $rebases */
    public function withRebases(array $rebases): self
    {
        return new self($this->type, $this->id, $this->clickAt, $this->installAt, $rebases, $this->clickId, $this->campaignId, $this->registrationId);
    }
}
