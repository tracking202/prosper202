<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Tables\SecretTables;

/**
 * The 1.9.78 -> 1.9.79 rung gives an install that has none its install id,
 * which names the API state directory (Prosper202\Database\InstallId), and
 * logs nothing doing it; run again, it keeps the id it gave. The Android
 * install-token key beside it is not touched.
 *
 * The rung runs as the upgrade page runs it (UPGRADE::upgrade_databases(),
 * fixtures/ladder-runner.php) from a stored 1.9.78 whose
 * 202_deployment_secrets holds the key and no id, as a 1.9.78 install's does.
 *
 * @group integration
 */
final class InstallIdUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const TABLE = '202_deployment_secrets';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    /** @var list<array<string, string>>|null 202_deployment_secrets as this class found it */
    private static ?array $secrets = null;

    private static bool $backfillWasEmpty = false;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::$versions = array_column(self::all('SELECT version FROM 202_version'), 'version');
        self::$secrets = self::all('SELECT secret_name, secret_value, created_at FROM ' . self::TABLE);
        self::$backfillWasEmpty = self::all('SELECT 1 FROM 202_reclick_backfill') === [];
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
        self::q('DELETE FROM ' . self::TABLE);
        foreach (self::$secrets ?? [] as $secret) {
            self::row(self::TABLE, $secret);
        }
        if (self::$backfillWasEmpty) {
            self::q('TRUNCATE TABLE 202_reclick_backfill');
        }
        self::$db->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::q(SecretTables::deploymentSecrets()->createStatement);
        // A 1.9.78 install: the Android key, minted since 1.9.76, and no id.
        self::q('DELETE FROM ' . self::TABLE);
        self::row(self::TABLE, [
            'secret_name' => 'android_install_token',
            'secret_value' => str_repeat('ab', 32),
            'created_at' => 1,
        ]);
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => '1.9.78']);
    }

    /** @return list<array<string, string>> */
    private static function all(string $sql): array
    {
        $result = self::$db->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error . ' in ' . $sql);

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /** @return array{0: string, 1: string} what the runner printed, and logged */
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

    /** @return array<string, string> secret name => value, by name */
    private static function secrets(): array
    {
        $rows = self::all('SELECT secret_name, secret_value FROM ' . self::TABLE);
        $secrets = array_column($rows, 'secret_value', 'secret_name');
        ksort($secrets);

        return $secrets;
    }

    public function testTheRungGivesTheInstallAnIdAndKeepsIt(): void
    {
        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');
        self::assertSame([['version' => '1.9.79']], self::all('SELECT version FROM 202_version'));
        $secrets = self::secrets();
        self::assertSame(['android_install_token', 'install_id'], array_keys($secrets));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $secrets['install_id']);
        self::assertSame(str_repeat('ab', 32), $secrets['android_install_token'], 'the key is not touched');

        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => '1.9.78']);
        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertSame($secrets, self::secrets(), 'run again, the rung keeps the id it gave');
    }
}
