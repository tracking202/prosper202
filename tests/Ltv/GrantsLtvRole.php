<?php

declare(strict_types=1);

namespace Tests\Ltv;

/**
 * Gives a scratch-database user a role holding what the LTV pages ask for
 * (the scratch schema seeds no roles). Use with ScratchReportDatabase.
 */
trait GrantsLtvRole
{
    /** @var list<int> permissions this class added, removed again by revokeLtvRole() */
    private static array $ltvAddedPermissions = [];

    private static function grantLtvRole(int $user, int $role): void
    {
        self::revokeLtvRole($user, $role);
        self::row('202_roles', ['role_id' => $role, 'role_name' => 'ltv test ' . $role]);
        foreach (['access_to_campaign_data', 'access_to_setup_section', 'access_to_api_integrations'] as $name) {
            $found = self::$db->query("SELECT permission_id FROM 202_permissions WHERE permission_description = '$name'");
            $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
            if ($permission === null) {
                $permission = self::insert("INSERT INTO 202_permissions SET permission_description = '$name'");
                self::$ltvAddedPermissions[] = $permission;
            }
            self::row('202_role_permission', ['role_id' => $role, 'permission_id' => (int) $permission]);
        }
        self::row('202_user_role', ['user_id' => $user, 'role_id' => $role]);
    }

    private static function revokeLtvRole(int $user, int $role): void
    {
        self::q("DELETE FROM 202_user_role WHERE user_id = $user OR role_id = $role");
        self::q("DELETE FROM 202_role_permission WHERE role_id = $role");
        self::q("DELETE FROM 202_roles WHERE role_id = $role");
        foreach (self::$ltvAddedPermissions as $permission) {
            self::q("DELETE FROM 202_permissions WHERE permission_id = $permission");
        }
        self::$ltvAddedPermissions = [];
    }
}
