<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;

/**
 * The installer, the requirements page and the upgrade page refuse the same
 * database servers. Each kept its own copy of the check, and they drifted:
 * upgrade.php once let MariaDB 10.0.12 and MySQL 5.6 through, and all three
 * missed MariaDB once mysqlnd dropped the "5.5.5-" prefix. They now share
 * database_version_check() (version.php, tested in DatabaseVersionCheckTest),
 * so a page that compares the server version itself fails here.
 */
final class DatabaseFloorsAgreeTest extends TestCase
{
    private const PAGES = ['202-config/install.php', '202-config/requirements.php', '202-config/upgrade.php'];

    public function testEveryPageUsesTheSharedCheck(): void
    {
        foreach (self::PAGES as $page) {
            $src = file_get_contents(dirname(__DIR__, 2) . '/' . $page);
            $this->assertIsString($src);
            $this->assertSame(1, preg_match_all('/=\s*database_version_check\(/', $src), "$page calls database_version_check() once");
            $this->assertMatchesRegularExpression(
                '/\$version_error\[\'mysqlversion\'\]\s*=\s*\$dbCheck\[\'error\'\];/',
                $src,
                "$page refuses with the shared check's error"
            );
            $this->assertDoesNotMatchRegularExpression('/version_compare\(\$mysqlversion/', $src, "$page compares no server version itself");
            $this->assertDoesNotMatchRegularExpression('/-MariaDB/i', $src, "$page parses no server string itself");
        }
    }
}
