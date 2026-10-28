<?php

declare(strict_types=1);

namespace Prosper202\User;

use Api\V3\Support\PayloadKeys;

/**
 * A 202_users_pref row as it leaves the server: the columns on SERVED as
 * stored, and every other column as whether it holds a value,
 * `{"stored": true}`, never the value.
 *
 * GET /users/{id}/preferences answered `SELECT *`, the account's
 * integration secrets included, while the docs said they were never served.
 * Served is an allow-list, so a column added to the table without a
 * decision is answered as a secret; ServedPreferencesTest fails until each
 * column of UserTables::usersPref() is on SERVED or SECRET.
 */
final class ServedPreferences
{
    /**
     * Columns that hold no credential, served as stored (`lpo_status` and
     * `lpo_ctx_kw` are the Landing Page Optimizer's on/off state).
     */
    public const SERVED = [
        'user_id', 'user_pref_limit', 'user_pref_show', 'user_pref_time_from',
        'user_pref_time_to', 'user_pref_time_predefined', 'user_pref_adv',
        'user_pref_ppc_network_id', 'user_pref_ppc_account_id',
        'user_pref_aff_network_id', 'user_pref_aff_campaign_id',
        'user_pref_text_ad_id', 'user_pref_method_of_promotion',
        'user_pref_landing_page_id', 'user_pref_country_id',
        'user_pref_region_id', 'user_pref_device_id', 'user_pref_browser_id',
        'user_pref_platform_id', 'user_pref_isp_id', 'user_pref_subid',
        'user_pref_ip', 'user_pref_dynamic_bid', 'user_pref_referer',
        'user_pref_keyword', 'user_pref_breakdown', 'user_pref_chart',
        'user_cpc_or_cpv', 'user_keyword_searched_or_bidded',
        'user_pref_privacy', 'user_pref_referer_data', 'user_tracking_domain',
        'user_pref_group_2', 'user_pref_group_3', 'user_pref_group_4',
        'user_pref_group_1', 'cache_time', 'cb_verified', 'maxmind_isp',
        'chart_time_range', 'user_pref_cloak_referer', 'auto_cron',
        'user_daily_email', 'user_auto_database_optimization_days',
        'user_delete_data_clickid', 'user_account_currency',
        'facebook_ads_linked', 'user_pref_ad_settings',
        'user_ltv_customer_cparam', 'user_ltv_personalization_fields',
        'user_ltv_score_weights', 'user_ltv_rec_fatigue', 'lpo_status',
        'lpo_ctx_kw', 'user_delete_data_before', 'user_pref_same_ip_fallback',
    ];

    /**
     * Columns answered as their stored state. Anything not on SERVED is
     * answered so whether or not it is listed here; the list is the decision
     * the test asks for. (`cb_verified` is ClickBank's flag, not the key;
     * `lpo_bridge_config` is JSON whose `ctx_key` is a signing key.)
     */
    public const SECRET = [
        'cb_key', 'jvzoo_ipn_secret_key', 'zaxaa_api_signature', 'ipqs_api_key',
        'revcontent_user_id', 'revcontent_user_secret',
        'user_slack_incoming_webhook', 'lpo_site_key', 'lpo_bridge_config',
    ];

    private function __construct()
    {
    }

    /** @return list<string> every column the lists classify */
    public static function columns(): array
    {
        return [...self::SERVED, ...self::SECRET];
    }

    /**
     * @param array<array-key, mixed> $row the row as stored
     * @return array<string, mixed>
     */
    public static function serve(array $row): array
    {
        $served = [];
        foreach ($row as $column => $value) {
            $column = (string) $column;
            $served[$column] = in_array($column, self::SERVED, true) ? $value : self::storedState($value);
        }

        return $served;
    }

    /** @return array{stored: bool} */
    public static function storedState(mixed $value): array
    {
        return ['stored' => $value !== null && trim(is_scalar($value) ? (string) $value : '') !== ''];
    }

    /**
     * The body less what GET answered and came back unchanged, and the
     * refusals of what came back changed. A key the endpoint does not write
     * is read-only: dropped when it holds what GET served, refused
     * otherwise. A secret the endpoint writes is written from a string; its
     * stored state sent back as GET gave it keeps the secret, and any other
     * object is refused, so `{"stored": true}` can neither clear a secret
     * nor be stored as one.
     *
     * @param array<array-key, mixed> $payload
     * @param array<string, mixed> $served the record as serve() answers it
     * @return array{0: array<string, mixed>, 1: array<string, string>} [the rest, errors]
     */
    public static function withoutEchoes(array $payload, array $served): array
    {
        $writable = PreferenceRules::columns();
        $rest = [];
        $errors = [];
        foreach ($payload as $key => $value) {
            $key = (string) $key;
            $writes = in_array($key, $writable, true);
            if ($writes && (in_array($key, self::SERVED, true) || !is_array($value))) {
                $rest[$key] = $value;
                continue;
            }
            if (PayloadKeys::same($value, $served[$key] ?? null)) {
                continue;
            }
            $errors[$key] = $writes
                ? 'is a secret, which GET answers only as {"stored": true} or {"stored": false}: send that back '
                    . 'unchanged to keep it, the new secret as a string to set it, or "" to remove it'
                : 'is read-only: send it only with the value the record holds (as GET returns it), or omit it';
        }

        return [$rest, $errors];
    }
}
