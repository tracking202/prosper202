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
    public const VERSION = '202_version';
    public const SESSIONS = '202_sessions';
    public const CRONJOBS = '202_cronjobs';
    public const CRONJOB_LOGS = '202_cronjob_logs';
    public const MYSQL_ERRORS = '202_mysql_errors';
    public const DELAYED_SQLS = '202_delayed_sqls';
    public const ALERTS = '202_alerts';
    public const OFFERS = '202_offers';
    public const FILTERS = '202_filters';
    public const SYNC_JOBS = '202_sync_jobs';
    public const SYNC_JOB_EVENTS = '202_sync_job_events';
    public const SYNC_JOB_ITEMS = '202_sync_job_items';
    public const CHANGE_LOG = '202_change_log';
    public const DELETED_LOG = '202_deleted_log';
    public const SYNC_AUDIT = '202_sync_audit';

    // User tables
    public const USERS = '202_users';
    public const USERS_PREF = '202_users_pref';
    public const USERS_LOG = '202_users_log';
    public const USER_ROLE = '202_user_role';
    public const ROLES = '202_roles';
    public const PERMISSIONS = '202_permissions';
    public const ROLE_PERMISSION = '202_role_permission';
    public const API_KEYS = '202_api_keys';
    public const AUTH_KEYS = '202_auth_keys';
    public const USER_DATA_FEEDBACK = 'user_data_feedback';

    // Click tables
    public const CLICKS = '202_clicks';
    public const CLICKS_ADVANCE = '202_clicks_advance';
    public const CLICKS_COUNTER = '202_clicks_counter';
    public const CLICKS_RECORD = '202_clicks_record';
    public const CLICKS_SITE = '202_clicks_site';
    public const CLICKS_SPY = '202_clicks_spy';
    public const CLICKS_TRACKING = '202_clicks_tracking';
    public const CLICKS_VARIABLE = '202_clicks_variable';
    public const CLICKS_ROTATOR = '202_clicks_rotator';
    public const CLICKS_TOTAL = '202_clicks_total';

    // Tracking tables
    public const TRACKING_C1 = '202_tracking_c1';
    public const TRACKING_C2 = '202_tracking_c2';
    public const TRACKING_C3 = '202_tracking_c3';
    public const TRACKING_C4 = '202_tracking_c4';
    public const TRACKING_CX = '202_tracking_cx';
    public const CLICKS_TRACKING_CX = '202_clicks_tracking_cx';
    public const MIGRATION_STATE = '202_migration_state';
    public const TRACKERS = '202_trackers';
    public const CPA_TRACKERS = '202_cpa_trackers';
    public const KEYWORDS = '202_keywords';
    public const GOOGLE = '202_google';
    public const BING = '202_bing';
    public const FACEBOOK = '202_facebook';
    public const UTM_CAMPAIGN = '202_utm_campaign';
    public const UTM_CONTENT = '202_utm_content';
    public const UTM_MEDIUM = '202_utm_medium';
    public const UTM_SOURCE = '202_utm_source';
    public const UTM_TERM = '202_utm_term';
    public const CUSTOM_VARIABLES = '202_custom_variables';
    public const PPC_NETWORK_VARIABLES = '202_ppc_network_variables';
    public const VARIABLE_SETS = '202_variable_sets';
    public const VARIABLE_SETS2 = '202_variable_sets2';

    // Campaign tables
    public const AFF_CAMPAIGNS = '202_aff_campaigns';
    public const AFF_NETWORKS = '202_aff_networks';
    public const PPC_ACCOUNTS = '202_ppc_accounts';
    public const PPC_NETWORKS = '202_ppc_networks';
    public const PPC_ACCOUNT_PIXELS = '202_ppc_account_pixels';
    public const LANDING_PAGES = '202_landing_pages';
    public const TEXT_ADS = '202_text_ads';

    // Attribution tables
    public const ATTRIBUTION_MODELS = '202_attribution_models';
    public const ATTRIBUTION_SNAPSHOTS = '202_attribution_snapshots';
    public const ATTRIBUTION_TOUCHPOINTS = '202_attribution_touchpoints';
    public const ATTRIBUTION_SETTINGS = '202_attribution_settings';
    public const ATTRIBUTION_AUDIT = '202_attribution_audit';
    public const ATTRIBUTION_EXPORTS = '202_attribution_exports';
    public const CONVERSION_LOGS = '202_conversion_logs';
    public const CONVERSION_TOUCHPOINTS = '202_conversion_touchpoints';

    // Rotator tables
    public const ROTATORS = '202_rotators';
    public const ROTATOR_RULES = '202_rotator_rules';
    public const ROTATOR_RULES_CRITERIA = '202_rotator_rules_criteria';
    public const ROTATOR_RULES_REDIRECTS = '202_rotator_rules_redirects';
    public const ROTATIONS = '202_rotations';

    // Ad network tables
    public const AD_NETWORK_FEEDS = '202_ad_network_feeds';
    public const AD_NETWORK_ADS = '202_ad_network_ads';
    public const AD_NETWORK_TITLES = '202_ad_network_titles';
    public const AD_NETWORK_BODIES = '202_ad_network_bodies';
    public const AD_FEED_CONTENTAD_TOKENS = '202_ad_feed_contentad_tokens';
    public const AD_FEED_OUTBRAIN_TOKENS = '202_ad_feed_outbrain_tokens';
    public const AD_FEED_TABOOLA_TOKENS = '202_ad_feed_taboola_tokens';
    public const AD_FEED_CUSTOM_TOKENS = '202_ad_feed_custom_tokens';
    public const AD_FEED_REVCONTENT_TOKENS = '202_ad_feed_revcontent_tokens';
    public const AD_FEED_FACEBOOK_TOKENS = '202_ad_feed_facebook_tokens';

    // Export tables
    public const EXPORT_ADGROUPS = '202_export_adgroups';
    public const EXPORT_CAMPAIGNS = '202_export_campaigns';
    public const EXPORT_KEYWORDS = '202_export_keywords';
    public const EXPORT_SESSIONS = '202_export_sessions';
    public const EXPORT_TEXTADS = '202_export_textads';

    // Location tables
    public const IPS = '202_ips';
    public const IPS_V6 = '202_ips_v6';
    public const LAST_IPS = '202_last_ips';
    public const LOCATIONS_CITY = '202_locations_city';
    public const LOCATIONS_COUNTRY = '202_locations_country';
    public const LOCATIONS_REGION = '202_locations_region';
    public const LOCATIONS_ISP = '202_locations_isp';

    // Device/browser tables
    public const BROWSERS = '202_browsers';
    public const PLATFORMS = '202_platforms';
    public const DEVICE_TYPES = '202_device_types';
    public const DEVICE_MODELS = '202_device_models';
    public const PIXEL_TYPES = '202_pixel_types';

    // Site tables
    public const SITE_DOMAINS = '202_site_domains';
    public const SITE_URLS = '202_site_urls';

    // Data engine tables
    public const DATAENGINE = '202_dataengine';
    public const DATAENGINE_JOB = '202_dataengine_job';
    public const DIRTY_HOURS = '202_dirty_hours';
    public const SORT_BREAKDOWNS = '202_sort_breakdowns';
    public const CHARTS = '202_charts';

    // DNI tables
    public const DNI_NETWORKS = '202_dni_networks';

    // Bot202 Facebook Pixel tables
    public const BOT202_FACEBOOK_PIXEL_ASSISTANT = '202_bot202_facebook_pixel_assistant';
    public const BOT202_FACEBOOK_PIXEL_CONTENT_TYPE = '202_bot202_facebook_pixel_content_type';
    public const BOT202_FACEBOOK_PIXEL_CLICK_EVENTS = '202_bot202_facebook_pixel_click_events';

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
