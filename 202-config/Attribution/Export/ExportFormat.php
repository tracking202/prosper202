<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Export;

enum ExportFormat: string
{
    case CSV = 'csv';
    case XLS = 'xls';

    public static function fromString(string $value): self
    {
        $normalised = strtolower(trim($value));

        return match ($normalised) {
            'csv' => self::CSV,
            'xls', 'xlsx', 'excel' => self::XLS,
            default => throw new \InvalidArgumentException('Unsupported export format requested.'),
        };
    }
}
