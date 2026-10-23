<?php

declare(strict_types=1);

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;

/**
 * functions-auth.php is included by legacy entry points before the Composer
 * autoloader exists, so every Prosper202\License class it names is
 * required at its top, not left to the autoloader.
 */
final class AuthFileRequiresItsLicenceClassesTest extends TestCase
{
    public function testEveryLicenceClassItNamesIsRequired(): void
    {
        $dir = dirname(__DIR__, 2) . '/202-config';
        $src = (string)file_get_contents($dir . '/functions-auth.php');
        preg_match_all('/\\\\?Prosper202\\\\License\\\\([A-Za-z0-9_]+)/', $src, $m);
        $named = array_values(array_unique($m[1]));
        self::assertNotSame([], $named);
        foreach ($named as $class) {
            self::assertFileExists($dir . '/License/' . $class . '.php');
            self::assertStringContainsString("require_once __DIR__ . '/License/" . $class . ".php';", $src, $class);
        }
    }
}
