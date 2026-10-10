<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;
use Prosper202\DataEngine\DataScope;
use Tests\Support\SourceScan;

/**
 * Whose clicks a page reads is decided in one place, DataScope::userId().
 *
 * $_SESSION['publisher'] is the key that decides it, and nothing in the app
 * sets it, so its absence is the case every session is in. The engine,
 * Analyze and the Overview read absence as "own clicks"; the Visitors list,
 * Spy and the click breakdown read it the other way, as "may see every
 * account's", and listed every account's visitors and conversions to every
 * signed-in user. Every read of the key outside DataScope is listed here
 * with what it decides, and none of them chooses whose rows are read.
 */
final class PublisherSessionReadsTest extends TestCase
{
    /** file => [reads of the key in code, what they decide] */
    private const READS = [
        '202-config/DataEngine/DataScope.php' => [2, 'the rule'],
        '202-config/Report/CampaignDataMask.php' => [1, 'exempts a publisher from the figure mask; its rows are already its own'],
        '202-config/class-dataengine.php' => [1, 'drops other accounts\' rows from a publisher\'s totals: narrows, never widens'],
        '202-config/functions-ui-overview.php' => [1, 'hides the traffic-source and offer filters from a publisher'],
        'tracking202/analyze/AnalyzeReportController.php' => [1, 'hides the traffic-source and offer filters from a publisher'],
        '202-config/template.php' => [2, 'the shell\'s feedback widget and menu'],
        'tracking202/Report/Json/FlatReportPayloadBuilder.php' => [1, 'tells the report script whether to show publisher-only columns'],
    ];

    public function testOnlyDataScopeDecidesWhoseClicksAreRead(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            $code = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }
            $n = SourceScan::countMatches('/\$_SESSION\s*\[\s*([\'"])publisher\1\s*\]/', $code, $path);
            if ($n > 0) {
                $found[$path] = $n;
            }
        }
        ksort($found);
        $expected = array_map(static fn (array $read): int => $read[0], self::READS);
        ksort($expected);
        self::assertSame(
            $expected,
            $found,
            "A file reads \$_SESSION['publisher'] that is not listed here. Whose clicks a page reads is "
            . 'DataScope::userId() (null: every account\'s; otherwise that account\'s), never a fresh test of the '
            . 'key: three pages read its absence as "every account" and listed every account\'s clicks.'
        );
    }

    /** Absent is the signed-in account's own; only an explicit false is every account's. */
    public function testTheRule(): void
    {
        $saved = $_SESSION ?? null;
        try {
            $_SESSION = ['user_own_id' => 7];
            self::assertSame(7, DataScope::userId(), 'no key: the account\'s own');
            $_SESSION['publisher'] = true;
            self::assertSame(7, DataScope::userId(), 'a publisher: its own');
            $_SESSION['publisher'] = false;
            self::assertNull(DataScope::userId(), 'explicitly not a publisher: every account\'s');
            $_SESSION = [];
            self::assertSame(0, DataScope::userId(), 'no account: account 0, which owns no clicks');
        } finally {
            if ($saved === null) {
                unset($_SESSION);
            } else {
                $_SESSION = $saved;
            }
        }
    }
}
