<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

/**
 * A stored ledger row the recompute cannot read. It names the row, so a
 * corrupt value is found and fixed rather than guessed around: a click value
 * computed from a row the code did not understand would be a wrong number
 * that looks right.
 */
final class LedgerIntegrityException extends \RuntimeException
{
}
