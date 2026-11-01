<?php

declare(strict_types=1);

namespace Prosper202\Click\Filter;

/** An entry the list refused (full, or the same addresses already there), naming the field. */
final class ClickFilterListRefusal extends \RuntimeException
{
    public function __construct(
        public readonly string $field,
        string $message,
        public readonly ?int $existingEntryId = null,
    ) {
        parent::__construct($message);
    }
}
