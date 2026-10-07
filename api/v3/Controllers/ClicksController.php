<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\AccountTimezone;
use Api\V3\Support\ReportFilter;
use Api\V3\Support\StatementHelpers;

class ClicksController
{
    use StatementHelpers;
    use AccountTimezone;

    /**
     * What a click is served with: its ids, and the values the Visitors page
     * (tracking202/ajax/click_history.php) shows for them — the campaign,
     * traffic source, landing page and text ad by name, the visitor's IP,
     * keyword, device and location, and the referrer, landing and outbound
     * URLs. The list and the single click share it, so the two cannot drift.
     * A name is joined only when the record is the click's own account's.
     */
    private const DETAIL_COLUMNS = '
                c.click_id, c.aff_campaign_id, c.ppc_account_id, c.landing_page_id,
                c.click_cpc, c.click_payout, c.click_lead, c.click_filtered,
                c.click_bot, c.click_alp, c.click_time, c.rotator_id, c.rule_id,
                cr.click_id_public, cr.click_cloaking, cr.click_in, cr.click_out,
                ca.text_ad_id, ca.keyword_id, ca.ip_id, ca.country_id, ca.region_id, ca.city_id,
                ca.platform_id, ca.browser_id, ca.device_id, ca.isp_id,
                ac.aff_campaign_name, pa.ppc_account_name, pn.ppc_network_name,
                lp.landing_page_nickname, ta.text_ad_name,
                ip.ip_address, kw.keyword,
                lc.country_name, lc.country_code, lr.region_name, lci.city_name, li.isp_name,
                p.platform_name, b.browser_name, dm.device_name, dt.type_name AS device_type,
                su_ref.site_url_address AS referer, su_lp.site_url_address AS landing,
                su_out.site_url_address AS outbound';

    private const DETAIL_JOINS = '
            LEFT JOIN 202_clicks_record cr ON c.click_id = cr.click_id
            LEFT JOIN 202_clicks_advance ca ON c.click_id = ca.click_id
            LEFT JOIN 202_clicks_site cs ON c.click_id = cs.click_id
            LEFT JOIN 202_aff_campaigns ac ON c.aff_campaign_id = ac.aff_campaign_id AND ac.user_id = c.user_id
            LEFT JOIN 202_ppc_accounts pa ON c.ppc_account_id = pa.ppc_account_id AND pa.user_id = c.user_id
            LEFT JOIN 202_ppc_networks pn ON pa.ppc_network_id = pn.ppc_network_id AND pn.user_id = c.user_id
            LEFT JOIN 202_landing_pages lp ON c.landing_page_id = lp.landing_page_id AND lp.user_id = c.user_id
            LEFT JOIN 202_text_ads ta ON ca.text_ad_id = ta.text_ad_id AND ta.user_id = c.user_id
            LEFT JOIN 202_ips ip ON ca.ip_id = ip.ip_id
            LEFT JOIN 202_keywords kw ON ca.keyword_id = kw.keyword_id
            LEFT JOIN 202_locations_country lc ON ca.country_id = lc.country_id
            LEFT JOIN 202_locations_region lr ON ca.region_id = lr.region_id
            LEFT JOIN 202_locations_city lci ON ca.city_id = lci.city_id
            LEFT JOIN 202_locations_isp li ON ca.isp_id = li.isp_id
            LEFT JOIN 202_platforms p ON ca.platform_id = p.platform_id
            LEFT JOIN 202_browsers b ON ca.browser_id = b.browser_id
            LEFT JOIN 202_device_models dm ON ca.device_id = dm.device_id
            LEFT JOIN 202_device_types dt ON dm.device_type = dt.type_id
            LEFT JOIN 202_site_urls su_ref ON cs.click_referer_site_url_id = su_ref.site_url_id
            LEFT JOIN 202_site_urls su_lp ON cs.click_landing_site_url_id = su_lp.site_url_id
            LEFT JOIN 202_site_urls su_out ON cs.click_outbound_site_url_id = su_out.site_url_id';

    /**
     * Values the visitor (or the visitor's browser) wrote: stripped of
     * control and bidi characters and capped before they are served.
     */
    private const VISITOR_FIELDS = [
        'keyword', 'ip_address', 'referer', 'landing', 'outbound',
        'country_name', 'region_name', 'city_name', 'isp_name',
        'platform_name', 'browser_name', 'device_name', 'device_type',
    ];

    /**
     * The Visitors page's filters, through the reports' one parser
     * (ReportFilter): its logical columns on a click row and the joins in
     * FILTER_JOINS. Every filter the Analyze pages have is served here.
     */
    private const FILTER_COLUMNS = [
        'click_time'                => 'c.click_time',
        'aff_campaign_id'           => 'c.aff_campaign_id',
        'aff_network_id'            => 'ac.aff_network_id',
        'ppc_account_id'            => 'c.ppc_account_id',
        'ppc_network_id'            => 'pa.ppc_network_id',
        'landing_page_id'           => 'c.landing_page_id',
        'country_id'                => 'ca.country_id',
        'text_ad_id'                => 'ca.text_ad_id',
        'region_id'                 => 'ca.region_id',
        'isp_id'                    => 'ca.isp_id',
        'browser_id'                => 'ca.browser_id',
        'platform_id'               => 'ca.platform_id',
        'device_id'                 => 'ca.device_id',
        'click_filtered'            => 'c.click_filtered',
        'click_bot'                 => 'c.click_bot',
        'click_lead'                => 'c.click_lead',
        'keyword_id'                => 'ca.keyword_id',
        'ip_id'                     => 'ca.ip_id',
        'click_referer_site_url_id' => 'cs.click_referer_site_url_id',
    ];

    /** What the count needs for the filters' columns (DETAIL_JOINS has them too). */
    private const FILTER_JOINS = '
            LEFT JOIN 202_clicks_advance ca ON c.click_id = ca.click_id
            LEFT JOIN 202_clicks_site cs ON c.click_id = cs.click_id
            LEFT JOIN 202_aff_campaigns ac ON c.aff_campaign_id = ac.aff_campaign_id AND ac.user_id = c.user_id
            LEFT JOIN 202_ppc_accounts pa ON c.ppc_account_id = pa.ppc_account_id AND pa.user_id = c.user_id';

    /** Parameters of the list beyond ReportFilter's window and filters. */
    private const LIST_PARAMS = ['limit', 'offset', 'click_lead', 'click_bot'];

    public const MAX_LIMIT = 500;

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    /**
     * Every parameter GET /clicks takes: the window, the Visitors page's
     * filters, paging, and the lead and bot switches.
     *
     * @return list<string>
     */
    public static function listParams(): array
    {
        return [...ReportFilter::params(self::FILTER_COLUMNS), ...self::LIST_PARAMS];
    }

    public function list(array $params): array
    {
        // A misspelt or malformed filter is refused by name, never ignored:
        // ignored, the list answers for every click while reading as the
        // filtered one (CLAUDE.md #4). The same for a limit or offset out of
        // range, which were clamped.
        ReportFilter::rejectUnknown($params, self::listParams());
        $limit = self::wholeNumber($params, 'limit', 50, 1, self::MAX_LIMIT);
        $offset = self::wholeNumber($params, 'offset', 0, 0, PHP_INT_MAX);

        $where = ['c.user_id = ?'];
        $binds = [$this->userId];
        $types = 'i';

        ReportFilter::apply($params, fn (): string => $this->accountTimezone(), $where, $binds, $types, self::FILTER_COLUMNS);

        foreach (['click_lead', 'click_bot'] as $flag) {
            if (array_key_exists($flag, $params)) {
                $value = $params[$flag];
                if ($value !== '0' && $value !== '1' && $value !== 0 && $value !== 1) {
                    throw new ValidationException('Invalid ' . $flag, [$flag => 'Must be 0 or 1']);
                }
                $where[] = "c.$flag = ?";
                $binds[] = (int) $value;
                $types .= 'i';
            }
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countSql = 'SELECT COUNT(*) as total FROM 202_clicks c ' . self::FILTER_JOINS . " $whereClause";
        $stmt = $this->prepare($countSql);
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'Count query failed');
        $total = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        $sql = 'SELECT ' . self::DETAIL_COLUMNS . '
            FROM 202_clicks c ' . self::DETAIL_JOINS . "
            $whereClause
            ORDER BY c.click_time DESC, c.click_id DESC
            LIMIT ? OFFSET ?";

        $binds[] = $limit;
        $types .= 'i';
        $binds[] = $offset;
        $types .= 'i';

        $stmt = $this->prepare($sql);
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'List query failed');
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            // Resolved names derive from the visitor (user agent, IP): strip
            // control/bidi characters and cap length before serving them.
            $rows[] = \Api\V3\Support\ResponseSanitizer::cleanRowFields($row, self::VISITOR_FIELDS);
        }
        $stmt->close();

        return [
            'data' => $rows,
            'pagination' => ['total' => $total, 'limit' => $limit, 'offset' => $offset],
        ];
    }

    /** A whole number from $min to $max, or the default when absent; anything else is refused. */
    private static function wholeNumber(array $params, string $name, int $default, int $min, int $max): int
    {
        if (!array_key_exists($name, $params) || $params[$name] === '') {
            return $default;
        }
        $value = $params[$name];
        $text = is_int($value) ? (string) $value : (is_string($value) ? $value : '');
        if (preg_match('/^[0-9]{1,18}$/D', $text) !== 1 || (int) $text < $min || (int) $text > $max) {
            $range = $max === PHP_INT_MAX ? "$min or more" : "$min to $max";
            throw new ValidationException('Invalid ' . $name, [$name => "A whole number, $range"]);
        }

        return (int) $text;
    }

    public function get(int $id): array
    {
        $sql = 'SELECT ' . self::DETAIL_COLUMNS . ',
                c.user_id, cr.click_reviewed, ct.c1_id, ct.c2_id, ct.c3_id, ct.c4_id,
                t1.c1, t2.c2, t3.c3, t4.c4
            FROM 202_clicks c ' . self::DETAIL_JOINS . '
            LEFT JOIN 202_clicks_tracking ct ON c.click_id = ct.click_id
            LEFT JOIN 202_tracking_c1 t1 ON ct.c1_id = t1.c1_id
            LEFT JOIN 202_tracking_c2 t2 ON ct.c2_id = t2.c2_id
            LEFT JOIN 202_tracking_c3 t3 ON ct.c3_id = t3.c3_id
            LEFT JOIN 202_tracking_c4 t4 ON ct.c4_id = t4.c4_id
            WHERE c.click_id = ? AND c.user_id = ?
            LIMIT 1';

        $stmt = $this->prepare($sql);
        $this->bind($stmt, 'ii', $id, $this->userId);
        $this->execute($stmt, 'Query failed');
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new NotFoundException('Click not found');
        }

        // Resolved names derive from the visitor (user agent, IP): strip
        // control/bidi characters and cap length before serving them.
        $row = \Api\V3\Support\ResponseSanitizer::cleanRowFields($row, [...self::VISITOR_FIELDS, 'c1', 'c2', 'c3', 'c4']);

        return ['data' => $row];
    }

    /**
     * GET /clicks/{id}/conversions: every conversion row of the click, with
     * whether it counts toward the click's value and why not when it does
     * not, and the click's value from its rows beside the figure the
     * reports show (ClickBreakdown). All rows, deleted and superseded ones
     * included, oldest first; a click's rows are few, so it is not paged.
     */
    public function conversions(int $id): array
    {
        $conn = new \Prosper202\Database\Connection($this->db);
        try {
            $breakdown = (new \Prosper202\Conversion\Ledger\ClickBreakdown($conn))->forClick($id, $this->userId);
        } catch (\Prosper202\Conversion\Ledger\LedgerIntegrityException $e) {
            // A row the ledger cannot read (an unknown source, a corrupt
            // amount) makes the click's value unexplainable: say which row,
            // never serve a breakdown that silently leaves it out.
            throw new \Api\V3\HttpException('The click\'s conversions cannot be explained: ' . $e->getMessage(), 500, $e);
        } catch (\Prosper202\Database\Exceptions\QueryException $e) {
            throw new DatabaseException('Reading the click\'s conversions failed', $e);
        }
        if ($breakdown === null) {
            throw new NotFoundException('Click not found');
        }

        return ['data' => $breakdown['rows'], 'click' => $breakdown['click']];
    }
}
