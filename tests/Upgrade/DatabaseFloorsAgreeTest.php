<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;

/**
 * The upgrade page refuses the database servers the installer refuses. They
 * disagreed: install.php and requirements.php asked for MariaDB 10.6 and
 * MySQL 8.0 while upgrade.php still let MariaDB 10.0.12 and MySQL 5.6 through
 * to a schema with JSON columns, which such a server rejects partway. Each
 * page's MariaDB and MySQL floor is read from the version_compare() it
 * refuses on, so a floor changed in one page and not the others fails here.
 */
final class DatabaseFloorsAgreeTest extends TestCase
{
    private const PAGES = ['202-config/install.php', '202-config/requirements.php', '202-config/upgrade.php'];

    public function testEveryPageRefusesTheSameServers(): void
    {
        $floors = [];
        foreach (self::PAGES as $page) {
            $floors[$page] = $this->floors($page);
        }
        foreach (self::PAGES as $page) {
            $this->assertSame(
                ['mariadb' => '10.6', 'mysql' => '8.0'],
                $floors[$page],
                "$page refuses below MariaDB 10.6 and MySQL 8.0"
            );
        }
    }

    /**
     * Every server-version comparison in the page, paired with the refusal it
     * sets; the floor compared and the floor the message names must agree,
     * and a comparison that is not in that shape is not read, so it fails.
     *
     * @return array{mariadb: string, mysql: string}
     */
    private function floors(string $page): array
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/' . $page);
        $this->assertIsString($src);
        $shape = '/version_compare\(\$mysqlversion,\s*\'([0-9.]+)\'\)\s*<\s*0\)\)\s*\{\s*'
            . '\$version_error\[\'mysqlversion\'\]\s*=\s*'
            . '\'Prosper202 requires (MariaDB|MySQL) ([0-9.]+), or newer\.\';/';
        $pairs = preg_match_all($shape, $src, $m, PREG_SET_ORDER);
        $this->assertSame(
            preg_match_all('/version_compare\(\$mysqlversion/', $src),
            $pairs,
            "$page: every server-version comparison refuses in the shape this test reads"
        );
        $found = ['mariadb' => [], 'mysql' => []];
        foreach ($m as [, $compared, $product, $named]) {
            $this->assertSame($compared, $named, "$page names the floor it compares ($product)");
            $found[strtolower($product)][] = $compared;
        }
        $this->assertNotEmpty($found['mariadb'], "$page checks MariaDB");
        $this->assertNotEmpty($found['mysql'], "$page checks MySQL");
        $this->assertCount(1, array_unique($found['mariadb']), "$page has one MariaDB floor");
        $this->assertCount(1, array_unique($found['mysql']), "$page has one MySQL floor");

        return ['mariadb' => $found['mariadb'][0], 'mysql' => $found['mysql'][0]];
    }
}
