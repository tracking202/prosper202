<?php
declare(strict_types=1);

namespace Prosper202\Database\Schema;

/**
 * Registry of all table names in the Prosper202 database.
 * Use these constants instead of magic strings to prevent typos and enable IDE auto-completion.
 */
final class TableRegistry
{
    // Core tables
    public const string VERSION = '202_version';
    public const string SESSIONS = '202_sessions';
    public const string CRONJOBS = '202_cronjobs';
    public const string CRONJOB_LOGS = '202_cronjob_logs';
    public const string MYSQL_ERRORS = '202_mysql_errors';
    public const string DELAYED_SQLS = '202_delayed_sqls';
    public const string ALERTS = '202_alerts';
    public const string OFFERS = '202_offers';
    public const string FILTERS = '202_filters';
    public const string SYNC_JOBS = '202_sync_jobs';
    public const string SYNC_JOB_EVENTS = '202_sync_job_events';
    public const string SYNC_JOB_ITEMS = '202_sync_job_items';
    public const string CHANGE_LOG = '202_change_log';
    public const string DELETED_LOG = '202_deleted_log';
    public const string SYNC_AUDIT = '202_sync_audit';

    // User tables
    public const string USERS = '202_users';
    public const string USERS_PREF = '202_users_pref';
    public const string USERS_LOG = '202_users_log';
    public const string USER_ROLE = '202_user_role';
    public const string ROLES = '202_roles';
    public const string PERMISSIONS = '202_permissions';
    public const string ROLE_PERMISSION = '202_role_permission';
    public const string API_KEYS = '202_api_keys';
    public const string AUTH_KEYS = '202_auth_keys';
    public const string USER_DATA_FEEDBACK = 'user_data_feedback';

    // Click tables
    public const string CLICKS = '202_clicks';
    public const string CLICKS_ADVANCE = '202_clicks_advance';
    public const string CLICKS_COUNTER = '202_clicks_counter';
    public const string CLICKS_RECORD = '202_clicks_record';
    public const string CLICKS_SITE = '202_clicks_site';
    public const string CLICKS_SPY = '202_clicks_spy';
    public const string CLICKS_TRACKING = '202_clicks_tracking';
    public const string CLICKS_VARIABLE = '202_clicks_variable';
    public const string CLICKS_ROTATOR = '202_clicks_rotator';
    public const string CLICKS_TOTAL = '202_clicks_total';

    // Tracking tables
    public const string TRACKING_C1 = '202_tracking_c1';
    public const string TRACKING_C2 = '202_tracking_c2';
    public const string TRACKING_C3 = '202_tracking_c3';
    public const string TRACKING_C4 = '202_tracking_c4';
    public const string TRACKERS = '202_trackers';
    public const string CPA_TRACKERS = '202_cpa_trackers';
    public const string KEYWORDS = '202_keywords';
    public const string GOOGLE = '202_google';
    public const string BING = '202_bing';
    public const string FACEBOOK = '202_facebook';
    public const string UTM_CAMPAIGN = '202_utm_campaign';
    public const string UTM_CONTENT = '202_utm_content';
    public const string UTM_MEDIUM = '202_utm_medium';
    public const string UTM_SOURCE = '202_utm_source';
    public const string UTM_TERM = '202_utm_term';
    public const string CUSTOM_VARIABLES = '202_custom_variables';
    public const string PPC_NETWORK_VARIABLES = '202_ppc_network_variables';
    public const string VARIABLE_SETS = '202_variable_sets';
    public const string VARIABLE_SETS2 = '202_variable_sets2';

    // Campaign tables
    public const string AFF_CAMPAIGNS = '202_aff_campaigns';
    public const string AFF_NETWORKS = '202_aff_networks';
    public const string PPC_ACCOUNTS = '202_ppc_accounts';
    public const string PPC_NETWORKS = '202_ppc_networks';
    public const string PPC_ACCOUNT_PIXELS = '202_ppc_account_pixels';
    public const string LANDING_PAGES = '202_landing_pages';
    public const string TEXT_ADS = '202_text_ads';

    // Attribution tables
    public const string ATTRIBUTION_MODELS = '202_attribution_models';
    public const string ATTRIBUTION_SNAPSHOTS = '202_attribution_snapshots';
    public const string ATTRIBUTION_TOUCHPOINTS = '202_attribution_touchpoints';
    public const string ATTRIBUTION_SETTINGS = '202_attribution_settings';
    public const string ATTRIBUTION_AUDIT = '202_attribution_audit';
    public const string ATTRIBUTION_EXPORTS = '202_attribution_exports';
    public const string CONVERSION_LOGS = '202_conversion_logs';
    public const string CONVERSION_TOUCHPOINTS = '202_conversion_touchpoints';

    // Rotator tables
    public const string ROTATORS = '202_rotators';
    public const string ROTATOR_RULES = '202_rotator_rules';
    public const string ROTATOR_RULES_CRITERIA = '202_rotator_rules_criteria';
    public const string ROTATOR_RULES_REDIRECTS = '202_rotator_rules_redirects';
    public const string ROTATIONS = '202_rotations';

    // Ad network tables
    public const string AD_NETWORK_FEEDS = '202_ad_network_feeds';
    public const string AD_NETWORK_ADS = '202_ad_network_ads';
    public const string AD_NETWORK_TITLES = '202_ad_network_titles';
    public const string AD_NETWORK_BODIES = '202_ad_network_bodies';
    public const string AD_FEED_CONTENTAD_TOKENS = '202_ad_feed_contentad_tokens';
    public const string AD_FEED_OUTBRAIN_TOKENS = '202_ad_feed_outbrain_tokens';
    public const string AD_FEED_TABOOLA_TOKENS = '202_ad_feed_taboola_tokens';
    public const string AD_FEED_CUSTOM_TOKENS = '202_ad_feed_custom_tokens';
    public const string AD_FEED_REVCONTENT_TOKENS = '202_ad_feed_revcontent_tokens';
    public const string AD_FEED_FACEBOOK_TOKENS = '202_ad_feed_facebook_tokens';

    // Export tables
    public const string EXPORT_ADGROUPS = '202_export_adgroups';
    public const string EXPORT_CAMPAIGNS = '202_export_campaigns';
    public const string EXPORT_KEYWORDS = '202_export_keywords';
    public const string EXPORT_SESSIONS = '202_export_sessions';
    public const string EXPORT_TEXTADS = '202_export_textads';

    // Location tables
    public const string IPS = '202_ips';
    public const string IPS_V6 = '202_ips_v6';
    public const string LAST_IPS = '202_last_ips';
    public const string LOCATIONS_CITY = '202_locations_city';
    public const string LOCATIONS_COUNTRY = '202_locations_country';
    public const string LOCATIONS_REGION = '202_locations_region';
    public const string LOCATIONS_ISP = '202_locations_isp';

    // Device/browser tables
    public const string BROWSERS = '202_browsers';
    public const string PLATFORMS = '202_platforms';
    public const string DEVICE_TYPES = '202_device_types';
    public const string DEVICE_MODELS = '202_device_models';
    public const string PIXEL_TYPES = '202_pixel_types';

    // Site tables
    public const string SITE_DOMAINS = '202_site_domains';
    public const string SITE_URLS = '202_site_urls';

    // Data engine tables
    public const string DATAENGINE = '202_dataengine';
    public const string DATAENGINE_JOB = '202_dataengine_job';
    public const string DIRTY_HOURS = '202_dirty_hours';
    public const string SORT_BREAKDOWNS = '202_sort_breakdowns';
    public const string CHARTS = '202_charts';

    // DNI tables
    public const string DNI_NETWORKS = '202_dni_networks';

    // Bot202 Facebook Pixel tables
    public const string BOT202_FACEBOOK_PIXEL_ASSISTANT = '202_bot202_facebook_pixel_assistant';
    public const string BOT202_FACEBOOK_PIXEL_CONTENT_TYPE = '202_bot202_facebook_pixel_content_type';
    public const string BOT202_FACEBOOK_PIXEL_CLICK_EVENTS = '202_bot202_facebook_pixel_click_events';

    /**
     * Get all table names as an array.
     *
     * @return array<string>
     */
    public static function getAllTables(): array
    {
        $reflection = new \ReflectionClass(self::class);
        return array_values($reflection->getConstants());
    }

    /**
     * Check if a table name is valid.
     */
    public static function isValidTable(string $tableName): bool
    {
        return in_array($tableName, self::getAllTables(), true);
    }
}
