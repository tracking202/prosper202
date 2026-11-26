<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Auth;

/**
 * What each part of Customer LTV asks of a key: a scope, and the role
 * permissions its pages ask. The /ltv route groups in index.php ask these
 * (RolePermissionTest holds each route to its part), /capabilities answers
 * per part whether the key may use it (principal.ltv), so a client can leave
 * out what a refused role would only be refused, and the LTV › Settings
 * values on /users/{id}/preferences are changed only as settings_write allows.
 */
final class LtvAccess
{
    /**
     * read: reports, customers, companies, products, subscriptions, fields.
     * settings_read: webhooks, their deliveries, integrations (Account › API
     * Integrations' to read). crm_write: customers, companies, products.
     * incoming: revenue, events, subscriptions, next-offer impressions an
     * integration sends. settings_write: fields, webhooks, integrations, and
     * the LTV › Settings preferences.
     *
     * @var array<string, array{scope: string, permissions: list<string>}>
     */
    public const PARTS = [
        'read' => ['scope' => 'ltv:read', 'permissions' => ['access_to_campaign_data']],
        'settings_read' => [
            'scope' => 'ltv:read',
            'permissions' => ['access_to_campaign_data', 'access_to_api_integrations'],
        ],
        'crm_write' => ['scope' => 'ltv:write', 'permissions' => ['access_to_setup_section']],
        'incoming' => ['scope' => 'ltv:write', 'permissions' => ['access_to_campaign_data']],
        'settings_write' => ['scope' => 'ltv:write', 'permissions' => ['access_to_api_integrations']],
    ];

    /**
     * The 202_users_pref columns LTV › Settings writes; LtvAccessTest holds
     * the list to every user_ltv_ column the preferences endpoint writes.
     */
    public const PREFERENCES = [
        'user_ltv_customer_cparam', 'user_ltv_personalization_fields',
        'user_ltv_score_weights', 'user_ltv_rec_fatigue',
    ];

    private function __construct()
    {
    }

    /**
     * Whether this key may use each part. A failed permission lookup throws
     * (Auth::hasPermission()), never reads as no.
     *
     * @return array<string, bool>
     */
    public static function of(Auth $auth, \mysqli $db): array
    {
        $held = [];
        $answer = [];
        foreach (self::PARTS as $part => $need) {
            $may = $auth->hasScope($need['scope']);
            foreach ($need['permissions'] as $permission) {
                $held[$permission] ??= $auth->hasPermission($db, $permission);
                if (!$held[$permission]) {
                    $may = false;
                }
            }
            $answer[$part] = $may;
        }

        return $answer;
    }

    /** Refuses a key that may not use $part, as its route group does. */
    public static function require(Auth $auth, \mysqli $db, string $part): void
    {
        if (!isset(self::PARTS[$part])) {
            throw new \LogicException('No LTV part named ' . $part);
        }
        $auth->requireScope(self::PARTS[$part]['scope']);
        foreach (self::PARTS[$part]['permissions'] as $permission) {
            $auth->requirePermission($db, $permission);
        }
    }
}
