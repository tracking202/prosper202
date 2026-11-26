<?php

declare(strict_types=1);

namespace Prosper202\Conversion;

/** The revenue upload batch named is not the account's (or does not exist). */
final class UploadBatchNotFound extends \RuntimeException
{
    public function __construct(public readonly int $batchId)
    {
        parent::__construct('upload batch ' . $batchId . ' is not one of this account\'s uploads');
    }
}
