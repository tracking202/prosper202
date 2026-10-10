<?php

declare(strict_types=1);

namespace Prosper202\User;

use Prosper202\Click\TrackingDomain;
use Prosper202\Ltv\MysqlEngagementRepository;
use Prosper202\Ltv\MysqlPersonalizationRepository;

/**
 * Every preference `PUT /users/{id}/preferences` writes, and the rule the
 * settings page that owns it holds the value to.
 *
 * The endpoint validated one value of ten (the currency) and wrote whatever
 * arrived for the rest, and dropped a key it did not know without a word:
 * `user_daily_email: "off"` failed the strict-mode write or was stored as
 * "of", `user_pref_limit: 7` was stored and broke every report page, and a
 * misspelled key answered 200 having saved nothing (error pattern #4). Each
 * rule here is the page's own: the choices its form offers, the format its
 * handler checks, the parser its read path uses.
 *
 * Owners: Personal settings (202-account/account.php) for the profile
 * choices, the report pages (functions-report-prefs.php,
 * functions-ui-partials.php p202_report_ranges()) for the report defaults,
 * Integrations (202-account/api-integrations.php) for the network secrets,
 * and LTV › Settings (tracking202/ajax/ltv_settings.php) for the LTV values.
 */
final class PreferenceRules
{
    /** p202_report_ranges()' keys (PreferenceRulesTest holds them together). */
    public const REPORT_RANGES = ['today', 'yesterday', 'last7', 'last14', 'last30', 'thismonth', 'lastmonth', 'thisyear', 'lastyear', 'alltime'];

    /** p202_report_pref_fields()['user_pref_limit']['options']. */
    public const REPORT_ROW_LIMITS = ['10', '25', '50', '75', '100', '150', '200'];

    /** account.php's $dailyEmailChoices: '' (never) or an hour, 00-23. */
    public const DAILY_EMAIL_PATTERN = '/^(?:[01][0-9]|2[0-3])$/D';

    /**
     * Fixed choices, column => the values its form offers. The first
     * group is account.php's, then the report pages', then the chart's.
     *
     * @var array<string, list<string>>
     */
    public const CHOICES = [
        'user_keyword_searched_or_bidded' => ['searched', 'bidded'],
        'user_pref_referer_data' => ['browser', 't202ref'],
        'user_pref_dynamic_bid' => ['0', '1'],
        'user_pref_privacy' => ['disabled', 'eu', 'all'],
        'user_pref_cloak_referer' => ['origin', 'never'],
        'user_pref_ad_settings' => ['show_all', 'hide_login', 'hide_all'],
        'user_pref_limit' => self::REPORT_ROW_LIMITS,
        'user_pref_time_predefined' => self::REPORT_RANGES,
        'user_cpc_or_cpv' => ['cpc', 'cpv'],
        'chart_time_range' => ['hours', 'days'],
        'user_ltv_customer_cparam' => ['0', '1', '2', '3', '4'],
    ];

    /** Integer columns among CHOICES: bound as 'i'. */
    private const INTEGER_COLUMNS = ['user_pref_dynamic_bid', 'user_pref_limit', 'user_ltv_customer_cparam'];

    /**
     * Free-text secrets and settings, column => the column's width. '' is
     * accepted and clears the value (the pages refuse an empty box; the API
     * is the way to remove one).
     *
     * @var array<string, int>
     */
    public const TEXT = [
        'ipqs_api_key' => 250,
        'cb_key' => 250,
        'zaxaa_api_signature' => 250,
        'jvzoo_ipn_secret_key' => 250,
    ];

    /** Everything else this class knows, handled by name in check(). */
    private const SPECIAL = [
        'user_tracking_domain', 'user_account_currency', 'user_slack_incoming_webhook', 'user_daily_email',
        'user_ltv_personalization_fields', 'user_ltv_score_weights', 'user_ltv_rec_fatigue',
    ];

    private function __construct()
    {
    }

    /** @return list<string> every column the endpoint writes */
    public static function columns(): array
    {
        return [...array_keys(self::CHOICES), ...array_keys(self::TEXT), ...self::SPECIAL];
    }

    /**
     * The payload's values, each held to its rule and normalized for the
     * write, or the reasons by column. A key that is not a preference this
     * endpoint writes is an error too: dropping it would answer success for
     * a write that did not happen.
     *
     * @param array<array-key, mixed> $payload
     * @param list<string> $currencies the account currencies this install supports
     * @return array{0: array<string, int|string>, 1: array<string, string>} [clean, errors]
     */
    public static function validate(array $payload, array $currencies): array
    {
        $clean = [];
        $errors = [];
        $known = self::columns();
        foreach ($payload as $column => $value) {
            $column = (string) $column;
            if (!in_array($column, $known, true)) {
                $errors[$column] = 'Not a preference this endpoint writes; it takes: ' . implode(', ', $known);
                continue;
            }
            try {
                $clean[$column] = self::check($column, $value, $currencies);
            } catch (\InvalidArgumentException $e) {
                $errors[$column] = $e->getMessage();
            }
        }

        return [$clean, $errors];
    }

    /** Whether a column binds as an integer. */
    public static function isInteger(string $column): bool
    {
        return in_array($column, self::INTEGER_COLUMNS, true);
    }

    /**
     * @param list<string> $currencies
     * @throws \InvalidArgumentException naming what the column takes
     */
    private static function check(string $column, mixed $value, array $currencies): int|string
    {
        if (isset(self::CHOICES[$column])) {
            // A JSON number is accepted for the integer columns (50, not
            // "50"); anything else must be one of the strings exactly — no
            // cast reads "50.0" or " 50" as 50 (error pattern #18).
            $text = is_int($value) ? (string) $value : $value;
            if (!is_string($text) || !in_array($text, self::CHOICES[$column], true)) {
                throw new \InvalidArgumentException('Must be one of: ' . implode(', ', self::CHOICES[$column]));
            }
            return self::isInteger($column) ? (int) $text : $text;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Must be a string');
        }
        if (isset(self::TEXT[$column])) {
            $value = trim($value);
            if (strlen($value) > self::TEXT[$column]) {
                throw new \InvalidArgumentException('At most ' . self::TEXT[$column] . ' characters');
            }
            return $value;
        }

        switch ($column) {
            case 'user_tracking_domain':
                // account.php stores the trimmed text and the read path
                // normalizes it (TrackingDomain); a value with no host in
                // it at all would make every generated link point nowhere.
                $value = trim($value);
                if (strlen($value) > 255) {
                    throw new \InvalidArgumentException('At most 255 characters');
                }
                if ($value !== '' && TrackingDomain::normalize($value) === '') {
                    throw new \InvalidArgumentException('Must be a host name such as track.example.com (or "" to use this install\'s own domain)');
                }
                return $value;
            case 'user_account_currency':
                $code = strtoupper(trim($value));
                if (!in_array($code, $currencies, true)) {
                    throw new \InvalidArgumentException('Must be one of: ' . implode(', ', $currencies));
                }
                return $code;
            case 'user_slack_incoming_webhook':
                $value = trim($value);
                if ($value !== '' && (filter_var($value, FILTER_VALIDATE_URL) === false || stripos($value, 'https://') !== 0)) {
                    throw new \InvalidArgumentException('Must be an https:// webhook URL (or "" to stop Slack notifications)');
                }
                return $value;
            case 'user_daily_email':
                if ($value !== '' && preg_match(self::DAILY_EMAIL_PATTERN, $value) !== 1) {
                    throw new \InvalidArgumentException('Must be the hour to send it, 00-23 in your time zone, or "" for never');
                }
                return $value;
            case 'user_ltv_personalization_fields':
                return self::personalizationFields($value);
            case 'user_ltv_score_weights':
                return self::scoreWeights($value);
            case 'user_ltv_rec_fatigue':
                $value = trim($value);
                if ($value !== '' && preg_match('/^\d{1,3}(,\d{1,4})?$/D', $value) !== 1) {
                    throw new \InvalidArgumentException('Must be "" (defaults), 0 (off), or "times,days" such as 3,21');
                }
                return $value;
        }
        throw new \LogicException("PreferenceRules: no rule for $column");
    }

    /**
     * LTV › Settings' personalization list: a comma list of at most 500
     * characters, each entry a CRM field, `cf:<field_key>` or
     * `rec:next_offer`; de-duplicated. The page's own check calls this.
     */
    public static function personalizationFields(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (strlen($raw) > 500) {
            throw new \InvalidArgumentException('Personalization fields list exceeds 500 characters.');
        }
        $valid = [];
        $invalid = [];
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (MysqlPersonalizationRepository::isAllowedEntry($entry)) {
                $valid[] = $entry;
            } else {
                $invalid[] = $entry;
            }
        }
        if ($invalid !== []) {
            throw new \InvalidArgumentException(
                'Invalid personalization field(s): ' . implode(', ', $invalid)
                . '. Allowed: ' . implode(', ', MysqlPersonalizationRepository::ALLOWED_CRM_FIELDS)
                . ', cf:<field_key>, rec:next_offer.'
            );
        }

        return implode(',', array_values(array_unique($valid)));
    }

    /**
     * Engagement-score weights as the pref stores them: '' for the
     * defaults, else "volume:N,time:N,scroll:N,video:N,recency:N" summing to
     * 100 — validated by the parser the read path uses, and stored as ''
     * when they are the defaults, as LTV › Settings stores them.
     */
    public static function scoreWeights(string $raw): string
    {
        try {
            $weights = MysqlEngagementRepository::parseScoreWeights($raw);
        } catch (\RuntimeException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
        // Canonical order before comparing: the parser keeps the order it
        // was given, and an array compared with === is order-sensitive, so
        // "time:20,volume:40,…" would never read as the defaults.
        $canonical = [];
        $pairs = [];
        foreach (array_keys(MysqlEngagementRepository::DEFAULT_SCORE_WEIGHTS) as $component) {
            $canonical[$component] = $weights[$component];
            $pairs[] = $component . ':' . $weights[$component];
        }

        return $canonical === MysqlEngagementRepository::DEFAULT_SCORE_WEIGHTS ? '' : implode(',', $pairs);
    }
}
