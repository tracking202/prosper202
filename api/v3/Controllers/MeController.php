<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Auth;
use Api\V3\Exception\DatabaseException;
use Api\V3\Support\StatementHelpers;
use Prosper202\Report\AccountZone;

/**
 * GET /me: everything a client needs to decide which screens, buttons and
 * money figures to show the caller, in one read. The flags are the gates the
 * pages and the routes themselves apply (template.php, top.php, sub-menu.php
 * and api/v3/index.php), narrowed by the key's scope where a route asks for
 * one, so a flag that says yes is a route that answers.
 */
final class MeController
{
    use StatementHelpers;

    /** The values Personal Settings offers (PreferenceRules); the pages show ads for anything but hide_all. */
    private const AD_SETTINGS = ['show_all', 'hide_login', 'hide_all'];

    public function __construct(
        private readonly \mysqli $db,
        private readonly Auth $auth,
    ) {
    }

    /**
     * @param 'api_key'|'session' $method how the request authenticated
     * @param string|null $csrfToken the session's token, for a session; null for a key
     * @return array{data: array<string, mixed>}
     */
    public function me(string $method, ?string $csrfToken): array
    {
        $userId = $this->auth->userId();
        $user = $this->user($userId);
        $preferences = $this->preferences($userId);
        $permissions = $this->auth->permissions($this->db);

        $capabilitiesController = new CapabilitiesController($this->db, $userId);
        $capabilities = $capabilitiesController->capabilities()['data'];

        return [
            'data' => [
                'user' => [
                    'user_id' => $userId,
                    'user_name' => (string) $user['user_name'],
                    'user_email' => (string) $user['user_email'],
                    'user_fname' => (string) ($user['user_fname'] ?? ''),
                    'user_lname' => (string) ($user['user_lname'] ?? ''),
                ],
                'account' => [
                    'timezone' => AccountZone::normalize(isset($user['user_timezone']) ? (string) $user['user_timezone'] : null),
                    'currency' => UsersController::normalizeCurrency($preferences['user_account_currency']),
                ],
                'roles' => $this->roles($userId),
                'permissions' => $permissions,
                'flags' => self::flags($this->auth, $permissions),
                'scopes' => $this->auth->scopes(),
                'switches' => [
                    'licence' => self::licenceStatus((bool) $capabilities['cli'], $capabilitiesController->licenceWasUnconfirmed()),
                    'sponsored_ads' => $preferences['user_pref_ad_settings'],
                ],
                'install' => [
                    'version' => self::codeVersion(),
                    'schema_version' => (string) $capabilities['server']['build'],
                    'api_version' => 'v3',
                ],
                'features' => self::featureNames($capabilities['features']),
                'auth' => [
                    'method' => $method,
                    'csrf_token' => $csrfToken,
                ],
            ],
        ];
    }

    /**
     * Each flag is a gate some page or route applies. Where the route also
     * asks the key for a scope, the flag asks it too: a read-only key's
     * can_edit_campaigns is false although its user's role may edit.
     *
     * @param list<string> $permissions
     * @return array<string, bool>
     */
    public static function flags(Auth $auth, array $permissions): array
    {
        $has = static fn (string $permission): bool => in_array($permission, $permissions, true);
        $setup = $has('access_to_setup_section');
        $update = $has('access_to_update_section');
        $admin = $auth->isAdmin();

        return [
            'is_admin' => $admin,
            // Changing an Admin or the Super user, granting Admin, removing users.
            'can_manage_admins' => $admin && $has('add_edit_delete_admin') && $auth->hasScope('users:write'),
            // Clicks, leads and money in reports and records; without it they are masked.
            'can_see_money' => $has('access_to_campaign_data'),
            'can_view_setup' => $setup && $auth->hasScope('campaigns:read'),
            'can_edit_campaigns' => $setup && $auth->hasScope('campaigns:write'),
            'can_delete_campaigns' => $setup && $has('remove_campaign') && $auth->hasScope('campaigns:write'),
            'can_use_update_section' => $update && $auth->hasScope('conversions:write'),
            'can_delete_subids' => $update && $has('delete_individual_subids') && $auth->hasScope('conversions:write'),
            'can_view_attribution' => $has('view_attribution_reports') && $auth->hasScope('attribution:read'),
            'can_manage_attribution' => $has('manage_attribution_models') && $auth->hasScope('attribution:write'),
            'can_edit_personal_settings' => $has('access_to_personal_settings') && $auth->hasScope('users:write'),
            'can_manage_users' => $admin && $has('add_users') && $auth->hasScope('users:write'),
            'can_view_settings' => $admin && $has('access_to_settings') && $auth->hasScope('system:read'),
            'can_use_api_integrations' => $has('access_to_api_integrations'),
            'can_see_vip_perks' => $has('access_to_vip_perks'),
        ];
    }

    /**
     * What the licence-gated surfaces (the Go CLI, connecting an AI
     * assistant) answer now: CapabilitiesController::cliAccess(), whose
     * `cli` GET /capabilities reports. `unconfirmed` is a refusal for want of
     * an answer from Tracking202 within the grace period.
     */
    public static function licenceStatus(bool $licensed, bool $unconfirmed): string
    {
        if ($licensed) {
            return 'licensed';
        }

        return $unconfirmed ? 'unconfirmed' : 'not_licensed';
    }

    /**
     * The features GET /capabilities advertises, by name; one switched off
     * (api_key_scopes on an install without the column) is left out.
     *
     * @param array<string, mixed> $features
     * @return list<string>
     */
    public static function featureNames(array $features): array
    {
        $names = [];
        foreach ($features as $name => $value) {
            if ($value !== false) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    private static function codeVersion(): string
    {
        if (!defined('PROSPER202_VERSION')) {
            require_once dirname(__DIR__, 3) . '/202-config/version.php';
        }

        return (string) PROSPER202_VERSION;
    }

    /** @return array<string, mixed> */
    private function user(int $userId): array
    {
        $stmt = $this->prepare(
            'SELECT user_name, user_email, user_fname, user_lname, user_timezone
             FROM 202_users WHERE user_id = ? AND user_deleted = 0 LIMIT 1'
        );
        $this->bind($stmt, 'i', $userId);
        $this->execute($stmt, 'User query failed');
        $row = $this->resultOf($stmt, 'User query failed')->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            // Authentication found this user a moment ago; gone now is a
            // server-side contradiction, not an empty answer.
            throw new DatabaseException('The signed-in user could not be read');
        }

        return $row;
    }

    /**
     * The two preferences /me answers. An account with no preferences row
     * has the columns' defaults, as every page reads it; a read that fails
     * throws.
     *
     * @return array{user_account_currency: ?string, user_pref_ad_settings: string}
     */
    private function preferences(int $userId): array
    {
        $stmt = $this->prepare('SELECT user_account_currency, user_pref_ad_settings FROM 202_users_pref WHERE user_id = ? LIMIT 1');
        $this->bind($stmt, 'i', $userId);
        $this->execute($stmt, 'Preferences query failed');
        $row = $this->resultOf($stmt, 'Preferences query failed')->fetch_assoc();
        $stmt->close();

        $currency = is_array($row) && isset($row['user_account_currency']) ? (string) $row['user_account_currency'] : null;
        $ads = is_array($row) ? (string) ($row['user_pref_ad_settings'] ?? '') : 'show_all';

        return [
            'user_account_currency' => $currency,
            // A value Personal Settings would not write shows ads on every
            // page (each hides them only on its own exact choice): say so.
            'user_pref_ad_settings' => in_array($ads, self::AD_SETTINGS, true) ? $ads : 'show_all',
        ];
    }

    /** @return list<array{role_id: int, role_name: string}> */
    private function roles(int $userId): array
    {
        $stmt = $this->prepare(
            'SELECT r.role_id, r.role_name FROM 202_user_role ur
             INNER JOIN 202_roles r ON ur.role_id = r.role_id
             WHERE ur.user_id = ? ORDER BY r.role_id'
        );
        $this->bind($stmt, 'i', $userId);
        $this->execute($stmt, 'Roles query failed');
        $result = $this->resultOf($stmt, 'Roles query failed');
        $roles = [];
        while ($row = $result->fetch_assoc()) {
            $roles[] = ['role_id' => (int) $row['role_id'], 'role_name' => (string) $row['role_name']];
        }
        $stmt->close();

        return $roles;
    }
}
