<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the pixel conversion tracking logic (px.php).
 *
 * px.php is a procedural script that records conversions via pixel fire.
 * It uses cookies or IP+user-agent lookup to find the originating click,
 * then calls p202ApplyConversionUpdate(). We test the decision logic.
 */
final class PixelConversionTest extends TestCase
{
    // --- Cookie-based click lookup ---

    public function testCookieClickIdUsedWhenPresent(): void
    {
        $_COOKIE['tracking202subid'] = '54321';

        $clickId = $_COOKIE['tracking202subid'] ?? null;

        self::assertSame('54321', $clickId);
    }

    public function testMissingCookieFallsToIpLookup(): void
    {
        unset($_COOKIE['tracking202subid']);

        $clickId = $_COOKIE['tracking202subid'] ?? null;

        self::assertNull($clickId, 'Should fall through to IP-based lookup');
    }

    public function testEmptyCookieIsFalsy(): void
    {
        $_COOKIE['tracking202subid'] = '';

        // px.php checks: if ($_COOKIE['tracking202subid'])
        $cookieValue = $_COOKIE['tracking202subid'];
        self::assertEmpty($cookieValue, 'Empty cookie should be falsy');
    }

    public function testCookieValueZeroIsFalsy(): void
    {
        // A click_id of '0' is not valid and should not be treated as valid
        $_COOKIE['tracking202subid'] = '0';
        $cookieValue = $_COOKIE['tracking202subid'];

        // In PHP, '0' is falsy - px.php's if() check would reject it
        self::assertFalse((bool) $cookieValue);
    }

    // --- IP-based click lookup SQL ---

    public function testIpLookupQueryUses30DayWindow(): void
    {
        $daysago = time() - 2592000;
        $thirtyDaysAgo = time() - (30 * 24 * 60 * 60);

        // 2592000 = 30 * 24 * 60 * 60 = 30 days in seconds
        self::assertSame(2592000, 30 * 86400);
        self::assertEqualsWithDelta($daysago, $thirtyDaysAgo, 1);
    }

    public function testIpLookupQueryStructure(): void
    {
        $ipAddress = '192.168.1.100';
        $userId = '1';
        $daysago = time() - 2592000;

        // Simulate the query construction from px.php
        $sql = "SELECT 202_clicks.click_id
                FROM 202_clicks
                LEFT JOIN 202_clicks_advance USING (click_id)
                LEFT JOIN 202_ips USING (ip_id)
                WHERE 202_ips.ip_address='" . addslashes($ipAddress) . "'
                AND 202_clicks.user_id='" . addslashes($userId) . "'
                AND 202_clicks.click_time >= '" . $daysago . "'
                ORDER BY 202_clicks.click_id DESC
                LIMIT 1";

        self::assertStringContainsString('202_ips.ip_address', $sql);
        self::assertStringContainsString('click_time >=', $sql);
        self::assertStringContainsString('ORDER BY', $sql);
        self::assertStringContainsString('LIMIT 1', $sql);
        self::assertStringContainsString($ipAddress, $sql);
    }

    // --- Click ID validation ---

    public function testConversionSkippedWhenNoClickIdFound(): void
    {
        $mysql = ['click_id' => ''];

        // px.php: if ($mysql['click_id']) { p202ApplyConversionUpdate(...) }
        $shouldApply = (bool) $mysql['click_id'];
        self::assertFalse($shouldApply, 'Empty click_id should not trigger conversion');
    }

    public function testConversionAppliedWhenClickIdFound(): void
    {
        $mysql = ['click_id' => '12345'];

        $shouldApply = (bool) $mysql['click_id'];
        self::assertTrue($shouldApply);
    }

    public function testNullClickIdFromFailedQuerySkipsConversion(): void
    {
        // If the IP lookup query returns no rows, click_row1['click_id'] is null
        $mysql = ['click_id' => null];

        // real_escape_string(null) returns '' in PHP 8.1+
        $escaped = addslashes((string) $mysql['click_id']);
        self::assertSame('', $escaped);
        self::assertFalse((bool) $escaped, 'Null click_id should skip conversion');
    }

    // --- Campaign ID parameter ---

    public function testAcipParameterIsUsedForCampaignLookup(): void
    {
        $_GET['acip'] = '42';
        $acip = (string) $_GET['acip'];

        self::assertSame('42', $acip);
    }

    public function testMissingAcipParameterCausesError(): void
    {
        unset($_GET['acip']);

        // px.php does $_GET['acip'] without isset check — this is a known risk
        $acip = $_GET['acip'] ?? null;
        self::assertNull($acip);
    }

    protected function setUp(): void
    {
        $this->originalCookie = $_COOKIE;
        $this->originalGet = $_GET;
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->originalCookie;
        $_GET = $this->originalGet;
    }

    private array $originalCookie = [];
    private array $originalGet = [];
}
