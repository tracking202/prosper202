<?php

declare(strict_types=1);

namespace Prosper202\Setup;

use Prosper202\Database\Connection;

/**
 * ChatGPT Ads (OpenAI Ads) as a traffic source: the one-click setup on Setup ›
 * Traffic Sources (also POST /api/v3/ppc-networks/chatgpt-ads) and the pair Get
 * Links hands out for a tracker on it (also GET /api/v3/trackers/{id}/url).
 *
 * A source is the ChatGPT one when its ppc_network_platform says so, which
 * only add() writes; its name is the user's to change and decides nothing.
 * The next platform copies this class.
 *
 * add() is idempotent per account: with a live ChatGPT source already there
 * it makes no second one, and gives that source what it lacks (a live
 * account, a live variable for each parameter below), never changing or
 * retiring what the user set.
 */
final class ChatGptAdsSource
{
    /** 202_ppc_networks.ppc_network_platform of the source add() makes. */
    public const PLATFORM = 'chatgpt_ads';

    public const SOURCE_NAME = 'ChatGPT Ads';
    public const ACCOUNT_NAME = 'ChatGPT Ads account';

    /** The macros OpenAI documents for landing_page_configuration.query_string_template. */
    public const VARIABLES = [
        ['name' => 'Campaign ID', 'parameter' => 'campaign_id', 'placeholder' => '{campaign_id}'],
        ['name' => 'Ad ID', 'parameter' => 'ad_id', 'placeholder' => '{ad_id}'],
    ];

    /** What Ads Manager appends to the ad's destination URL. */
    public const TEMPLATE = 'utm_source=chatgpt&utm_medium=paid&utm_campaign={campaign_id}&utm_content={ad_id}'
        . '&campaign_id={campaign_id}&ad_id={ad_id}&oppref={oppref}';

    /** OpenAI's limit on query_string_template. */
    public const TEMPLATE_MAX_LENGTH = 2048;

    /** The parameters TEMPLATE sets, which a link's own variables do not repeat. */
    private const TEMPLATE_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'campaign_id', 'ad_id', 'oppref', 't202id'];

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * Sets ChatGPT Ads up for $userId, or completes the setup already there.
     *
     * @return array{ppc_network_id: int, ppc_account_id: int, source_created: bool, account_created: bool, variables_added: list<string>}
     * @throws \Prosper202\Database\Exceptions\QueryException when a statement fails; nothing is written then
     * @throws \RuntimeException when $userId is no account
     */
    public function add(int $userId, ?int $now = null): array
    {
        $now ??= time();

        return $this->conn->transaction(function () use ($userId, $now): array {
            // The account's row first, before any plain read (CLAUDE.md #36):
            // two clicks at once are taken one after the other, so the second
            // finds the source the first made.
            $lock = $this->conn->prepareWrite('SELECT user_id FROM 202_users WHERE user_id = ? FOR UPDATE');
            $this->conn->bind($lock, 'i', [$userId]);
            if ($this->conn->fetchOne($lock) === null) {
                throw new \RuntimeException('There is no account ' . $userId . ' to set ChatGPT Ads up for.');
            }

            $find = $this->conn->prepareWrite('SELECT ppc_network_id FROM 202_ppc_networks'
                . ' WHERE user_id = ? AND ppc_network_platform = ? AND ppc_network_deleted = 0'
                . ' ORDER BY ppc_network_id LIMIT 1');
            $this->conn->bind($find, 'is', [$userId, self::PLATFORM]);
            $found = $this->conn->fetchOne($find);
            $sourceCreated = $found === null;
            if ($found === null) {
                $insert = $this->conn->prepareWrite('INSERT INTO 202_ppc_networks'
                    . ' (user_id, ppc_network_name, ppc_network_platform, ppc_network_deleted, ppc_network_time)'
                    . ' VALUES (?, ?, ?, 0, ?)');
                $this->conn->bind($insert, 'issi', [$userId, self::SOURCE_NAME, self::PLATFORM, $now]);
                $sourceId = $this->conn->executeInsert($insert);
            } else {
                $sourceId = (int) $found['ppc_network_id'];
            }

            $account = $this->conn->prepareWrite('SELECT ppc_account_id FROM 202_ppc_accounts'
                . ' WHERE ppc_network_id = ? AND user_id = ? AND ppc_account_deleted = 0'
                . ' ORDER BY ppc_account_id LIMIT 1');
            $this->conn->bind($account, 'ii', [$sourceId, $userId]);
            $accountRow = $this->conn->fetchOne($account);
            $accountCreated = $accountRow === null;
            if ($accountRow === null) {
                $insert = $this->conn->prepareWrite('INSERT INTO 202_ppc_accounts'
                    . ' (user_id, ppc_network_id, ppc_account_name, ppc_account_deleted, ppc_account_time)'
                    . ' VALUES (?, ?, ?, 0, ?)');
                $this->conn->bind($insert, 'iisi', [$userId, $sourceId, self::ACCOUNT_NAME, $now]);
                $accountId = $this->conn->executeInsert($insert);
            } else {
                $accountId = (int) $accountRow['ppc_account_id'];
            }

            $live = $this->conn->prepareWrite('SELECT parameter FROM 202_ppc_network_variables'
                . ' WHERE ppc_network_id = ? AND deleted = 0');
            $this->conn->bind($live, 'i', [$sourceId]);
            $have = array_map(static fn (array $row): string => (string) $row['parameter'], $this->conn->fetchAll($live));
            $added = [];
            foreach (self::VARIABLES as $variable) {
                if (in_array($variable['parameter'], $have, true)) {
                    continue;
                }
                $insert = $this->conn->prepareWrite('INSERT INTO 202_ppc_network_variables'
                    . ' (ppc_network_id, name, parameter, placeholder, deleted) VALUES (?, ?, ?, ?, 0)');
                $this->conn->bind($insert, 'isss', [$sourceId, $variable['name'], $variable['parameter'], $variable['placeholder']]);
                $this->conn->executeInsert($insert);
                $added[] = $variable['parameter'];
            }

            return [
                'ppc_network_id' => $sourceId,
                'ppc_account_id' => $accountId,
                'source_created' => $sourceCreated,
                'account_created' => $accountCreated,
                'variables_added' => $added,
            ];
        });
    }

    /** Whether a 202_ppc_networks row (as read with its ppc_network_platform) is a ChatGPT Ads source. */
    public static function isSourceRow(array $networkRow): bool
    {
        return array_key_exists('ppc_network_platform', $networkRow)
            && (string) $networkRow['ppc_network_platform'] === self::PLATFORM;
    }

    /**
     * Whether $userId's traffic source account $ppcAccountId is on a ChatGPT
     * Ads source of the same account's. A failed read throws: read as "no",
     * a ChatGPT tracker would be answered without its template.
     *
     * @throws \Prosper202\Database\Exceptions\QueryException
     */
    public function isAccountOnSource(int $userId, int $ppcAccountId): bool
    {
        if ($ppcAccountId <= 0) {
            return false;
        }
        $stmt = $this->conn->prepareRead('SELECT n.ppc_network_platform FROM 202_ppc_accounts a'
            . ' INNER JOIN 202_ppc_networks n ON n.ppc_network_id = a.ppc_network_id AND n.user_id = a.user_id'
            . ' WHERE a.ppc_account_id = ? AND a.user_id = ? LIMIT 1');
        $this->conn->bind($stmt, 'ii', [$ppcAccountId, $userId]);
        $row = $this->conn->fetchOne($stmt);

        return $row !== null && self::isSourceRow($row);
    }

    /**
     * The two values to paste into Ads Manager for a tracker on the source.
     *
     * The destination is the landing page as saved, for a landing-page
     * tracker whose page has a host; otherwise the tracker's redirect link
     * with no variables, since OpenAI fills its macros in the template only.
     * The template is TEMPLATE, then (on a landing page, where the page's
     * script finds the tracker by it) t202id and the publisher, then the
     * link's own variables that TEMPLATE does not already set; an empty
     * t202kw only on a landing page.
     *
     * @param string $linkBase the links' base, `scheme://host/…/` (TrackingBaseUrl)
     * @param string $publisherQuery '&t202pubid=…' for a publisher's link, or ''
     * @param string $variableQuery '&…' (TrackingLinkVariables::query())
     * @return array{destination: 'landing_page'|'tracking_link', destination_url: string, tracking_template: string}
     */
    public static function links(
        string $linkBase,
        string $trackerIdPublic,
        string $publisherQuery,
        string $variableQuery,
        string $landingPageUrl,
        bool $rotator
    ): array {
        $page = trim($landingPageUrl);
        $parsed = $page === '' ? false : parse_url($page);
        $onPage = is_array($parsed) && ($parsed['host'] ?? '') !== '';

        $parts = [self::TEMPLATE];
        if ($onPage) {
            $parts[] = 't202id=' . $trackerIdPublic . $publisherQuery;
        }
        foreach (explode('&', $variableQuery) as $pair) {
            if ($pair === '') {
                continue;
            }
            $key = explode('=', $pair, 2)[0];
            if (in_array($key, self::TEMPLATE_KEYS, true) || (!$onPage && $pair === 't202kw=')) {
                continue;
            }
            $parts[] = $pair;
        }

        if ($onPage) {
            return ['destination' => 'landing_page', 'destination_url' => $page, 'tracking_template' => implode('&', $parts)];
        }

        return [
            'destination' => 'tracking_link',
            'destination_url' => rtrim($linkBase, '/') . '/tracking202/redirect/' . ($rotator ? 'rtr.php' : 'dl.php')
                . '?t202id=' . $trackerIdPublic . $publisherQuery,
            'tracking_template' => implode('&', $parts),
        ];
    }
}
