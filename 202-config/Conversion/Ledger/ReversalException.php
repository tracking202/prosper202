<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

/**
 * A reversal that cannot be recorded. The message is written for the caller
 * (a network's postback, an API client): it names the transaction and, for a
 * conflict, the reversal already on file.
 */
final class ReversalException extends \RuntimeException
{
    public const NO_TARGET = 'no_target';
    public const CONFLICT = 'conflict';

    public function __construct(string $message, public readonly string $kind)
    {
        parent::__construct($message);
    }
}
