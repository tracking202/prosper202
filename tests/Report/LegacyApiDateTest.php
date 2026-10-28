<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;

/**
 * The legacy report API's dates (validateDate(), api/legacy/functions.php).
 * A report sent date_from without date_to validated the missing one too, and
 * DateTime::createFromFormat() refuses null under strict types: the request
 * answered 500 where a malformed date answers "Wrong date format".
 */
final class LegacyApiDateTest extends TestCase
{
    public function testOnlyADateInTheFormatIsADate(): void
    {
        $file = dirname(__DIR__, 2) . '/api/legacy/functions.php';
        $script = 'declare(strict_types=1); require ' . var_export($file, true) . '; echo json_encode(['
            . 'validateDate("10/13/2026"), validateDate(null), validateDate(["10/13/2026"]),'
            . ' validateDate("2026-10-13"), validateDate("13/10/2026"), validateDate("")]);';
        $php = escapeshellarg(PHP_BINARY);
        $out = shell_exec($php . ' -d display_errors=stderr -r ' . escapeshellarg($script) . ' 2>&1');

        self::assertSame('[true,false,false,false,false,false]', trim((string) $out), (string) $out);
    }
}
