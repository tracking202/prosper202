<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Exception\DatabaseException;
use Api\V3\Support\ServerStateStore;
use Tests\TestCase;

final class ServerStateStoreDefaultDirTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedGlobals = [];
    private string|false $savedEnv = false;
    /** @var string[] dirs created by the test, removed in tearDown */
    private array $createdDirs = [];

    /**
     * What the code in a child (runChild()) starts with: the install a store
     * is built for, as forInstall() hands it over, without a database.
     */
    private const CHILD_PRELUDE = <<<'PHP'
        use Api\V3\Support\ServerStateStore;
        $install = static fn (string $id): \Closure => static fn (): string => $id;
        PHP;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedGlobals = [
            'dbname' => $GLOBALS['dbname'] ?? null,
            'dbhost' => $GLOBALS['dbhost'] ?? null,
        ];
        $this->savedEnv = getenv('P202_SERVER_STATE_DIR');
        putenv('P202_SERVER_STATE_DIR');
    }

    protected function tearDown(): void
    {
        foreach ($this->savedGlobals as $name => $value) {
            if ($value === null) {
                unset($GLOBALS[$name]);
            } else {
                $GLOBALS[$name] = $value;
            }
        }
        if ($this->savedEnv !== false) {
            putenv('P202_SERVER_STATE_DIR=' . $this->savedEnv);
        }
        foreach ($this->createdDirs as $dir) {
            $this->removeDir($dir);
        }
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    /** The directory the store names for an install of $name on $host in the temp dir $tmp. */
    private static function dirFor(string $tmp, string $host, string $name, string $installId): string
    {
        return $tmp . '/p202-api-v3-state-' . substr(sha1(strlen($host) . ':' . $host . '|' . $name), 0, 12)
            . '-' . substr(sha1($installId), 0, 12);
    }

    /** The directory the store named before 1.9.79: the database's host and name alone. */
    private static function databaseOnlyDirFor(string $tmp, string $host, string $name): string
    {
        return $tmp . '/p202-api-v3-state-' . substr(sha1($host . '|' . $name), 0, 12);
    }

    public function testDefaultDirIsScopedByInstanceIdentity(): void
    {
        $GLOBALS['dbname'] = 'p202_state_test_' . bin2hex(random_bytes(4));
        $GLOBALS['dbhost'] = 'db.internal:3306';
        $install = static fn (): string => 'install-a';

        $store = new ServerStateStore(null, $install);
        $this->createdDirs[] = $store->baseDir();
        $this->assertSame(
            self::dirFor(rtrim(sys_get_temp_dir(), '/'), 'db.internal:3306', $GLOBALS['dbname'], 'install-a'),
            $store->baseDir()
        );

        // A different database on the same host gets a different directory —
        // no shared idempotency, staged changes, or rate limits.
        $GLOBALS['dbname'] = 'p202_state_test_' . bin2hex(random_bytes(4));
        $other = new ServerStateStore(null, $install);
        $this->createdDirs[] = $other->baseDir();
        $this->assertNotSame($store->baseDir(), $other->baseDir());

        // The host and name are not run together as another pair's.
        $GLOBALS['dbhost'] = 'db.internal:3306|x';
        $GLOBALS['dbname'] = 'y';
        $joined = new ServerStateStore(null, $install);
        $this->createdDirs[] = $joined->baseDir();
        $GLOBALS['dbhost'] = 'db.internal:3306';
        $GLOBALS['dbname'] = 'x|y';
        $split = new ServerStateStore(null, $install);
        $this->createdDirs[] = $split->baseDir();
        $this->assertNotSame($joined->baseDir(), $split->baseDir());
    }

    /**
     * Two installs of one database on one host: the database dropped and
     * created again under its name, and installed again. The directory was
     * named by the database's host and name alone, so the second install
     * took the first's as its own -- measured on a live instance, its seed's
     * creates were answered 201 from the first install's Idempotency-Key
     * records and created nothing, and the first install's staged DELETE of
     * campaign 1, listed to the second, deleted the second's campaign 1. The
     * install's id names the directory now, and a reinstall mints another.
     *
     * Run in a child PHP with its own TMPDIR, so nothing here reads or
     * leaves a directory in this host's real temp dir.
     */
    public function testTwoInstallsOfOneDatabaseOnOneHostHaveTwoDirectories(): void
    {
        $tmp = $this->childTempDir();
        $code = self::CHILD_PRELUDE . <<<'PHP'
            $GLOBALS['dbhost'] = '127.0.0.1:3306';
            $GLOBALS['dbname'] = 'prosper202';
            $scope = ServerStateStore::idempotencyScopeForUser(1);
            $first = new ServerStateStore(null, $install('first-install'));
            $first->putIdempotent($scope, 'k-1', ['data' => ['ppc_network_id' => 1]], 'fp');
            $first->stageWriteChange(1, ['change_id' => 'chg-1', 'method' => 'DELETE', 'path' => '/campaigns/1',
                'status' => 'staged', 'created_at_epoch' => time()]);
            $second = new ServerStateStore(null, $install('second-install'));
            $again = new ServerStateStore(null, $install('second-install'));
            echo json_encode([
                'first' => $first->baseDir(),
                'second' => $second->baseDir(),
                'again' => $again->baseDir(),
                'replay' => $second->lookupIdempotent($scope, 'k-1', 'fp')['state'],
                'staged' => array_column($second->listStagedChangesForUser(1), 'change_id'),
                'first_still_holds' => $first->lookupIdempotent($scope, 'k-1', 'fp')['state'],
            ]);
            PHP;
        $seen = $this->runChild($code, $tmp);

        self::assertSame('miss', $seen['replay'], 'the first install\'s Idempotency-Key replays nothing here');
        self::assertSame([], $seen['staged'], 'and its staged change is not listed here to apply');
        self::assertNotSame($seen['first'], $seen['second'], 'the second install has a directory of its own');
        $first = self::dirFor($tmp, '127.0.0.1:3306', 'prosper202', 'first-install');
        $second = self::dirFor($tmp, '127.0.0.1:3306', 'prosper202', 'second-install');
        self::assertSame($first, $seen['first']);
        self::assertSame($second, $seen['second']);
        self::assertSame($second, $seen['again'], 'every time');
        self::assertSame('replay', $seen['first_still_holds'], 'and its directory is left as it was');
        $log = (string) @file_get_contents($tmp . '/php.log');
        self::assertStringContainsString(
            'the API state directory ' . $first . ' is not used: it holds the state of an earlier install',
            $log,
            'the log names the first install\'s directory for the operator'
        );
        self::assertSame(1, substr_count($log, 'is not used'), 'once, when the second install makes its own');
    }

    /**
     * The directory named by the database alone, as every install had before
     * 1.9.79, is not adopted: it may be this install's state from before its
     * upgrade, or the state of the install this database replaced, and
     * nothing in it says which. It is left where it is, and the log names it
     * with what to do.
     */
    public function testTheDirectoryNamedByTheDatabaseAloneIsNotAdopted(): void
    {
        $tmp = $this->childTempDir();
        $code = self::CHILD_PRELUDE . <<<'PHP'
            $GLOBALS['dbhost'] = '127.0.0.1:3306';
            $GLOBALS['dbname'] = 'prosper202';
            $scope = ServerStateStore::idempotencyScopeForUser(1);
            $before = sys_get_temp_dir() . '/p202-api-v3-state-' . substr(sha1('127.0.0.1:3306|prosper202'), 0, 12);
            $old = new ServerStateStore($before);
            $old->putIdempotent($scope, 'k-1', ['data' => ['ppc_network_id' => 1]], 'fp');
            $store = new ServerStateStore(null, $install('this-install'));
            new ServerStateStore(null, $install('this-install'));
            echo json_encode([
                'before' => $before,
                'dir' => $store->baseDir(),
                'replay' => $store->lookupIdempotent($scope, 'k-1', 'fp')['state'],
                'old_still_holds' => $old->lookupIdempotent($scope, 'k-1', 'fp')['state'],
            ]);
            PHP;
        $seen = $this->runChild($code, $tmp);

        self::assertSame(self::databaseOnlyDirFor($tmp, '127.0.0.1:3306', 'prosper202'), $seen['before']);
        self::assertSame(self::dirFor($tmp, '127.0.0.1:3306', 'prosper202', 'this-install'), $seen['dir']);
        self::assertSame('miss', $seen['replay']);
        self::assertSame('replay', $seen['old_still_holds'], 'the directory is left as it was');
        $log = (string) @file_get_contents($tmp . '/php.log');
        self::assertStringContainsString('the API state directory ' . $seen['before'] . ' is not used', $log);
        self::assertStringContainsString('copy what it holds into ' . $seen['dir'] . '; otherwise remove it', $log);
        self::assertSame(1, substr_count($log, 'is not used'), 'once a process');
    }

    /**
     * A store that cannot read the install's id refuses, and makes no
     * directory: it does not fall back to the name the database alone gives,
     * which is the name a reinstalled database shared with its predecessor
     * (CLAUDE.md #11: a fallback is a namespace too). Nor does a store built
     * without the install at all.
     */
    public function testAStoreThatCannotNameTheInstallRefusesAndWritesNothing(): void
    {
        $tmp = $this->childTempDir();
        $code = self::CHILD_PRELUDE . <<<'PHP'
            $GLOBALS['dbhost'] = '127.0.0.1:3306';
            $GLOBALS['dbname'] = 'prosper202';
            $refusals = [];
            $builds = [
                'unreadable' => static fn () => new ServerStateStore(null, static function (): string {
                    throw new \RuntimeException('The install id could not be read');
                }),
                'empty' => static fn () => new ServerStateStore(null, static fn (): string => ''),
                'without' => static fn () => new ServerStateStore(),
            ];
            foreach ($builds as $name => $build) {
                try {
                    $refusals[$name] = 'used ' . $build()->baseDir();
                } catch (\Api\V3\Exception\DatabaseException $e) {
                    $refusals[$name] = $e->getPrevious()?->getMessage() ?? $e->getMessage();
                }
            }
            echo json_encode([
                'refusals' => $refusals,
                'made' => array_values(array_diff(scandir(sys_get_temp_dir()), ['.', '..', 'php.log'])),
            ]);
            PHP;
        $seen = $this->runChild($code, $tmp);

        self::assertStringContainsString(
            'named by this install\'s id, which could not be read',
            $seen['refusals']['unreadable']
        );
        self::assertStringContainsString('The install\'s id is empty', $seen['refusals']['empty']);
        self::assertStringContainsString('ServerStateStore::forInstall($db)', $seen['refusals']['without']);
        self::assertSame([], $seen['made'], 'no directory was made, under any name');
    }

    /**
     * The install's id is read for the directory it names, the default one
     * or the install's own in P202_SERVER_STATE_DIR
     * (ServerStateStoreOperatorDirTest); a store given a directory never
     * asks the database.
     */
    public function testTheInstallIdIsReadForEveryDirectoryItNames(): void
    {
        $GLOBALS['dbname'] = 'p202_state_test_env';
        $unreadable = static function (): string {
            throw new \LogicException('the install id was read');
        };
        $dir = sys_get_temp_dir() . '/p202-state-given-' . bin2hex(random_bytes(4));
        $this->createdDirs[] = $dir;
        $this->assertSame($dir, (new ServerStateStore($dir, $unreadable))->baseDir());

        foreach (['P202_SERVER_STATE_DIR=' . $dir, 'P202_SERVER_STATE_DIR'] as $env) {
            putenv($env);
            try {
                new ServerStateStore(null, $unreadable);
                $this->fail('a store that cannot read the install id was built with ' . $env);
            } catch (DatabaseException $e) {
                $this->assertSame('the install id was read', $e->getPrevious()?->getPrevious()?->getMessage());
            }
        }
    }

    /**
     * The pre-1.9.75 shared directory in the temp dir is never taken as this
     * instance's own.
     *
     * A new instance with no directory of its own renamed it into place, on
     * the theory that what sat there was its own pre-upgrade state. Nothing
     * says whose it is: every install on the host wrote it before the
     * scoping (and one still on an older version still does), and in a
     * temp dir anyone can create it. What it holds decides things: an
     * Idempotency-Key recorded there replays another install's response to
     * this one's user 1 and executes nothing; a staged change recorded there
     * is listed for this install's users to apply, against this install's
     * database. Measured: both, in a brand-new instance on another database.
     * The directory is left where it is, untouched, and the log names it and
     * says how an operator who knows it is theirs carries it over.
     *
     * Run in a child PHP with its own TMPDIR (sys_get_temp_dir() is fixed
     * for a process): planting the legacy directory in this host's real temp
     * dir would hand it to any instance resolving its directory meanwhile.
     */
    public function testTheSharedLegacyDirectoryIsNeverAdopted(): void
    {
        $tmp = $this->childTempDir();
        $code = self::CHILD_PRELUDE . <<<'PHP'
            $legacy = sys_get_temp_dir() . '/p202-api-v3-state';
            // What another install (or anyone who can write the temp dir) left there.
            $other = new ServerStateStore($legacy);
            $scope = ServerStateStore::idempotencyScopeForUser(1);
            $other->putIdempotent($scope, 'k-1', ['data' => ['id' => 999]], 'fp');
            $other->stageWriteChange(1, ['change_id' => 'chg-1', 'method' => 'DELETE', 'path' => '/campaigns/1',
                'status' => 'staged', 'created_at_epoch' => time()]);
            $GLOBALS['dbhost'] = 'db.example:3306';
            $GLOBALS['dbname'] = 'brand_new';
            $fresh = new ServerStateStore(null, $install('brand-new-install'));
            $again = new ServerStateStore(null, $install('brand-new-install'));
            echo json_encode([
                'fresh' => $fresh->baseDir(),
                'again' => $again->baseDir(),
                'replay' => $fresh->lookupIdempotent($scope, 'k-1', 'fp')['state'],
                'staged' => array_column($fresh->listStagedChangesForUser(1), 'change_id'),
                'legacy_still_holds' => $other->lookupIdempotent($scope, 'k-1', 'fp')['state'],
            ]);
            PHP;
        $seen = $this->runChild($code, $tmp);

        self::assertSame('miss', $seen['replay'], 'another install\'s idempotency record replays nothing here');
        self::assertSame([], $seen['staged'], 'and its staged change is not listed here to apply');
        $scoped = self::dirFor($tmp, 'db.example:3306', 'brand_new', 'brand-new-install');
        self::assertSame($scoped, $seen['fresh'], 'the instance uses its own directory');
        self::assertSame($scoped, $seen['again'], 'every time');
        self::assertSame('replay', $seen['legacy_still_holds'], 'and the legacy directory is left as it was');
        $log = (string) @file_get_contents($tmp . '/php.log');
        self::assertStringContainsString($tmp . '/p202-api-v3-state is not adopted', $log, 'the log names it');
        self::assertSame(1, substr_count($log, 'is not adopted'), 'once a process, not once a request');
    }

    /**
     * A process with no database identity (a test run, a script that loads
     * the configuration inside a function) wrote the legacy directory — the
     * one a new instance with no directory of its own used to adopt. So the
     * next instance installed on the host took that process's state as its
     * own: measured, its users were replayed the identity-less process's
     * idempotent create and listed its staged DELETE to apply. The fallback
     * has its own directory now, and a new instance finds nothing there
     * (nor adopts the legacy directory: the test above).
     *
     * Run in a child PHP with its own TMPDIR: sys_get_temp_dir() is fixed
     * for a process, and this host's real temp dir may hold a legacy
     * directory the test must not adopt or delete.
     */
    public function testAProcessWithNoIdentityDoesNotWriteWhatTheNextNewInstanceAdopts(): void
    {
        $tmp = $this->childTempDir();
        $code = self::CHILD_PRELUDE . <<<'PHP'
            $scope = ServerStateStore::idempotencyScopeForUser(1);
            $unscoped = new ServerStateStore();
            $unscoped->putIdempotent($scope, 'k-1', ['data' => ['id' => 999]], 'fp');
            $unscoped->stageWriteChange(1, ['change_id' => 'chg-1', 'method' => 'DELETE', 'path' => '/campaigns/1',
                'status' => 'staged', 'created_at_epoch' => time()]);
            $GLOBALS['dbhost'] = 'db.example:3306';
            $GLOBALS['dbname'] = 'brand_new';
            $fresh = new ServerStateStore(null, $install('brand-new-install'));
            echo json_encode([
                'unscoped' => $unscoped->baseDir(),
                'fresh' => $fresh->baseDir(),
                'replay' => $fresh->lookupIdempotent($scope, 'k-1', 'fp')['state'],
                'staged' => array_column($fresh->listStagedChangesForUser(1), 'change_id'),
            ]);
            PHP;
        $seen = $this->runChild($code, $tmp);

        self::assertSame('miss', $seen['replay'], 'the new instance replays nothing it did not record');
        self::assertSame([], $seen['staged'], 'and lists no staged change it was not given');
        self::assertSame($tmp . '/p202-api-v3-state-unscoped', $seen['unscoped'], 'the fallback is not adopted');
        self::assertDirectoryDoesNotExist($tmp . '/p202-api-v3-state', 'nothing wrote the legacy directory');
        self::assertSame(self::dirFor($tmp, 'db.example:3306', 'brand_new', 'brand-new-install'), $seen['fresh']);
    }

    /**
     * The default directory is a name in a temp dir anyone can write, and it
     * was used whoever made it: another local user who made it first decided
     * what this install read -- measured on a live instance served by an
     * unprivileged user, a directory made by `nobody` replayed its planted
     * response to a POST /aff-networks (201, nothing created) and listed its
     * planted DELETE under GET /staged-changes. Now it is used only when it
     * is a real directory owned by this process's user that its group and
     * others cannot write; otherwise the first numbered alternative that is,
     * and the log says which and why.
     *
     * Each shape another user can leave there without root -- a directory
     * anyone can write, a symbolic link, a file -- planted with a record in
     * it, in a child PHP with its own TMPDIR.
     *
     * @return iterable<string, array{string}>
     */
    public static function untrustedShapes(): iterable
    {
        yield 'a directory its group and others can write' => ['world-writable'];
        yield 'a symbolic link to a directory that holds records' => ['symlink'];
        yield 'a file' => ['file'];
    }

    /** @dataProvider untrustedShapes */
    public function testADirectoryAnotherUserCouldHaveMadeIsNotUsed(string $shape): void
    {
        $tmp = $this->childTempDir();
        $preferred = self::dirFor($tmp, 'db.example:3306', 'victim', 'victim-install');
        $code = self::CHILD_PRELUDE . <<<'PHP'
            $shape = $argv[1] ?? '';
            $dir = $argv[2] ?? '';
            $planted = sys_get_temp_dir() . '/planted';
            $scope = ServerStateStore::idempotencyScopeForUser(1);
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
            } else {
                file_put_contents($dir, 'x');
            }
            $GLOBALS['dbhost'] = 'db.example:3306';
            $GLOBALS['dbname'] = 'victim';
            $store = new ServerStateStore(null, $install('victim-install'));
            $again = new ServerStateStore(null, $install('victim-install'));
            $stat = lstat($store->baseDir());
            echo json_encode([
                'dir' => $store->baseDir(),
                'again' => $again->baseDir(),
                'replay' => $store->lookupIdempotent($scope, 'k-1', 'fp')['state'],
                'staged' => array_column($store->listStagedChangesForUser(1), 'change_id'),
                'mode' => sprintf('%04o', $stat['mode'] & 07777),
                'owner_is_me' => $stat['uid'] === posix_geteuid(),
            ]);
            PHP;
        $seen = $this->runChild($code, $tmp, [$shape, $preferred]);

        self::assertSame($preferred . '.1', $seen['dir'], 'the first alternative this process made itself');
        self::assertSame($seen['dir'], $seen['again'], 'every time, so every process of the install agrees');
        self::assertSame('miss', $seen['replay'], 'the planted idempotency record replays nothing');
        self::assertSame([], $seen['staged'], 'and the planted staged change is not listed to apply');
        self::assertSame('0700', $seen['mode']);
        self::assertTrue($seen['owner_is_me']);
        $log = (string) @file_get_contents($tmp . '/php.log');
        self::assertStringContainsString(
            'the API state directory ' . $preferred . ' is not used',
            $log,
            'the log names it'
        );
        self::assertSame(1, substr_count($log, 'is not used'), 'once a process');
    }

    /** A directory of this process's own, private, is used as it was. */
    public function testItsOwnPrivateDirectoryIsUsed(): void
    {
        $tmp = $this->childTempDir();
        $code = self::CHILD_PRELUDE . <<<'PHP'
            $GLOBALS['dbhost'] = 'db.example:3306';
            $GLOBALS['dbname'] = 'mine';
            $scope = ServerStateStore::idempotencyScopeForUser(1);
            (new ServerStateStore(null, $install('my-install')))
                ->putIdempotent($scope, 'k-1', ['data' => ['id' => 7]], 'fp');
            $store = new ServerStateStore(null, $install('my-install'));
            echo json_encode([
                'dir' => $store->baseDir(),
                'replay' => $store->lookupIdempotent($scope, 'k-1', 'fp')['state'],
            ]);
            PHP;
        $seen = $this->runChild($code, $tmp);

        self::assertSame(self::dirFor($tmp, 'db.example:3306', 'mine', 'my-install'), $seen['dir']);
        self::assertSame('replay', $seen['replay']);
        self::assertStringNotContainsString('is not used', (string) @file_get_contents($tmp . '/php.log'));
    }

    /**
     * When the directory and every alternative are refused there is nowhere
     * this process can trust, and the store refuses to start, saying so --
     * it never settles for one of them.
     */
    public function testWhenNoCandidateCanBeTrustedTheStoreRefuses(): void
    {
        $tmp = $this->childTempDir();
        $code = self::CHILD_PRELUDE . <<<'PHP'
            $dir = $argv[1] ?? '';
            foreach (['', '.1', '.2', '.3'] as $suffix) {
                mkdir($dir . $suffix, 0700);
                chmod($dir . $suffix, 0777);
            }
            $GLOBALS['dbhost'] = 'db.example:3306';
            $GLOBALS['dbname'] = 'victim';
            try {
                $store = new ServerStateStore(null, $install('victim-install'));
                echo json_encode(['used' => $store->baseDir()]);
            } catch (\Api\V3\Exception\DatabaseException $e) {
                echo json_encode(['refused' => $e->getPrevious()?->getMessage() ?? $e->getMessage()]);
            }
            PHP;
        $seen = $this->runChild($code, $tmp, [self::dirFor($tmp, 'db.example:3306', 'victim', 'victim-install')]);

        self::assertArrayNotHasKey('used', $seen, 'a directory others can write was used');
        self::assertStringContainsString('No API state directory this process can trust', $seen['refused']);
        self::assertStringContainsString('P202_SERVER_STATE_DIR', $seen['refused'], 'and says what to do');
    }

    /**
     * A process running as root cannot tell the web server's user from
     * anyone else, and a directory of its own would be a store the web tier
     * never reads: a directory another user owns is refused outright. Only
     * root can make a directory another user owns, so this runs as root.
     */
    public function testRootRefusesADirectoryAnotherUserOwns(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('only root can make a directory another user owns');
        }
        $tmp = $this->childTempDir();
        $preferred = self::dirFor($tmp, 'db.example:3306', 'victim', 'victim-install');
        $code = self::CHILD_PRELUDE . <<<'PHP'
            $dir = $argv[1] ?? '';
            mkdir($dir, 0700);
            chown($dir, 'nobody');
            $GLOBALS['dbhost'] = 'db.example:3306';
            $GLOBALS['dbname'] = 'victim';
            try {
                echo json_encode(['used' => (new ServerStateStore(null, $install('victim-install')))->baseDir()]);
            } catch (\Api\V3\Exception\DatabaseException $e) {
                echo json_encode(['refused' => $e->getPrevious()?->getMessage() ?? $e->getMessage()]);
            }
            PHP;
        $seen = $this->runChild($code, $tmp, [$preferred]);

        self::assertArrayNotHasKey(
            'used',
            $seen,
            'root used a directory another user owns, or one of its own beside it'
        );
        self::assertStringContainsString('owned by uid', $seen['refused']);
        self::assertStringContainsString('runs as root', $seen['refused']);
        self::assertDirectoryDoesNotExist($preferred . '.1', 'nor made one of its own');
    }

    /** P202_SERVER_STATE_DIR wins over the temp dir, holding the install's own directory. */
    public function testExplicitEnvOverrideWins(): void
    {
        $GLOBALS['dbname'] = 'p202_state_test_env';
        $dir = sys_get_temp_dir() . '/p202-state-env-test-' . bin2hex(random_bytes(4));
        putenv('P202_SERVER_STATE_DIR=' . $dir . '/');
        $this->createdDirs[] = $dir;

        $store = new ServerStateStore(null, static fn (): string => 'install-a');
        $this->assertSame($dir . '/install-' . substr(sha1('install-a'), 0, 12), $store->baseDir());
    }

    /** A temp dir of its own for a child PHP, removed in tearDown. */
    private function childTempDir(): string
    {
        $tmp = sys_get_temp_dir() . '/p202-state-tmpdir-' . bin2hex(random_bytes(4));
        mkdir($tmp, 0700, true);
        $this->createdDirs[] = $tmp;

        return $tmp;
    }

    /**
     * Run $code in a child PHP whose temp dir is $tmp, with no
     * P202_SERVER_STATE_DIR and its error log in $tmp/php.log; the JSON it
     * prints, decoded. $args reach it as $argv[1...].
     *
     * @param list<string> $args
     * @return array<string, mixed>
     */
    private function runChild(string $code, string $tmp, array $args = []): array
    {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $env = getenv();
        unset($env['P202_SERVER_STATE_DIR']);
        $env['TMPDIR'] = $tmp;
        $command = [PHP_BINARY, '-d', 'error_log=' . $tmp . '/php.log', '-r',
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
