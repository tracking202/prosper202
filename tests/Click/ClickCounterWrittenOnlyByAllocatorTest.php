<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Tests\Support\AssembledStrings;
use Tests\Support\SourceScan;

/**
 * 202_clicks_counter is written by MysqlClickRepository::allocateClickId()
 * and nothing else in the served tree.
 *
 * Ids from ClickId::EDGE_FIRST up are the hosted edge's, and the install's
 * counter stays below them only while nothing but the allocator writes it:
 * one INSERT of an edge click's id into the counter, an UPDATE of a row, or an
 * `ALTER TABLE … AUTO_INCREMENT`, and every click the install records after it
 * takes an id the edge may already have given another click. allocateClickId()
 * refuses such an id (EdgeClickIdRefused); this keeps anything from getting the
 * counter there.
 *
 * Every string that names the table, as PHP assembles it (constants resolved,
 * literals joined across `.`), is a read (it starts with SELECT or SHOW) or is
 * listed in OTHER_NAMINGS by its first words with why. A bare name counts: a
 * list of table names is how a statement that names none is built. The scan
 * does not see a name built at runtime from parts it cannot read (sprintf(),
 * implode()), nor SQL outside the served PHP (a restore, a hand-run ALTER).
 */
final class ClickCounterWrittenOnlyByAllocatorTest extends TestCase
{
    private const TABLE = '202_clicks_counter';

    private const WRITER = '202-config/Click/MysqlClickRepository.php';

    private const ALLOCATOR = 'allocateClickId';

    /** The allocator's statement. */
    private const ALLOCATION = 'INSERT INTO 202_clicks_counter SET click_id = DEFAULT';

    /**
     * file => the naming's first words => why
     *
     * @var array<string, array<string, string>>
     */
    private const OTHER_NAMINGS = [
        '202-config/Database/Schema/TableRegistry.php' => [
            '202_clicks_counter' => 'TableRegistry::CLICKS_COUNTER, the name the definition resolves',
        ],
        '202-config/Database/Tables/ClickTables.php' => [
            '202_clicks_counter' => "clicksCounter(): the definition's table name",
            'CREATE TABLE IF NOT EXISTS `202_clicks_counter`' => 'the installer creates the counter empty, at no'
                . ' AUTO_INCREMENT of its own',
        ],
        '202-config/Click/ClickRetention.php' => [
            '202_clicks_counter' => 'KEPT: retention never deletes from the counter',
        ],
        '202-config/functions-upgrade.php' => [
            'ALTER TABLE `202_clicks_counter` CHANGE `click_id` `click_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT'
                => 'an old rung widens the column to BIGINT; the counter keeps its AUTO_INCREMENT',
        ],
        '202-config/Click/EdgeClickIdRefused.php' => [
            'The click counter (202_clicks_counter)' => "the refusal's message",
        ],
    ];

    public function testOnlyTheAllocatorWritesTheCounter(): void
    {
        $problems = [];
        $seen = [];
        $allocations = 0;
        foreach (SourceScan::phpFiles() as $path => $source) {
            $functions = null;
            foreach (AssembledStrings::of($source) as $string) {
                if (stripos($string['text'], self::TABLE) === false) {
                    continue;
                }
                $text = self::normalized($string['text']);
                if (preg_match('/\A(?:SELECT|SHOW)\b/i', $text) === 1) {
                    continue;
                }
                if ($path === self::WRITER && $text === self::ALLOCATION) {
                    $functions ??= self::functionLines($source);
                    $in = self::enclosingFunction($functions, $string['line']);
                    if ($in === self::ALLOCATOR) {
                        $allocations++;
                        continue;
                    }
                    $problems[] = "$path:{$string['line']} writes the counter in " . ($in ?? 'no function')
                        . ', not ' . self::ALLOCATOR . '()';
                    continue;
                }
                $listed = self::listedAs($path, $text);
                if ($listed !== null) {
                    $seen[$path][$listed] = ($seen[$path][$listed] ?? 0) + 1;
                    continue;
                }
                $problems[] = "$path:{$string['line']} names the counter: " . substr($text, 0, 90);
            }
        }

        self::assertSame([], $problems, "202_clicks_counter is named outside allocateClickId():\n  "
            . implode("\n  ", $problems)
            . "\nA click id comes from MysqlClickRepository::allocateClickId(), the counter's one writer: anything"
            . " else that writes it can move its AUTO_INCREMENT into the edge's ids (ClickId::EDGE_FIRST up)."
            . ' A naming that writes nothing goes in OTHER_NAMINGS with why.');
        self::assertSame(1, $allocations, 'allocateClickId() makes the one INSERT into the counter');

        $stale = [];
        foreach (self::OTHER_NAMINGS as $path => $namings) {
            foreach (array_keys($namings) as $words) {
                $count = $seen[$path][$words] ?? 0;
                if ($count !== 1) {
                    $stale[] = "$path  $words  (found $count times)";
                }
            }
        }
        self::assertSame([], $stale, 'each listed naming is found once; drop or correct the entry');
    }

    /** The assembled text on one line, each unread part as `…`. */
    private static function normalized(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_replace(AssembledStrings::UNREAD, '…', $text)));
    }

    /** The OTHER_NAMINGS key $text starts with, or null. The longest wins. */
    private static function listedAs(string $path, string $text): ?string
    {
        $match = null;
        foreach (array_keys(self::OTHER_NAMINGS[$path] ?? []) as $words) {
            $matches = $words === self::TABLE ? $text === $words : str_starts_with($text, $words);
            if ($matches && ($match === null || strlen($words) > strlen($match))) {
                $match = $words;
            }
        }

        return $match;
    }

    /**
     * Each named function or method: its name and the lines its body spans.
     *
     * @return list<array{string, int, int}>
     */
    private static function functionLines(string $source): array
    {
        $tokens = token_get_all($source);
        $out = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $start = $tokens[$i][2];
            $j = $i + 1;
            while ($j < $count && (!is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING)) {
                if ($tokens[$j] === '(') {
                    continue 2;
                }
                $j++;
            }
            $name = is_array($tokens[$j] ?? null) ? $tokens[$j][1] : '';
            while ($j < $count && $tokens[$j] !== '{' && $tokens[$j] !== ';') {
                $j++;
            }
            if (($tokens[$j] ?? null) !== '{') {
                continue;
            }
            $depth = 0;
            $line = $start;
            for (; $j < $count; $j++) {
                $token = $tokens[$j];
                if (is_array($token)) {
                    $line = $token[2] + substr_count($token[1], "\n");
                    if (in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                        $depth++;
                    }
                    continue;
                }
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}' && --$depth === 0) {
                    break;
                }
            }
            $out[] = [$name, $start, $line];
        }

        return $out;
    }

    /** @param list<array{string, int, int}> $functions */
    private static function enclosingFunction(array $functions, int $line): ?string
    {
        $best = null;
        foreach ($functions as [$name, $from, $to]) {
            if ($line >= $from && $line <= $to && ($best === null || $from >= $best[1])) {
                $best = [$name, $from];
            }
        }

        return $best[0] ?? null;
    }
}
