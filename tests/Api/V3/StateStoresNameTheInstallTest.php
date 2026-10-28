<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Support\ServerStateStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMysqliConnection;
use Tests\Support\SourceScan;

/**
 * The served tree builds API state stores only with
 * ServerStateStore::forInstall($db).
 *
 * The store's default directory was named by the database's host and name,
 * so a database dropped and installed again under its name read its
 * predecessor's state as its own: measured on a live instance, the seed's
 * creates were answered 201 from the old install's Idempotency-Key records
 * and created nothing, and the old install's staged DELETE of campaign 1
 * deleted the new install's campaign 1. The install's id names it now, read
 * from the database forInstall() is given -- and names the install's own
 * directory inside P202_SERVER_STATE_DIR too, which was used as named, so a
 * reinstall under it (on the Coolify stack, any reset of the db_data volume)
 * read its predecessor's state the same way. A store built any other way in
 * a configured process has no install to name and refuses -- which a rate
 * limiter, failing open, turns into no limit at all, with nothing but a log
 * line to say so.
 *
 * Outside ServerStateStore.php nothing may say `new` of it (by any spelling
 * or alias), extend it, name it as `::class` or in a string (the ways to
 * construct it without `new` naming it); inside, the one construction is
 * forInstall()'s. P202_SERVER_STATE_DIR is read in one place, where the
 * store resolves its directory, and the store never writes that directory
 * bare.
 */
final class StateStoresNameTheInstallTest extends TestCase
{
    private const STORE = 'api/v3/Support/ServerStateStore.php';
    private const STATE_DIR_VARIABLE = 'P202_SERVER_STATE_DIR';

    /**
     * Nothing but the store's directory resolution reads
     * P202_SERVER_STATE_DIR, so nothing builds a path from the operator's
     * directory that skips the install's own inside it. A literal naming the
     * variable whole is a read (getenv(), $_ENV, $_SERVER); a name assembled
     * from pieces, or interpolated, is not seen.
     */
    public function testOnlyTheStoresDirectoryResolutionReadsTheStateDirVariable(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            $tokens = self::significant(token_get_all($source));
            foreach ($tokens as $i => $token) {
                $names = self::is($token, T_CONSTANT_ENCAPSED_STRING)
                    && substr($token[1], 1, -1) === self::STATE_DIR_VARIABLE;
                if ($names) {
                    $found[] = $path . ' line ' . $token[2] . ', in ' . (self::enclosingFunction($tokens, $i) ?: '-');
                }
            }
        }

        self::assertCount(1, $found, implode("\n", $found));
        self::assertStringStartsWith(self::STORE . ' line ', $found[0]);
        self::assertStringEndsWith(', in resolveDefaultBaseDir', $found[0]);
    }

    /**
     * A store built for an install under P202_SERVER_STATE_DIR keeps every
     * record in the install's own directory inside it, never in the
     * operator's directory itself. Asked of what the store does, not of how
     * the code that resolves the directory is written: every kind of record
     * is written through two installs' stores, built as the served tree
     * builds them, and the operator's directory then holds the two
     * installs' directories and nothing else. The install's id is read from
     * the database each is given.
     */
    public function testNoStoreWritesTheOperatorsDirectoryBare(): void
    {
        $operator = sys_get_temp_dir() . '/p202-operator-bare-' . bin2hex(random_bytes(6));
        $savedEnv = getenv(self::STATE_DIR_VARIABLE);
        $savedLog = ini_get('error_log');
        putenv(self::STATE_DIR_VARIABLE . '=' . $operator);
        ini_set('error_log', $operator . '.log');
        try {
            $made = [];
            foreach (['1', '2'] as $digit) {
                $db = new FakeMysqliConnection();
                $db->whenQueryContainsReturnRows(
                    '202_deployment_secrets',
                    [['secret_value' => str_repeat($digit, 64)]]
                );
                $store = ServerStateStore::forInstall($db);
                self::writeEveryKindOfRecord($store);
                $made[] = basename($store->baseDir());
            }
            $top = array_values(array_diff((array) scandir($operator), ['.', '..']));
            sort($top);
            sort($made);

            self::assertNotSame($made[0], $made[1], 'two installs share a directory');
            self::assertSame($made, $top, 'the operator\'s directory holds only the installs\' own');
        } finally {
            putenv($savedEnv === false ? self::STATE_DIR_VARIABLE : self::STATE_DIR_VARIABLE . '=' . $savedEnv);
            ini_set('error_log', $savedLog === false ? '' : $savedLog);
            exec('rm -rf ' . escapeshellarg($operator) . ' ' . escapeshellarg($operator . '.log'));
        }
    }

    private static function writeEveryKindOfRecord(ServerStateStore $store): void
    {
        $scope = ServerStateStore::idempotencyScopeForUser(1);
        $store->putIdempotent($scope, 'k-1', ['data' => ['id' => 1]], 'fp');
        $store->reserveIdempotent($scope, 'k-2', 'fp');
        $store->stageWriteChange(1, ['change_id' => 'chg-1', 'method' => 'DELETE', 'path' => '/campaigns/1',
            'status' => 'staged', 'created_at_epoch' => time()]);
        $store->recordChange('campaigns', 'delete', ['aff_campaign_id' => 1], 1);
        $job = $store->createJob(['entity' => 'campaigns', 'options' => []], 1);
        $store->appendJobEvent((string) $job['job_id'], 'info', 'started');
        $store->appendAudit(['job_id' => $job['job_id']]);
        $store->acquireJobLock((string) $job['job_id'])();
        $store->acquirePairLock('source', 'target')();
        $store->issuePruneToken('source|target');
        $store->issueConnectCode(hash('sha256', 'code'), ['user_id' => 1], 60);
        $store->saveSyncManifest('source|target', ['campaigns' => []]);
        $store->incrementMetric('jobs_failed');
        $store->endSpan($store->startSpan('sync.execute'));
        $store->softIpRateLimit('intake', 10, 60, '192.0.2.1');
        $store->reserveQuota('cap', 10, 60);
        $store->consumeRateLimit('user:1:sync', 10, 60);
    }

    public function testTheServedTreeBuildsStoresOnlyForAnInstall(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if ($path === self::STORE) {
                continue;
            }
            foreach (self::constructions($source) as $what) {
                $found[] = $path . ': ' . $what;
            }
        }

        self::assertSame([], $found, "Build an API state store with ServerStateStore::forInstall(\$db): its\n"
            . "default directory is named by the install, which only the database can say.\n");
    }

    public function testTheStoresOneConstructionIsForInstall(): void
    {
        $source = (string) file_get_contents(SourceScan::repoRoot() . '/' . self::STORE);
        $tokens = self::significant(token_get_all($source));

        $lines = [];
        $inside = [];
        foreach ($tokens as $i => $token) {
            if (!self::is($token, T_NEW)) {
                continue;
            }
            $class = $tokens[$i + 1] ?? null;
            $name = is_array($class) ? strtolower(self::lastSegment($class[1])) : '';
            if (!in_array($name, ['self', 'static', 'serverstatestore'], true)) {
                continue;
            }
            $lines[] = $token[2];
            $inside[] = self::enclosingFunction($tokens, $i);
        }

        self::assertSame(['forInstall'], $inside, 'constructions of the store in its own file, by the function'
            . ' each is in (lines ' . implode(', ', $lines) . ')');
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function shapes(): iterable
    {
        yield 'new, imported' => ['<?php use Api\V3\Support\ServerStateStore; $s = new ServerStateStore();', 1];
        yield 'new, fully qualified' => ['<?php $s = new \Api\V3\Support\ServerStateStore();', 1];
        yield 'new, qualified' => ['<?php namespace Api\V3; $s = new Support\ServerStateStore();', 1];
        yield 'new, in another case' => ['<?php $s = new \API\V3\Support\serverstatestore();', 1];
        yield 'new, with a directory' => ['<?php $s = new ServerStateStore($dir);', 1];
        yield 'new, as a default' => ['<?php function f(ServerStateStore $s = new ServerStateStore()) {}', 1];
        yield 'new, through an alias' => ['<?php use Api\V3\Support\ServerStateStore as Store; $s = new Store();', 1];
        yield 'new, through a grouped alias' => [
            '<?php use Api\V3\Support\{QuotaStore, ServerStateStore as Store}; $s = new Store;',
            1,
        ];
        yield 'extended' => ['<?php class Mine extends \Api\V3\Support\ServerStateStore {}', 1];
        yield 'extended, anonymously' => ['<?php $s = new class extends ServerStateStore {};', 1];
        yield 'its ::class' => ['<?php $c = ServerStateStore::class; $s = new $c();', 1];
        yield 'its name in a string' => ['<?php $r = new \ReflectionClass(\'Api\V3\Support\ServerStateStore\');', 1];
        yield 'forInstall() is not a construction' => ['<?php $s = ServerStateStore::forInstall($db);', 0];
        yield 'a type is not a construction' => ['<?php function f(ServerStateStore $s): ServerStateStore {}', 0];
        yield 'another store is not it' => ['<?php $s = new ServerStateStoreFake(); $t = new StateStore();', 0];
        yield 'a comment is not a construction' => ["<?php // new ServerStateStore()\n", 0];
    }

    /** @dataProvider shapes */
    public function testTheShapesAConstructionTakes(string $source, int $expected): void
    {
        self::assertCount($expected, self::constructions($source), implode('; ', self::constructions($source)));
    }

    /**
     * What in $source builds, or could build, a ServerStateStore: each a
     * line and what is there.
     *
     * @return list<string>
     */
    private static function constructions(string $source): array
    {
        $names = ['serverstatestore' => true];
        if (preg_match_all('/\bServerStateStore\s+as\s+([A-Za-z_][A-Za-z0-9_]*)/i', $source, $aliases) > 0) {
            foreach ($aliases[1] as $alias) {
                $names[strtolower($alias)] = true;
            }
        }

        $tokens = self::significant(token_get_all($source));
        $found = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }
            if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                if (stripos($token[1], 'ServerStateStore') !== false) {
                    $found[] = 'line ' . $token[2] . ': its name in a string';
                }
                continue;
            }
            if (in_array($token[0], [T_USE, T_AS], true) || !self::isName($token)) {
                continue;
            }
            if (!isset($names[strtolower(self::lastSegment($token[1]))])) {
                continue;
            }
            $before = $tokens[$i - 1] ?? null;
            $after = $tokens[$i + 1] ?? null;
            $afterThat = $tokens[$i + 2] ?? null;
            if (self::is($before, T_NEW)) {
                $found[] = 'line ' . $token[2] . ': new ' . $token[1];
            } elseif (self::is($before, T_EXTENDS)) {
                $found[] = 'line ' . $token[2] . ': extends ' . $token[1];
            } elseif (
                self::is($after, T_DOUBLE_COLON) && is_array($afterThat) && strtolower($afterThat[1]) === 'class'
            ) {
                $found[] = 'line ' . $token[2] . ': ' . $token[1] . '::class';
            }
        }

        return $found;
    }

    /**
     * The name of the function whose body holds token $at, or '' at file or
     * class scope.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function enclosingFunction(array $tokens, int $at): string
    {
        $depth = 0;
        for ($i = $at - 1; $i >= 0; $i--) {
            if ($tokens[$i] === '}') {
                $depth++;
            } elseif (
                $tokens[$i] === '{'
                || self::is($tokens[$i], T_CURLY_OPEN)
                || self::is($tokens[$i], T_DOLLAR_OPEN_CURLY_BRACES)
            ) {
                if ($depth > 0) {
                    $depth--;
                    continue;
                }
                // The brace that opens the block holding $at: a function's when
                // `function name(...)` comes before it.
                for ($j = $i - 1; $j >= 0; $j--) {
                    if (self::is($tokens[$j], T_FUNCTION)) {
                        $name = $tokens[$j + 1] ?? null;

                        return is_array($name) && $name[0] === T_STRING ? $name[1] : '';
                    }
                    if ($tokens[$j] === ';' || $tokens[$j] === '{' || $tokens[$j] === '}') {
                        break;
                    }
                }
            }
        }

        return '';
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     * @return list<array{int, string, int}|string>
     */
    private static function significant(array $tokens): array
    {
        return array_values(array_filter(
            $tokens,
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
    }

    /** @param array{int, string, int}|string|null $token */
    private static function is(mixed $token, int $id): bool
    {
        return is_array($token) && $token[0] === $id;
    }

    /** @param array{int, string, int} $token */
    private static function isName(array $token): bool
    {
        return in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true);
    }

    private static function lastSegment(string $name): string
    {
        $at = strrpos($name, '\\');

        return $at === false ? $name : substr($name, $at + 1);
    }
}
