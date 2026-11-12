<?php

declare(strict_types=1);

namespace Prosper202\Spend;

/** A spend ledger write refused by field, nothing written; $errors names each. */
final class SpendLedgerRefused extends \RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Spend rows refused: ' . implode('; ', array_map(
            static fn (string $field, string $why): string => "$field $why",
            array_keys($errors),
            $errors
        )));
    }
}
