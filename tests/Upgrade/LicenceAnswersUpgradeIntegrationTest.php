<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Tables\SecretTables;
use Prosper202\License\CliAccessCache;
use Prosper202\License\MysqlLicenceAnswerStore;
use Prosper202\License\ShellAccessCache;

/**
 * The 1.9.78 -> 1.9.79 rung creates 202_licence_answers as the installer
 * makes it, and copies into it the licence answers the install cached on
 * disk within the grace period (ShellAccessCache::seedAnswers()), each at the
 * time it was cached: an install upgraded in the middle of a session, whose
 * next sign-in meets an outage, keeps the answer it had.
 *
 * The rung runs as the upgrade page runs it (fixtures/ladder-runner.php), in
 * a process of this user with this temp directory, so it reads the cache
 * entries written here.
 *
 * @group integration
 */
final class LicenceAnswersUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    private string $key = '';

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        self::q('DELETE FROM 202_version');
        foreach (self::$versions ?? [] as $version) {
            self::row('202_version', ['version' => $version]);
        }
        self::$db->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        self::requireScratch();
        $this->key = 'grace-upgrade-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if ($this->key !== '') {
            ShellAccessCache::invalidate($this->key);
            CliAccessCache::invalidate($this->key);
        }
    }

    public function testTheRungCopiesTheCachedAnswersIntoTheRecord(): void
    {
        $fresh = self::shape();
        // A 1.9.78 install: no record, and a session's answers on disk.
        self::q('DROP TABLE 202_licence_answers');
        ShellAccessCache::write($this->key, true);
        $validAt = $this->age(ShellAccessCache::class, 86400);
        CliAccessCache::write($this->key, true);
        $paidAt = $this->age(CliAccessCache::class, 2 * 86400);
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => '1.9.78']);

        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');
        self::assertSame($fresh, self::shape(), 'the table is the installed one');
        $store = new MysqlLicenceAnswerStore(self::$db);
        $hash = hash('sha256', $this->key);
        self::assertSame([true, $validAt], $store->lastAnswer($hash, ShellAccessCache::QUESTION_VALID));
        self::assertSame([true, $paidAt], $store->lastAnswer($hash, ShellAccessCache::QUESTION_PAID));

        // The caches gone, as after a restart: the record carries the key.
        ShellAccessCache::invalidate($this->key);
        CliAccessCache::invalidate($this->key);
        self::assertTrue(ShellAccessCache::validWithinGrace($store, $this->key));
        self::assertTrue(CliAccessCache::validWithinGrace($store, $this->key));
        self::q("DELETE FROM 202_licence_answers WHERE key_sha256 = '$hash'");
    }

    /** @param class-string<ShellAccessCache> $cache */
    private function age(string $cache, int $age): int
    {
        $path = (new \ReflectionMethod($cache, 'path'))->invoke(null, $this->key);
        self::assertIsString($path);
        $at = time() - $age;
        self::assertTrue(touch($path, $at));
        clearstatcache();
        return $at;
    }

    private static function shape(): string
    {
        $ddl = self::$db->query('SHOW CREATE TABLE 202_licence_answers');
        self::assertInstanceOf(\mysqli_result::class, $ddl);
        self::assertStringContainsString('`key_sha256`', SecretTables::licenceAnswers()->createStatement);
        return (string) $ddl->fetch_row()[1];
    }

    /** @return array{string, string} what the runner printed, and what was logged */
    private static function upgrade(): array
    {
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=', __DIR__ . '/fixtures/ladder-runner.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            getenv()
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(2, proc_close($proc), "the runner could not set up: $err");

        return [trim($out), $err];
    }
}
