<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/**
 * Enumerates the export formats supported by attribution snapshot jobs.
 */
enum ExportFormat: string
{
    case CSV = 'csv';
    case XLS = 'xls';

    /**
     * Returns the file extension that should be used when persisting this format.
     */
    public function fileExtension(): string
    {
        return match ($this) {
            self::CSV => 'csv',
            self::XLS => 'xls',
        };
    }
}
