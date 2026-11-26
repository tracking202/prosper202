<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Auth;
use Api\V3\Controllers\MeController;
use PHPUnit\Framework\TestCase;

/**
 * GET /me's flags, licence status and feature names, from a role's
 * permissions and a key's scopes. MeServedIntegrationTest asks the served
 * route for the seeded roles.
 */
final class MeFlagsTest extends TestCase
{
    /** @param list<string> $roles @param list<string> $scopes */
    private static function auth(array $roles, array $scopes = ['*']): Auth
    {
        $class = new \ReflectionClass(Auth::class);
        $auth = $class->newInstanceWithoutConstructor();
        $constructor = $class->getConstructor();
        self::assertNotNull($constructor);
        $constructor->setAccessible(true);
        $constructor->invoke($auth, 7, $roles, $scopes, '');

        return $auth;
    }

    private const CAMPAIGN_MANAGER = ['access_to_campaign_data', 'access_to_setup_section', 'access_to_update_section', 'view_attribution_reports'];

    public function testACampaignManagerMaySeeMoneyAndEditButNotDeleteOrAdminister(): void
    {
        $flags = MeController::flags(self::auth(['campaign manager']), self::CAMPAIGN_MANAGER);

        self::assertSame(array_keys(self::everyFlag()), array_keys($flags), 'the flags OpenAPI and 30-me.md list, in order');
        self::assertSame([
            'can_see_money', 'can_view_setup', 'can_edit_campaigns', 'can_use_update_section', 'can_view_attribution',
        ], array_keys(array_filter($flags)));
    }

    public function testAReadOnlyKeyLosesEveryFlagWhoseRouteWrites(): void
    {
        $flags = MeController::flags(self::auth(['campaign manager'], ['read']), self::CAMPAIGN_MANAGER);

        self::assertSame(['can_see_money', 'can_view_setup', 'can_view_attribution'], array_keys(array_filter($flags)));
    }

    public function testAnAreaScopedKeyKeepsOnlyItsArea(): void
    {
        $flags = MeController::flags(self::auth(['campaign manager'], ['campaigns:write']), self::CAMPAIGN_MANAGER);

        self::assertSame(['can_see_money', 'can_view_setup', 'can_edit_campaigns'], array_keys(array_filter($flags)));
    }

    public function testAdminFlagsNeedTheAdminRoleAsWellAsThePermission(): void
    {
        $permissions = ['access_to_settings', 'add_edit_delete_admin', 'add_users'];

        $withoutRole = MeController::flags(self::auth(['campaign viewer']), $permissions);
        self::assertSame([], array_keys(array_filter($withoutRole)), 'requireAdmin() refuses the routes');

        $withRole = MeController::flags(self::auth(['admin']), $permissions);
        self::assertSame(['is_admin', 'can_manage_admins', 'can_manage_users', 'can_view_settings'], array_keys(array_filter($withRole)));
    }

    public function testEveryFlagIsTrueForAFullKeyWithEveryPermission(): void
    {
        $all = ['access_to_api_integrations', 'access_to_campaign_data', 'access_to_personal_settings', 'access_to_settings',
            'access_to_setup_section', 'access_to_update_section', 'access_to_vip_perks', 'add_edit_delete_admin', 'add_users',
            'delete_individual_subids', 'manage_attribution_models', 'remove_campaign', 'view_attribution_reports'];

        self::assertSame(self::everyFlag(), MeController::flags(self::auth(['super user']), $all));
    }

    public function testNoPermissionsIsNoFlags(): void
    {
        self::assertSame([], array_filter(MeController::flags(self::auth(['campaign viewer']), [])));
    }

    public function testTheLicenceStatusSaysWhyItRefused(): void
    {
        self::assertSame('licensed', MeController::licenceStatus(true, false));
        self::assertSame('not_licensed', MeController::licenceStatus(false, false));
        self::assertSame('unconfirmed', MeController::licenceStatus(false, true));
    }

    public function testAFeatureSwitchedOffIsLeftOutAndEveryOtherIsNamed(): void
    {
        self::assertSame(['create_idempotency', 'app_platforms', 'goals'], MeController::featureNames([
            'api_key_scopes' => false,
            'create_idempotency' => true,
            'app_platforms' => ['ios', 'android'],
            'goals' => ['scopes' => []],
        ]));
    }

    /** @return array<string, true> */
    private static function everyFlag(): array
    {
        return array_fill_keys([
            'is_admin', 'can_manage_admins', 'can_see_money', 'can_view_setup', 'can_edit_campaigns', 'can_delete_campaigns',
            'can_use_update_section', 'can_delete_subids', 'can_view_attribution', 'can_manage_attribution',
            'can_edit_personal_settings', 'can_manage_users', 'can_view_settings', 'can_use_api_integrations', 'can_see_vip_perks',
        ], true);
    }
}
