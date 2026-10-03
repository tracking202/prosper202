<?php

declare(strict_types=1);

namespace Tests\Cron;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Tables\CoreTables;

/**
 * Every job type the scheduler writes to 202_cronjobs, or looks for there,
 * fits in the cronjob_type column.
 *
 * The column is char(5). 1.9.55 wrote 'daily', 'hour' and 'secon'; a later
 * change renamed two of them 'hourly' and 'second', which MySQL (under the
 * sql_mode='' connect.php sets) stores cut to 'hourl' and 'secon'. Each tier's
 * already-ran check then asked for the full name and never found the row it
 * had just written: the hourly tier ran every minute, and a 202_cronjobs row
 * piled up for each run. `p202 system cron` found it on a real install.
 *
 * A name longer than the column is the whole defect, so that is the check:
 * the scheduler's writes, the names its checks ask for, and the names the
 * live passes delete to reset a gate.
 */
final class CronjobTypesFitTheColumnTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function width(): int
    {
        $sql = CoreTables::cronjobs()->createStatement;
        $found = preg_match('/`cronjob_type`\s+(?:var)?char\((\d+)\)/i', $sql, $m);
        self::assertSame(1, $found, 'cronjob_type is a char column');

        return (int) $m[1];
    }

    /** @return array<string, list<string>> name => where it appears */
    private static function names(): array
    {
        $names = [];
        $sources = glob(self::root() . '/202-cronjobs/*.php') ?: [];
        $sources = array_merge($sources, glob(self::root() . '/tests/live/*.sh') ?: []);
        self::assertNotEmpty($sources);
        foreach ($sources as $file) {
            $text = (string) file_get_contents($file);
            $where = substr($file, strlen(self::root()) + 1);
            // The scheduler's own writes.
            $write = "/\\\$mysql\\['cronjob_type'\\]\\s*=\\s*\\\$db->real_escape_string\\('([^']*)'\\)/";
            preg_match_all($write, $text, $m);
            foreach ($m[1] as $name) {
                $names[$name][] = $where . ' (written)';
            }
            // A literal compared with the column: cronjob_type='x', or every
            // quoted literal in cronjob_type IN (...). A type name is a bare
            // identifier; `cronjob_type='" . $mysql[...] . "'` interpolates the
            // written name (counted above) and is not a literal, and neither
            // is a PHP array key such as $mysql['cronjob_type'] in the list.
            preg_match_all("/cronjob_type\\s*=\\s*'([A-Za-z0-9_]*)'/i", $text, $m);
            foreach ($m[1] as $name) {
                $names[$name][] = $where;
            }
            preg_match_all('/cronjob_type\s+IN\s*\(([^)]*)\)/i', $text, $lists);
            foreach ($lists[1] as $list) {
                preg_match_all("/(?<!\\[)'([A-Za-z0-9_]+)'(?!\\])/", $list, $m);
                foreach ($m[1] as $name) {
                    $names[$name][] = $where . ' (IN list)';
                }
            }
        }

        return $names;
    }

    public function testTheColumnWidthIsReadFromTheTableDefinition(): void
    {
        self::assertSame(5, self::width(), 'char(5); if this changes, the names may grow with it');
    }

    public function testEveryTypeNameFitsTheColumn(): void
    {
        $width = self::width();
        $names = self::names();
        // A scanner that stopped finding names would pass on nothing: the
        // daily, hourly and per-minute tiers each write one. Counted rather
        // than named, so a tier that writes a name too long fails below, on
        // the length, instead of here.
        $written = array_filter($names, static fn(array $where): bool => preg_grep('/\(written\)$/', $where) !== []);
        $found = implode(', ', array_keys($written));
        self::assertCount(3, $written, "the three tiers' written names were found: $found");
        $tooLong = [];
        foreach ($names as $name => $where) {
            if (strlen($name) > $width) {
                $tooLong[] = "'$name' (" . strlen($name) . ' > ' . $width . ') in '
                    . implode(', ', array_unique($where));
            }
        }
        self::assertSame(
            [],
            $tooLong,
            "cronjob_type is char($width): a longer name is stored cut short and its check never matches"
        );
    }
}
