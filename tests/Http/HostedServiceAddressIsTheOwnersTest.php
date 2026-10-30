<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * The address the hosted service calls back with the install hash is the
 * install owner's tracking domain, whoever is signed in.
 *
 * p202TrackingBaseUrl() read the signed-in user's domain, and every user
 * sets one on Personal settings while every user row carries the install
 * hash: user 2 saved `attacker.example`, and the hosted service was told to
 * call https://attacker.example/202-cronjobs/... with the hash that
 * authenticates dni.php (measured on a live instance, before the fix:
 * `http://attacker.example/` for user 2).
 *
 * Executed from source: the function as written, with the domain read
 * stubbed per account and user 2 signed in.
 */
final class HostedServiceAddressIsTheOwnersTest extends TestCase
{
    public function testTheBaseIsUserOnesDomainWhoeverIsSignedIn(): void
    {
        [$out, $err] = self::runBaseUrl(true, false);

        self::assertStringStartsWith('http://owner.example/', $out, "signed in as user 2: $out $err");
    }

    /**
     * Before the installer has made an account there is no owner and nothing
     * stored: the installer's key step, which runs before any table exists,
     * names the server's own name. Its read of the owner's domain threw
     * there, a 500 on every fresh install. With an account, a failed read
     * still throws.
     */
    public function testBeforeAnyAccountTheBaseIsTheServersOwnName(): void
    {
        [$out, $err] = self::runBaseUrl(false, true);
        self::assertSame('http://internal/', $out, "not installed, no tables: $err");

        [$out, $err] = self::runBaseUrl(true, true);
        self::assertStringStartsWith('THREW: Unable to read the tracking domain of account 1', $out, $err);
    }

    /**
     * p202TrackingBaseUrl() as written, with is_installed() answering
     * $installed and the domain read stubbed per account, or throwing as a
     * read of a table that is not there does.
     *
     * @return array{string, string} stdout, stderr
     */
    private static function runBaseUrl(bool $installed, bool $readFails): array
    {
        $source = (string) file_get_contents(SourceScan::repoRoot() . '/202-config/functions-tracking202.php');
        $found = preg_match('/^function p202TrackingBaseUrl\(\): string\n\{\n.*?^\}\n/ms', $source, $m);
        self::assertSame(1, $found, 'p202TrackingBaseUrl() in functions-tracking202.php');

        $autoload = SourceScan::repoRoot() . '/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . ';'
            . 'const INSTALLED = ' . var_export($installed, true) . ';'
            . 'const READ_FAILS = ' . var_export($readFails, true) . ';'
            . <<<'PHP'
            function is_installed(): bool {
                return INSTALLED;
            }
            function p202StoredTrackingDomain(?int $userId = null): string {
                if (READ_FAILS) {
                    throw new RuntimeException("Unable to read the tracking domain of account 1: no 202_users_pref");
                }
                $userId ??= (int) ($_SESSION['user_id'] ?? 1);
                return [1 => 'owner.example', 2 => 'attacker.example'][$userId] ?? '';
            }
            $_SESSION = ['user_id' => 2];
            $_SERVER = ['HTTP_HOST' => 'request.example', 'SERVER_NAME' => 'internal', 'SERVER_PORT' => '80',
                'SCRIPT_NAME' => '/202-account/account.php', 'REQUEST_URI' => '/202-account/account.php'];
            PHP . $m[0]
            . 'try { echo p202TrackingBaseUrl(); } catch (RuntimeException $e) { echo "THREW: ", $e->getMessage(); }';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($process);

        return [$out, $err];
    }
}
