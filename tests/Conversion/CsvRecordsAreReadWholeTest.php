<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Tests\Support\CallArgs;
use Tests\Support\SourceScan;

/**
 * A CSV record is read whole. fgetcsv()'s length is not a guard: a record
 * longer than it comes back in pieces, each read as a record of its own. With
 * the 100,000 the revenue upload's readers passed, a report line holding a
 * long description was split, the piece after the cut was read as a line with
 * no subid, and every later line's number moved by one (measured); a line
 * whose subid or commission came after the cut was skipped. Every fgetcsv()
 * in the served tree passes no length (null).
 *
 * The length is read where it can be: the second positional argument, or a
 * named `length:`. A call it cannot read (arguments spread with `...`) is
 * refused by name rather than passed.
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
                if ($call['operator'] !== '') {
                    // SplFileObject::fgetcsv() takes no length.
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

    /**
     * Why a call's length cannot stand, or null when it passes none.
     *
     * @param list<list<mixed>> $args
     */
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
