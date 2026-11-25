<?php

declare(strict_types=1);

namespace Tests\Conversion\PostbackAuth;

use PHPUnit\Framework\TestCase;
use Prosper202\InboundLog\Entry;
use Prosper202\InboundLog\InboundLog;

/**
 * Home's postback-token warning on a real server: shown while the account's
 * stored mode is off, with the readiness counts; nothing for token, signed,
 * no row (an account made from 1.9.80 on requires the token) or a mode that
 * cannot be read; and the warning without counts when only readiness fails.
 *
 * @group integration
 */
final class PostbackAuthHomeNoticeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const A = 990811;
    private const BASE = 'https://t.example/';

    private string $log = '';
    private string $previousLog = '';

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        require_once dirname(__DIR__, 3) . '/202-config/functions-postback-auth-ui.php';
        self::cleanUp();
        $log = tempnam(sys_get_temp_dir(), 'p202-6mnt-');
        self::assertIsString($log);
        $this->log = $log;
        $this->previousLog = (string) ini_get('error_log');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousLog);
        if ($this->log !== '') {
            @unlink($this->log);
        }
    }

    private static function cleanUp(): void
    {
        foreach (['202_postback_auth', '202_inbound_log'] as $table) {
            self::$db->query("DELETE FROM $table WHERE user_id = " . self::A);
        }
    }

    private static function mode(string $mode): void
    {
        self::q('INSERT INTO 202_postback_auth SET user_id = ' . self::A . ', auth_mode = ' . self::str($mode)
            . ', updated_at = 0 ON DUPLICATE KEY UPDATE auth_mode = VALUES(auth_mode)');
    }

    private static function arrived(string $auth, int $at): void
    {
        $entry = new Entry('gpb', 'GET', 200, 'unknown_click', 'x', self::A, null, null, null, [], $auth);
        self::assertTrue((new InboundLog(self::$db))->append($entry, $at));
    }

    private function notice(): string
    {
        return p202_postback_auth_home_notice(self::$db, self::A, true, self::BASE);
    }

    public function testOffShowsTheWarningWithTheLastWeeksCounts(): void
    {
        self::mode('off');
        $none = $this->notice();
        self::assertStringContainsString('data-postback-ready="unknown"', $none, 'nothing arrived: warned, not "safe"');

        self::arrived('token', time() - 3600);
        $safe = $this->notice();
        self::assertStringContainsString('data-postback-ready="yes"', $safe);
        self::assertStringContainsString('safe to require', $safe);

        self::arrived('none', time() - 1800);
        self::arrived('invalid', time() - 900);
        $not = $this->notice();
        self::assertStringContainsString('data-postback-ready="no"', $not);
        self::assertStringContainsString('2 of the 3 that arrived in the last 7 days came without it', $not);
        self::assertStringContainsString('202-account/api-integrations.php#postback-auth', $not);
        self::assertSame('', (string) file_get_contents($this->log), 'nothing failed, nothing logged');
    }

    public function testAnythingButOffShowsNothing(): void
    {
        self::arrived('none', time() - 60);
        self::assertSame('', $this->notice(), 'no row: the account requires its token');
        foreach (['token', 'signed'] as $mode) {
            self::mode($mode);
            self::assertSame('', $this->notice(), $mode);
        }
        self::mode('off');
        $notice = p202_postback_auth_home_notice(self::$db, self::A, false, self::BASE);
        self::assertSame('', $notice, 'a role that may not change it');
        self::assertSame('', (string) file_get_contents($this->log));
    }

    public function testAModeThatCannotBeReadShowsNothingAndIsLogged(): void
    {
        self::mode('of');
        self::assertSame('', $this->notice());
        $logged = (string) file_get_contents($this->log);
        self::assertStringContainsString('Home: the postback authentication mode could not be read', $logged);
    }

    public function testOffWithReadinessUnreadableStillWarnsWithoutCounts(): void
    {
        self::mode('off');
        self::arrived('none', time() - 60);
        self::q('RENAME TABLE 202_inbound_log TO 202_inbound_log_6mnt_away');
        try {
            $html = $this->notice();
        } finally {
            self::q('RENAME TABLE 202_inbound_log_6mnt_away TO 202_inbound_log');
        }
        self::assertStringContainsString('accepted without your postback token', $html);
        self::assertStringContainsString('data-postback-ready="unknown"', $html);
        self::assertStringContainsString('could not be counted just now', $html);
        $logged = (string) file_get_contents($this->log);
        self::assertStringContainsString('Home: postback readiness could not be read', $logged);
    }
}
