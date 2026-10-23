<?php

declare(strict_types=1);

namespace Prosper202\Click;

use LogicException;
use Prosper202\Conversion\Ledger\MysqlConversionLedger;
use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;
use Prosper202\Report\RollupDirty;

/**
 * The one writer of a click's rows, for every path that records one or
 * sends one on from its landing page (recordExit(), which writes another
 * account's click never).
 *
 * 202_clicks and 202_clicks_spy have no key on click_id (they are
 * partitioned by time), so a re-click gives the click a second row there at
 * the time of the re-click (one in a second it already has a row at rewrites
 * that row), which ClickRetention, SubidBatch and LastClickFromAddress count
 * on, and records it as the latest (LatestVisit). The tables keyed on
 * click_id are upserted with only the columns the redirector sets: the
 * REPLACE this replaced deleted the row first, so a re-click reset the
 * click's customer, its reviewed flag, its public id (and with it every
 * cl.php and pci.php link already handed out) and its landing and cloaking
 * URLs.
 *
 * A redirect URL resolved inside the transaction (rtr.php) reads the click's
 * rows back for its placeholders, so every row but 202_clicks_site is
 * written before it runs. rtr.php looks it up uncached (it is the click's
 * own) and runs the write inside p202WithHeldCacheWrites(), so a cached
 * lookup made there later cannot leave the cache naming a row a rollback
 * took back.
 */
final class MysqlClickRepository implements ClickRepositoryInterface
{
    public function __construct(private Connection $conn)
    {
    }

    #[\Override]
    public function allocateClickId(): int
    {
        $stmt = $this->conn->prepareWrite('INSERT INTO 202_clicks_counter SET click_id = DEFAULT');
        $clickId = $this->conn->executeInsert($stmt);
        RecordedClicks::note($clickId);

        return $clickId;
    }

    #[\Override]
    public function recordClick(ClickRecord $click, ?\Closure $redirectSiteUrlId = null): void
    {
        if ($redirectSiteUrlId !== null && $click->clickRedirectSiteUrlId !== 0) {
            throw new LogicException('A click carries its redirect URL id or has it resolved, not both');
        }
        $this->conn->transaction(function () use ($click, $redirectSiteUrlId): void {
            $this->writeClickRows(
                $click,
                self::arrivalOf($click),
                ['click_lead' => 0, 'click_payout' => $click->clickPayout]
            );
            $this->write(
                'INSERT INTO 202_clicks_advance (click_id, text_ad_id, keyword_id, ip_id, country_id, region_id,'
                    . ' isp_id, city_id, platform_id, browser_id, device_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                'iiiiiiiiiii',
                self::advanceValues($click)
            );
            $this->write(
                'INSERT INTO 202_clicks_tracking (click_id, c1_id, c2_id, c3_id, c4_id) VALUES (?, ?, ?, ?, ?)',
                'iiiii',
                [$click->clickId, $click->c1Id, $click->c2Id, $click->c3Id, $click->c4Id]
            );
            if ($click->recordsCampaignRows) {
                $this->write(
                    'INSERT INTO 202_clicks_variable (click_id, variable_set_id) VALUES (?, ?)',
                    'ii',
                    [$click->clickId, $click->variableSetId]
                );
                $this->write(
                    'INSERT INTO 202_google (click_id, gclid, utm_source_id, utm_medium_id, utm_campaign_id,'
                        . ' utm_term_id, utm_content_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    'isiiiii',
                    [
                        $click->clickId, $click->gclid, $click->utmSourceId, $click->utmMediumId,
                        $click->utmCampaignId, $click->utmTermId, $click->utmContentId,
                    ]
                );
            }
            if ($click->rotator !== null) {
                $this->write(
                    'INSERT INTO 202_clicks_rotator (click_id, rotator_id, rule_id, rule_redirect_id)'
                        . ' VALUES (?, ?, ?, ?)',
                    'iiii',
                    self::rotatorValues($click, $click->rotator)
                );
            }
            $this->write(
                'INSERT INTO 202_clicks_record (click_id, click_id_public, click_cloaking, click_in, click_out)'
                    . ' VALUES (?, ?, ?, ?, ?)',
                'isiii',
                self::recordValues($click)
            );
            $redirectId = $redirectSiteUrlId !== null ? $redirectSiteUrlId() : $click->clickRedirectSiteUrlId;
            $this->write(
                'INSERT INTO 202_clicks_site (click_id, click_referer_site_url_id, click_landing_site_url_id,'
                    . ' click_outbound_site_url_id, click_cloaking_site_url_id, click_redirect_site_url_id)'
                    . ' VALUES (?, ?, ?, ?, ?, ?)',
                'iiiiii',
                [
                    $click->clickId, $click->clickRefererSiteUrlId, $click->clickLandingSiteUrlId,
                    $click->clickOutboundSiteUrlId, $click->clickCloakingSiteUrlId, $redirectId,
                ]
            );
        });
        $this->link($click);
    }

    /**
     * The visit's row takes the traffic source, cost and filtered and bot
     * flags of the click's first visit (FirstVisit), not the record's: they
     * belong to its arrival from the traffic source, and the duplicate filter
     * reads a re-click, which comes from its click's address, as a second
     * arrival. A click that is a lead gives the row its lead and value, as
     * the ledger's recompute leaves every row of it; one that is not gives it
     * no lead and the record's payout. All of it is read under the click's
     * lock (MysqlConversionLedger::clickTransaction()), the one a conversion
     * rewrites the cost and value under: a caller reading them first could
     * copy a value already replaced. The click keeps its keyword, and the
     * c1-c4 the record does not bring (an id of 0); the campaign, landing
     * page, route and time are the record's.
     *
     * A visit in a second the click already has a row at rewrites that row
     * (writeVisitRows()), and the click's latest visit is recorded
     * (LatestVisit::refresh()), so a click has one latest row.
     */
    #[\Override]
    public function recordReClick(ClickRecord $click, \Closure $redirectSiteUrlId): void
    {
        $route = $click->rotator;
        if ($route === null || $click->recordsCampaignRows) {
            throw new LogicException('Only the redirector re-clicks a click');
        }
        if ($click->clickRedirectSiteUrlId !== 0) {
            throw new LogicException('A click carries its redirect URL id or has it resolved, not both');
        }
        $ledger = new MysqlConversionLedger($this->conn);
        $ledger->clickTransaction($click->clickId, $click->userId, function (?array $locked) use (
            $click,
            $route,
            $redirectSiteUrlId
        ): void {
            // The click's lock, as every ledger writer takes it, is the
            // transaction's first statement, so the reads after it see what
            // it holds. rtr.php finds the click among its account's: anything
            // else is a caller's mistake.
            if ($locked === null || $this->accountsClickFirstTime($click->userId, $click->clickId) === null) {
                throw new LogicException(
                    "A re-click names click {$click->clickId}, which is not account {$click->userId}'s"
                );
            }
            $arrival = $this->firstVisitArrival($click->clickId);
            $value = $this->leadValue($click->clickId) ?? ['click_lead' => 0, 'click_payout' => $click->clickPayout];
            // The rollup has summed the click into its hours and those of
            // every conversion whose journey holds it; the mark sends them
            // back to exact (AttributionRollup rule 2).
            RollupDirty::click($this->conn, $click->userId, $click->clickId);
            $this->writeVisitRows($click, $arrival, $value);
            $this->recordLatestVisit($click->clickId);
            // The redirector records no text ad: the click's is kept unless
            // the re-click brings one. The keyword is the click's.
            $this->write(
                'INSERT INTO 202_clicks_advance (click_id, text_ad_id, keyword_id, ip_id, country_id, region_id,'
                    . ' isp_id, city_id, platform_id, browser_id, device_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    . ' ON DUPLICATE KEY UPDATE'
                    . ' text_ad_id = IF(VALUES(text_ad_id) = 0, text_ad_id, VALUES(text_ad_id)),'
                    . ' ip_id = VALUES(ip_id), country_id = VALUES(country_id), region_id = VALUES(region_id),'
                    . ' isp_id = VALUES(isp_id), city_id = VALUES(city_id), platform_id = VALUES(platform_id),'
                    . ' browser_id = VALUES(browser_id), device_id = VALUES(device_id)',
                'iiiiiiiiiii',
                self::advanceValues($click)
            );
            // customer_id is the click's, set by a conversion: kept.
            $this->write(
                'INSERT INTO 202_clicks_tracking (click_id, c1_id, c2_id, c3_id, c4_id) VALUES (?, ?, ?, ?, ?)'
                    . ' ON DUPLICATE KEY UPDATE c1_id = IF(VALUES(c1_id) = 0, c1_id, VALUES(c1_id)),'
                    . ' c2_id = IF(VALUES(c2_id) = 0, c2_id, VALUES(c2_id)),'
                    . ' c3_id = IF(VALUES(c3_id) = 0, c3_id, VALUES(c3_id)),'
                    . ' c4_id = IF(VALUES(c4_id) = 0, c4_id, VALUES(c4_id))',
                'iiiii',
                [$click->clickId, $click->c1Id, $click->c2Id, $click->c3Id, $click->c4Id]
            );
            $this->write(
                'INSERT INTO 202_clicks_rotator (click_id, rotator_id, rule_id, rule_redirect_id) VALUES (?, ?, ?, ?)'
                    . ' ON DUPLICATE KEY UPDATE rotator_id = VALUES(rotator_id), rule_id = VALUES(rule_id),'
                    . ' rule_redirect_id = VALUES(rule_redirect_id)',
                'iiii',
                self::rotatorValues($click, $route)
            );
            // The public id is kept unless the re-click brings one (rtr.php
            // reuses the click's own when it has one), and click_reviewed
            // always.
            $this->write(
                'INSERT INTO 202_clicks_record (click_id, click_id_public, click_cloaking, click_in, click_out)'
                    . ' VALUES (?, ?, ?, ?, ?)'
                    . ' ON DUPLICATE KEY UPDATE'
                    . ' click_id_public = IF(VALUES(click_id_public) = 0, click_id_public, VALUES(click_id_public)),'
                    . ' click_cloaking = VALUES(click_cloaking), click_in = VALUES(click_in),'
                    . ' click_out = VALUES(click_out)',
                'isiii',
                self::recordValues($click)
            );
            $redirectId = $redirectSiteUrlId();
            // The landing URL is the page the click was made on: kept, as is
            // the cloaking URL unless the re-click brings one.
            $this->write(
                'INSERT INTO 202_clicks_site (click_id, click_referer_site_url_id, click_landing_site_url_id,'
                    . ' click_outbound_site_url_id, click_cloaking_site_url_id, click_redirect_site_url_id)'
                    . ' VALUES (?, ?, ?, ?, ?, ?)'
                    . ' ON DUPLICATE KEY UPDATE click_referer_site_url_id = VALUES(click_referer_site_url_id),'
                    . ' click_outbound_site_url_id = VALUES(click_outbound_site_url_id),'
                    . ' click_cloaking_site_url_id = IF(VALUES(click_cloaking_site_url_id) = 0,'
                    . ' click_cloaking_site_url_id, VALUES(click_cloaking_site_url_id)),'
                    . ' click_redirect_site_url_id = VALUES(click_redirect_site_url_id)',
                'iiiiii',
                [
                    $click->clickId, $click->clickRefererSiteUrlId, $click->clickLandingSiteUrlId,
                    $click->clickOutboundSiteUrlId, $click->clickCloakingSiteUrlId, $redirectId,
                ]
            );
        });
        $this->link($click);
    }

    #[\Override]
    public function recordExit(ClickExit $exit, ?\Closure $redirectSiteUrlId = null): bool
    {
        if (($redirectSiteUrlId === null) !== ($exit->outboundSiteUrlId === 0)) {
            throw new LogicException('A click exit records its outbound and redirect URLs together, or neither');
        }
        if ($exit->offer === null && $exit->rotator === null && !$exit->clickedOut) {
            throw new LogicException('A click exit that writes nothing');
        }

        $ledger = new MysqlConversionLedger($this->conn);

        return $ledger->clickTransaction($exit->clickId, $exit->userId, function (?array $locked) use (
            $exit,
            $redirectSiteUrlId
        ): bool {
            // The click's lock first, as recordReClick() and the ledger take
            // it: a re-click holds it while it upserts the route, so an exit
            // that took the route first and the click's rows after could
            // deadlock with it.
            if ($locked === null) {
                return false;
            }
            $firstClickTime = $this->accountsClickFirstTime($exit->userId, $exit->clickId);
            if ($firstClickTime === null || !$this->namesTheAccountsRecords($exit)) {
                return false;
            }
            $route = $exit->rotator;
            if ($route !== null) {
                $this->write(
                    'INSERT INTO 202_clicks_rotator (click_id, rotator_id, rule_id, rule_redirect_id)'
                        . ' VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE rotator_id = VALUES(rotator_id),'
                        . ' rule_id = VALUES(rule_id), rule_redirect_id = VALUES(rule_redirect_id)',
                    'iiii',
                    [$exit->clickId, $route->rotatorId, $route->ruleId, $route->ruleRedirectId]
                );
                // rule_id holds the rule's redirect, as rtr.php writes it. No sum reads either column.
                $this->write(
                    'UPDATE 202_clicks SET rotator_id = ?, rule_id = ? WHERE click_id = ?',
                    'iii',
                    [$route->rotatorId, $route->ruleRedirectId, $exit->clickId]
                );
            }
            $offer = $exit->offer;
            if ($offer !== null) {
                // A click old enough to be summed is marked in the rewrite's
                // transaction (AttributionRollup rule 2).
                if (RollupDirty::hotPathRewriteNeedsMark($firstClickTime)) {
                    RollupDirty::click($this->conn, $exit->userId, $exit->clickId);
                }
                $this->write(
                    'UPDATE 202_clicks AS c'
                        . ' LEFT JOIN 202_clicks_spy AS s ON (s.click_id = c.click_id AND s.user_id = c.user_id)'
                        . ' SET c.aff_campaign_id = ?, s.aff_campaign_id = ?, c.click_payout = ?, s.click_payout = ?'
                        . ' WHERE c.click_id = ? AND c.click_lead = 0',
                    'iissi',
                    [$offer->campaignId, $offer->campaignId, $offer->payout, $offer->payout, $exit->clickId]
                );
            }
            if ($exit->clickedOut) {
                if ($exit->cloaking === null) {
                    $this->write(
                        'UPDATE 202_clicks_record SET click_out = 1 WHERE click_id = ?',
                        'i',
                        [$exit->clickId]
                    );
                } else {
                    $this->write(
                        'UPDATE 202_clicks_record SET click_out = 1, click_cloaking = ? WHERE click_id = ?',
                        'ii',
                        [$exit->cloaking, $exit->clickId]
                    );
                }
            }
            if ($redirectSiteUrlId !== null) {
                $this->write(
                    'UPDATE 202_clicks_site SET click_outbound_site_url_id = ?, click_redirect_site_url_id = ?'
                        . ' WHERE click_id = ?',
                    'iii',
                    [$exit->outboundSiteUrlId, $redirectSiteUrlId(), $exit->clickId]
                );
            }

            return true;
        });
    }

    #[\Override]
    public function isAccountsClick(int $userId, int $clickId): bool
    {
        return $this->accountsClickFirstTime($userId, $clickId) !== null;
    }

    /**
     * A click re-clicked before 1.9.79 brought to the rule recordReClick()
     * writes by (ReClickBackfill): every row takes the first visit's traffic
     * source, cost and filtered and bot flags, and, when a row is a lead,
     * the lead and value leadValue() reads; the click's latest visit is
     * recorded and the click marked for the attribution rollup, under its
     * lock, in one transaction. Its report rows are the caller's to roll up,
     * after the commit. Run again, it writes the same values.
     *
     * A click whose rows name more than one account is left as it is
     * (`accounts`): no rule says whose source it keeps. `gone` is a click
     * with no rows (deleted since the caller found it), `one_visit` one with
     * a single row.
     *
     * @return array{outcome: 'repaired'|'accounts'|'gone'|'one_visit', tie: bool}
     *     tie: the click's latest second holds more than one of its rows
     */
    public function repairVisits(int $clickId): array
    {
        $ledger = new MysqlConversionLedger($this->conn);

        return $ledger->clickTransaction($clickId, null, function (?array $locked) use ($clickId): array {
            if ($locked === null) {
                return ['outcome' => 'gone', 'tie' => false];
            }
            $stmt = $this->conn->prepareWrite('SELECT user_id, click_time FROM 202_clicks WHERE click_id = ?');
            $this->conn->bind($stmt, 'i', [$clickId]);
            $users = [];
            $times = [];
            foreach ($this->conn->fetchAll($stmt) as $row) {
                $users[self::intColumn($row, 'user_id')] = true;
                $times[] = self::intColumn($row, 'click_time');
            }
            if (count($users) > 1) {
                return ['outcome' => 'accounts', 'tie' => false];
            }
            if (count($times) < 2) {
                return ['outcome' => count($times) === 0 ? 'gone' : 'one_visit', 'tie' => false];
            }
            $latest = max($times);
            $tie = count(array_keys($times, $latest, true)) > 1;
            $arrival = $this->firstVisitArrival($clickId);
            $value = $this->leadValue($clickId);
            // Each table's rows named by click and the span of its visits, and
            // the UPDATE stopped at as many as were read: it reads and locks
            // nothing past them (the gap after the newest click's rows is
            // where new clicks go), nor any partition of 202_clicks outside
            // the span. The span, not a list of the visits' seconds: a click
            // re-clicked every minute for months has more seconds than a
            // statement takes placeholders (65,535), and its repair failed on
            // every run, holding the walk at it. The Spy view keeps a day, so
            // an older click has no rows there.
            $span = [min($times), max($times)];
            $spyRows = $this->rowCount('SELECT COUNT(*) AS n FROM 202_clicks_spy WHERE click_id = ?', 'i', [$clickId]);
            $source = [
                $arrival['ppc_account_id'], $arrival['click_cpc'], $arrival['click_filtered'], $arrival['click_bot'],
            ];
            $lead = $value === null ? [] : [$value['click_payout']];
            foreach ([[false, count($times)], [true, $spyRows]] as [$spy, $rows]) {
                if ($rows === 0) {
                    continue;
                }
                $sql = match (true) {
                    !$spy && $value === null => 'UPDATE 202_clicks SET ppc_account_id = ?, click_cpc = ?,'
                        . ' click_filtered = ?, click_bot = ? WHERE click_id = ? AND click_time BETWEEN ? AND ? LIMIT ?',
                    !$spy => 'UPDATE 202_clicks SET ppc_account_id = ?, click_cpc = ?, click_filtered = ?,'
                        . ' click_bot = ?, click_lead = 1, click_payout = ? WHERE click_id = ? AND click_time BETWEEN ? AND ?'
                        . ' LIMIT ?',
                    $value === null => 'UPDATE 202_clicks_spy SET ppc_account_id = ?, click_cpc = ?,'
                        . ' click_filtered = ?, click_bot = ? WHERE click_id = ? AND click_time BETWEEN ? AND ? LIMIT ?',
                    default => 'UPDATE 202_clicks_spy SET ppc_account_id = ?, click_cpc = ?, click_filtered = ?,'
                        . ' click_bot = ?, click_lead = 1, click_payout = ? WHERE click_id = ? AND click_time BETWEEN ? AND ?'
                        . ' LIMIT ?',
                };
                $this->write(
                    $sql,
                    'isii' . str_repeat('s', count($lead)) . 'iiii',
                    [...$source, ...$lead, $clickId, ...$span, $rows]
                );
            }
            LatestVisit::refresh($this->conn, $clickId);
            RollupDirty::click($this->conn, (int) array_key_first($users), $clickId);

            return ['outcome' => 'repaired', 'tie' => $tie];
        });
    }

    /**
     * The time of the click's first row when every row is the account's;
     * null when it has none or one is another's. Not locked: a click's
     * account never changes, and FOR UPDATE on the non-unique click_id key
     * would hold the gap after it, where new clicks are inserted, until the
     * exit commits.
     */
    private function accountsClickFirstTime(int $userId, int $clickId): ?int
    {
        $stmt = $this->conn->prepareWrite('SELECT user_id, click_time FROM 202_clicks WHERE click_id = ?');
        $this->conn->bind($stmt, 'i', [$clickId]);
        $first = null;
        foreach ($this->conn->fetchAll($stmt) as $row) {
            if (self::intColumn($row, 'user_id') !== $userId) {
                return null;
            }
            $time = self::intColumn($row, 'click_time');
            $first = $first === null ? $time : min($first, $time);
        }

        return $first;
    }

    /**
     * The first visit's traffic source, cost and filtered and bot flags.
     *
     * @return array{ppc_account_id: int, click_cpc: string, click_filtered: int, click_bot: int}
     */
    private function firstVisitArrival(int $clickId): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT fv.ppc_account_id, fv.click_cpc, fv.click_filtered, fv.click_bot FROM '
                . FirstVisit::of('?') . ' AS fv'
        );
        $this->conn->bind($stmt, 'i', [$clickId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            throw new LogicException("Click {$clickId} has no first visit under its lock");
        }
        $cpc = $row['click_cpc'] ?? null;
        if (!is_string($cpc) || preg_match('/\A-?\d+(?:\.\d+)?\z/', $cpc) !== 1) {
            throw new \UnexpectedValueException('202_clicks.click_cpc read as ' . get_debug_type($cpc));
        }
        $flags = [];
        foreach (['click_filtered', 'click_bot'] as $column) {
            $flags[$column] = self::intColumn($row, $column);
            if ($flags[$column] !== 0 && $flags[$column] !== 1) {
                throw new \UnexpectedValueException("202_clicks.$column read as {$flags[$column]}");
            }
        }

        return [
            'ppc_account_id' => self::intColumn($row, 'ppc_account_id'), 'click_cpc' => $cpc,
            'click_filtered' => $flags['click_filtered'], 'click_bot' => $flags['click_bot'],
        ];
    }

    /**
     * The click's lead and value when it is a lead: what the ledger's
     * recompute writes to every row it has (MysqlConversionLedger::recompute()),
     * from the latest visit that is a lead (a row written since the last
     * recompute is none), as ensureManaged() takes a pre-ledger lead's. Null
     * when no row is a lead.
     *
     * @return array{click_lead: int, click_payout: string}|null
     */
    private function leadValue(int $clickId): ?array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT c.click_payout FROM 202_clicks AS c'
            . ' LEFT JOIN 202_clicks_rotator AS r ON r.click_id = c.click_id'
            . ' WHERE c.click_id = ? AND c.click_lead = 1 ' . LatestVisit::orderBy('c', 'r') . ' LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [$clickId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }
        $payout = $row['click_payout'] ?? null;
        if (!is_string($payout) || preg_match('/\A-?\d+(?:\.\d+)?\z/', $payout) !== 1) {
            throw new \UnexpectedValueException('202_clicks.click_payout read as ' . get_debug_type($payout));
        }

        return ['click_lead' => 1, 'click_payout' => $payout];
    }

    /**
     * A new click's own traffic source, cost and filtered and bot flags.
     *
     * @return array{ppc_account_id: int, click_cpc: string, click_filtered: int, click_bot: int}
     */
    private static function arrivalOf(ClickRecord $click): array
    {
        return [
            'ppc_account_id' => $click->ppcAccountId, 'click_cpc' => $click->clickCpc,
            'click_filtered' => $click->clickFiltered, 'click_bot' => $click->clickBot,
        ];
    }

    /** Whether the offer's campaign and the rotator, its rule and redirect, are the exit's account's. */
    private function namesTheAccountsRecords(ClickExit $exit): bool
    {
        if ($exit->offer !== null) {
            $stmt = $this->conn->prepareWrite(
                'SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'ii', [$exit->offer->campaignId, $exit->userId]);
            if ($this->conn->fetchOne($stmt) === null) {
                return false;
            }
        }
        $route = $exit->rotator;
        if ($route !== null) {
            $stmt = $this->conn->prepareWrite(
                'SELECT r.id, ru.id AS rule_id, rr.id AS redirect_id FROM 202_rotators AS r'
                    . ' LEFT JOIN 202_rotator_rules AS ru ON (ru.id = ? AND ru.rotator_id = r.id)'
                    . ' LEFT JOIN 202_rotator_rules_redirects AS rr ON (rr.id = ? AND rr.rule_id = ?)'
                    . ' WHERE r.id = ? AND r.user_id = ?'
            );
            $this->conn->bind(
                $stmt,
                'iiiii',
                [$route->ruleId, $route->ruleRedirectId, $route->ruleId, $route->rotatorId, $exit->userId]
            );
            $row = $this->conn->fetchOne($stmt);
            if ($row === null) {
                return false;
            }
            if ($route->ruleId !== 0 && ($row['rule_id'] ?? null) === null) {
                return false;
            }
            if ($route->ruleRedirectId !== 0 && ($row['redirect_id'] ?? null) === null) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $row */
    private static function intColumn(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1) {
            return (int) $value;
        }
        throw new \UnexpectedValueException("202_clicks.$column read as " . get_debug_type($value));
    }

    /**
     * 202_clicks (with the route, 0 off the redirector) and its 202_clicks_spy
     * copy, with the traffic source, cost and filtered and bot flags given
     * (the record's for a new click, the first visit's for a re-click) and
     * the lead and value given (none and the record's payout, or the click's
     * own when a re-click is of a lead).
     *
     * @param array{ppc_account_id: int, click_cpc: string, click_filtered: int, click_bot: int} $arrival
     * @param array{click_lead: int, click_payout: string} $value
     */
    private function writeClickRows(ClickRecord $click, array $arrival, array $value): void
    {
        $this->insertClickRow($click, $arrival, $value);
        $this->insertSpyRow($click, $arrival, $value);
    }

    /**
     * A re-click's rows as writeClickRows() writes them, except in a table
     * where the click already has a row at the record's second: there the
     * click's rows at that click_time are rewritten with what the visit sets
     * (campaign, landing page, payout, route, the arrival and value given),
     * and none is added. No key tells a click's visits apart but their time
     * (LatestVisit), so a second row in that second would be a second
     * latest visit, counted again by every per-row sum. Two requests in one
     * second are the same visit as far as any reader can tell; the one
     * written last is the visit.
     *
     * The rows are counted with a plain read: the click's lock, taken first,
     * is taken by every writer of its rows, so the read sees each committed
     * before it. Click and time name one partition of 202_clicks, and the
     * UPDATE stops at the rows counted (LIMIT), so it reads and locks
     * nothing past them: the index gap after the newest click's rows is
     * where new clicks go.
     *
     * @param array{ppc_account_id: int, click_cpc: string, click_filtered: int, click_bot: int} $arrival
     * @param array{click_lead: int, click_payout: string} $value
     */
    private function writeVisitRows(ClickRecord $click, array $arrival, array $value): void
    {
        $route = [$click->rotator->rotatorId ?? 0, $click->rotator->ruleRedirectId ?? 0];
        $visit = [
            $click->affCampaignId, $click->landingPageId, $arrival['ppc_account_id'], $arrival['click_cpc'],
            $value['click_payout'], $value['click_lead'], $arrival['click_filtered'], $arrival['click_bot'],
            $click->clickAlp,
        ];
        $at = [$click->clickId, $click->userId, $click->clickTime];

        $rows = $this->rowCount(
            'SELECT COUNT(*) AS n FROM 202_clicks WHERE click_id = ? AND user_id = ? AND click_time = ?',
            'iii',
            $at
        );
        if ($rows === 0) {
            $this->insertClickRow($click, $arrival, $value);
        } else {
            $this->write(
                'UPDATE 202_clicks SET aff_campaign_id = ?, landing_page_id = ?, ppc_account_id = ?, click_cpc = ?,'
                    . ' click_payout = ?, click_lead = ?, click_filtered = ?, click_bot = ?, click_alp = ?,'
                    . ' rotator_id = ?, rule_id = ? WHERE click_id = ? AND user_id = ? AND click_time = ? LIMIT ?',
                'iiissiiiiiiiiii',
                [...$visit, ...$route, ...$at, $rows]
            );
        }

        $rows = $this->rowCount(
            'SELECT COUNT(*) AS n FROM 202_clicks_spy WHERE click_id = ? AND user_id = ? AND click_time = ?',
            'iii',
            $at
        );
        if ($rows === 0) {
            $this->insertSpyRow($click, $arrival, $value);
        } else {
            $this->write(
                'UPDATE 202_clicks_spy SET aff_campaign_id = ?, landing_page_id = ?, ppc_account_id = ?,'
                    . ' click_cpc = ?, click_payout = ?, click_lead = ?, click_filtered = ?, click_bot = ?,'
                    . ' click_alp = ? WHERE click_id = ? AND user_id = ? AND click_time = ? LIMIT ?',
                'iiissiiiiiiii',
                [...$visit, ...$at, $rows]
            );
        }
    }

    /** @param list<int> $values */
    private function rowCount(string $sql, string $types, array $values): int
    {
        $stmt = $this->conn->prepareWrite($sql);
        $this->conn->bind($stmt, $types, $values);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            throw new \UnexpectedValueException('A COUNT(*) answered no row: ' . $sql);
        }

        return self::intColumn($row, 'n');
    }

    /**
     * LatestVisit::refresh(), or, while the 1.9.79 upgrade has not created
     * its table, a log line: rtr.php does not ask whether the database is
     * upgraded, so this code can run first. The visit is kept, and the click
     * counts at each of its visits, as every re-click from before 1.9.79
     * does, until ReClickBackfill records it: the click is older than the
     * table, and the upgrade marks the newest click for the backfill after
     * creating the table. Any other failure fails the re-click.
     */
    private function recordLatestVisit(int $clickId): void
    {
        try {
            LatestVisit::refresh($this->conn, $clickId);
        } catch (\RuntimeException $e) {
            if (!LatestVisit::tableMissing($e)) {
                throw $e;
            }
            error_log('p202 re-click: click ' . $clickId . ' was re-clicked before the 1.9.79 upgrade created '
                . TableRegistry::CLICK_LATEST_VISITS . '; its visit is stored, and the upgrade\'s backfill records'
                . ' which visit is its latest. Until then the reports count it at each visit. Run the upgrade.');
        }
    }

    /**
     * @param array{ppc_account_id: int, click_cpc: string, click_filtered: int, click_bot: int} $arrival
     * @param array{click_lead: int, click_payout: string} $value
     */
    private function insertClickRow(ClickRecord $click, array $arrival, array $value): void
    {
        $this->write(
            'INSERT INTO 202_clicks (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id,'
                . ' click_cpc, click_payout, click_lead, click_filtered, click_bot, click_alp, click_time,'
                . ' rotator_id, rule_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'iiiiissiiiiiii',
            [
                $click->clickId, $click->userId, $click->affCampaignId, $click->landingPageId,
                $arrival['ppc_account_id'], $arrival['click_cpc'], $value['click_payout'], $value['click_lead'],
                $arrival['click_filtered'], $arrival['click_bot'], $click->clickAlp, $click->clickTime,
                // rule_id holds the rule's redirect, as it always has.
                $click->rotator->rotatorId ?? 0, $click->rotator->ruleRedirectId ?? 0,
            ]
        );
    }

    /**
     * @param array{ppc_account_id: int, click_cpc: string, click_filtered: int, click_bot: int} $arrival
     * @param array{click_lead: int, click_payout: string} $value
     */
    private function insertSpyRow(ClickRecord $click, array $arrival, array $value): void
    {
        $this->write(
            'INSERT INTO 202_clicks_spy (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id,'
                . ' click_cpc, click_payout, click_lead, click_filtered, click_bot, click_alp, click_time)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'iiiiissiiiii',
            [
                $click->clickId, $click->userId, $click->affCampaignId, $click->landingPageId,
                $arrival['ppc_account_id'], $arrival['click_cpc'], $value['click_payout'], $value['click_lead'],
                $arrival['click_filtered'], $arrival['click_bot'], $click->clickAlp, $click->clickTime,
            ]
        );
    }

    /**
     * Linking runs in its own transaction once the click is committed: a
     * failure there is logged by ClickIdentity and never costs the click.
     */
    private function link(ClickRecord $click): void
    {
        $click->identity?->attach($this->conn, $click->userId, $click->clickId, $click->clickTime);
    }

    /** @return list<int> */
    private static function advanceValues(ClickRecord $click): array
    {
        return [
            $click->clickId, $click->textAdId, $click->keywordId, $click->ipId, $click->countryId,
            $click->regionId, $click->ispId, $click->cityId, $click->platformId, $click->browserId,
            $click->deviceId,
        ];
    }

    /** @return list<int> */
    private static function rotatorValues(ClickRecord $click, RotatorRoute $route): array
    {
        return [$click->clickId, $route->rotatorId, $route->ruleId, $route->ruleRedirectId];
    }

    /** @return list<int|string> */
    private static function recordValues(ClickRecord $click): array
    {
        return [$click->clickId, $click->clickIdPublic, $click->clickCloaking, $click->clickIn, $click->clickOut];
    }

    /** @param list<int|string> $values */
    private function write(string $sql, string $types, array $values): void
    {
        $stmt = $this->conn->prepareWrite($sql);
        $this->conn->bind($stmt, $types, $values);
        $this->conn->executeUpdate($stmt);
    }
}
