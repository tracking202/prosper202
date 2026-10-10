<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Tests\Support\CallArgs;
use Tests\Support\SourceScan;

/**
 * Every fgetcsv() passes no length: a record longer than the length comes back
 * as several records. Spread arguments cannot be read and are refused.
 */
final class CsvRecordsAreReadWholeTest extends TestCase
{
    public function testNoCsvReaderCutsARecordAtALength(): void
    {
        $refused = [];
        $calls = 0;
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (stripos($source, 'fgetcsv') === false) {
                continue;
            }
            foreach (CallArgs::calls($source, ['fgetcsv']) as $call) {
                if ($call['operator'] !== '') { // SplFileObject::fgetcsv() takes no length
                    continue;
                }
                $calls++;
                $problem = self::lengthProblem($call['args']);
                if ($problem !== null) {
                    $refused[] = "$path:{$call['line']}: $problem";
                }
            }
        }

        self::assertGreaterThanOrEqual(5, $calls, 'the revenue upload\'s five readers were found');
        self::assertSame([], $refused, "fgetcsv() is called with a length, so a longer record is split into records of its own; pass null:\n" . implode("\n", $refused));
    }

    /** @param list<list<mixed>> $args */
    private static function lengthProblem(array $args): ?string
    {
        foreach ($args as $position => $arg) {
            $text = CallArgs::text($arg);
            if (str_starts_with($text, '...')) {
                return 'its arguments are spread, so the length cannot be read';
            }
            if (preg_match('/^length:(.*)$/s', $text, $named) === 1) {
                return self::isNoLength($named[1]) ? null : 'a length of ' . $named[1];
            }
            if ($position === 1 && preg_match('/^[A-Za-z_]\w*:(?!:)/', $text) !== 1) {
                return self::isNoLength($text) ? null : 'a length of ' . $text;
            }
        }

        return null;
    }

    private static function isNoLength(string $text): bool
    {
        return in_array(strtolower($text), ['null', '0'], true);
    }
}
