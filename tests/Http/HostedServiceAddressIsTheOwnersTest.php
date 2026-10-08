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
 * hash: user 2 saved `attacker.example`, and registerDailyEmail() told the
 * hosted service to call https://attacker.example/202-cronjobs/... with the
 * hash that authenticates daily-email.php and dni.php (measured on a live
 * instance, before the fix: `http://attacker.example/` for user 2).
 *
 * Executed from source: the function as written, with the domain read
 * stubbed per account and user 2 signed in.
 */
final class HostedServiceAddressIsTheOwnersTest extends TestCase
{
    public function testTheBaseIsUserOnesDomainWhoeverIsSignedIn(): void
    {
        $source = (string) file_get_contents(SourceScan::repoRoot() . '/202-config/functions-tracking202.php');
        $found = preg_match('/^function p202TrackingBaseUrl\(\): string\n\{\n.*?^\}\n/ms', $source, $m);
        self::assertSame(1, $found, 'p202TrackingBaseUrl() in functions-tracking202.php');

        $autoload = SourceScan::repoRoot() . '/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . ';' . <<<'PHP'
            function p202StoredTrackingDomain(?int $userId = null): string {
                $userId ??= (int) ($_SESSION['user_id'] ?? 1);
                return [1 => 'owner.example', 2 => 'attacker.example'][$userId] ?? '';
            }
            $_SESSION = ['user_id' => 2];
            $_SERVER = ['HTTP_HOST' => 'request.example', 'SERVER_NAME' => 'internal', 'SERVER_PORT' => '80',
                'SCRIPT_NAME' => '/202-account/account.php', 'REQUEST_URI' => '/202-account/account.php'];
            PHP . $m[0] . 'echo p202TrackingBaseUrl();';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($process);

        self::assertStringStartsWith('http://owner.example/', $out, "signed in as user 2: $out $err");
    }

    /** Only the owner's save registers the owner's daily email. */
    public function testOnlyTheOwnersProfileSaveRegistersTheDailyEmail(): void
    {
        $page = (string) file_get_contents(SourceScan::repoRoot() . '/202-account/account.php');
        self::assertSame(1, substr_count($page, 'p202_account_register_daily_email('), 'one registration in account.php');
        self::assertMatchesRegularExpression(
            '/if \(\(int\) \$_SESSION\[\'user_id\'\] === 1\) \{\s*p202_account_register_daily_email\(/',
            $page,
            'the registration runs only for user 1'
        );
    }
}
