<?php

declare(strict_types=1);

// The report helpers GET /api/v1/reports/ and /api/v2/reports/ share, loaded
// by each version's functions.php. Declared here only: DuplicateGlobalFunctionTest.

/**
 * $a / $b times $scale, rounded; 0 when $b is zero or not a number. The
 * reports divided with @round($a / $b): since PHP 8 a zero divisor throws
 * DivisionByZeroError, which @ does not silence, so a row with no clicks
 * or no cost answered 500.
 */
function legacy_api_ratio(mixed $a, mixed $b, int $precision = 0, int|float $scale = 1): float
{
    $num = is_numeric($a) ? (float) $a : 0.0;
    $den = is_numeric($b) ? (float) $b : 0.0;

    return $den == 0.0 ? 0.0 : round($num / $den * $scale, $precision);
}

/**
 * One report for a key's user. The versions answer different sets of types:
 * v1 also answers the WordPress plugin's (wtkeywords, and the landing-page
 * feed and writes, whose handlers are api/v1/functions.php's), which v2 never
 * has.
 */
function runReports($db, $vars, $user, $timezone, string $version): array
{

    date_default_timezone_set($timezone);
    $c1 = null;
    $c2 = null;
    $c3 = null;
    $c4 = null;
    $cid = null;

    $report_types = match ($version) {
        'v1' => [
            'keywords', 'wtkeywords', 'text_ads', 'referers', 'ips', 'countries', 'cities', 'carriers', 'landing_pages',
            'get_data_for_wp', 'wp_create_lp', 'wp_update_lp',
        ],
        'v2' => ['keywords', 'text_ads', 'referers', 'ips', 'countries', 'cities', 'carriers', 'landing_pages'],
        default => throw new InvalidArgumentException("runReports(): no legacy API version '$version'"),
    };

    if (in_array($vars['type'], $report_types)) {
        if (isset($vars['c1'])) {
            $c1 = $vars['c1'];
        }

        if (isset($vars['c2'])) {
            $c2 = $vars['c2'];
        }

        if (isset($vars['c3'])) {
            $c3 = $vars['c3'];
        }

        if (isset($vars['c4'])) {
            $c4 = $vars['c4'];
        }


        // No cid, an empty one or 0 is every campaign. Anything else is one
        // of the account's campaigns by its id exactly: `> 0` let -1 through
        // as every campaign, and MySQL read 12abc as campaign 12.
        $cidSent = $vars['cid'] ?? null;
        $cidText = is_scalar($cidSent) ? (string) $cidSent : null;
        if ($cidSent !== null && $cidText !== '' && $cidText !== '0') {
            if ($cidText === null || !ctype_digit($cidText)) {
                $notFound = ['msg' => 'Campaign not found', 'error' => true, 'status' => 404];
                print_r(pretty_json(json_encode($notFound, JSON_UNESCAPED_SLASHES)));
                die();
            }
            if (getCampaignID($db, $cidText, $user)) {
                $cid = $cidText;
            }
        }

        // Both dates or neither: neither is today, and one alone is refused below.
        $dateFromSent = $vars['date_from'] ?? null;
        $dateToSent = $vars['date_to'] ?? null;
        if ($dateFromSent != null || $dateToSent != null) {
            if (!validateDate($dateFromSent) || !validateDate($dateToSent)) {
                $data = ['msg' => 'Wrong date format', 'error' => true, 'status' => 404];
                $json = json_encode($data, JSON_UNESCAPED_SLASHES);
                print_r(pretty_json($json));
                die();
            }

            $timestamps = getTimestamp($dateFromSent, $dateToSent);
            $date_from = $timestamps['from'];
            $date_to = $timestamps['to'];
        } else {
            $date_from = mktime(0, 0, 0, (int)date('m', time()), (int)date('d', time()), (int)date('Y', time()));
            $date_to = mktime(23, 59, 59, (int)date('m', time()), (int)date('d', time()), (int)date('Y', time()));
        }

        $window = [$user, $date_from, $date_to, $cid, $c1, $c2, $c3, $c4];
        switch ($vars['type']) {
            case 'keywords':
                return reportQuery($db, "keywords", "keyword_id", "keyword", ...$window);
            case 'wtkeywords':
                return reportQuery($db, "wtkeywords", "keyword_id", "keyword", ...$window);

            case 'text_ads':
                return reportQuery($db, "text_ads", "text_ad_id", "text_ad_name", ...$window);

            case 'referers':
                return reportQuery($db, "referers", "site_domain_id", "referer", ...$window);

            case 'ips':
                return reportQuery($db, "ips", "ip_id", "ip_address", ...$window);

            case 'countries':
                return reportQuery($db, "locations_country", "country_id", "country_name", ...$window);

            case 'cities':
                return reportQuery($db, "locations_city", "city_id", "city_name", ...$window);

            case 'carriers':
                return reportQuery($db, "locations_isp", "isp_id", "isp_name", ...$window);

            case 'landing_pages':
                return reportQuery($db, "landing_pages", "landing_page_id", "landing_page", ...$window);

            case 'get_data_for_wp':
                return getDataForWP($db, $user);

            case 'wp_create_lp':
                return wpCreateLp($db, $user);

            case 'wp_update_lp':
                return wpUpdateLp($db, $user);
            default:
                return [];
        }
    } else {
        return ['msg' => 'Not allowed report type', 'error' => true, 'status' => 404];
    }
}

/**
 * The table a report groups by, joined as `2l` on the click's id for it.
 * Each is written out: the name used to be built from the report type
 * (`202_{$type}`), so no reader could tell which tables a report reads. A
 * text ad is an account's own record, joined only within the click's account
 * (CLAUDE.md #27): a click naming another account's ad reads as naming none,
 * and is counted in the "[no text ad]" row.
 */
function reportDimensionJoin(string $type): string
{
    return match ($type) {
        'keywords', 'wtkeywords' => " LEFT OUTER JOIN 202_keywords AS 2l ON (2l.keyword_id = 2ca.keyword_id)",
        'text_ads' => " LEFT OUTER JOIN 202_text_ads AS 2l"
            . " ON (2l.text_ad_id = 2ca.text_ad_id AND 2l.user_id = 2c.user_id)",
        'ips' => " LEFT OUTER JOIN 202_ips AS 2l ON (2l.ip_id = 2ca.ip_id)",
        'locations_country' => " LEFT OUTER JOIN 202_locations_country AS 2l ON (2l.country_id = 2ca.country_id)",
        'locations_city' => " LEFT OUTER JOIN 202_locations_city AS 2l ON (2l.city_id = 2ca.city_id)",
        'locations_isp' => " LEFT OUTER JOIN 202_locations_isp AS 2l ON (2l.isp_id = 2ca.isp_id)",
        default => throw new InvalidArgumentException("reportQuery(): no report type '$type'"),
    };
}

/**
 * A report statement the server refused: logged for the operator, and
 * answered as the API's error envelope rather than an HTML error page, under
 * the status the envelope names, which a client reads before the body.
 *
 * @return array{msg: string, error: true, status: int}
 */
function legacy_api_database_error(mysqli $db): array
{
    error_log('Legacy report API: a report query failed: ' . $db->errno . ' ' . $db->error);
    if (!headers_sent()) {
        http_response_code(500);
    }

    return ['msg' => 'Database error', 'error' => true, 'status' => 500];
}

function reportQuery(
    $db,
    $type,
    $id,
    $name,
    $user,
    $date_from,
    $date_to,
    $cid = null,
    $c1 = null,
    $c2 = null,
    $c3 = null,
    $c4 = null
): array {

    $date = [
            'date_from' => date('m/d/Y', $date_from),
            'date_to' => date('m/d/Y', $date_to),
            'time_zone' => date_default_timezone_get()
    ];

    $data = [];
    $total_clicks = 0;
    $total_click_throughs = 0;
    $total_ctr_ratio = 0;
    $total_cost = 0.0;
    $total_avg_cpc = 0.0;
    $total_leads = 0;
    $total_su_ratio = 0.0;
    $total_payout = 0.0;
    $total_income = 0.0;
    $total_epc = 0.0;
    $total_net = 0.0;
    $total_roi = 0.0;

    // The timestamps are ints from mktime(), and cid and c1-c4 are null when
    // not asked for: under strict types real_escape_string() threw on each,
    // and every report request answered 500.
    $mysql['user_id'] = (int) $user;
    $select_id = $db->real_escape_string((string) $id);
    $mysql['date_from'] = (int) $date_from;
    $mysql['date_to'] = (int) $date_to;
    $mysql['aff_campaign_id'] = $db->real_escape_string(is_scalar($cid) ? (string) $cid : '');
    $mysql['c1'] = $db->real_escape_string(is_scalar($c1) ? (string) $c1 : '');
    $mysql['c2'] = $db->real_escape_string(is_scalar($c2) ? (string) $c2 : '');
    $mysql['c3'] = $db->real_escape_string(is_scalar($c3) ? (string) $c3 : '');
    $mysql['c4'] = $db->real_escape_string(is_scalar($c4) ? (string) $c4 : '');

    $report_sql = "SELECT *
				FROM   	202_clicks AS 2c
				LEFT OUTER JOIN 202_clicks_advance AS 2ca ON (2ca.click_id = 2c.click_id)";

    //If referers report type
    if ($type == "referers") {
        $report_sql .= "
						LEFT OUTER JOIN 202_clicks_site AS 2cs ON (2cs.click_id = 2c.click_id)
						LEFT OUTER JOIN 202_site_urls AS 2su ON (2cs.click_referer_site_url_id = 2su.site_url_id)
						LEFT OUTER JOIN 202_site_domains AS 2l ON (2l.site_domain_id = 2su.site_domain_id)";
        //If landing pages report type
    } elseif ($type == "landing_pages") {
        $report_sql .= " LEFT OUTER JOIN 202_clicks_site AS 2cs ON (2cs.click_id = 2c.click_id)"
            . " LEFT OUTER JOIN 202_landing_pages AS 2lp"
            . " ON (2lp.landing_page_id = 2c.landing_page_id AND 2lp.user_id = 2c.user_id)";
    } else {
        //If any other report type
        $report_sql .= reportDimensionJoin($type);
    }

    //If any of C1-C4 variables are set
    // A value is a filter even when it is "0"; only an empty one is none.
    if ($mysql['c1'] !== '' || $mysql['c2'] !== '' || $mysql['c3'] !== '' || $mysql['c4'] !== '') {
        $report_sql .= "LEFT OUTER JOIN 202_clicks_tracking AS 2cv ON (2cv.click_id = 2c.click_id)";

        if ($mysql['c1'] !== '') {
            $report_sql .= "LEFT OUTER JOIN 202_tracking_c1 AS 2c1 ON (2c1.c1_id = 2cv.c1_id)";
        }

        if ($mysql['c2'] !== '') {
            $report_sql .= "LEFT OUTER JOIN 202_tracking_c2 AS 2c2 ON (2c2.c2_id = 2cv.c2_id)";
        }

        if ($mysql['c3'] !== '') {
            $report_sql .= "LEFT OUTER JOIN 202_tracking_c3 AS 2c3 ON (2c3.c3_id = 2cv.c3_id)";
        }

        if ($mysql['c4'] !== '') {
            $report_sql .= "LEFT OUTER JOIN 202_tracking_c4 AS 2c4 ON (2c4.c4_id = 2cv.c4_id)";
        }
    }

    $report_sql .= " WHERE 2c.user_id='" . $mysql['user_id'] . "'"
        . " AND click_time > " . $mysql['date_from'] . " AND click_time < " . $mysql['date_to'] . "";

    //If C variables are set
    if ($mysql['c1'] !== '') {
        $report_sql .= " AND 2c1.c1='" . $mysql['c1'] . "'";
    }
    if ($mysql['c2'] !== '') {
        $report_sql .= " AND 2c2.c2='" . $mysql['c2'] . "'";
    }
    if ($mysql['c3'] !== '') {
        $report_sql .= " AND 2c3.c3='" . $mysql['c3'] . "'";
    }
    if ($mysql['c4'] !== '') {
        $report_sql .= " AND 2c4.c4='" . $mysql['c4'] . "'";
    }

    //If CID variable set
    if ($mysql['aff_campaign_id'] !== '') {
        $report_sql .= " AND 2c.aff_campaign_id='" . $mysql['aff_campaign_id'] . "'";
    }

    //If ISP/Carriers report type
    if ($type == "locations_isp") {
        $report_sql .= " AND 2ca.$select_id >= 1";
    }

    //WT hack for keyword report type
    if ($type == "wtkeywords") {
        $report_sql .= " AND 2l.keyword LIKE 'WT%'";
    }

    //If landing pages report type
    if ($type == "landing_pages") {
        $report_sql .= " GROUP BY 2c.landing_page_id";
    } else {
        $report_sql .= " GROUP BY 2l.$select_id";
    }

    $report_result = $db->query($report_sql);
    if ($report_result === false) {
        return legacy_api_database_error($db);
    }
    $rows = $report_result->num_rows;
    if ($rows > 0) {
        // A fetch that fails is false, which a bare loop reads as the last row.
        while (($report_row = $report_result->fetch_assoc()) !== null) {
            if ($report_row === false) {
                return legacy_api_database_error($db);
            }
            $click_sql = "SELECT 
							COUNT(*) AS clicks,
							AVG(2c.click_cpc) AS avg_cpc,
							SUM(2cr.click_out) AS click_throughs,
							SUM(2c.click_lead) AS leads,
							SUM(2c.click_payout*2c.click_lead) AS income
					   FROM
							202_clicks AS 2c
					   LEFT OUTER JOIN 202_clicks_advance AS 2ca ON (2ca.click_id = 2c.click_id)";

            //If referers report type
            if ($type == "referers") {
                $click_sql .= "
					   		LEFT OUTER JOIN 202_clicks_site AS 2cs ON (2cs.click_id = 2c.click_id)
							LEFT OUTER JOIN 202_site_urls AS 2su ON (2cs.click_referer_site_url_id=2su.site_url_id)
							LEFT OUTER JOIN 202_site_domains AS 2l ON (2l.site_domain_id = 2su.site_domain_id)";
            } elseif ($type != "landing_pages") {
                // A landing page row is counted by the click's own id
                // (below), with no join.
                $click_sql .= reportDimensionJoin($type);
            }

            //If any of C1-C4 variables are set
            // A value is a filter even when it is "0"; only an empty one is none.
            if ($mysql['c1'] !== '' || $mysql['c2'] !== '' || $mysql['c3'] !== '' || $mysql['c4'] !== '') {
                $click_sql .= "LEFT OUTER JOIN 202_clicks_tracking AS 2cv ON (2cv.click_id = 2c.click_id)";

                if ($mysql['c1'] !== '') {
                    $click_sql .= "LEFT OUTER JOIN 202_tracking_c1 AS 2c1 ON (2c1.c1_id = 2cv.c1_id)";
                }

                if ($mysql['c2'] !== '') {
                    $click_sql .= "LEFT OUTER JOIN 202_tracking_c2 AS 2c2 ON (2c2.c2_id = 2cv.c2_id)";
                }

                if ($mysql['c3'] !== '') {
                    $click_sql .= "LEFT OUTER JOIN 202_tracking_c3 AS 2c3 ON (2c3.c3_id = 2cv.c3_id)";
                }

                if ($mysql['c4'] !== '') {
                    $click_sql .= "LEFT OUTER JOIN 202_tracking_c4 AS 2c4 ON (2c4.c4_id = 2cv.c4_id)";
                }
            }

            //If any other
            $click_sql .= " LEFT OUTER JOIN 202_clicks_record AS 2cr ON (2cr.click_id = 2c.click_id)"
                . " WHERE 2c.user_id='" . $mysql['user_id'] . "'"
                . " AND click_time > '" . $mysql['date_from'] . "' AND click_time < '" . $mysql['date_to'] . "'";

            //If C variables are set
            if ($mysql['c1'] !== '') {
                $click_sql .= " AND 2c1.c1='" . $mysql['c1'] . "'";
            }
            if ($mysql['c2'] !== '') {
                $click_sql .= " AND 2c2.c2='" . $mysql['c2'] . "'";
            }
            if ($mysql['c3'] !== '') {
                $click_sql .= " AND 2c3.c3='" . $mysql['c3'] . "'";
            }
            if ($mysql['c4'] !== '') {
                $click_sql .= " AND 2c4.c4='" . $mysql['c4'] . "'";
            }

            //If CID variable is set
            if ($mysql['aff_campaign_id'] !== '') {
                $click_sql .= " AND 2c.aff_campaign_id='" . $mysql['aff_campaign_id'] . "'";
            }

            //If referers report type
            if ($type == "referers") {
                $click_sql .= "AND 2l." . $select_id . "='" . $report_row[$select_id] . "'";
                //If landing pages report type
            } elseif ($type == "landing_pages") {
                $click_sql .= "AND 2c." . $select_id . "='" . $report_row[$select_id] . "'
					   				  GROUP BY 2c." . $select_id;
            } elseif ($type == "text_ads" && $report_row[$select_id] === null) {
                // The "[no text ad]" row: no ad, or one that is not this
                // account's (the join reads it as none) -- every click
                // the report grouped there, not only those with id 0.
                $click_sql .= "AND 2l.text_ad_id IS NULL";
            } else {
                $click_sql .= "AND 2ca." . $select_id . "='" . $report_row[$select_id] . "'";
            }

            $click_result = $db->query($click_sql);
            if ($click_result === false) {
                return legacy_api_database_error($db);
            }
            $click_row = $click_result->fetch_assoc();
            if (!is_array($click_row)) {
                // COUNT(*) always answers a row: none is a fetch that failed.
                return legacy_api_database_error($db);
            }
            $country_code = '';

            //get the stats
            $clicks = 0;
            $clicks = $click_row['clicks'];

            $total_clicks += $clicks;

            $click_throughs = 0;
            $click_throughs = $click_row['click_throughs'];

            $total_click_throughs += $click_throughs;

            //ctr rate
            $ctr_ratio = 0;
            $ctr_ratio = legacy_api_ratio($click_throughs, $clicks, 2, 100);

            $total_ctr_ratio = legacy_api_ratio($total_click_throughs, $total_clicks, 2, 100);

            //avg cpc and cost
            $avg_cpc = 0;
            $avg_cpc = $click_row['avg_cpc'];

            $cost = 0;
            $cost = $clicks * $avg_cpc;

            $total_cost += $cost;
            $total_avg_cpc = legacy_api_ratio($total_cost, $total_clicks, 5);

            //leads
            $leads = 0;
            $leads = $click_row['leads'];

            $total_leads += $leads;

            //signup ratio
            $su_ratio = 0;
            $su_ratio = legacy_api_ratio($leads, $clicks, 2, 100);

            $total_su_ratio = legacy_api_ratio($total_leads, $total_clicks, 2, 100);

            //current payout
            $payout = 0;
            $payout = $report_row['click_payout'];
            $total_payout += $payout;

            //income
            $income = 0;
            $income = $click_row['income'];

            $total_income += $income;
            //grab the EPC
            $epc = 0;
            $epc = legacy_api_ratio($income, $clicks, 2);

            $total_epc = legacy_api_ratio($total_income, $total_clicks, 2);

            //net income
            $net = 0;
            $net = $income - $cost;

            $total_net = $total_income - $total_cost;

            //roi
            $roi = 0;
            $roi = legacy_api_ratio($net, $cost, 0, 100);

            // a percentage, as each row's is
            $total_roi = legacy_api_ratio($total_net, $total_cost, 0, 100);

            if ($name == "keyword") {
                if (!$report_row['keyword']) {
                    $report_row[$name] = "[no keyword]";
                }
            }

            if ($name == "text_ad_name") {
                if (!$report_row['text_ad_name']) {
                    $report_row[$name] = "[no text ad]";
                }
            }

            if ($name == "referer") {
                if (!$report_row['site_domain_host']) {
                    $report_row[$name] = "[no referer]";
                } else {
                    $report_row[$name] = $report_row['site_domain_host'];
                }
            }

            if ($type == "landing_pages") {
                if (!$report_row['landing_page_nickname']) {
                    $report_row[$name] = "[direct link]";
                } else {
                    $report_row[$name] = $report_row['landing_page_nickname'];
                }
            }

            $data[] = [
                $name => $report_row[$name],
                "clicks" => $clicks,
                "click_throughs" => $click_throughs,
                "lp_ctr" => $ctr_ratio . "%",
                "leads" => $leads,
                "su_ratio" => $su_ratio . "%",
                "payout" => legacy_api_dollar_format($payout),
                "epc" => legacy_api_dollar_format($epc),
                "avg_cpc" => "$" . $avg_cpc,
                "income" => legacy_api_dollar_format($income),
                "cost" => legacy_api_dollar_format($cost),
                "net" => legacy_api_dollar_format($net),
                "roi" => $roi . "%"
            ];
        }

        $totals = [
            "clicks" => $total_clicks,
            "click_throughs" => $total_click_throughs,
            "lp_ctr" => $total_ctr_ratio . "%",
            "leads" => $total_leads,
            "su_ratio" => $total_su_ratio . "%",
            "payout" => legacy_api_dollar_format($total_payout / $rows),
            "epc" => legacy_api_dollar_format($total_epc),
            "avg_cpc" => legacy_api_dollar_format($total_avg_cpc),
            "income" => legacy_api_dollar_format($total_income),
            "cost" => legacy_api_dollar_format($total_cost),
            "net" => legacy_api_dollar_format($total_net),
            "roi" => $total_roi . "%"
        ];
    } else {
        $totals = [];
    }
    // Named as the report was asked for, here and not in the row loop:
    // every row's statement reads $type as the table to join.
    $key = match ($type) {
        'locations_country' => 'countries',
        'locations_city' => 'cities',
        'locations_isp' => 'carriers',
        'wtkeywords' => 'keywords',
        default => $type,
    };

    return ["date_range" => $date, $key => $data, "totals" => $totals];
}

function getCampaignID($db, $campaign, $user): bool
{
    // v2 hands the user id as an int, and cid can arrive as a list (cid[]=1).
    $mysql['user_id'] = $db->real_escape_string((string) $user);
    $mysql['campaign_id'] = $db->real_escape_string(is_scalar($campaign) ? (string) $campaign : '');
    $key_sql = "SELECT 	*
				FROM   	`202_aff_campaigns` 
				WHERE  	`user_id`='" . $mysql['user_id'] . "' AND `aff_campaign_id`='" . $mysql['campaign_id'] . "'";
    $key_result = _mysqli_query($db, $key_sql);
    if ($key_result === false) {
        print_r(pretty_json(json_encode(legacy_api_database_error($db), JSON_UNESCAPED_SLASHES)));
        die();
    }

    if ($key_result->num_rows > 0) {
        return true;
    } else {
        $json = json_encode(['msg' => 'Campaign not found', 'error' => true, 'status' => 404], JSON_UNESCAPED_SLASHES);
        print_r(pretty_json($json));
        die();
    }
}

function validateDate($date, $format = 'm/d/Y'): bool
{
    // A date not sent (date_from without date_to) or sent as a list.
    if (!is_string($date)) {
        return false;
    }
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) == $date;
}

function getTimestamp($datefrom, $dateto): array
{
    $date = [];

    $from = explode('/', (string) $datefrom);
    $from_month = trim($from[0]);
    $from_day = trim($from[1]);
    $from_year = trim($from[2]);

    $date_from = mktime(0, 0, 0, (int)$from_month, (int)$from_day, (int)$from_year);

    $to = explode('/', (string) $dateto);
    $to_month = trim($to[0]);
    $to_day = trim($to[1]);
    $to_year = trim($to[2]);

    $date_to = mktime(23, 59, 59, (int)$to_month, (int)$to_day, (int)$to_year);

    $date['from'] = $date_from;
    $date['to'] = $date_to;

    return $date;
}

function pretty_json($json): string
{

    $result      = '';
    $pos         = 0;
    $strLen      = strlen((string) $json);
    $indentStr   = '  ';
    $newLine     = "\n";
    $prevChar    = '';
    $outOfQuotes = true;

    for ($i = 0; $i <= $strLen; $i++) {
        // Grab the next character in the string.
        $char = substr((string) $json, $i, 1);

        // Are we inside a quoted string?
        if ($char == '"' && $prevChar != '\\') {
            $outOfQuotes = !$outOfQuotes;

            // If this character is the end of an element,
            // output a new line and indent the next line.
        } elseif (($char == '}' || $char == ']') && $outOfQuotes) {
            $result .= $newLine;
            $pos--;
            for ($j = 0; $j < $pos; $j++) {
                $result .= $indentStr;
            }
        }

        // Add the character to the result string.
        $result .= $char;

        // If the last character was the beginning of an element,
        // output a new line and indent the next line.
        if (($char == ',' || $char == '{' || $char == '[') && $outOfQuotes) {
            $result .= $newLine;
            if ($char == '{' || $char == '[') {
                $pos++;
            }

            for ($j = 0; $j < $pos; $j++) {
                $result .= $indentStr;
            }
        }

        $prevChar = $char;
    }

    return $result;
}

// Not dollar_format(): that is functions-tracking202.php's, which takes a
// currency second and formats in the account's symbol.
function legacy_api_dollar_format($amount, $cpv = false): string
{
    if ($cpv == true) {
        $decimals = 5;
    } else {
        $decimals = 2;
    }

    if ($amount >= 0) {
        $new_amount = "\$" . sprintf("%." . $decimals . "f", $amount);
    } else {
        $new_amount = "\$" . sprintf("%." . $decimals . "f", substr((string) $amount, 1, strlen((string) $amount)));
        $new_amount = '(' . $new_amount . ')';
    }

    return $new_amount;
}
