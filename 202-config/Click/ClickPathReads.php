<?php

declare(strict_types=1);

namespace Prosper202\Click;

use InvalidArgumentException;
use JsonException;
use LogicException;
use Prosper202\Database\Connection;
use Prosper202\Repository\Cache\NullRowCache;
use Prosper202\Repository\Cache\RowCache;

/**
 * The rows the click path reads by a public id from a link or a cookie, and
 * the records those rows name by id: a landing page's campaign, a tracker's
 * campaign, text ad, traffic source and redirector. A record of another
 * account named that way reads as naming nothing (CLAUDE.md #27): every join
 * is tied to the account of the row it comes from, and a lookup that takes
 * an account asks for it.
 *
 * Rows come back as the scripts read them before, every value text or null,
 * and an id a script copies into a click is 0 where the join found nothing,
 * as a tracker that names none always gave. Setup rows are kept in the row
 * cache, a missing one too; a click's row never is, since the click path
 * rewrites it while it is read.
 *
 * @phpstan-type Row array<string, string|null>
 */
final readonly class ClickPathReads
{
    private function __construct(
        private Connection $conn,
        private RowCache $cache,
        private string $namespace,
    ) {
    }

    public static function uncached(Connection $conn): self
    {
        return new self($conn, new NullRowCache(), '');
    }

    /** $namespace keeps one install's entries apart from another's in a shared cache. */
    public static function cached(Connection $conn, RowCache $cache, string $namespace): self
    {
        if ($namespace === '') {
            throw new InvalidArgumentException('A cached read needs the install\'s namespace');
        }

        return new self($conn, $cache, $namespace);
    }

    /**
     * A tracker by its public id (dl.php's direct link, the variable names
     * get_custom_vars.php and a landing page's script with no page named
     * read), with its campaign, text ad and traffic source when each is the
     * tracker's account's, and the account's settings.
     *
     * @return Row|null
     */
    public function tracker(int $publicId): ?array
    {
        return $this->setupRow(
            'SELECT tr.user_id,
                    tr.tracker_id_public,
                    tr.click_cpc,
                    tr.click_cpa,
                    tr.click_cloaking,
                    IFNULL(ta.text_ad_id, 0) AS text_ad_id,
                    IFNULL(ppc.ppc_account_id, 0) AS ppc_account_id,
                    IFNULL(ac.aff_campaign_id, 0) AS aff_campaign_id,
                    ac.aff_campaign_rotate,
                    ac.aff_campaign_url,
                    ac.aff_campaign_url_2,
                    ac.aff_campaign_url_3,
                    ac.aff_campaign_url_4,
                    ac.aff_campaign_url_5,
                    ac.aff_campaign_payout,
                    ac.aff_campaign_cloaking,
                    ac.identity_signals,
                    (SELECT GROUP_CONCAT(v.ppc_variable_id ORDER BY v.ppc_variable_id)
                       FROM 202_ppc_network_variables AS v
                      WHERE v.ppc_network_id = pn.ppc_network_id AND v.deleted = 0) AS ppc_variable_ids,
                    (SELECT GROUP_CONCAT(v.parameter ORDER BY v.ppc_variable_id)
                       FROM 202_ppc_network_variables AS v
                      WHERE v.ppc_network_id = pn.ppc_network_id AND v.deleted = 0) AS parameters,
                    u.user_timezone,
                    u.user_public_publisher_id,
                    up.user_keyword_searched_or_bidded,
                    up.user_pref_referer_data,
                    up.user_pref_dynamic_bid,
                    up.maxmind_isp
               FROM 202_trackers AS tr
               LEFT JOIN 202_text_ads AS ta
                      ON ta.text_ad_id = tr.text_ad_id AND ta.user_id = tr.user_id
               LEFT JOIN 202_ppc_accounts AS ppc
                      ON ppc.ppc_account_id = tr.ppc_account_id AND ppc.user_id = tr.user_id
               LEFT JOIN 202_ppc_networks AS pn
                      ON pn.ppc_network_id = ppc.ppc_network_id AND pn.user_id = tr.user_id
               LEFT JOIN 202_aff_campaigns AS ac
                      ON ac.aff_campaign_id = tr.aff_campaign_id AND ac.user_id = tr.user_id
               LEFT JOIN 202_users AS u ON u.user_id = tr.user_id
               LEFT JOIN 202_users_pref AS up ON up.user_id = tr.user_id
              WHERE tr.tracker_id_public = ?
              ORDER BY tr.tracker_id
              LIMIT 1',
            'i',
            [$publicId]
        );
    }

    /**
     * A tracker a landing page's visit names (t202id), only when it is the
     * landing page's account's: the visitor's link carries the id, and an id
     * of another account's tracker named its traffic source, cost and, on
     * an advanced landing page, the campaign the click was recorded under.
     *
     * @return Row|null
     */
    public function accountTracker(int $userId, int $publicId): ?array
    {
        return $this->setupRow(
            'SELECT IFNULL(ta.text_ad_id, 0) AS text_ad_id,
                    IFNULL(ppc.ppc_account_id, 0) AS ppc_account_id,
                    tr.click_cpc,
                    tr.click_cloaking,
                    IFNULL(ac.aff_campaign_id, 0) AS aff_campaign_id,
                    ac.aff_campaign_payout,
                    ac.identity_signals,
                    (SELECT GROUP_CONCAT(v.ppc_variable_id ORDER BY v.ppc_variable_id)
                       FROM 202_ppc_network_variables AS v
                      WHERE v.ppc_network_id = pn.ppc_network_id AND v.deleted = 0) AS ppc_variable_ids,
                    (SELECT GROUP_CONCAT(v.parameter ORDER BY v.ppc_variable_id)
                       FROM 202_ppc_network_variables AS v
                      WHERE v.ppc_network_id = pn.ppc_network_id AND v.deleted = 0) AS parameters
               FROM 202_trackers AS tr
               LEFT JOIN 202_text_ads AS ta
                      ON ta.text_ad_id = tr.text_ad_id AND ta.user_id = tr.user_id
               LEFT JOIN 202_ppc_accounts AS ppc
                      ON ppc.ppc_account_id = tr.ppc_account_id AND ppc.user_id = tr.user_id
               LEFT JOIN 202_ppc_networks AS pn
                      ON pn.ppc_network_id = ppc.ppc_network_id AND pn.user_id = tr.user_id
               LEFT JOIN 202_aff_campaigns AS ac
                      ON ac.aff_campaign_id = tr.aff_campaign_id AND ac.user_id = tr.user_id
              WHERE tr.tracker_id_public = ? AND tr.user_id = ?
              ORDER BY tr.tracker_id
              LIMIT 1',
            'ii',
            [$publicId, $userId]
        );
    }

    /**
     * A redirector's tracker (rtr.php), with its rotator and the rotator's
     * default campaign and landing page when each is the tracker's
     * account's, and the account's settings. rotator_id is 0 when the
     * tracker names no rotator of its account.
     *
     * @return Row|null
     */
    public function redirectorTracker(int $publicId): ?array
    {
        return $this->setupRow(
            'SELECT tr.user_id,
                    IFNULL(ppc.ppc_account_id, 0) AS ppc_account_id,
                    IFNULL(rt.id, 0) AS rotator_id,
                    tr.click_cpc,
                    rt.default_url,
                    rt.default_campaign,
                    rt.default_lp,
                    rt.auto_monetizer,
                    ca.aff_campaign_id,
                    ca.aff_campaign_rotate,
                    ca.aff_campaign_url,
                    ca.aff_campaign_url_2,
                    ca.aff_campaign_url_3,
                    ca.aff_campaign_url_4,
                    ca.aff_campaign_url_5,
                    ca.aff_campaign_payout,
                    ca.aff_campaign_cloaking,
                    u.user_timezone,
                    up.user_keyword_searched_or_bidded,
                    up.user_pref_dynamic_bid,
                    up.maxmind_isp,
                    lp.landing_page_url,
                    lp.landing_page_id AS default_landing_page_id
               FROM 202_trackers AS tr
               LEFT JOIN 202_ppc_accounts AS ppc
                      ON ppc.ppc_account_id = tr.ppc_account_id AND ppc.user_id = tr.user_id
               LEFT JOIN 202_rotators AS rt ON rt.id = tr.rotator_id AND rt.user_id = tr.user_id
               LEFT JOIN 202_aff_campaigns AS ca
                      ON ca.aff_campaign_id = rt.default_campaign AND ca.user_id = tr.user_id
               LEFT JOIN 202_landing_pages AS lp
                      ON lp.landing_page_id = rt.default_lp AND lp.user_id = tr.user_id
               LEFT JOIN 202_users AS u ON u.user_id = tr.user_id
               LEFT JOIN 202_users_pref AS up ON up.user_id = tr.user_id
              WHERE tr.tracker_id_public = ?
              ORDER BY tr.tracker_id
              LIMIT 1',
            'i',
            [$publicId]
        );
    }

    /**
     * A simple landing page and its campaign (lp.php, lpc.php,
     * record_simple.php). None when the campaign is not the landing page's
     * account's: the campaign is where its visitors are sent.
     *
     * @return Row|null
     */
    public function landingPageOffer(int $publicId): ?array
    {
        return $this->setupRow(
            'SELECT lp.user_id,
                    lp.landing_page_id,
                    lp.landing_page_id_public,
                    lp.leave_behind_page_url,
                    ac.aff_campaign_id,
                    ac.aff_campaign_name,
                    ac.aff_campaign_rotate,
                    ac.aff_campaign_url,
                    ac.aff_campaign_url_2,
                    ac.aff_campaign_url_3,
                    ac.aff_campaign_url_4,
                    ac.aff_campaign_url_5,
                    ac.aff_campaign_payout,
                    ac.aff_campaign_cloaking,
                    ac.identity_signals
               FROM 202_landing_pages AS lp
               INNER JOIN 202_aff_campaigns AS ac
                       ON ac.aff_campaign_id = lp.aff_campaign_id AND ac.user_id = lp.user_id
              WHERE lp.landing_page_id_public = ?
              ORDER BY lp.landing_page_id
              LIMIT 1',
            'i',
            [$publicId]
        );
    }

    /**
     * The traffic source variables a landing page's script captures
     * (landing.php): those of the tracker the visit names, when it is the
     * page's account's.
     *
     * @return Row|null
     */
    public function landingPageVisitVariables(int $publicId, int $trackerPublicId): ?array
    {
        return $this->setupRow(
            'SELECT (SELECT GROUP_CONCAT(v.ppc_variable_id ORDER BY v.ppc_variable_id)
                       FROM 202_ppc_network_variables AS v
                      WHERE v.ppc_network_id = pn.ppc_network_id AND v.deleted = 0) AS ppc_variable_ids,
                    (SELECT GROUP_CONCAT(v.parameter ORDER BY v.ppc_variable_id)
                       FROM 202_ppc_network_variables AS v
                      WHERE v.ppc_network_id = pn.ppc_network_id AND v.deleted = 0) AS parameters
               FROM 202_landing_pages AS lp
               INNER JOIN 202_trackers AS tr
                       ON tr.tracker_id_public = ? AND tr.user_id = lp.user_id
               LEFT JOIN 202_ppc_accounts AS ppc
                      ON ppc.ppc_account_id = tr.ppc_account_id AND ppc.user_id = tr.user_id
               LEFT JOIN 202_ppc_networks AS pn
                      ON pn.ppc_network_id = ppc.ppc_network_id AND pn.user_id = tr.user_id
              WHERE lp.landing_page_id_public = ?
              ORDER BY lp.landing_page_id, tr.tracker_id
              LIMIT 1',
            'ii',
            [$trackerPublicId, $publicId]
        );
    }

    /**
     * The traffic source variables of a landing page reached with no
     * tracker named (landing.php): those of a tracker of the page's
     * campaign, of the page's account.
     *
     * @return Row|null
     */
    public function landingPageTrackerVariables(int $publicId): ?array
    {
        return $this->setupRow(
            'SELECT (SELECT GROUP_CONCAT(v.ppc_variable_id ORDER BY v.ppc_variable_id)
                       FROM 202_ppc_network_variables AS v
                      WHERE v.ppc_network_id = pn.ppc_network_id AND v.deleted = 0) AS ppc_variable_ids,
                    (SELECT GROUP_CONCAT(v.parameter ORDER BY v.ppc_variable_id)
                       FROM 202_ppc_network_variables AS v
                      WHERE v.ppc_network_id = pn.ppc_network_id AND v.deleted = 0) AS parameters
               FROM 202_landing_pages AS lp
               INNER JOIN 202_trackers AS tr
                       ON tr.aff_campaign_id = lp.aff_campaign_id AND tr.user_id = lp.user_id
               LEFT JOIN 202_ppc_accounts AS ppc
                      ON ppc.ppc_account_id = tr.ppc_account_id AND ppc.user_id = tr.user_id
               LEFT JOIN 202_ppc_networks AS pn
                      ON pn.ppc_network_id = ppc.ppc_network_id AND pn.user_id = tr.user_id
              WHERE lp.landing_page_id_public = ?
              ORDER BY tr.tracker_id
              LIMIT 1',
            'i',
            [$publicId]
        );
    }

    /**
     * What decides whether a landing page's script may capture identity
     * signals (landing.php): the page's type, its campaign's setting, and
     * the setting of the campaign of the tracker the visit names, each only
     * when it is the page's account's. tracker_campaign is null when the
     * visit names no such tracker or its tracker no such campaign.
     *
     * @return Row|null
     */
    public function landingPageCapture(int $publicId, ?int $trackerPublicId): ?array
    {
        return $this->setupRow(
            'SELECT lp.landing_page_type,
                    lpc.identity_signals AS lp_setting,
                    trc.identity_signals AS tracker_setting,
                    trc.aff_campaign_id AS tracker_campaign
               FROM 202_landing_pages AS lp
               LEFT JOIN 202_aff_campaigns AS lpc
                      ON lpc.aff_campaign_id = lp.aff_campaign_id AND lpc.user_id = lp.user_id
               LEFT JOIN 202_trackers AS tr
                      ON tr.tracker_id_public = ? AND tr.user_id = lp.user_id
               LEFT JOIN 202_aff_campaigns AS trc
                      ON trc.aff_campaign_id = tr.aff_campaign_id AND trc.user_id = tr.user_id
              WHERE lp.landing_page_id_public = ?
              ORDER BY lp.landing_page_id, tr.tracker_id
              LIMIT 1',
            'ii',
            // No tracker named: 0, which no tracker's public id is.
            [$trackerPublicId ?? 0, $publicId]
        );
    }

    /**
     * A campaign by its public id (off.php reached with no click), any
     * account's: the account is the campaign's own.
     *
     * @return Row|null
     */
    public function offer(int $publicId): ?array
    {
        return $this->setupRow(
            'SELECT ac.user_id,
                    ac.aff_campaign_id,
                    ac.aff_campaign_name,
                    ac.aff_campaign_rotate,
                    ac.aff_campaign_url,
                    ac.aff_campaign_url_2,
                    ac.aff_campaign_url_3,
                    ac.aff_campaign_url_4,
                    ac.aff_campaign_url_5,
                    ac.aff_campaign_cloaking
               FROM 202_aff_campaigns AS ac
              WHERE ac.aff_campaign_id_public = ?
              ORDER BY ac.aff_campaign_id
              LIMIT 1',
            'i',
            [$publicId]
        );
    }

    /**
     * Where a cloaked click goes (cl.php), by its public id: the URL it was
     * sent to, its campaign's name when the campaign is the click's
     * account's, and that account's cloak referer setting. Not cached: a
     * rotator re-click keeps the public id and changes the URL.
     *
     * @return Row|null
     */
    public function cloakedClick(int $clickPublicId): ?array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT ac.aff_campaign_name,
                    su.site_url_address,
                    up.user_pref_cloak_referer
               FROM 202_clicks AS c
               INNER JOIN 202_clicks_record AS cr ON cr.click_id = c.click_id
               INNER JOIN 202_clicks_site AS cs ON cs.click_id = c.click_id
               INNER JOIN 202_site_urls AS su ON su.site_url_id = cs.click_redirect_site_url_id
               LEFT JOIN 202_aff_campaigns AS ac
                      ON ac.aff_campaign_id = c.aff_campaign_id AND ac.user_id = c.user_id
               LEFT JOIN 202_users_pref AS up ON up.user_id = c.user_id
              WHERE cr.click_id_public = ?
              ORDER BY c.click_time DESC
              LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [$clickPublicId]);
        $row = $this->conn->fetchOne($stmt);

        return $row === null ? null : self::text($row);
    }

    /**
     * @param list<int> $params
     * @return Row|null
     */
    private function setupRow(string $sql, string $types, array $params): ?array
    {
        $key = $this->key($sql, $params);
        $hit = $this->cache->get($key);
        if ($hit !== null) {
            return $hit->row;
        }

        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, $types, $params);
        $row = $this->conn->fetchOne($stmt);
        $row = $row === null ? null : self::text($row);
        $this->cache->set($key, $row);

        return $row;
    }

    /**
     * The statement is part of the key, so a changed select list is never
     * answered with a row kept for the old one.
     *
     * @param list<int> $params
     */
    private function key(string $sql, array $params): string
    {
        try {
            $values = json_encode($params, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LogicException('Unencodable read parameters', 0, $e);
        }

        return 'p202-row-' . hash('sha256', $this->namespace . "\0" . $sql . "\0" . $values);
    }

    /**
     * A prepared statement answers an integer column as an int; the scripts
     * were written against the text protocol, and pass values on to string
     * functions under strict_types.
     *
     * @param array<string, mixed> $row
     * @return Row
     */
    private static function text(array $row): array
    {
        $text = [];
        foreach ($row as $column => $value) {
            $text[$column] = match (true) {
                $value === null, is_string($value) => $value,
                is_int($value) => (string) $value,
                default => throw new LogicException(
                    'Column ' . $column . ' of a click-path read is a ' . get_debug_type($value)
                ),
            };
        }

        return $text;
    }
}
