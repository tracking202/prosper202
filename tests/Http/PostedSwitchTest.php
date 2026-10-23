<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\PostedSwitch;
use Tests\Support\SourceScan;

/**
 * A form switch's two values are its only answers (PostedSwitch), and
 * Settings' ISP lookup switch is read through it: its `== "true"` and
 * `== "false"` changed nothing and said nothing for any other value.
 */
final class PostedSwitchTest extends TestCase
{
    public function testOnlyTheFormsTwoValuesAreAnAnswer(): void
    {
        self::assertTrue(PostedSwitch::read('true', 'true', 'false'));
        self::assertFalse(PostedSwitch::read('false', 'true', 'false'));
        foreach (['TRUE', 'on', '1', '', ' true', 'true ', true, false, 1, 0, null, ['true']] as $value) {
            self::assertNull(PostedSwitch::read($value, 'true', 'false'), var_export($value, true));
        }
    }

    public function testSettingsReadsTheIspSwitchOnceThroughIt(): void
    {
        $page = (string) file_get_contents(SourceScan::repoRoot() . '/202-account/administration.php');
        $start = strpos($page, "if (isset(\$_POST['maxmind'])) {");
        $end = strpos($page, "if (isset(\$_POST['database_management']))");
        self::assertIsInt($start);
        self::assertIsInt($end);
        $handler = substr($page, $start, $end - $start);
        self::assertSame(2, substr_count($handler, "\$_POST['maxmind']"), 'the isset, and one strict read');
        self::assertSame(1, substr_count($handler, "PostedSwitch::read(\$_POST['maxmind'], 'true', 'false')"));
        $flat = (string) preg_replace('/\s+/', ' ', $handler);
        self::assertStringContainsString("if (\$ispOn === null) { p202_account_flash( 'bad',", $flat);
    }
}
