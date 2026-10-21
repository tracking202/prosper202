<?php

use UAParser\Parser;

// The functions the click path's bootstrap shares (record_mysql_error(),
// the cached reads, systemHash(), getTrackingDomain()).
require_once __DIR__ . '/mysql-error-args.php';
require_once __DIR__ . '/functions-shared.php';

/** A packed IPv6 address as text (inet_ntop(), quiet on a malformed value). */
function inet6_ntoa($ip)
{
    return @inet_ntop($ip);
}

function dollar_format($amount, $currency = null, $cpv = false)
{
    setlocale(LC_MONETARY, 'en_US.UTF-8');

    if ($cpv == true) {
        $decimals = 5;
    } else {
        $decimals = 2;
    }

    if ($currency == null) {
        $currency = 'USD';
    }

    // Convert string amount to float for number_format
    if (is_string($amount)) {
        $amount = (float)$amount;
    }

    // One table, on UsersController, which normalizeCurrency() admits the
    // keys of: a code that has no symbol here is a code no stored preference
    // can hold. The class_exists guard is for the installer, which formats
    // before the autoloader is up; the fallback is the behaviour this always
    // had.
    $currency_before = '';
    $currency_after = '';
    if (class_exists(\Api\V3\Controllers\UsersController::class)) {
        [$currency_before, $currency_after] =
            \Api\V3\Controllers\UsersController::CURRENCY_SYMBOLS[$currency] ?? ['', ''];
    }

    // Last resort: the code itself stands in for the symbol. Reachable only
    // for a currency the table does not know, which a stored preference
    // cannot be.
    if ($currency_before == '' && $currency_after == '') $currency_before = $currency;

    if ($amount !== null) {
        if ($amount >= 0) {
            $new_amount = $currency_before . number_format($amount, $decimals) . $currency_after;
        } else {
            // Accounting form: the parentheses are the sign, so the amount
            // inside has none ("($5.00)", as the v1/v2 APIs write it). It
            // kept its own, and a refund read "($-5.00)".
            $new_amount = $currency_before . number_format(abs($amount), $decimals) . $currency_after;
            $new_amount = '(' . $new_amount . ')';
        }
    } else {
        $new_amount = $currency_before . $currency_after;
    }
    return $new_amount;
}

function grab_timeframe($unused = null): array
{
    $auth = new AUTH();
    $auth->set_timezone($_SESSION['user_timezone']);

    $database = DB::getInstance();
    $db = $database->getConnection();

    $mysql['user_id'] = isset($_SESSION['user_id']) ? $db->real_escape_string((string) $_SESSION['user_id']) : 0;
    $user_sql = "SELECT user_pref_time_predefined, user_pref_time_from, user_pref_time_to FROM 202_users_pref WHERE user_id='" . $mysql['user_id'] . "'";
    // A read that failed is not an account without preferences: that row
    // gave every report the window 0 to 0, which holds no clicks.
    $user_result = _mysqli_query($user_sql);
    if (!$user_result instanceof mysqli_result) {
        record_mysql_error($user_sql);
    }
    $user_row = $user_result->fetch_assoc() ?? [];
    $user_row = \Prosper202\DataEngine\ReportView::apply($user_row, $_SESSION['user_id'] ?? null);
    $pref_time = $user_row['user_pref_time_predefined'] ?? '';

    $time = [
        'from' => time(),
        'to' => time(),
    ];

    // A named window, in the account's zone (AUTH::set_timezone() above
    // reads it from the account), with calendar days rather than 86400-second
    // strides: ReportWindow says what each one is and what this got wrong.
    if (in_array($pref_time, \Tracking202\Report\ReportWindow::PRESETS, true)) {
        $registeredAt = null;
        if ($pref_time === 'alltime' && isset($_SESSION['user_id'])) {
            $mysql['user_id'] = $db->real_escape_string((string) $_SESSION['user_id']);
            $user2_sql = "SELECT user_time_register FROM 202_users WHERE user_id='" . $mysql['user_id'] . "'";
            $user2_result = $db->query($user2_sql) or record_mysql_error($user2_sql);
            $user2_row = $user2_result->fetch_assoc();
            if ($user2_row !== null && is_numeric($user2_row['user_time_register'] ?? null)) {
                $registeredAt = (int) $user2_row['user_time_register'];
            }
        }
        $time = \Tracking202\Report\ReportWindow::preset($pref_time, date_default_timezone_get(), time(), $registeredAt);
    } elseif (isset($user_row['user_pref_time_from']) && $user_row['user_pref_time_from'] != '') {
        // A custom window: today until the stored bounds are read below.
        $time = \Tracking202\Report\ReportWindow::preset('today', date_default_timezone_get(), time());
    }

    if ($pref_time == '') {
        $time['from'] = $user_row['user_pref_time_from'] ?? 0;
        $time['to'] = $user_row['user_pref_time_to'] ?? 0;
    }

    $time['user_pref_time_predefined'] = $pref_time;
    return $time;
}

/**
 * This install's tracking base, `scheme://host[:port]/<install path>/`:
 * TrackingBaseUrl::build() over the install owner's (user 1's) stored
 * tracking domain (a stored scheme kept, the request's otherwise).
 * callAutoCron(), registerDailyEmail(), getDNIHost() and the account home's
 * deeplink pixel hand it to the hosted service, which calls the install back
 * there with the install hash. They took the scheme from SERVER_PROTOCOL,
 * which is "HTTP/1.1" or "HTTP/2.0" and never names https, so every install
 * was registered as http://, and a site served only over HTTPS was called
 * back where nothing answers.
 *
 * It is the owner's domain, not the signed-in user's: every user sets their
 * own on Personal settings and every user row carries the install hash, so a
 * session-dependent read let any user save attacker.example and have the
 * hosted service call it with the hash that authenticates the cron
 * callbacks (daily-email.php, dni.php) -- measured, signed in as user 2 this
 * answered `http://attacker.example/`.
 */
function p202TrackingBaseUrl(): string
{
    return \Prosper202\Click\TrackingBaseUrl::build(p202StoredTrackingDomain(1), $_SERVER, dirname(__DIR__));
}

/**
 * The account's dash email as a JavaScript string, for the footer's support
 * widget: written between quotes as stored, an address with a ' ended the
 * string and broke the script.
 */
function p202DashEmailJson(): string
{
    $json = json_encode(
        (string) getDashEmail(),
        JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        error_log('The dash email could not be written into the footer script: ' . json_last_error_msg());

        return '""';
    }

    return $json;
}

/**
 * The statement in every signed-in page's footer script (template.php) that
 * has the user's own browser ping this install's cron endpoint:
 * `navigator.sendBeacon("//<tracking domain><install path>202-cronjobs/?page_ping=1");`.
 * The query marks the run as a page's, which does the cron's work and is not
 * recorded as the cron job's last run (Prosper202\Cron\CronRun).
 *
 * The ping is optional and the page above it has rendered, so a tracking
 * domain that cannot be read -- getTrackingDomain() throws rather than guess
 * -- skips the ping, logged, instead of cutting the page off mid-footer; the
 * statement is then a comment saying so.
 */
function p202CronBeaconStatement(): string
{
    try {
        $url = '//' . getTrackingDomain() . get_absolute_url() . '202-cronjobs/?'
            . \Prosper202\Cron\CronRun::pagePingQuery();
        $flags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_THROW_ON_ERROR;

        return 'navigator.sendBeacon(' . json_encode($url, $flags) . ');';
    } catch (\Throwable $e) {
        error_log('p202: the cron ping was skipped: ' . $e->getMessage());

        return '/* the cron ping was skipped: the tracking domain could not be read */';
    }
}

// the above, if true, are options to turn on specific filtering techniques.
function query(
    $command,
    $db_table = null,
    $pref_time = null,
    $pref_adv = null,
    $pref_show = null,
    $pref_order = null,
    $offset = null,
    $pref_limit = null,
    $count = null,
    $isspy = false,
    $extra_where = null
)
{
    $database = DB::getInstance();
    if ($isspy) {
        $db = \Prosper202\Database\ConnectionFactory::readConnectionOf($database);
    } else {
        $db = $database->getConnection();
    }


    // grab user preferences
    $mysql['user_id'] = isset($_SESSION['user_id']) ? $db->real_escape_string((string) $_SESSION['user_id']) : 0;
    $user_sql = "SELECT * FROM 202_users_pref WHERE user_id='" . $mysql['user_id'] . "'";
    // A read that failed is not an account without preferences: that row
    // dropped every filter the user had set.
    $user_result = _mysqli_query($user_sql);
    if (!$user_result instanceof mysqli_result) {
        record_mysql_error($user_sql);
    }
    $user_row = $user_result->fetch_assoc() ?? [];
    $user_row = \Prosper202\DataEngine\ReportView::apply($user_row, $_SESSION['user_id'] ?? null);

    // Apply sane defaults when optional arguments are omitted
    if ($db_table === null) {
        $db_table = '2c';
    }
    if ($pref_time === null) {
        $pref_time = true;
    }
    if ($pref_adv === null) {
        $pref_adv = true;
    }
    if ($pref_show === null) {
        $pref_show = true;
    }
    if ($pref_order === null) {
        $pref_order = 'click_time DESC';
    }
    if ($offset === null) {
        $offset = 0;
    }
    if ($pref_limit === null) {
        $pref_limit = true;
    }
    if ($count === null) {
        $count = true;
    }

    $count_sql = "SELECT count(*) AS count FROM 202_dataengine AS 2c ";

    // do extra joins if advance selector is enabled
    if ($pref_adv == true) {

        // if Traffic Source lookup with no individual Traffic Source account lookup do this
        if ($user_row['user_pref_ppc_network_id'] and ! ($user_row['user_pref_ppc_account_id'])) {

            if (! preg_match('/202_ppc_accounts/', (string) $command)) {
                $command .= " LEFT JOIN 202_ppc_accounts AS 2pa ON (2c.ppc_account_id = 2pa.ppc_account_id AND 2pa.user_id = 2c.user_id) ";
            }

            if (! preg_match('/202_ppc_networks/', (string) $command)) {
                $command .= " LEFT JOIN 202_ppc_networks AS 2pn ON (2pa.ppc_network_id = 2pn.ppc_network_id AND 2pn.user_id = 2c.user_id) ";
            }
        }

        // if Category lookup with no individual  campaign lookup do this
        if ($user_row['user_pref_aff_network_id'] and ! ($user_row['user_pref_aff_campaign_id'])) {

            if (! preg_match('/202_aff_campaigns/', (string) $command)) {
                $command .= " LEFT JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id AND 2ac.user_id = 2c.user_id) ";
            }

            if (! preg_match('/202_aff_networks/', (string) $command)) {
                $command .= " LEFT JOIN 202_aff_networks AS 2an ON (2ac.aff_network_id = 2an.aff_network_id AND 2an.user_id = 2c.user_id) ";
            }
        }

        // The referer, keyword and IP filters need no join: they are
        // subqueries over the click row's ids (TextFilterSql, below).

        // if there is a country lookup, and we have not joined the 202 country table. do so now
        if ($user_row['user_pref_country_id'] and ! preg_match('/202_locations_country/', (string) $command)) {
            $command .= " LEFT JOIN 202_locations_country AS 2cy ON (2ca.country_id = 2cy.country_id) ";
        }

        // if there is a region lookup, and we have not joined the 202 region table. do so now
        if ($user_row['user_pref_region_id'] and ! preg_match('/202_locations_region/', (string) $command)) {
            $command .= " LEFT JOIN 202_locations_region AS 2rg ON (2ca.region_id = 2rg.region_id) ";
        }

        // if there is a isp lookup, and we have not joined the 202 isp table. do so now
        if ($user_row['user_pref_isp_id'] and ! preg_match('/202_locations_isp/', (string) $command)) {
            $command .= " LEFT JOIN 202_locations_isp AS 2is ON (2ca.isp_id = 2is.isp_id) ";
        }

        // if there is a device lookup, and we have not joined the 202 device table. do so now
        if ($user_row['user_pref_device_id']) {
            if (! preg_match('/202_device_models/', (string) $command)) {
                $command .= " LEFT JOIN 202_device_models AS 2d ON (2ca.device_id = 2d.device_id) ";
            }
            // Count query: no JOIN needed — use subquery (see count_where below)
        }

        // if there is a browser lookup, and we have not joined the 202 browser table. do so now
        if ($user_row['user_pref_browser_id'] and ! preg_match('/202_browsers/', (string) $command)) {
            $command .= " LEFT JOIN 202_browsers AS 2b ON (2ca.browser_id = 2b.browser_id) ";
        }

        // if there is a platform lookup, and we have not joined the 202 platform table. do so now
        if ($user_row['user_pref_platform_id'] and ! preg_match('/202_platforms/', (string) $command)) {
            $command .= " LEFT JOIN 202_platforms AS 2p ON (2ca.platform_id = 2p.platform_id) ";
        }
    }

    // Whose clicks: the rule every report page reads (DataScope). This read
    // the absent publisher key as "may see every campaign", so the Visitors
    // list and Spy showed every account's clicks to every signed-in user.
    $dataUserId = \Prosper202\DataEngine\DataScope::userId();
    $count_where = $dataUserId === null
        ? " WHERE $db_table.user_id!='0' "
        : " WHERE $db_table.user_id='" . $dataUserId . "' ";
    $click_sql = $command . $count_where;
    if ($user_row['user_pref_subid']) {
        $mysql['user_landing_subid'] = $db->real_escape_string($user_row['user_pref_subid']);
        $click_sql .= " AND      2c.click_id='" . $mysql['user_landing_subid'] . "'";
    }

    // NOTE: intentionally NOT converged to UserPrefFilters::showFilter — this
    // visitor-log view scopes 'leads' to real (non-filtered) leads only.
    // set show preferences
    if ($pref_show == true) {
        if ($user_row['user_pref_show'] == 'filtered') {
            $click_sql .= " AND click_filtered='1' ";
            $count_where .= " AND click_filtered='1' ";
        } elseif ($user_row['user_pref_show'] == 'real') {
            $click_sql .= " AND click_filtered='0' ";
            $count_where .= " AND click_filtered='0' ";
        } elseif ($user_row['user_pref_show'] == 'leads') {
            $click_sql .= " AND click_filtered='0' AND click_lead='1' ";
            $count_where .= " AND click_filtered='0' AND click_lead='1' ";
        } elseif ($user_row['user_pref_show'] == 'filtered_bot') {
            $click_sql .= " AND click_bot='1'";
            $count_where .= " AND click_bot='1'";
        }
    }

    // set advanced preferences
    if ($pref_adv == true) {
        if ($user_row['user_pref_ppc_network_id'] and ! ($user_row['user_pref_ppc_account_id'])) {
            $mysql['user_pref_ppc_network_id'] = $db->real_escape_string($user_row['user_pref_ppc_network_id']);
            if ($user_row['user_pref_ppc_network_id'] == '16777215') {
                // The count reads what the list's joins read: the click's
                // own account's traffic source account, and that account's
                // own source (CLAUDE.md #27). One naming another account's
                // reads as no source in both.
                $click_sql .= "  AND      2pn.ppc_network_id IS NULL";
                $count_where .= "  AND      NOT EXISTS (SELECT 1 FROM 202_ppc_accounts AS own_pa INNER JOIN 202_ppc_networks AS own_pn ON (own_pn.ppc_network_id = own_pa.ppc_network_id AND own_pn.user_id = own_pa.user_id) WHERE own_pa.ppc_account_id = 2c.ppc_account_id AND own_pa.user_id = 2c.user_id)";
            } else {
                $click_sql .= "  AND      2pn.ppc_network_id='" . $mysql['user_pref_ppc_network_id'] . "'";
                $count_where .= "  AND      2c.ppc_account_id IN (SELECT own_pa.ppc_account_id FROM 202_ppc_accounts AS own_pa INNER JOIN 202_ppc_networks AS own_pn ON (own_pn.ppc_network_id = own_pa.ppc_network_id AND own_pn.user_id = own_pa.user_id) WHERE own_pa.user_id = 2c.user_id AND own_pa.ppc_network_id='" . $mysql['user_pref_ppc_network_id'] . "')";
            }
        }

        if ($user_row['user_pref_ppc_account_id']) {
            $mysql['user_pref_ppc_account_id'] = $db->real_escape_string($user_row['user_pref_ppc_account_id']);
            $click_sql .= " AND      2c.ppc_account_id='" . $mysql['user_pref_ppc_account_id'] . "'";
            $count_where .= " AND      2c.ppc_account_id='" . $mysql['user_pref_ppc_account_id'] . "'";
        }

        if ($user_row['user_pref_aff_network_id'] and ! $user_row['user_pref_aff_campaign_id']) {

            $mysql['user_pref_aff_network_id'] = $db->real_escape_string($user_row['user_pref_aff_network_id']);
            $click_sql .= "  AND      2an.aff_network_id='" . $mysql['user_pref_aff_network_id'] . "'";
            $count_where .= "  AND      2c.aff_network_id='" . $mysql['user_pref_aff_network_id'] . "'";
        }

        if ($user_row['user_pref_aff_campaign_id']) {
            $mysql['user_pref_aff_campaign_id'] = $db->real_escape_string($user_row['user_pref_aff_campaign_id']);
            $click_sql .= " AND      2c.aff_campaign_id='" . $mysql['user_pref_aff_campaign_id'] . "'";
            $count_where .= " AND      2c.aff_campaign_id='" . $mysql['user_pref_aff_campaign_id'] . "'";
        }
        if ($user_row['user_pref_text_ad_id']) {
            $mysql['user_pref_text_ad_id'] = $db->real_escape_string($user_row['user_pref_text_ad_id']);
            $click_sql .= " AND      2ca.text_ad_id='" . $mysql['user_pref_text_ad_id'] . "'";
            $count_where .= " AND      2c.text_ad_id='" . $mysql['user_pref_text_ad_id'] . "'";
        }
        if ($user_row['user_pref_method_of_promotion'] != '0') {
            if ($user_row['user_pref_method_of_promotion'] == 'directlink') {
                $click_sql .= " AND      2c.landing_page_id=''";
                $count_where .= " AND      2c.landing_page_id=''";
            } elseif ($user_row['user_pref_method_of_promotion'] == 'landingpage') {
                $click_sql .= " AND      2c.landing_page_id!=''";
                $count_where .= " AND      2c.landing_page_id!=''";
            }
        }

        if ($user_row['user_pref_landing_page_id']) {
            $mysql['user_landing_page_id'] = $db->real_escape_string($user_row['user_pref_landing_page_id']);
            $click_sql .= " AND      2c.landing_page_id='" . $mysql['user_landing_page_id'] . "'";
            $count_where .= " AND      2c.landing_page_id='" . $mysql['user_landing_page_id'] . "'";
        }

        if ($user_row['user_pref_country_id']) {
            // Cast to int: these ids are interpolated UNQUOTED, where
            // real_escape_string does not neutralize a payload like
            // "1 OR (SELECT ...)" (it contains no quotes to escape).
            $mysql['user_pref_country_id'] = (int) $user_row['user_pref_country_id'];
            $click_sql .= " AND      2ca.country_id=" . $mysql['user_pref_country_id'];
            $count_where .= " AND      2c.country_id=" . $mysql['user_pref_country_id'];
        }

        if ($user_row['user_pref_region_id']) {
            $mysql['user_pref_region_id'] = (int) $user_row['user_pref_region_id'];
            $click_sql .= " AND      2ca.region_id=" . $mysql['user_pref_region_id'];
            $count_where .= " AND      2c.region_id=" . $mysql['user_pref_region_id'];
        }

        if ($user_row['user_pref_isp_id']) {
            $mysql['user_pref_isp_id'] = (int) $user_row['user_pref_isp_id'];
            $click_sql .= " AND      2is.isp_id=" . $mysql['user_pref_isp_id'];
            $count_where .= " AND      2c.isp_id=" . $mysql['user_pref_isp_id'];
        }

        // Referer, keyword and IP, as every report and GET /clicks read them
        // (TextFilterSql): this list matched the referer's domain only, the
        // text as a LIKE pattern (`50%` matched "500 off"), and the address
        // as a substring (10.0.0.1 matched 10.0.0.12).
        $textFilters = \Prosper202\DataEngine\TextFilterSql::where(
            $user_row,
            $db_table,
            static fn (string $text): string => $db->real_escape_string($text)
        );
        $click_sql .= $textFilters;
        $count_where .= $textFilters;

        if ($user_row['user_pref_device_id']) {
            $mysql['user_pref_device_id'] = (int) $user_row['user_pref_device_id'];
            $click_sql .= " AND      2d.device_type=" . $mysql['user_pref_device_id'];
            $count_where .= " AND      2c.device_id IN (SELECT device_id FROM 202_device_models WHERE device_type=" . $mysql['user_pref_device_id'] . ")";
        }

        if ($user_row['user_pref_browser_id']) {
            $mysql['user_pref_browser_id'] = (int) $user_row['user_pref_browser_id'];
            $click_sql .= " AND      2b.browser_id=" . $mysql['user_pref_browser_id'];
            $count_where .= " AND      2c.browser_id=" . $mysql['user_pref_browser_id'];
        }

        if ($user_row['user_pref_platform_id']) {
            $mysql['user_pref_platform_id'] = (int) $user_row['user_pref_platform_id'];
            $click_sql .= " AND      2p.platform_id=" . $mysql['user_pref_platform_id'];
            $count_where .= " AND      2c.platform_id=" . $mysql['user_pref_platform_id'];
        }
    }

    // set time preferences
    if ($pref_time == true) {
        $time = grab_timeframe();

        $mysql['from'] = $db->real_escape_string((string)$time['from']);
        $mysql['to'] = $db->real_escape_string((string)$time['to']);
        // Both bounds are inclusive, as every report and GET /clicks read a
        // window: a day ends at 23:59:59 and the next begins at 00:00:00, so
        // `>` and `<` dropped the clicks of those two seconds from the list.
        if ($mysql['from'] != '') {
            $click_sql .= " AND click_time >= " . $mysql['from'] . " ";
            $count_where .= " AND click_time >= " . $mysql['from'] . " ";
        }
        if ($mysql['to'] != '') {
            $click_sql .= " AND click_time <= " . $mysql['to'] . " ";
            $count_where .= " AND click_time <= " . $mysql['to'] . " ";
        }
    }

    if ($isspy) {
        $from = time() - 86400;
        $click_sql .= " AND click_time > " . $from . " ";
    }

    // Append caller-supplied extra WHERE conditions (e.g. incremental time bound)
    if ($extra_where !== null && $extra_where !== '') {
        $click_sql .= ' ' . $extra_where;
        $count_where .= ' ' . $extra_where;
    }

    // set limit preferences
    if ($pref_order) {
        $orderClause = trim((string) $pref_order);
        if ($orderClause !== '') {
            if (!preg_match('/^\s*ORDER\s+BY/i', $orderClause)) {
                $orderClause = ' ORDER BY ' . $orderClause;
            } else {
                $orderClause = ' ' . $orderClause;
            }
            $click_sql .= $orderClause;
        }
    }

    // only if we want to count stuff like the click history clicks do we need to do any of the stuff below.
    $limitValue = null;
    $userPrefLimit = isset($user_row['user_pref_limit']) ? (int)$user_row['user_pref_limit'] : 0;
    if ($userPrefLimit <= 0) {
        $userPrefLimit = 50;
    }

    if (is_numeric($pref_limit)) {
        $limitValue = max(0, (int)$pref_limit);
    } elseif ($pref_limit === true) {
        $limitValue = $userPrefLimit;
    }
    if ($limitValue !== null && $limitValue <= 0) {
        $limitValue = null;
    }

    $rows = null;
    if ($count == true || $limitValue !== null || $pref_limit !== false) {
        // For spy mode, apply the 24-hour time bound to the count query too
        if ($isspy) {
            $spy_count_from = time() - 86400;
            $count_where .= " AND click_time > " . $spy_count_from . " ";
        }
        $count_sql_to_run = $count_sql . $count_where;
        if (isset($mysql['user_landing_subid']) && $mysql['user_landing_subid']) {
            $count_sql_to_run .= " AND 2c.click_id='" . $mysql['user_landing_subid'] . "'";
        }
        // A count that failed is not a count of 0, which the Visitors page
        // showed as "0-0 of 0" above the rows it listed, with no pages to
        // reach the rest.
        $count_result = _mysqli_query($count_sql_to_run);
        if (!$count_result instanceof mysqli_result) {
            record_mysql_error($count_sql_to_run);
        }
        $count_row = $count_result->fetch_assoc();
        $rows = (int) ($count_row['count'] ?? 0);
    }

    if ($count == true) {
        $query['rows'] = $rows;
        $query['offset'] = (int)$offset;
    }

    if ($limitValue !== null) {
        $click_sql .= " LIMIT ";
        $offsetValue = (is_numeric($offset) && $offset >= 0) ? (int)$offset : 0;
        if ($offsetValue > 0) {
            $limitOffset = $offsetValue * $limitValue;
            $click_sql .= $db->real_escape_string((string)$limitOffset) . ",";
        }
        $click_sql .= $limitValue;

        $fromRow = $offsetValue > 0 ? ($offsetValue * $limitValue) + 1 : 1;
        $toRow = $fromRow + $limitValue - 1;
        if ($rows !== null && $toRow > $rows) {
            $toRow = $rows;
        }
    } else {
        $fromRow = ($rows !== null && $rows > 0) ? 1 : 0;
        $toRow = $rows ?? 0;
    }

    if ($count == true) {
        $query['from'] = $fromRow;
        $query['to'] = $toRow;
        $query['pages'] = ($limitValue !== null && $limitValue > 0) ? (int)ceil($rows / $limitValue) : 1;
        if (($query['from'] == 1) && ($query['to'] == 0)) {
            $query['from'] = 0;
        }
    } else {
        if ($rows !== null) {
            $query['rows'] = $rows;
        }
        $query['offset'] = (int)$offset;
        $query['from'] = $fromRow;
        $query['to'] = $toRow;
        $limitForPages = ($limitValue !== null && $limitValue > 0) ? $limitValue : ($rows ?? 0);
        $query['pages'] = ($limitForPages > 0) ? (int)ceil(($rows ?? 0) / $limitForPages) : 1;
        if (($query['from'] == 1) && ($query['to'] == 0)) {
            $query['from'] = 0;
        }
    }

    // check if using dataengine

    if (stripos($click_sql, "202_dataengine")) {
        $click_sql = str_replace("2ca.", "2c.", $click_sql);
    }
    $query['click_sql'] = $click_sql;

    return $query;
}

/**
 * Validates a URL for safe use in href attributes.
 * Blocks dangerous schemes (javascript:, data:, vbscript:, etc.)
 * while allowing http, https, scheme-relative, and relative URLs.
 */
function safe_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if ($scheme !== null && !in_array(strtolower($scheme), ['http', 'https'], true)) {
        return '';
    }
    // Catch obfuscated schemes that parse_url misses (e.g. tab/newline inside scheme)
    if (preg_match('/^(javascript|data|vbscript)\s*:/i', $url) === 1) {
        return '';
    }
    return $url;
}

function pcc_network_icon($ppc_network_name, $ppc_account_name)
{
    // 7search
    if ((preg_match("/7search/i", (string) $ppc_network_name)) or (preg_match("/7 search/i", (string) $ppc_network_name))) {
        $ppc_network_icon = '7search.ico';
    }

    // adbrite
    if (preg_match("/adbrite/i", (string) $ppc_network_name)) {
        $ppc_network_icon = 'adbrite.ico';
    }

    // adoori
    if (preg_match("/adoori/i", (string) $ppc_network_name)) {
        $ppc_network_icon = 'adoori.ico';
    }

    // adTegrity
    if ((preg_match("/adtegrity/i", (string) $ppc_network_name)) or (preg_match("/ad tegrity/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'adtegrity.png';
    }

    // ask
    if (preg_match("/ask/i", (string) $ppc_network_name)) {
        $ppc_network_icon = 'ask.ico';
    }

    // adblade
    if ((preg_match("/adblade/i", (string) $ppc_network_name)) or (preg_match("/ad blade/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'adblade.ico';
    }

    // adsonar
    if ((preg_match("/adsonar/i", (string) $ppc_network_name)) or (preg_match("/ad sonar/i", (string) $ppc_network_name)) or (preg_match("/quigo/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'adsonar.png';
    }

    // marchex
    if ((preg_match("/marchex/i", (string) $ppc_network_name)) or (preg_match("/goclick/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'marchex.png';
    }

    // bidvertiser
    if (preg_match("/bidvertiser/i", (string) $ppc_network_name)) {
        $ppc_network_icon = 'bidvertiser.gif';
    }

    // enhance
    if (preg_match("/enhance/i", (string) $ppc_network_name)) {
        $ppc_network_icon = 'enhance.ico';
    }

    // facebook
    if ((preg_match("/facebook/i", (string) $ppc_network_name)) or (preg_match("/fb/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'facebook.ico';
    }

    // findology
    if (preg_match("/findology/i", (string) $ppc_network_name)) {
        $ppc_network_icon = 'findology.png';
    }

    // google
    if ((preg_match("/google/i", (string) $ppc_network_name)) or (preg_match("/adwords/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'google.ico';
    }

    // instagram
    if (preg_match("/instagram/i", (string) $ppc_network_name)) {
        $ppc_network_icon = 'instagram.ico';
    }

    // kanoodle
    if (preg_match("/kanoodle/i", (string) $ppc_network_name)) {
        $ppc_network_icon = 'kanoodle.ico';
    }

    // looksmart
    if (preg_match("/looksmart/i", (string) $ppc_network_name)) {
        $ppc_network_icon = 'looksmart.gif';
    }

    // hi5
    if ((preg_match("/hi5/i", (string) $ppc_network_name)) or (preg_match("/hi 5/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'hi5.ico';
    }

    // miva
    if ((preg_match("/miva/i", (string) $ppc_network_name)) or (preg_match("/searchfeed/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'miva.ico';
    }

    // mailchimp
    if ((preg_match("/mailchimp/i", (string) $ppc_network_name)) or (preg_match("/searchfeed/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'mailchimp.ico';
    }

    // msn
    if ((preg_match("/microsoft/i", (string) $ppc_network_name)) or (preg_match("/MSN/i", (string) $ppc_network_name)) or (preg_match("/bing/i", (string) $ppc_network_name)) or (preg_match("/adcenter/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'msn.ico';
    }

    // pinterest
    if ((preg_match("/pinterest/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'pinterest.ico';
    }

    // pulse360
    if ((preg_match("/pulse360/i", (string) $ppc_network_name)) or (preg_match("/pulse 360/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'pulse360.ico';
    }

    // quora
    if (preg_match("/quora/i", (string) $ppc_network_name)) {
        $ppc_network_icon = 'quora.ico';
    }

    // snapchat
    if (preg_match("/snapchat/i", (string) $ppc_network_name)) {
        $ppc_network_icon = 'snapchat.ico';
    }
    // search123
    if ((preg_match("/search123/i", (string) $ppc_network_name)) or (preg_match("/search 123/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'google.ico';
    }

    // searchfeed
    if (preg_match("/searchfeed/i", (string) $ppc_network_name)) {
        $ppc_network_icon = 'searchfeed.gif';
    }

    // yahoo
    if ((preg_match("/yahoo/i", (string) $ppc_network_name)) or (preg_match("/YSM/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'yahoo.ico';
    }

    // mediatraffic
    if ((preg_match("/mediatraffic/i", (string) $ppc_network_name)) or (preg_match("/media traffic/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'mediatraffic.png';
    }

    // mochi
    if ((preg_match("/mochi/i", (string) $ppc_network_name)) or (preg_match("/mochimedia/i", (string) $ppc_network_name)) or (preg_match("/mochi media/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'mochi.ico';
    }

    // myspace
    if ((preg_match("/myspace/i", (string) $ppc_network_name)) or (preg_match("/my space/i", (string) $ppc_network_name)) or (preg_match("/myads/i", (string) $ppc_network_name)) or (preg_match("/my ads/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'myspace.ico';
    }

    // fox audience network
    if (preg_match("/fox/i", (string) $ppc_network_name)) {
        $ppc_network_icon = 'foxnetwork.ico';
    }

    // adsdaq
    if (preg_match("/adsdaq/i", (string) $ppc_network_name)) {
        $ppc_network_icon = 'adsdaq.png';
    }

    // twitter
    if (preg_match("/twitter/i", (string) $ppc_network_name)) {
        $ppc_network_icon = 'twitter.ico';
    }

    // amazon
    if (preg_match("/amazon/i", (string) $ppc_network_name)) {
        $ppc_network_icon = 'amazon.ico';
    }

    // adengage
    if ((preg_match("/adengage/i", (string) $ppc_network_name)) or (preg_match("/ad engage/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'adengage.ico';
    }

    // adtoll
    if ((preg_match("/adtoll/i", (string) $ppc_network_name)) or (preg_match("/ad toll/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'adtoll.ico';
    }

    // ezanga
    if ((preg_match("/ezangag/i", (string) $ppc_network_name)) or (preg_match("/e zanga/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'ezanga.ico';
    }

    // aol
    if ((preg_match("/aol/i", (string) $ppc_network_name)) or (preg_match("/quigo/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'aol.ico';
    }

    // aol
    if ((preg_match("/revtwt/i", (string) $ppc_network_name)) or (preg_match("/rev twt/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'revtwt.ico';
    }

    // advertising.com
    if (preg_match("/advertising.com/i", (string) $ppc_network_name)) {
        $ppc_network_icon = 'advertising.com.ico';
    }

    // advertise.com
    if (preg_match("/advertise.com/i", (string) $ppc_network_name)) {
        $ppc_network_icon = 'advertise.com.gif';
    }

    // adready
    if ((preg_match("/adready/i", (string) $ppc_network_name)) or (preg_match("/ad ready/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'adready.ico';
    }

    // abc search
    if ((preg_match("/abcsearch/i", (string) $ppc_network_name)) or (preg_match("/abc search/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'abcsearch.png';
    }

    // abc search
    if ((preg_match("/megaclick/i", (string) $ppc_network_name)) or (preg_match("/mega click/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'megaclick.ico';
    }

    // etology
    if (preg_match("/etology/i", (string) $ppc_network_name)) {
        $ppc_network_icon = 'etology.ico';
    }

    // youtube
    if ((preg_match("/youtube/i", (string) $ppc_network_name)) or (preg_match("/you tube/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'youtube.ico';
    }

    // social media
    if ((preg_match("/socialmedia/i", (string) $ppc_network_name)) or (preg_match("/social media/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'socialmedia.ico';
    }

    // zango
    if ((preg_match("/zango/i", (string) $ppc_network_name)) or (preg_match("/leadimpact/i", (string) $ppc_network_name)) or (preg_match("/lead impact/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'zango.ico';
    }

    // jema media
    if ((preg_match("/jema media/i", (string) $ppc_network_name)) or (preg_match("/jemamedia/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'jemamedia.png';
    }

    // direct cpv
    if ((preg_match("/directcpv/i", (string) $ppc_network_name)) or (preg_match("/direct cpv/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'directcpv.png';
    }

    // linksador
    if ((preg_match("/linksador/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'linksador.png';
    }

    // adon network
    if ((preg_match("/adonnetwork/i", (string) $ppc_network_name)) or (preg_match("/adon network/i", (string) $ppc_network_name)) or (preg_match("/Adon/i", (string) $ppc_network_name)) or (preg_match("/ad-on/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'adonnetwork.ico';
    }

    // plenty of fish
    if ((preg_match("/plentyoffish/i", (string) $ppc_network_name)) or (preg_match("/plenty of fish/i", (string) $ppc_network_name)) or (preg_match("/pof/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'plentyoffish.ico';
    }

    // clicksor
    if (preg_match("/clicksor/i", (string) $ppc_network_name)) {
        $ppc_network_icon = 'clicksor.ico';
    }

    // traffic vance
    if ((preg_match("/trafficvance/i", (string) $ppc_network_name)) or (preg_match("/traffic vance/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'trafficvance.ico';
    }

    // adknowledge
    if ((preg_match("/adknowledge/i", (string) $ppc_network_name)) or (preg_match("/bidsystem/i", (string) $ppc_network_name)) or (preg_match("/bid system/i", (string) $ppc_network_name)) or (preg_match("/cubics/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'adknowledge.ico';
    }

    //admob
    if ((preg_match("/admob/i", (string) $ppc_network_name)) or (preg_match("/ad mob/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'admob.ico';
    }

    //adside
    if ((preg_match("/adside/i", (string) $ppc_network_name)) or (preg_match("/ad side/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'adside.ico';
    }

    //linkedin
    if ((preg_match("/linkedin/i", (string) $ppc_network_name)) or (preg_match("/ad side/i", (string) $ppc_network_name))) {
        $ppc_network_icon = 'linkedin.ico';
    }

    // unknown
    if (! isset($ppc_network_icon)) {
        $ppc_network_icon = 'unknown.gif';
    }

    $html['ppc_network_icon'] = '<img src="' . get_absolute_url() . '202-img/icons/ppc/' . $ppc_network_icon . '" width="16" height="16" alt="' . $ppc_network_name . '" title="' . $ppc_network_name . ': ' . $ppc_account_name . '"/>';

    return $html['ppc_network_icon'];
}

function runHourly($user_pref) {}

function runWeekly($user_pref) {}



function get_user_data_feedback($user_id)
{
    $database = DB::getInstance();
    $db = $database->getConnection();
    $mysql['user_id'] = $db->real_escape_string((string)$user_id);
    $sql = "SELECT user_email, user_time_register, p202_customer_api_key, install_hash, user_hash, modal_status, vip_perks_status FROM 202_users WHERE user_id='" . $mysql['user_id'] . "'";

    // Every signed-in page's header reads this: a failed read throws, and the
    // page's handler shows the recorded error page.
    $result = _mysqli_query($db, $sql);
    if ($result === false) {
        throw new \Prosper202\Database\Exceptions\QueryException(
            'MySQL query failed: ' . p202FailedQueryMessage($db->errno, $db->error) . ' [errno ' . $db->errno . ']'
        );
    }
    $row = p202FetchRow($result, $sql);

    if ($row === null) {
        return [
            'user_email' => null,
            'time_stamp' => null,
            'api_key' => null,
            'install_hash' => null,
            'user_hash' => null,
            'modal_status' => null,
            'vip_perks_status' => null
        ];
    }

    return [
        'user_email' => $row['user_email'],
        'time_stamp' => $row['user_time_register'],
        'api_key' => $row['p202_customer_api_key'],
        'install_hash' => $row['install_hash'],
        'user_hash' => $row['user_hash'],
        'modal_status' => $row['modal_status'],
        'vip_perks_status' => $row['vip_perks_status']
    ];
}

function clickserver_api_upgrade_url($key)
{

    // Initiate curl
    $ch = curl_init();
    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Set the url
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v1/auth/?apiKey=' . $key . '&clickserverId=' . base64_encode((string) $_SERVER['HTTP_HOST']));
    // Execute
    $result = curl_exec($ch);

    $data = json_decode($result, true);

    if ($data['isValidKey'] != 'true' || $data['isValidDomain'] != 'true') {
        curl_close($ch);
        return false;
    }

    $download_url = $data['downloadURL'];
    curl_close($ch);
    return $download_url;
}

/**
 * Whether a ClickServer API key is valid for this install, as
 * my.tracking202.com's auth answers: true, false, or null when no usable
 * answer came (an outage, an error page). A caller must not read null as
 * "invalid". The key and host are encoded into the query, the call is
 * bounded, and no redirect is followed.
 */
function clickserver_api_key_validate($key): ?bool
{
    $ch = curl_init();
    if ($ch === false) {
        return null;
    }
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v1/auth/?' . http_build_query([
        'apiKey' => (string) $key,
        'clickserverId' => base64_encode((string) ($_SERVER['HTTP_HOST'] ?? '')),
    ]));
    $result = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return clickserver_auth_answer(is_string($result) ? $result : null, $status);
}

/**
 * The auth service's answer read as clickserver_api_key_validate() reports
 * it. A key the service rejects is invalid whatever else the answer holds or
 * its status says; a verdict of valid counts only on a 2xx answer that holds
 * both verdicts, each one the service sends. Anything else (no body, a body
 * that is not the service's JSON, a failure status, a verdict missing or one
 * it does not send) is null: no answer.
 */
function clickserver_auth_answer(?string $body, int $status): ?bool
{
    $data = $body === null ? null : json_decode($body, true);
    if (!is_array($data)) {
        return null;
    }
    $verdict = static fn ($v): ?bool => match (true) {
        $v === true, $v === 'true' => true,
        $v === false, $v === 'false', $v === 0, $v === '0' => false,
        default => null,
    };
    if (array_key_exists('isValidKey', $data) && $verdict($data['isValidKey']) === false) {
        return false;
    }
    $answered = $status >= 200 && $status <= 299;
    if (!$answered || !array_key_exists('isValidKey', $data) || !array_key_exists('isValidDomain', $data)) {
        return null;
    }
    $key = $verdict($data['isValidKey']);
    $domain = $verdict($data['isValidDomain']);
    if ($key === null || $domain === null) {
        return null;
    }
    return $key && $domain;
}

/**
 * The licence service's raw answer for a key, as the installer and the
 * fetch-a-key page read it: the body, or false when no answer came, which
 * includes a body that is none of the service's own answers (a gateway's
 * error page). Asked through ClickServerKeyValidator::answer(), which
 * follows a redirect only to the service itself.
 */
function api_key_validate($key)
{
    $answer = \Prosper202\License\ClickServerKeyValidator::answer((string) $key);
    if ($answer === null || \Prosper202\License\ClickServerKeyValidator::interpret($answer[0], $answer[1]) === null) {
        return false;
    }
    return $answer[1];
}

function getBrowserIcon($name)
{
    $icon = match ($name) {
        'Chrome' => 'chrome',
        'Chrome Frame' => 'chrome',
        'Edge' => 'edge',
        'Android' => 'android',
        'Chrome Mobile' => 'chrome',
        'Chrome Mobile iOS' => 'chrome',
        'Firefox' => 'firefox',
        'IE' => 'ie',
        'Mobile Safari' => 'safari',
        'Safari' => 'safari',
        'Opera' => 'opera',
        'Opera Tablet' => 'opera',
        'Opera Mobile' => 'opera',
        'WebKit Nightly' => 'webkitnightly',
        default => 'other',
    };

    return $icon;
}

function getSurveyData($install_hash)
{

    // Initiate curl
    $ch = curl_init();
    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Set the url
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v1/deep/survey/' . $install_hash);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    // Execute
    $result = curl_exec($ch);
    // close connection
    curl_close($ch);

    $data = json_decode($result, true);

    return $data;
}

function updateSurveyData($install_hash, $post)
{
    $fields = http_build_query($post);

    // Initiate curl
    $ch = curl_init();
    // Set the url
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v1/deep/survey/' . $install_hash);
    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Set to post
    curl_setopt($ch, CURLOPT_POST, true);
    // Set post fields
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    // Execute
    $result = curl_exec($ch);

    $data = json_decode($result, true);

    // close connection
    curl_close($ch);

    return $data;
}


function rotator_data($query, $type)
{
    // Initiate curl
    $ch = curl_init();
    // Set the url
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v1/deep/rotator/' . $type . '/' . $query);
    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Bounded: Setup › Redirector asks this as the user types, and an
    // upstream that hangs must cost a missing suggestion, not a worker held
    // for the default connect timeout.
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    // Execute
    $result = curl_exec($ch);

    // close connection
    curl_close($ch);

    return $result;
}

function changelog(): array
{
    // Release notes are an object keyed by version, each entry { version, logs[] }.
    //
    // The build ships a bundled copy (202-config/changelogs.json) as a reliable,
    // offline floor, then merges the remote list on top. The merge matters for the
    // forward-looking screens (update-needed / 1-click upgrade) that run on the OLD
    // install before files are replaced: those need notes for a newer version that
    // this build's bundle cannot yet contain. The bundle guarantees the list is
    // never empty even when the remote endpoint is stale, slow, or offline.
    $entries = changelog_bundled();

    foreach (changelog_remote() as $version => $entry) {
        if (is_array($entry) && isset($entry['version'], $entry['logs']) && is_array($entry['logs'])) {
            $entries[(string) $version] = $entry;
        }
    }

    // The array key is the authoritative version. Keep each entry's 'version' field
    // in sync with it so the rendered release number is correct even when an
    // upstream source mislabels an entry (the remote list ships at least one).
    foreach ($entries as $version => $entry) {
        if (is_array($entry)) {
            $entry['version'] = (string) $version;
            $entries[$version] = $entry;
        }
    }

    // Newest first for display.
    uksort($entries, static fn($a, $b): int => version_compare((string) $b, (string) $a));

    return $entries;
}

function changelog_bundled(): array
{
    $changelog_file = __DIR__ . '/changelogs.json';

    if (!is_readable($changelog_file)) {
        return [];
    }

    $result = file_get_contents($changelog_file);
    if ($result === false) {
        return [];
    }

    $decoded = json_decode($result, true);
    // Fail safe with an empty list rather than returning null and triggering a
    // TypeError against the declared : array return type.
    return is_array($decoded) ? $decoded : [];
}

function changelog_remote(): array
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/clickserver/currentversion/paid/changelogs.php');
    // Disable SSL verification (legacy endpoint).
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Bounded timeouts so a slow/offline endpoint can never hang the upgrade screen.
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $result = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Best-effort only: any failure (transport, non-200, malformed JSON) yields an
    // empty list so the caller falls back to the bundled changelog.
    if ($result === false || $http_code !== 200) {
        return [];
    }

    $decoded = json_decode($result, true);
    return is_array($decoded) ? $decoded : [];
}

function changelogPremium(): array
{
    // Premium and paid share the same release notes. Premium call sites iterate the
    // result as version => [log, ...], so flatten the { version, logs[] } entries
    // that changelog() returns into that shape.
    $flat = [];
    foreach (changelog() as $version => $entry) {
        $flat[(string) $version] = $entry['logs'] ?? [];
    }

    return $flat;
}

function callAutoCron($endpoint)
{
    $domain = base64_encode(p202TrackingBaseUrl());

    // Initiate curl
    $ch = curl_init();
    // Set the url
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/autocron/' . $endpoint . '/' . $domain);
    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Bound the wait so a slow/unreachable service can't hang the caller (e.g. install)
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    // Execute
    $result = curl_exec($ch);

    // close connection
    curl_close($ch);

    return json_decode($result, true);
}

function registerDailyEmail($time, $timezone, $hash)
{
    $domain = base64_encode(rtrim(p202TrackingBaseUrl(), '/'));

    if ($time) {
        $date = new DateTime($time . ':00:00', new DateTimeZone($timezone));
        $date->setTimezone(new DateTimeZone('UTC'));
        $set_time = $date->format('H');
    } else {
        $set_time = 'NA';
    }

    // Initiate curl
    $ch = curl_init();
    // Set the url
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/' . $hash . '/' . $domain . '/' . $set_time);
    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Bound the wait so a slow/unreachable service can't hang the caller (e.g. install)
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    // Execute
    $result = curl_exec($ch);

    // close connection
    curl_close($ch);
}

function tagUserByNetwork($install_hash, $type, $network)
{
    $post = [];
    $post['network'] = $network;
    $fields = http_build_query($post);

    // Initiate curl
    $ch = curl_init();
    // Set the url
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/tag/user/' . $install_hash . '/' . $type);
    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Set to post
    curl_setopt($ch, CURLOPT_POST, true);
    // Set post fields
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    // Execute
    $result = curl_exec($ch);

    if (curl_errno($ch)) {
        echo 'error:' . curl_error($ch);
    }
    // close connection
    curl_close($ch);
}

function getDNIHost(): string
{
    return base64_encode(rtrim(p202TrackingBaseUrl(), '/'));
}

function getAllDniNetworks($install_hash)
{
    // Initiate curl
    $ch = curl_init();
    // Set the url
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/dni/' . $install_hash . '/networks/all');
    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Execute
    $result = curl_exec($ch);

    curl_close($ch);

    return json_decode($result, true);
}

function authDniNetworks($hash, $network, $key, $affId)
{
    $fields = [
        'api_key' => $key,
        'affiliate_id' => $affId,
        'host' => getDNIHost()
    ];
    $fields = http_build_query($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/dni/' . $hash . '/auth/' . $network);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    $result = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpcode !== 200) {
        return [
            'auth' => false
        ];
    } else {
        $json = json_decode($result, true);
        return [
            'auth' => true,
            'processed' => $json['processed'],
            'currency' => $json['currency']
        ];
    }
}

function getDniOffers($hash, $network, $key, $affId, $offset, $limit, $sort_by, $filter_by, $currency = '')
{
    $fields = [
        'api_key' => $key,
        'affiliate_id' => $affId,
        'currency' => $currency,
        'host' => getDNIHost(),
        'sort' => $sort_by,
        'filter' => $filter_by
    ];
    $fields = http_build_query($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/dni/' . $hash . '/offers/' . $network . '/all/' . $offset . '/' . $limit);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function getDniOfferById($hash, $network, $key, $affId, $id)
{
    $fields = [
        'api_key' => $key,
        'affiliate_id' => $affId,
        'host' => getDNIHost()
    ];
    $fields = http_build_query($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/dni/' . $hash . '/offers/' . $network . '/id/' . $id);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function requestDniOfferAccess($hash, $network, $key, $affId, $id, $type)
{
    $fields = [
        'api_key' => $key,
        'affiliate_id' => $affId,
        'host' => getDNIHost()
    ];
    $fields = http_build_query($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/dni/' . $hash . '/offers/' . $network . '/' . $type . '/' . $id);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function submitDniOfferAnswers($hash, $network, $api_key, $affId, $id, $answers)
{
    $fields = [
        'api_key' => $api_key,
        'affiliate_id' => $affId,
        'host' => getDNIHost(),
        'answers' => $answers
    ];
    $fields = http_build_query($fields);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/dni/' . $hash . '/offers/' . $network . '/answers/' . $id);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function setupDniOffer($hash, $network, $key, $affId, $currency, $id, $ddlci)
{
    $fields = [
        'api_key' => $key,
        'affiliate_id' => $affId,
        'currency' => $currency,
        'host' => getDNIHost(),
        'ddlci' => $ddlci
    ];
    $fields = http_build_query($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/dni/' . $hash . '/offers/' . $network . '/setup/' . $id);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function getDNICacheProgress($hash, $data)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/dni/' . $hash . '/cache/progress');
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $results = curl_exec($ch);
    curl_close($ch);
    return $results;
}

function setupDniOfferTrack($hash, $network, $key, $affId, $id, $ddlci = false)
{
    $fields = [
        'api_key' => $key,
        'affiliate_id' => $affId,
        'host' => getDNIHost(),
        'ddlci' => $ddlci
    ];

    $url = 'https://my.tracking202.com/api/v2/dni/' . $hash . '/offers/' . $network . '/setup/track/';

    if ($ddlci) {
        $url .= 'dl/';
    }

    $fields = http_build_query($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . $id);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function getForeignPayout($currency, $payout_currency, $payout)
{
    $fields = [
        'currency' => $currency,
        'payout_currency' => $payout_currency,
        'payout' => $payout
    ];

    $fields = http_build_query($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/get-foreign-payout');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $result = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($result, true);
    return $result;
}

function validateRevContentCredentials($id, $secret)
{
    $fields = [
        'id' => $id,
        'secret' => $secret
    ];

    $fields = http_build_query($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/premium-p202/validate-revcontent-credentials');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $result = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($result, true);
    return $result;
}

function pushToRevContent($id, $secret, $boost, $boost_id, array $ads)
{
    $fields = [
        'id' => $id,
        'secret' => $secret,
        'boost' => $boost,
        'boost_id' => $boost_id,
        'ads' => $ads
    ];

    $fields = http_build_query($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/premium-p202/push-revcontent');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $result = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($result, true);
    return $result;
}

function pushToFacebook($api_key, $group, $ad_set_id, array $ads)
{
    $fields = [
        'campaign_name' => $group,
        'ad_set_id' => $ad_set_id,
        'ads' => $ads
    ];

    $fields = http_build_query($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/premium-p202/facebook-ads/push-facebook/' . $api_key);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $result = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($result, true);
    return $result;
}

function getFacebookCampaignsAndAdSets($api_key)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/premium-p202/facebook-ads/get-ad-sets/' . $api_key);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $result = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($result, true);
    return $result;
}

function getData($url, int $timeout = 60, int $connectTimeout = 5)
{
    $timeout = max(1, $timeout);
    $connectTimeout = max(1, min($timeout, $connectTimeout));

    if (function_exists('curl_version')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    } else {
        if (ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'timeout' => $timeout,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);
            return @file_get_contents($url, false, $context);
        } else {
            return false;
        }
    }
}

/**
 * The help article for a page, or '' when there is none. A page renders its
 * own link from it.
 */
function showHelpUrl(string $page): string
{
    $url = '';
    switch ($page) {
        case 'step1':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=7158893&t202kw=";
            break;
        case 'step2':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=7158909&t202kw=";
            break;
        case 'step3':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=3158915&t202kw=";
            break;
        case 'step4':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=3158922&t202kw=";
            break;
        case 'step5':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=2158936&t202kw=";
            break;
        case 'step6':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=6158942&t202kw=";
            break;
        case 'step7':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=5158952&t202kw=";
            break;
        case 'slp':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=5158884&t202kw=";
            break;
        case 'alp':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=3158798&t202kw=";
            break;
        case 'step8':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=8158965&t202kw=";
            break;
        case 'step9':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=4158973&t202kw=";
            break;
        case 'overview':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=5158862&t202kw=";
            break;
        case 'groupoverview':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=4158853&t202kw=";
            break;
        case 'breakdown':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=3158819&t202kw=";
            break;
        case 'dayparting':
        case 'weekparting':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=1158832&t202kw=";
            break;
        case 'analyze':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=8158803&t202kw=";
            break;
        case 'visitor':
        case 'spy':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=1158987&t202kw=";
            break;
        case 'dni':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=3158846&t202kw=";
            break;
        case 'clickbank':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=4158829&t202kw=";
            break;
        case 'slack':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=1158876&t202kw=";
            break;
        case 'update':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=5158996&t202kw=";
            break;
    }

    return $url === '' ? '' : $url . 'helpdocs';
}

function createId($length)
{
    return bin2hex(random_bytes($length));
}

function createPublisherIds(): void
{
    if (function_exists('random_bytes')) {
        $sql = "SELECT user_id FROM 202_users WHERE TRIM(COALESCE(`user_public_publisher_id`, '')) = ''";
        $update_query = 'UPDATE';
        $user_result = _mysqli_query($sql);

        if ($user_result) { //loop if not empty
            //loop over all empty publisher ids and set them
            while ($user_row = $user_result->fetch_assoc()) {
                $query = "UPDATE `202_users` SET `user_public_publisher_id` = '" . createId(5) . "' WHERE `user_id` = '" . $user_row['user_id'] . "'";
                _mysqli_query($query);
            }
        }
    }
}

function getDashEmail(): string|bool
{

    global $db;
    //get the main users dash email and api key from the db
    $sql = "SELECT user_dash_email,p202_customer_api_key FROM 202_users WHERE user_id ='" . $_SESSION['user_id'] . "'";
    $user_result = _mysqli_query($sql);
    // Every signed-in page's footer reads this: a failed read (logged by
    // _mysqli_query()) leaves the address unknown rather than the page cut
    // off by a fatal.
    if (!$user_result instanceof \mysqli_result) {
        return false;
    }
    $user_row = $user_result->fetch_assoc();

    if (!is_array($user_row)) {
        return false;
    }

    //if found we are done return the email for usage
    if ($user_row['user_dash_email']) {
        return $user_row['user_dash_email'];
    } else {
        //email not found, try and set it from api call to the server
        if ($user_row['p202_customer_api_key']) {
            $dashEmail = getSetDashEmail($user_row['p202_customer_api_key']);
            if ($dashEmail['code'] == 200 && $dashEmail['email'] != '') {
                //found it save to DB and return
                $mysql['email'] = $db->real_escape_string($dashEmail['email']);
                $sql = "UPDATE `202_users` SET user_dash_email='" . $mysql['email'] . "' WHERE user_id ='" . $_SESSION['user_id'] . "'";
                _mysqli_query($sql);
                // The address, not its copy escaped for the statement above.
                return (string) $dashEmail['email'];
            }
        }
    }

    return false;
}

function getSetDashEmail($key)
{
    $fields = [
        'key' => $key
    ];

    $fields = http_build_query($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/get-customers-email');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $result = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($result, true);
    return $result;
}

function  upgrade_config()
{
    $odbname = '';
    $odbuser = '';
    $odbpass = '';


    //check to see if the sample config file exists
    if (!file_exists('202-config-sample.php')) {
        //_die('Sorry, I need a 202-config-sample.php file to work from. Please re-upload this file from your Prosper202 installation.');
        return;
    }


    //lets make a new config file
    $configFile = file('202-config-sample.php');


    //check to see if the directory is writable
    if (!is_writable(substr(__DIR__, 0, -10) . '/')) {
        // _die("Sorry your 202-config.php needs upgrading but I can't update it automatically because I can't write to the directory.<br>You'll have to either change the permissions on your Prosper202 directory or create your 202-config.php manually by copying from 202-config-sample.php.");
        return;
    }


    // Check if 202-config.php has been created
    if (file_exists('202-config.php')) {
        $re = '/(\$\w*) = \'(.*)\';/i';
        $handle = fopen('202-config.php', 'r');
        $old = [];
        while (!feof($handle)) {
            $line = fgets($handle);
            preg_match($re, $line, $matches);
            switch ($matches[1]) {
                case '$dbname':
                    $odbname = $matches[2];
                    echo $odbname;
                    break;
                case '$dbuser':
                    $odbuser = $matches[2];
                    echo $odbuser;
                    break;
                case '$dbpass':
                    $odbpass = $matches[2];
                    echo $odbpass;
                    break;
                case '$dbhost':
                    $odbhost = $matches[2];
                    break;
                case '$dbhostro':
                    $odbhostro = $matches[2];
                    break;
                case '$mchost':
                    $omchost = $matches[2];
                    break;
            }
        }
        fclose($handle);
    }


    $dbname  = trim($odbname);
    $dbuser   = trim($odbuser);
    $dbpass = trim($odbpass);

    if (isset($odbhost) && $odbhost != '') {
        $dbhost  = trim($odbhost);
    } else {
        $dbhost  = 'localhost';
    }
    if (isset($odbhostro) && $odbhostro != '') {
        $dbhostro  = trim($odbhostro);
    } else {
        $dbhostro  = $dbhost;
    }

    if (isset($omchost) && $omchost != '') {
        $mchost  = trim($omchost);
    } else {
        $mchost  = 'localhost';
    }

    //regex to find values in the config file
    $re = '/(\$\w*) = \'(\w*)\';/i';

    $handle = fopen('202-config.php', 'w');

    foreach ($configFile as $line) {
        preg_match($re, $line, $matches);
        match ($matches[1]) {
            '$dbname' => fwrite($handle, str_replace("putyourdbnamehere", $dbname, $line)),
            '$dbuser' => fwrite($handle, str_replace("'usernamehere'", "'$dbuser'", $line)),
            '$dbpass' => fwrite($handle, str_replace("'yourpasswordhere'", "'$dbpass'", $line)),
            '$dbhost' => fwrite($handle, str_replace("localhost", $dbhost, $line)),
            '$dbhostro' => fwrite($handle, str_replace("localhost", $dbhostro, $line)),
            '$mchost' => fwrite($handle, str_replace("localhost", $mchost, $line)),
            default => fwrite($handle, $line),
        };
    }
    fclose($handle);

    chmod('202-config.php', 0666);
}

function getSecureStatus(): bool
{
    // One answer for the whole install, the one the session cookie's Secure
    // flag is set from (202-config/request-https.php).
    require_once __DIR__ . '/request-https.php';
    return p202_request_is_https($_SERVER);
}

/**
 * Generate the inbound JavaScript loader snippet for landing page tracking.
 *
 * @param string $landing_page_id_public The public landing page ID
 * @return string The complete <script> tag for embedding on landing pages
 */
function generateTrackingLoaderSnippet(string $landing_page_id_public): string
{
    // Prosper202\Setup\LandingPageCode builds it, for the pages and the API.
    return \Prosper202\Setup\LandingPageCode::loader('//' . getTrackingDomain() . get_absolute_url(), $landing_page_id_public);
}

/**
 * Return the list of dynamic content segment definitions.
 * Each entry maps a t202 element name to its human-readable description.
 * This is the single source of truth used by both the help text renderer
 * and the tracking script's t202Data() function.
 *
 * @return array<string, string> Element name => description
 */
function getDynamicContentSegments(): array
{
    return \Prosper202\Setup\LandingPageCode::SEGMENTS;
}

/**
 * Render the Dynamic Content Segment help text shown on landing page code setup pages.
 * Generated from getDynamicContentSegments() so the help text stays in sync
 * with the actual elements supported by the tracking script.
 */
function renderDynamicContentSegmentHelp(): void
{
    $segments = getDynamicContentSegments();
    $listItems = '';
    foreach ($segments as $name => $description) {
        $listItems .= '<li>' . htmlspecialchars($description) . ' - <strong>' . htmlspecialchars($name) . '</strong></li>';
    }

    print('<br/><strong><small>Dynamic Content Segment</strong></small><br/>
		   <span class="infotext">Currently Dynamic Content Segments can dynamically display the following information on your landing pages:
		   <ul style="font-size: 12px;">' . $listItems . '</ul>
		   So how easy is it to display the visitor\'s country on your landing page? Here\'s the html for it:<br/>
		   <code>Welcome I see you are reading this from &lt;span name=&quot;t202Country&quot; t202Default=&apos;Your Country&apos;&gt;Your Country&lt;/span&gt;</code></span>');
}
