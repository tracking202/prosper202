<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Support\ServerStateStore;
use PHPUnit\Framework\TestCase;

/**
 * Under an operator's P202_SERVER_STATE_DIR (the Coolify stack's api_state
 * volume) the API state store keeps each install's state in a directory of
 * its own, install-<hash of the install's id>.
 *
 * The directory was used as named, for whichever install was there, so a
 * database dropped and installed again under it read its predecessor's
 * records as its own: a create retried under an Idempotency-Key the old
 * install had answered was answered 201 with nothing created, and the old
 * install's staged DELETE was listed to the new one to apply against its
 * database -- the bug the temp-dir default had until it was named by the
 * install, kept wherever an operator chose the directory.
 *
 * Each case runs in a child PHP with P202_SERVER_STATE_DIR set and an error
 * log of its own, as ServerStateStoreDefaultDirTest's do: what the store
 * logs once a process is read from there.
 */
final class ServerStateStoreOperatorDirTest extends TestCase
{
    private const CHILD_PRELUDE = <<<'PHP'
        use Api\V3\Support\ServerStateStore;
        $install = static fn (string $id): \Closure => static fn (): string => $id;
        $scope = ServerStateStore::idempotencyScopeForUser(1);
        $operator = (string) getenv('P202_SERVER_STATE_DIR');
        $refusal = static function (\Closure $build): string {
            try {
                return 'used ' . $build()->baseDir();
            } catch (\Api\V3\Exception\DatabaseException $e) {
                return $e->getPrevious()?->getMessage() ?? $e->getMessage();
            }
        };
        PHP;

    private string $work = '';
    private string $operator = '';

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/p202-operator-dir-' . bin2hex(random_bytes(6));
        mkdir($this->work, 0700);
        $this->operator = $this->work . '/api-v3-state';
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->work));
    }

    /** The directory the store names inside $operator for the install $installId. */
    private static function installDir(string $operator, string $installId): string
    {
        return $operator . '/install-' . substr(sha1($installId), 0, 12);
    }

    public function testAReinstallUnderOneDirectoryReadsNothingOfItsPredecessor(): void
    {
        $seen = $this->runChild(self::CHILD_PRELUDE . <<<'PHP'
            $first = new ServerStateStore(null, $install('first-install'));
            $first->putIdempotent($scope, 'k-1', ['data' => ['aff_network_id' => 1]], 'fp');
            $first->stageWriteChange(1, ['change_id' => 'chg-1', 'method' => 'DELETE', 'path' => '/campaigns/1',
                'status' => 'staged', 'created_at_epoch' => time()]);
            $second = new ServerStateStore(null, $install('second-install'));
            $again = new ServerStateStore(null, $install('second-install'));
            $mode = static fn (string $dir): string => sprintf('%04o', lstat($dir)['mode'] & 07777);
            echo json_encode([
                'first' => $first->baseDir(),
                'second' => $second->baseDir(),
                'again' => $again->baseDir(),
                'replay' => $second->lookupIdempotent($scope, 'k-1', 'fp')['state'],
                'staged' => array_column($second->listStagedChangesForUser(1), 'change_id'),
                'found' => $second->findStagedChange('chg-1'),
                'first_still_holds' => $first->lookupIdempotent($scope, 'k-1', 'fp')['state'],
                'top' => array_values(array_diff(scandir($operator), ['.', '..'])),
                'mode' => $mode($second->baseDir()),
                'owner_is_me' => lstat($second->baseDir())['uid'] === posix_geteuid(),
            ]);
            PHP);

        self::assertSame('miss', $seen['replay'], 'the first install\'s Idempotency-Key replays nothing here');
        self::assertSame([], $seen['staged'], 'its staged change is not listed here to apply');
        self::assertNull($seen['found'], 'nor found by its id');
        $first = self::installDir($this->operator, 'first-install');
        $second = self::installDir($this->operator, 'second-install');
        self::assertSame($first, $seen['first']);
        self::assertSame($second, $seen['second'], 'the second install has a directory of its own');
        self::assertSame($second, $seen['again'], 'every time');
        self::assertSame('replay', $seen['first_still_holds'], 'and the first\'s is left as it was');
        $both = [basename($first), basename($second)];
        sort($both);
        sort($seen['top']);
        self::assertSame($both, $seen['top'], 'nothing at the top level');
        self::assertSame('0700', $seen['mode']);
        self::assertTrue($seen['owner_is_me']);
        $log = $this->log();
        self::assertStringContainsString(
            'the API state directory ' . $first . ' is not used: it holds the state of another install',
            $log,
            'the log names the first install\'s directory for the operator'
        );
        self::assertSame(1, substr_count($log, 'is not used'), 'once, when the second install makes its own');
    }

    /**
     * What a store wrote before 1.9.79 sits at the top level of the
     * operator's directory, and nothing in it says whose it is: this
     * install's from before its upgrade, or an earlier install's of a
     * database the directory served. It is not adopted; the log names it
     * with what to do. What else the operator keeps there is not mentioned.
     */
    public function testStateWrittenAtTheTopLevelBefore1979IsNotAdopted(): void
    {
        $seen = $this->runChild(self::CHILD_PRELUDE . <<<'PHP'
            $before = new ServerStateStore($operator);
            $before->putIdempotent($scope, 'k-1', ['data' => ['aff_network_id' => 1]], 'fp');
            $before->stageWriteChange(1, ['change_id' => 'chg-1', 'method' => 'DELETE', 'path' => '/campaigns/1',
                'status' => 'staged', 'created_at_epoch' => time()]);
            file_put_contents($operator . '/README', 'the operator\'s own');
            mkdir($operator . '/backups');
            $store = new ServerStateStore(null, $install('this-install'));
            new ServerStateStore(null, $install('this-install'));
            echo json_encode([
                'dir' => $store->baseDir(),
                'replay' => $store->lookupIdempotent($scope, 'k-1', 'fp')['state'],
                'staged' => array_column($store->listStagedChangesForUser(1), 'change_id'),
                'before_still_holds' => $before->lookupIdempotent($scope, 'k-1', 'fp')['state'],
            ]);
            PHP);

        $dir = self::installDir($this->operator, 'this-install');
        self::assertSame($dir, $seen['dir']);
        self::assertSame('miss', $seen['replay']);
        self::assertSame([], $seen['staged']);
        self::assertSame('replay', $seen['before_still_holds'], 'what was there is left as it was');
        $log = $this->log();
        self::assertStringContainsString('the API state in ' . $this->operator . ' (', $log);
        self::assertStringContainsString('idempotency/', $log);
        self::assertStringContainsString('staged_changes/', $log);
        self::assertStringContainsString('copy what it holds into ' . $dir . ' and then remove it', $log);
        self::assertStringNotContainsString('README', $log, 'the operator\'s own files are not named');
        self::assertStringNotContainsString('backups', $log);
        self::assertSame(1, substr_count($log, 'is not used'), 'once a process');
    }

    /**
     * Its own directory, private to this process's user, is used as it was,
     * and nothing is logged.
     */
    public function testItsOwnPrivateDirectoryIsUsedAgain(): void
    {
        $seen = $this->runChild(self::CHILD_PRELUDE . <<<'PHP'
            (new ServerStateStore(null, $install('my-install')))
                ->putIdempotent($scope, 'k-1', ['data' => ['id' => 7]], 'fp');
            $store = new ServerStateStore(null, $install('my-install'));
            echo json_encode([
                'dir' => $store->baseDir(),
                'replay' => $store->lookupIdempotent($scope, 'k-1', 'fp')['state'],
            ]);
            PHP);

        self::assertSame(self::installDir($this->operator, 'my-install'), $seen['dir']);
        self::assertSame('replay', $seen['replay']);
        self::assertSame('', $this->log());
    }

    /**
     * Each shape the install's directory can take that does not show it is
     * this process's user's alone, planted with records: refused, logged,
     * and never read. Unlike the temp dir there is no alternative to take,
     * so the store refuses to start.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function untrustedShapes(): iterable
    {
        yield 'a directory its group and others can write' => ['world-writable', 'its group or others can write it'];
        yield 'a symbolic link to a directory that holds records' => ['symlink', 'it is a symbolic link'];
        yield 'a file' => ['file', 'it is not a directory'];
        yield 'a directory another user owns' => ['other-owner', 'it is owned by uid'];
    }

    /** @dataProvider untrustedShapes */
    public function testAnInstallDirectoryThatIsNotThisUsersAloneIsRefused(string $shape, string $why): void
    {
        if ($shape === 'other-owner' && (!function_exists('posix_geteuid') || posix_geteuid() !== 0)) {
            self::markTestSkipped('only root can make a directory another user owns');
        }
        $dir = self::installDir($this->operator, 'victim-install');
        $seen = $this->runChild(self::CHILD_PRELUDE . <<<'PHP'
            $shape = $argv[1] ?? '';
            $dir = $argv[2] ?? '';
            mkdir($operator, 0700);
            $planted = dirname($operator) . '/planted';
            $plant = static function (string $at) use ($scope): void {
                $other = new ServerStateStore($at);
                $other->putIdempotent($scope, 'k-1', ['data' => ['planted' => true]], '');
                $other->stageWriteChange(1, ['change_id' => 'chg-planted', 'method' => 'DELETE',
                    'path' => '/campaigns/1', 'status' => 'staged', 'created_at_epoch' => time()]);
            };
            if ($shape === 'world-writable') {
                $plant($dir);
                chmod($dir, 0777);
            } elseif ($shape === 'symlink') {
                $plant($planted);
                symlink($planted, $dir);
            } elseif ($shape === 'other-owner') {
                $plant($dir);
                exec('chown -R nobody ' . escapeshellarg($dir));
            } else {
                file_put_contents($dir, 'x');
            }
            echo json_encode([
                'refused' => $refusal(static fn () => new ServerStateStore(null, $install('victim-install'))),
                'top' => array_values(array_diff(scandir($operator), ['.', '..'])),
            ]);
            PHP, [$shape, $dir]);

        self::assertStringStartsNotWith('used ', $seen['refused'], 'the planted directory was used');
        self::assertStringContainsString('The API state directory ' . $dir . ' is not used: ' . $why, $seen['refused']);
        self::assertSame([basename($dir)], $seen['top'], 'and no other was taken in its place');
        $log = $this->log();
        self::assertStringContainsString('p202: The API state directory ' . $dir . ' is not used: ' . $why, $log);
    }

    /**
     * A process running as root does not make the install's directory in
     * an operator's directory another user owns: the web server's user
     * could not use one root owns, and every API request would be refused.
     */
    public function testRootDoesNotMakeTheInstallsDirectoryForAnotherUser(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('only root can make a directory another user owns');
        }
        $seen = $this->runChild(self::CHILD_PRELUDE . <<<'PHP'
            mkdir($operator, 0755);
            chown($operator, 'nobody');
            echo json_encode([
                'refused' => $refusal(static fn () => new ServerStateStore(null, $install('victim-install'))),
                'top' => array_values(array_diff(scandir($operator), ['.', '..'])),
            ]);
            PHP);

        self::assertStringContainsString('P202_SERVER_STATE_DIR is owned by uid 65534', $seen['refused']);
        self::assertStringContainsString('runs as root', $seen['refused']);
        self::assertSame([], $seen['top'], 'nothing was made');
    }

    /**
     * A store that cannot read the install's id refuses and writes nothing:
     * it does not fall back to the operator's directory bare, which is the
     * directory every install under it shared (CLAUDE.md #11: a fallback is
     * a namespace too). Nor does a store built without the install.
     */
    public function testAStoreThatCannotNameTheInstallRefusesAndWritesNothing(): void
    {
        $seen = $this->runChild(self::CHILD_PRELUDE . <<<'PHP'
            $refusals = [];
            foreach ([
                'unreadable' => static fn () => new ServerStateStore(null, static function (): string {
                    throw new \RuntimeException('The install id could not be read');
                }),
                'empty' => static fn () => new ServerStateStore(null, static fn (): string => ''),
                'without' => static fn () => new ServerStateStore(),
            ] as $name => $build) {
                $refusals[$name] = $refusal($build);
            }
            echo json_encode(['refusals' => $refusals, 'made' => file_exists($operator)]);
            PHP);

        self::assertStringContainsString(
            'named by this install\'s id, which could not be read',
            $seen['refusals']['unreadable']
        );
        self::assertStringContainsString('The install\'s id is empty', $seen['refusals']['empty']);
        self::assertStringContainsString('ServerStateStore::forInstall($db)', $seen['refusals']['without']);
        self::assertFalse($seen['made'], 'the operator\'s directory was not even made');
    }

    /** An operator's directory that is a file refuses, as it did. */
    public function testAnOperatorDirectoryThatIsAFileRefuses(): void
    {
        $seen = $this->runChild(self::CHILD_PRELUDE . <<<'PHP'
            file_put_contents($operator, 'x');
            $build = static fn () => new ServerStateStore(null, $install('an-install'));
            echo json_encode(['refused' => $refusal($build)]);
            PHP);

        self::assertStringContainsString('(P202_SERVER_STATE_DIR) is not a directory', $seen['refused']);
    }

    /**
     * The directories the store writes are the ones it names as its own
     * (STATE_DIRS), which is what the note above finds at the top level of
     * an operator's directory: a directory the store wrote and the list
     * left out would be state from before 1.9.79 that nobody is told of.
     */
    public function testTheStoresDirectoriesAreTheOnesItNames(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/api/v3/Support/ServerStateStore.php');
        self::assertGreaterThan(0, preg_match_all('/->dir\(\'([a-z_]+)\'\)/', $source, $used));
        $named = (new \ReflectionClassConstant(ServerStateStore::class, 'STATE_DIRS'))->getValue();
        $used = array_values(array_unique($used[1]));
        sort($used);
        sort($named);

        self::assertSame($used, $named);
        preg_match_all('/->dir\((?!\'[a-z_]+\'\))[^)]*\)/', $source, $other);
        self::assertSame(['->dir($name)'], $other[0], 'the constructor\'s, over STATE_DIRS, and no other');
    }

    private function log(): string
    {
        return (string) @file_get_contents($this->work . '/php.log');
    }

    /**
     * Run $code in a child PHP with P202_SERVER_STATE_DIR set to the
     * operator's directory and its error log in the work dir; the JSON it
     * prints, decoded. $args reach it as $argv[1...].
     *
     * @param list<string> $args
     * @return array<string, mixed>
     */
    private function runChild(string $code, array $args = []): array
    {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $env = getenv();
        $env['P202_SERVER_STATE_DIR'] = $this->operator;
        $env['TMPDIR'] = $this->work;
        $command = [PHP_BINARY, '-d', 'error_log=' . $this->work . '/php.log', '-r',
            'require ' . var_export($autoload, true) . ';' . $code, '--', ...$args];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($process);
        $seen = json_decode($out, true);
        self::assertIsArray($seen, "the child answered: $out $err");

        return $seen;
    }
}
