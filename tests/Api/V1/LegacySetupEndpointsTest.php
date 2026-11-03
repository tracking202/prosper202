<?php

declare(strict_types=1);

namespace Tests\Api\V1;

use Api\V1\LegacySetup;
use PHPUnit\Framework\TestCase;

/**
 * Each paid 1.9.56 setup read is a directory with an index.php, as
 * api/v1/reports/ is, so /api/v1/{resource}/ reaches it on Apache and on
 * the README's nginx config with no rewrite rule.
 */
final class LegacySetupEndpointsTest extends TestCase
{
    public function testEveryResourceHasItsIndexAndNamesItself(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (array_keys(LegacySetup::RESOURCES) as $resource) {
            $index = "$root/api/v1/$resource/index.php";
            self::assertFileExists($index);
            $source = (string) file_get_contents($index);
            self::assertStringContainsString("\$p202LegacySetupResource = '$resource';", $source);
        }
        self::assertSame(
            [
                'ads', 'landing_pages', 'traffic_sources', 'smart_redirectors',
                'trackers', 'campaigns', 'categories', 'version',
            ],
            array_column(array_values(LegacySetup::RESOURCES), 0)
        );
    }

    public function testValuesAreTheOldStrings(): void
    {
        self::assertSame(
            ['a' => '7', 'b' => '0.25', 'c' => '', 'd' => ['e' => 'x', 'f' => '1']],
            LegacySetup::legacyValues(['a' => 7, 'b' => 0.25, 'c' => null, 'd' => ['e' => 'x', 'f' => true]])
        );
    }

    public function testAnUnknownMethodIsRefusedBeforeTheDatabase(): void
    {
        self::assertSame(
            [405, ['campaigns' => ['msg' => 'Not allowed request method', 'error' => true, 'status' => 405]]],
            LegacySetup::answer(null, 'campaigns', 'POST', ['apikey' => 'k'])
        );
    }
}
