<?php

declare(strict_types=1);

namespace Tests\Conversion\Ledger;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\Ledger\ConversionSource;

/**
 * The two CLIs check `--source` before they send it. The Go CLI keeps a copy
 * of the ledger's source list; a copy that falls behind refuses a source the
 * server accepts (or sends one it refuses), so it is read here, as text, and
 * must equal the enum, in its order. The PHP CLI no longer keeps one: it
 * reads the enum (P202Cli\ServerLists::conversionSources()), and its
 * command, read as text (loading it needs Symfony Console), must not grow a
 * copy back.
 */
final class ConversionSourceListsTest extends TestCase
{
    /** @return list<string> */
    private static function quoted(string $list): array
    {
        preg_match_all('/"([a-z_]+)"|\'([a-z_]+)\'/', $list, $m);
        $out = [];
        foreach ($m[1] as $i => $double) {
            $out[] = $double !== '' ? $double : $m[2][$i];
        }

        return $out;
    }

    public function testBothCliCopiesOfTheSourceListMatchTheLedger(): void
    {
        $root = dirname(__DIR__, 3);
        $want = array_map(static fn (ConversionSource $s): string => $s->value, ConversionSource::cases());

        $go = (string) file_get_contents($root . '/go-cli/cmd/conversion.go');
        self::assertSame(1, preg_match('/var conversionSources = \[\]string\{(.*?)\}/s', $go, $m), 'go-cli/cmd/conversion.go declares conversionSources');
        self::assertSame($want, self::quoted($m[1]), 'the Go CLI\'s --source list is the ledger\'s');

        self::assertSame($want, \P202Cli\ServerLists::conversionSources(), 'the PHP CLI\'s --source list is the ledger\'s');
        $php = (string) file_get_contents($root . '/cli/Commands/ConversionListCommand.php');
        self::assertSame(0, preg_match('/\'(postback|legacy_baseline)\'/', $php), 'ConversionListCommand spells no source itself');
        self::assertGreaterThanOrEqual(2, substr_count($php, 'ServerLists::conversionSources()'), 'its help and its check both read the list');
    }
}
