<?php

declare(strict_types=1);

namespace Prosper202\Goals;

/**
 * A goal write that was refused, with a reason a caller can map to a status:
 * EVENT_CONFLICT (409: an event id reused with different content),
 * EVENT_CAP (422: the subject holds the most events it may), NOT_FOUND (404),
 * INVALID (422), CONFLICT (409), and INTEGRITY (500: stored state that
 * cannot be read, named so the row can be found).
 */
final class GoalEngineException extends \RuntimeException
{
    public const EVENT_CONFLICT = 'event_conflict';
    public const EVENT_CAP = 'event_cap';
    public const NOT_FOUND = 'not_found';
    public const INVALID = 'invalid';
    public const CONFLICT = 'conflict';
    public const INTEGRITY = 'integrity';

    /**
     * @param array<string, string> $fieldErrors
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly array $fieldErrors = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
