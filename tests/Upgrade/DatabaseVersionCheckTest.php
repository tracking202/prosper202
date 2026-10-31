<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;

/**
 * database_version_check() against server_info strings as servers report them.
 * mysqlnd drops MariaDB 10's "5.5.5-" prefix (seen on a real 10.1.48 under PHP
 * 8.3.35 and 8.5.2), and the old check needed it, so 10.1 passed as MySQL 8.
 */
final class DatabaseVersionCheckTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/version.php';
    }

    /**
     * @return array<string, array{string, string, string, bool}>
     */
    public static function servers(): array
    {
        return [
            'MariaDB 10.1, prefix dropped by mysqlnd' => ['10.1.48-MariaDB-1~bionic', 'MariaDB', '10.1.48', false],
            'MariaDB 10.1, with 5.5.5- prefix' => ['5.5.5-10.1.48-MariaDB', 'MariaDB', '10.1.48', false],
            'MariaDB 10.5' => ['10.5.27-MariaDB', 'MariaDB', '10.5.27', false],
            'MariaDB 10.6.0' => ['10.6.0-MariaDB', 'MariaDB', '10.6.0', true],
            'MariaDB 10.6, prefix and -log' => ['5.5.5-10.6.18-MariaDB-log', 'MariaDB', '10.6.18', true],
            'MariaDB 10.11' => ['10.11.9-MariaDB-ubu2204', 'MariaDB', '10.11.9', true],
            'MariaDB 11.4' => ['11.4.2-MariaDB', 'MariaDB', '11.4.2', true],
            'MySQL 5.7' => ['5.7.44-log', 'MySQL', '5.7.44', false],
            'MySQL 8.0' => ['8.0.46', 'MySQL', '8.0.46', true],
            'MySQL 8.4' => ['8.4.3', 'MySQL', '8.4.3', true],
            'no connection' => ['', 'MySQL', '', false],
            'unreadable' => ['unknown', 'MySQL', '', false],
        ];
    }

    /**
     * @dataProvider servers
     */
    public function testFloors(string $serverInfo, string $product, string $version, bool $supported): void
    {
        $check = database_version_check($serverInfo);
        $this->assertSame($product, $check['product']);
        $this->assertSame($version, $check['version']);
        $this->assertSame($product === 'MariaDB' ? '10.6' : '8.0', $check['floor']);
        if ($supported) {
            $this->assertNull($check['error']);
        } else {
            $this->assertSame("Prosper202 requires $product {$check['floor']}, or newer.", $check['error']);
        }
    }
}
