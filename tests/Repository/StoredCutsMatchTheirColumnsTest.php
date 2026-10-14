<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use Prosper202\Repository\Mysql\MysqlDeviceRepository;
use Prosper202\Repository\Mysql\MysqlLocationRepository;
use Prosper202\Repository\Mysql\MysqlTrackingRepository;

/**
 * Every cut a repository makes before a lookup is its column's width, in the
 * column's unit: a VARCHAR counts characters, a TEXT bytes. Cut shorter, two
 * values the column tells apart were one row; cut longer, the server cut the
 * stored value and the lookup never found it again. The c1-c4, UTM and
 * variable values were cut at 350 bytes for columns that hold 350
 * characters, so 200 emoji kept 87.
 */
final class StoredCutsMatchTheirColumnsTest extends TestCase
{
    private const REPOSITORIES = [
        '202-config/Repository/Mysql/MysqlTrackingRepository.php',
        '202-config/Repository/Mysql/MysqlLocationRepository.php',
        '202-config/Repository/Mysql/MysqlDeviceRepository.php',
    ];

    /** cut constant => [unit, table.column it stores] */
    private const CUTS = [
        MysqlTrackingRepository::class . '::VALUE_CHARS' => ['chars', [
            '202_tracking_c1.c1', '202_tracking_c2.c2', '202_tracking_c3.c3', '202_tracking_c4.c4',
            '202_utm_source.utm_source', '202_utm_medium.utm_medium', '202_utm_campaign.utm_campaign',
            '202_utm_term.utm_term', '202_utm_content.utm_content', '202_custom_variables.variable',
        ]],
        MysqlTrackingRepository::class . '::KEYWORD_CHARS' => ['chars', ['202_keywords.keyword']],
        MysqlLocationRepository::class . '::COUNTRY_CODE_CHARS' => ['chars', ['202_locations_country.country_code']],
        MysqlLocationRepository::class . '::NAME_CHARS' => ['chars', [
            '202_locations_country.country_name', '202_locations_region.region_name',
            '202_locations_city.city_name', '202_locations_isp.isp_name',
        ]],
        MysqlLocationRepository::class . '::DOMAIN_HOST_CHARS' => ['chars', ['202_site_domains.site_domain_host']],
        MysqlLocationRepository::class . '::SITE_URL_BYTES' => ['bytes', ['202_site_urls.site_url_address']],
        MysqlDeviceRepository::class . '::NAME_CHARS' => ['chars', [
            '202_browsers.browser_name', '202_platforms.platform_name', '202_device_models.device_name',
        ]],
    ];

    public function testEachCutIsItsColumnsWidthInItsColumnsUnit(): void
    {
        $columns = self::columns();
        foreach (self::CUTS as $cut => [$unit, $stores]) {
            self::assertTrue(defined($cut), "$cut is gone: list the cut that stores these columns by its name");
            $width = constant($cut);
            foreach ($stores as $column) {
                self::assertArrayHasKey($column, $columns, "$cut names $column, which no table definition has");
                self::assertSame([$unit, $width], self::widthOf($columns[$column]), "$cut against $column");
            }
        }
    }

    /** The constant's name says its unit, and the cut that reads it is that unit's. */
    public function testEachCutIsMadeInTheUnitItsNameSays(): void
    {
        $root = dirname(__DIR__, 2);
        $seen = 0;
        foreach (self::REPOSITORIES as $file) {
            $source = (string) file_get_contents($root . '/' . $file);
            preg_match_all('/StoredText::(\w+)\([^;]*?self::\w+_(CHARS|BYTES)\b/', $source, $calls, PREG_SET_ORDER);
            foreach ($calls as [$call, $method, $unit]) {
                $seen++;
                self::assertSame(strtolower($unit), $method, "$file: $call");
            }
            self::assertSame(
                substr_count($source, 'StoredText::'),
                count($calls),
                "$file: a StoredText cut whose width is not a *_CHARS or *_BYTES constant"
            );
        }
        self::assertGreaterThanOrEqual(7, $seen);
    }

    /** @return array{string, int} the unit and width a column's type stores */
    private static function widthOf(string $columnSql): array
    {
        if (preg_match('/^`[^`]+`\s+(?:var)?char\((\d+)\)/i', $columnSql, $m) === 1) {
            return ['chars', (int) $m[1]];
        }
        $texts = ['tinytext' => 255, 'text' => 65535, 'mediumtext' => 16777215];
        if (preg_match('/^`[^`]+`\s+(tinytext|text|mediumtext)\b/i', $columnSql, $m) === 1) {
            return ['bytes', $texts[strtolower($m[1])]];
        }

        return ['unread', 0];
    }

    /** @return array<string, string> table.column => the column's SQL */
    private static function columns(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];
        foreach (glob($root . '/202-config/Database/Tables/*Tables.php') ?: [] as $file) {
            $class = 'Prosper202\\Database\\Tables\\' . basename($file, '.php');
            foreach ($class::getDefinitions() as $definition) {
                foreach (preg_split('/\R/', $definition->createStatement) ?: [] as $line) {
                    if (preg_match('/^\s*(`([^`]+)`\s+.*?),?\s*$/', $line, $c) === 1) {
                        $out[$definition->tableName . '.' . $c[2]] = $c[1];
                    }
                }
            }
        }

        return $out;
    }
}
