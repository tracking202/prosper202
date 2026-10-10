<?php

declare(strict_types=1);

namespace Tests\DataEngine;

use PHPUnit\Framework\TestCase;
use Prosper202\DataEngine\NoTrafficSource;
use Tests\Support\SourceScan;

/**
 * "No traffic source" is one condition, asked through NoTrafficSource.
 *
 * The rollup writes NULL for a click whose account names no traffic source
 * and the writer before it wrote 0, so a reader that asked for `IS NULL`
 * alone left the older rows out of the "[No traffic source]" report: the API
 * filter, the pages' report filter and the summary form each spelled it so
 * (CLAUDE.md #25). This holds the readers to the helper.
 *
 * What it cannot see: a condition assembled from pieces (`$col . ' IS NULL'`,
 * which is how the API filter spelled it) names no column in any one string,
 * so the second test asks instead that every file which handles the
 * sentinel — the pages' 16777215, ReportFilter's `none` — calls the helper or
 * is listed with why it builds no SQL from it. A file that calls the helper
 * once and spells the condition by hand elsewhere passes; the integration
 * test in GroupReportIntegrationTest is what reads the API's answer.
 */
final class NoTrafficSourceTest extends TestCase
{
    /** file => why a literal `ppc_network_id IS NULL` there is right */
    private const LITERAL_ALLOWED = [
        '202-config/functions-tracking202.php' => '2pn is the 202_ppc_networks row LEFT JOINed through the account: its own key, which has no row 0, so NULL is the only "none"',
    ];

    /** file => why it handles the sentinel without building the condition */
    private const SENTINEL_ALLOWED = [
        '202-config/functions-tracking202.php' => 'the click list joins 202_ppc_networks (see LITERAL_ALLOWED)',
        '202-config/functions-report-prefs.php' => 'defines the pages\' sentinel and its range; builds no SQL from it',
        '202-config/functions-ui-overview.php' => 'offers the sentinel in the Overview\'s filter list',
        'tracking202/Report/ReportFilterInput.php' => 'defines the sentinel for the filter form',
        'tracking202/Report/ReportPrefsStore.php' => 'offers the sentinel in the filter form\'s list',
        'api/v3/Controller.php' => 'MEDIUMINT UNSIGNED\'s range, not the sentinel',
    ];

    public function testTheConditionCountsBothNones(): void
    {
        self::assertSame('(de.ppc_network_id IS NULL OR de.ppc_network_id = 0)', NoTrafficSource::condition('de.ppc_network_id'));
        self::assertSame('(ppc_network_id IS NULL OR ppc_network_id = 0)', NoTrafficSource::condition('ppc_network_id'));
        foreach (['de.ppc_network_id OR 1=1', 'de.ppc_account_id', '`de`.`ppc_network_id`', 'de.ppc_network_id ', ''] as $bad) {
            try {
                NoTrafficSource::condition($bad);
                self::fail('accepted ' . var_export($bad, true));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEveryReaderAsksTheHelper(): void
    {
        $files = SourceScan::phpFiles();
        self::assertGreaterThan(500, count($files), 'the scan found the tree');

        $literal = [];
        $sentinel = [];
        foreach ($files as $file => $code) {
            if ($file === '202-config/DataEngine/NoTrafficSource.php') {
                continue;
            }
            foreach (self::literalNones($code) as $line) {
                $literal[$file][] = $line;
            }
            if (preg_match('/16777215|NO_TRAFFIC_SOURCE|isNoTrafficSource\(/', self::code($code)) === 1
                && !str_contains($code, 'NoTrafficSource::condition(')) {
                $sentinel[] = $file;
            }
        }

        $found = [];
        foreach ($literal as $file => $lines) {
            if (!isset(self::LITERAL_ALLOWED[$file])) {
                $found[] = $file . ':' . implode(',', $lines);
            }
        }
        self::assertSame([], $found, 'A literal `ppc_network_id IS NULL` misses the rows that hold 0: use Prosper202\\DataEngine\\NoTrafficSource::condition(), or list the site in LITERAL_ALLOWED with why NULL is the only "none" there.');
        self::assertSame([], array_values(array_diff($sentinel, array_keys(self::SENTINEL_ALLOWED))), 'These files handle the "No traffic source" sentinel without NoTrafficSource::condition(): use it, or list the file in SENTINEL_ALLOWED with why it builds no condition.');

        $stale = array_merge(
            array_diff(array_keys(self::LITERAL_ALLOWED), array_keys($literal)),
            array_diff(array_keys(self::SENTINEL_ALLOWED), $sentinel)
        );
        self::assertSame([], array_values($stale), 'These allowed entries match nothing any more; remove them.');
    }

    public function testTheLiteralScanSeesItsSpellings(): void
    {
        foreach ([
            "\$s .= ' AND 2st.ppc_network_id IS NULL';",
            "\$s .= \" AND 2c.ppc_network_id   is  null \";",
            "\$s = \"WHERE {\$x} AND de.ppc_network_id IS NULL\";",
            "\$s = 'AND `ppc_network_id` IS NULL';",
            "\$s = <<<SQL\nAND de.ppc_network_id IS NULL\nSQL;",
        ] as $code) {
            self::assertNotSame([], self::literalNones("<?php\n" . $code . "\n"), $code);
        }
        foreach ([
            '// ppc_network_id IS NULL in a comment',
            "\$s = 'ppc_network_id IS NOT NULL';",
            "\$s = 'ppc_network_id_x IS NULL';",
        ] as $code) {
            self::assertSame([], self::literalNones("<?php\n" . $code . "\n"), $code);
        }
    }

    /**
     * The lines of string pieces in $code that test a ppc_network_id for
     * NULL.
     *
     * @return list<int>
     */
    private static function literalNones(string $code): array
    {
        $lines = [];
        foreach (token_get_all($code) as $token) {
            if (is_array($token)
                && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                && preg_match('/\bppc_network_id`?\s+IS\s+NULL\b/i', $token[1]) === 1) {
                $lines[] = $token[2];
            }
        }

        return $lines;
    }

    /** $code without its comments, so a docblock naming the sentinel is not a use of it. */
    private static function code(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
