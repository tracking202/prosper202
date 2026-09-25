<?php

declare(strict_types=1);

namespace Tracking202\Report;

use mysqli;
use Prosper202\Database\Connection;
use RuntimeException;

/**
 * The classic reports' stored filters (`202_users_pref`), and the option
 * lists a v2 report's filter bar offers.
 *
 * Every report and every *_download.php reads its filters from that row, so a
 * v2 report that takes its filters from the URL writes them here before it
 * queries: what the URL says is then what the report, its download and the
 * classic pages that still share the row all see.
 *
 * Every query's return is checked (error pattern #1): a failed read is an
 * exception, never an empty list that reads as "nothing to choose from".
 */
final class ReportPrefsStore
{
    private readonly Connection $conn;

    public function __construct(private readonly mysqli $db)
    {
        // Every statement goes through Connection: checked execute, a
        // get_result() false that throws, reference-safe binds.
        $this->conn = new Connection($db);
    }

    /**
     * The user's report preferences as this request draws them: the stored
     * row, with the request's view laid over it when one is installed
     * (ReportView).
     *
     * @return array<string, mixed>
     */
    public function load(int $userId): array
    {
        return \Prosper202\DataEngine\ReportView::apply($this->loadStored($userId), $userId);
    }

    /**
     * The row as stored, whatever view this request draws: what save() reads
     * back to prove its write.
     *
     * @return array<string, mixed>
     */
    private function loadStored(int $userId): array
    {
        $rows = $this->query('SELECT * FROM 202_users_pref WHERE user_id = ?', $userId);
        return $rows[0] ?? [];
    }

    /**
     * The stored filters as the URL names them: what the report is showing
     * when the URL has not spoken.
     *
     * A stored value is shown as what it does, not as what it says. The
     * classic calendar saved "Landing page" as `landingpages`, which the
     * report filter (UserPrefFilters) does not recognise — it only filters on
     * `landingpage` — so that stored value filters nothing, and is shown as
     * "Any" rather than as a filter the report is not applying.
     *
     * @param array<string, mixed> $row  from load()
     * @param list<string> $offered
     * @return array<string, string>
     */
    public static function valuesFromRow(array $row, array $offered): array
    {
        $values = [];
        foreach ($offered as $field) {
            $column = ReportFilterInput::COLUMNS[$field];
            $raw = trim((string) ($row[$column] ?? ''));
            if (in_array($field, ReportFilterInput::ID_FIELDS, true) && ($raw === '0' || $raw === '')) {
                $raw = '';
            }
            if (isset(ReportFilterInput::CHOICES[$field])) {
                [$allowed, $default] = ReportFilterInput::CHOICES[$field];
                if (!in_array($raw, $allowed, true)) {
                    $raw = $default;
                }
            }
            $values[$field] = $raw;
        }
        return $values;
    }

    /**
     * Store a report's filters and window, and prove they were stored.
     *
     * The session runs with `sql_mode=''` (connect.php), under which MySQL
     * does not refuse a value that does not fit its column: it clamps it and
     * carries on. Several of these columns are narrow — the region and ISP
     * columns are TINYINT, so any region id above 255 would be stored as 255
     * and the report would quietly filter by a different region. So the
     * write happens in a transaction, the row is read back, and anything that
     * did not come back as written rolls the whole write back and is named.
     *
     * @param array<string, string> $values  checked values, URL name => value
     * @param array{range: string, from: ?array{int,int,int}, to: ?array{int,int,int}}|null $window
     *   null keeps the stored window
     * @return array<string, string>  URL name => why it could not be stored;
     *   empty when everything was stored
     */
    public function save(int $userId, array $values, ?array $window): array
    {
        $set = [];
        foreach ($values as $field => $value) {
            $column = ReportFilterInput::COLUMNS[$field] ?? null;
            if ($column === null) {
                throw new \InvalidArgumentException("ReportPrefsStore: '$field' is not a stored report filter");
            }
            $set[$column] = $value === '' && !isset(ReportFilterInput::CHOICES[$field]) ? null : $value;
        }
        if ($window !== null) {
            if ($window['range'] === ReportFilterInput::RANGE_CUSTOM) {
                [$fy, $fm, $fd] = $window['from'];
                [$ty, $tm, $td] = $window['to'];
                // In the user's timezone, which the page set before calling,
                // exactly as set_user_prefs.php stores a custom window.
                $set['user_pref_time_predefined'] = '';
                $set['user_pref_time_from'] = (string) mktime(0, 0, 0, $fm, $fd, $fy);
                $set['user_pref_time_to'] = (string) mktime(23, 59, 59, $tm, $td, $ty);
            } else {
                $set['user_pref_time_predefined'] = $window['range'];
                $set['user_pref_time_from'] = null;
                $set['user_pref_time_to'] = null;
            }
        }
        if ($set === []) {
            return [];
        }

        $assignments = implode(', ', array_map(static fn (string $c): string => "`$c` = ?", array_keys($set)));
        if (!$this->db->begin_transaction()) {
            throw new RuntimeException('Could not start saving the report filters: ' . $this->db->error);
        }
        try {
            $stmt = $this->conn->prepareWrite("UPDATE 202_users_pref SET $assignments WHERE user_id = ?");
            $params = array_values($set);
            $params[] = (string) $userId;
            $this->conn->bind($stmt, str_repeat('s', count($params)), $params);
            $this->conn->executeUpdate($stmt);

            $stored = $this->loadStored($userId);
            if ($stored === []) {
                throw new RuntimeException('There is no preferences row for this user to save the report filters in.');
            }
            $refused = [];
            foreach ($set as $column => $wanted) {
                $got = $stored[$column] ?? null;
                if ((string) ($got ?? '') !== (string) ($wanted ?? '')) {
                    $field = array_search($column, ReportFilterInput::COLUMNS, true);
                    $refused[$field === false ? 'range' : (string) $field] = $wanted;
                }
            }
            if ($refused !== []) {
                if (!$this->db->rollback()) {
                    throw new RuntimeException('Could not undo a report filter that did not fit: ' . $this->db->error);
                }
                $sentences = [];
                foreach ($refused as $field => $wanted) {
                    $sentences[$field] = "This value ($wanted) does not fit the column the saved filter is kept in, so it was not applied.";
                }
                return $sentences;
            }
            if (!$this->db->commit()) {
                throw new RuntimeException('Could not save the report filters: ' . $this->db->error);
            }
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        return [];
    }

    /**
     * The option lists the filter bar's menus need, from this user's own
     * setup. Deleted entries are left out, as the classic menus left them
     * out; a stored value that points at one is still shown, by the partial,
     * as "not in your list".
     *
     * @return array<string, array<string|int, string|array<string|int, string>>>
     */
    public function lists(int $userId): array
    {
        $lists = [];

        $lists['ppc_network_id'] = [ReportFilterInput::NO_TRAFFIC_SOURCE => 'No traffic source'];
        foreach ($this->query(
            'SELECT ppc_network_id, ppc_network_name FROM 202_ppc_networks WHERE user_id = ? AND ppc_network_deleted = 0 ORDER BY ppc_network_name',
            $userId
        ) as $row) {
            $lists['ppc_network_id'][(string) $row['ppc_network_id']] = (string) $row['ppc_network_name'];
        }

        $lists['ppc_account_id'] = [];
        foreach ($this->query(
            'SELECT a.ppc_account_id, a.ppc_account_name, n.ppc_network_name FROM 202_ppc_accounts a'
            . ' JOIN 202_ppc_networks n ON n.ppc_network_id = a.ppc_network_id'
            . ' WHERE a.user_id = ? AND a.ppc_account_deleted = 0 AND n.ppc_network_deleted = 0'
            . ' ORDER BY n.ppc_network_name, a.ppc_account_name',
            $userId
        ) as $row) {
            $lists['ppc_account_id'][(string) $row['ppc_network_name']][(string) $row['ppc_account_id']] = (string) $row['ppc_account_name'];
        }

        $lists['aff_network_id'] = [];
        foreach ($this->query(
            'SELECT aff_network_id, aff_network_name FROM 202_aff_networks WHERE user_id = ? AND aff_network_deleted = 0 ORDER BY aff_network_name',
            $userId
        ) as $row) {
            $lists['aff_network_id'][(string) $row['aff_network_id']] = (string) $row['aff_network_name'];
        }

        // One <optgroup>ed menu in place of the classic category-then-campaign
        // pair (UI standard, "One replacement per legacy job").
        $lists['aff_campaign_id'] = [];
        foreach ($this->query(
            'SELECT c.aff_campaign_id, c.aff_campaign_name, n.aff_network_name FROM 202_aff_campaigns c'
            . ' JOIN 202_aff_networks n ON n.aff_network_id = c.aff_network_id'
            . ' WHERE c.user_id = ? AND c.aff_campaign_deleted = 0 AND n.aff_network_deleted = 0'
            . ' ORDER BY n.aff_network_name, c.aff_campaign_name',
            $userId
        ) as $row) {
            $lists['aff_campaign_id'][(string) $row['aff_network_name']][(string) $row['aff_campaign_id']] = (string) $row['aff_campaign_name'];
        }

        // Simple landing pages under their campaign, advanced ones (which
        // belong to no single campaign) in a group of their own — the two
        // halves of the classic refine menu's UNION.
        $lists['landing_page_id'] = [];
        foreach ($this->query(
            'SELECT lp.landing_page_id, lp.landing_page_nickname, lp.landing_page_type, c.aff_campaign_name FROM 202_landing_pages lp'
            . ' LEFT JOIN 202_aff_campaigns c ON c.aff_campaign_id = lp.aff_campaign_id'
            . ' WHERE lp.user_id = ? AND lp.landing_page_deleted = 0'
            . ' ORDER BY lp.landing_page_type, c.aff_campaign_name, lp.landing_page_nickname',
            $userId
        ) as $row) {
            $group = (int) $row['landing_page_type'] === 1
                ? 'Advanced landing pages'
                : (string) ($row['aff_campaign_name'] ?? 'No campaign');
            $lists['landing_page_id'][$group][(string) $row['landing_page_id']] = (string) $row['landing_page_nickname'];
        }

        $lists['text_ad_id'] = [];
        foreach ($this->query(
            'SELECT t.text_ad_id, t.text_ad_name, c.aff_campaign_name FROM 202_text_ads t'
            . ' LEFT JOIN 202_aff_campaigns c ON c.aff_campaign_id = t.aff_campaign_id'
            . ' WHERE t.user_id = ? AND t.text_ad_deleted = 0'
            . ' ORDER BY c.aff_campaign_name, t.text_ad_name',
            $userId
        ) as $row) {
            $lists['text_ad_id'][(string) ($row['aff_campaign_name'] ?? 'No campaign')][(string) $row['text_ad_id']] = (string) $row['text_ad_name'];
        }

        // Install-wide but bounded: a few hundred countries, the four device
        // types, the browsers and platforms the user-agent parser names. The
        // classic menus grouped by name, keeping one id per name; so do these.
        foreach ([
            'country_id' => 'SELECT MIN(country_id) AS id, country_name AS name FROM 202_locations_country GROUP BY country_name ORDER BY country_name',
            'device_id' => 'SELECT type_id AS id, type_name AS name FROM 202_device_types ORDER BY type_name',
            'browser_id' => 'SELECT MIN(browser_id) AS id, browser_name AS name FROM 202_browsers GROUP BY browser_name ORDER BY browser_name',
            'platform_id' => 'SELECT MIN(platform_id) AS id, platform_name AS name FROM 202_platforms GROUP BY platform_name ORDER BY platform_name',
        ] as $field => $sql) {
            $lists[$field] = [];
            foreach ($this->query($sql) as $row) {
                $lists[$field][(string) $row['id']] = (string) $row['name'];
            }
        }

        return $lists;
    }

    /**
     * Regions and ISPs are install-wide and unbounded — too long for any
     * menu — so the filter is a typed name with suggestions, and these are
     * the suggestions: the names this account's clicks in the window carry,
     * busiest first. A region's country code rides along, because region
     * names repeat across countries.
     *
     * @param 'region'|'isp' $kind
     * @param ?int $dataUserId  whose clicks (null: every user's, which is
     *   what DataEngine reports for an account that sees all campaigns)
     * @return list<string>
     */
    public function suggestions(string $kind, ?int $dataUserId, int $from, int $to, int $limit = 300): array
    {
        [$select, $join, $group] = $this->namedShape($kind);
        $scope = $dataUserId === null ? '2st.user_id != 0' : '2st.user_id = ' . $dataUserId;
        $sql = "SELECT $select FROM 202_dataengine 2st $join"
            . " WHERE $scope AND 2st.click_time >= ? AND 2st.click_time <= ?"
            . " GROUP BY $group ORDER BY SUM(2st.clicks) DESC LIMIT " . max(1, $limit);
        $out = [];
        foreach ($this->query($sql, $from, $to) as $row) {
            $out[] = self::label($kind, $row);
        }
        return array_values(array_unique($out));
    }

    /**
     * The name a stored region or ISP id is shown as in its field.
     *
     * @param 'region'|'isp' $kind
     */
    public function nameOf(string $kind, string $id): string
    {
        if ($id === '') {
            return '';
        }
        [$select, $join] = $this->namedShape($kind);
        $idColumn = $kind === 'region' ? 'r.region_id' : 'i.isp_id';
        $sql = "SELECT $select FROM " . ($kind === 'region' ? '202_locations_region r' : '202_locations_isp i')
            . ($kind === 'region' ? ' LEFT JOIN 202_locations_country c ON c.country_id = r.main_country_id' : '')
            . " WHERE $idColumn = ?";
        $rows = $this->query($sql, (int) $id);
        return $rows === [] ? $id : self::label($kind, $rows[0]);
    }

    /**
     * The id a typed region or ISP name means.
     *
     * A region may be typed as the suggestions show it, "Name (CC)", or as a
     * bare name. When several ids answer — a name several countries use, or
     * the same name listed twice — the one this account's clicks in the
     * window use most is taken if the country settles it; a bare name that
     * spans countries is refused with the ones to choose from, rather than
     * silently filtering by one of them.
     *
     * @param 'region'|'isp' $kind
     * @return array{id: ?string, error: ?string}
     */
    public function resolveName(string $kind, string $typed, ?int $dataUserId, int $from, int $to): array
    {
        $typed = trim($typed);
        $name = $typed;
        $country = null;
        if ($kind === 'region' && preg_match('/^(.*\S)\s*\(([A-Za-z]{2})\)$/', $typed, $m) === 1) {
            $name = $m[1];
            $country = strtoupper($m[2]);
        }

        if ($kind === 'region') {
            $sql = 'SELECT r.region_id AS id, r.region_name, c.country_code,'
                . ' (SELECT COALESCE(SUM(2st.clicks), 0) FROM 202_dataengine 2st WHERE 2st.region_id = r.region_id AND '
                . ($dataUserId === null ? '2st.user_id != 0' : '2st.user_id = ' . $dataUserId)
                . ' AND 2st.click_time >= ? AND 2st.click_time <= ?) AS used'
                . ' FROM 202_locations_region r LEFT JOIN 202_locations_country c ON c.country_id = r.main_country_id'
                . ' WHERE r.region_name = ?' . ($country !== null ? ' AND c.country_code = ?' : '')
                . ' ORDER BY used DESC, r.region_id';
            $rows = $country !== null
                ? $this->query($sql, $from, $to, $name, $country)
                : $this->query($sql, $from, $to, $name);
        } else {
            $sql = 'SELECT i.isp_id AS id,'
                . ' (SELECT COALESCE(SUM(2st.clicks), 0) FROM 202_dataengine 2st WHERE 2st.isp_id = i.isp_id AND '
                . ($dataUserId === null ? '2st.user_id != 0' : '2st.user_id = ' . $dataUserId)
                . ' AND 2st.click_time >= ? AND 2st.click_time <= ?) AS used'
                . ' FROM 202_locations_isp i WHERE i.isp_name = ? ORDER BY used DESC, i.isp_id';
            $rows = $this->query($sql, $from, $to, $name);
        }

        $what = $kind === 'region' ? 'region' : 'ISP';
        if ($rows === []) {
            return ['id' => null, 'error' => "There is no $what called “{$typed}”. Choose one of the suggestions."];
        }
        if ($kind === 'region' && $country === null) {
            $countries = array_values(array_unique(array_map(static fn (array $r): string => (string) ($r['country_code'] ?? ''), $rows)));
            if (count($countries) > 1) {
                $choices = implode(', ', array_map(static fn (string $cc): string => "$name ($cc)", $countries));
                return ['id' => null, 'error' => "More than one country has a region called “{$name}”: choose $choices."];
            }
        }
        return ['id' => (string) $rows[0]['id'], 'error' => null];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function label(string $kind, array $row): string
    {
        if ($kind === 'region') {
            $code = strtoupper(trim((string) ($row['country_code'] ?? '')));
            return (string) $row['region_name'] . ($code !== '' ? " ($code)" : '');
        }
        return (string) $row['isp_name'];
    }

    /**
     * @return array{string, string, string} select, join, group by
     */
    private function namedShape(string $kind): array
    {
        return match ($kind) {
            'region' => [
                'r.region_name, c.country_code',
                'JOIN 202_locations_region r ON r.region_id = 2st.region_id LEFT JOIN 202_locations_country c ON c.country_id = r.main_country_id',
                'r.region_name, c.country_code',
            ],
            'isp' => [
                'i.isp_name',
                'JOIN 202_locations_isp i ON i.isp_id = 2st.isp_id',
                'i.isp_name',
            ],
            default => throw new \InvalidArgumentException("ReportPrefsStore: '$kind' is not a named filter"),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function query(string $sql, int|string ...$params): array
    {
        $stmt = $this->conn->prepareWrite($sql);
        $types = '';
        foreach ($params as $param) {
            $types .= is_int($param) ? 'i' : 's';
        }
        $this->conn->bind($stmt, $types, array_values($params));
        return $this->conn->fetchAll($stmt);
    }
}
