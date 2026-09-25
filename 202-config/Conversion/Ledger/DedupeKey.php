<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

use InvalidArgumentException;

/**
 * The one builder of ledger dedupe keys.
 *
 * `UNIQUE (click_id, dedupe_key)` is what makes a retry of any conversion a
 * duplicate instead of a second row. Every source gets its own namespace so
 * two sources can never produce the same key (CLAUDE.md error pattern #17):
 * each key starts with a prefix no other source uses, and within a
 * namespace every component but the last is an integer or a fixed word, so
 * a colon inside the free-text last component cannot be mistaken for a
 * separator. The keys without a colon (install, legacy, conversion) are
 * whole words no prefixed key can equal.
 */
final class DedupeKey
{
    /** Column width; every key built here fits. */
    public const MAX_LENGTH = 320;

    /** Free-text components (transaction ids, event ids) are capped here. */
    private const MAX_TEXT = 255;

    private function __construct()
    {
    }

    /** A network's or merchant's transaction id (a ClickBank receipt is one). */
    public static function transaction(string $transactionId): string
    {
        return 'tx:' . self::text($transactionId, 'transaction id');
    }

    /** A goal outcome: the n-th time the goal was reached, by that event. */
    public static function goal(int $goalId, int $goalVersion, int $n, string $eventId): string
    {
        return 'goal:' . self::positive($goalId, 'goal id') . ':' . self::positive($goalVersion, 'goal version')
            . ':' . self::positive($n, 'repeat index') . ':' . self::text($eventId, 'event id');
    }

    /** The built-in install goal's row. One per click. */
    public static function install(): string
    {
        return 'install';
    }

    /** An app or web event reported for visibility. Event ids are unique within their subject. */
    public static function event(string $subjectType, int $subjectId, string $eventId): string
    {
        if ($subjectType !== 'click' && $subjectType !== 'install') {
            throw new InvalidArgumentException('subject type must be click or install, got "' . $subjectType . '"');
        }

        return 'evt:' . $subjectType . ':' . self::positive($subjectId, 'subject id') . ':' . self::text($eventId, 'event id');
    }

    /** A reversal of an earlier row; the ref is the network's reversal id, or "1". */
    public static function reversal(int $originalConvId, string $reversalRef): string
    {
        return 'rev:' . self::positive($originalConvId, 'conversion id') . ':' . self::text($reversalRef, 'reversal reference');
    }

    /** The value a click held before the ledger managed it. */
    public static function legacy(): string
    {
        return 'legacy';
    }

    /** One line of a revenue CSV upload. */
    public static function upload(int $batchId, int $line): string
    {
        return 'up:' . self::positive($batchId, 'upload batch') . ':' . self::positive($line, 'upload line');
    }

    /**
     * An accumulate-mode payable row with no id of its own: the campaign's
     * one plain conversion, so it happens once per click.
     */
    public static function plainConversion(): string
    {
        return 'conversion';
    }

    /**
     * A replace-mode row with no id of its own. Such rows are never
     * deduplicated (today's behaviour), so the key is the row's own id —
     * which does not exist until the INSERT, hence the placeholder the
     * writer inserts first and rewrites to row() in the same transaction.
     */
    public static function row(int $convId): string
    {
        return 'row:' . self::positive($convId, 'conversion id');
    }

    /** Unique for the instant between the INSERT and the rewrite to row(). */
    public static function rowPlaceholder(): string
    {
        return 'row-pending:' . bin2hex(random_bytes(12));
    }

    private static function positive(int $value, string $what): int
    {
        if ($value <= 0) {
            throw new InvalidArgumentException($what . ' must be a positive integer, got ' . $value);
        }

        return $value;
    }

    private static function text(string $value, string $what): string
    {
        if ($value === '') {
            throw new InvalidArgumentException($what . ' must not be empty');
        }
        if (strlen($value) > self::MAX_TEXT) {
            throw new InvalidArgumentException($what . ' is longer than ' . self::MAX_TEXT . ' bytes');
        }

        return $value;
    }
}
