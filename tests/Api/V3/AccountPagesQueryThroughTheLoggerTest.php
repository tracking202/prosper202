<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PhpToken;
use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * The account pages run their statements through _mysqli_query(), which logs
 * a failure, so that MysqliQueryResultIsTestedTest reads every result they
 * use. A direct `->query(` is a result that test never sees: a failed read
 * there showed a cron that ran as "never", the DNI networks a page had as
 * none, and a ClickServer key the account had as missing.
 */
final class AccountPagesQueryThroughTheLoggerTest extends TestCase
{
    /** The directories held to it. */
    private const DIRECTORIES = ['202-account/'];

    public function testNoAccountPageCallsQueryDirectly(): void
    {
        $found = [];
        $scanned = 0;
        foreach (SourceScan::phpFiles() as $file => $source) {
            $held = false;
            foreach (self::DIRECTORIES as $dir) {
                $held = $held || str_starts_with($file, $dir);
            }
            if (!$held) {
                continue;
            }
            $scanned++;
            $found = array_merge($found, array_map(
                static fn (int $line): string => $file . ':' . $line,
                self::directQueryLines($source)
            ));
        }
        self::assertGreaterThan(5, $scanned, 'the scan read no account page');
        self::assertSame(
            [],
            $found,
            'Call _mysqli_query($db, $sql) and test its answer (MysqliQueryResultIsTestedTest).'
        );
    }

    public function testTheScanSeesEverySpelling(): void
    {
        foreach (
            [
            '<?php $db->query($sql);',
            '<?php $r = $db -> query ($sql);',
            '<?php $r = $db?->query($sql);',
            '<?php $r = $this->db->query($sql);',
            '<?php $r = $db->Query($sql);',
            '<?php $r = $db->query(' . "\n" . '$sql);',
            ] as $source
        ) {
            self::assertNotSame([], self::directQueryLines($source), $source);
        }
        $notCalls = [
            '<?php _mysqli_query($db, $sql);',
            '<?php $q = "query(";',
            '<?php $db->queryAll();',
            '<?php query($x);',
        ];
        foreach ($notCalls as $source) {
            self::assertSame([], self::directQueryLines($source), $source);
        }
    }

    /** @return list<int> */
    private static function directQueryLines(string $source): array
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn (PhpToken $t): bool => !$t->isIgnorable()
        ));
        $lines = [];
        foreach ($tokens as $i => $token) {
            if (!$token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                continue;
            }
            $name = $tokens[$i + 1] ?? null;
            $isQuery = $name !== null && $name->is(T_STRING) && strtolower($name->text) === 'query';
            if ($isQuery && ($tokens[$i + 2]->text ?? '') === '(') {
                $lines[] = $token->line;
            }
        }
        return $lines;
    }
}
