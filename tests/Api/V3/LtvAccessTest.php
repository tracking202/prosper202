<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Support\LtvAccess;
use PHPUnit\Framework\TestCase;
use Prosper202\User\PreferenceRules;

/**
 * The LTV › Settings values that PUT /users/{id}/preferences writes only as
 * settings_write allows are every user_ltv_ column it writes, and the same
 * columns LTV › Settings itself writes (tracking202/ajax/ltv_settings.php).
 */
final class LtvAccessTest extends TestCase
{
    public function testThePreferencesAreEveryLtvColumnTheEndpointWrites(): void
    {
        $written = array_values(array_filter(
            PreferenceRules::columns(),
            static fn (string $column): bool => str_starts_with($column, 'user_ltv_')
        ));
        sort($written);
        $listed = LtvAccess::PREFERENCES;
        sort($listed);
        self::assertSame($written, $listed);
    }

    public function testThePreferencesAreTheColumnsLtvSettingsWrites(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 3) . '/tracking202/ajax/ltv_settings.php');
        $writes = preg_match('/UPDATE\s+202_users_pref\s+SET\s+(.*?)\s+WHERE/s', $page, $found);
        self::assertSame(1, $writes, 'ltv_settings.php writes its values with one UPDATE of 202_users_pref');
        preg_match_all('/(\w+)\s*=\s*\?/', $found[1], $columns);
        $set = $columns[1];
        sort($set);
        $listed = LtvAccess::PREFERENCES;
        sort($listed);
        self::assertSame($set, $listed);
    }

    public function testEveryPartNamesAnLtvScopeAndAPermission(): void
    {
        foreach (LtvAccess::PARTS as $part => $need) {
            self::assertContains($need['scope'], ['ltv:read', 'ltv:write'], $part);
            self::assertNotSame([], $need['permissions'], $part);
        }
    }
}
