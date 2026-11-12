<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

use Prosper202\Database\Connection;
use Prosper202\Spend\LedgerCost;

/**
 * The report pages' clicks costed from the spend ledger, as the REST reports
 * cost them (LedgerCost): what stands for 202_dataengine in a page's FROM.
 *
 * With no ledger row of the account in the window, forPage() answers null
 * and table() is `202_dataengine`, so every page's SQL is the text it was
 * before the ledger existed (LedgerPagesIntegrationTest). Otherwise table()
 * is a derived table of the account's clicks in the window with every
 * column of 202_dataengine, `cost` read from the ledger, so each page's own
 * sums, joins and filters run on it unchanged, pre-aggregated or not.
 */
final class LedgerClicks
{
    public const string TABLE = '202_dataengine';

    /** 202_dataengine's columns other than cost, in MiscTables::dataengine() order (LedgerClicksColumnsTest). */
    public const array COLUMNS = [
        'user_id', 'click_id', 'click_time', 'ppc_network_id', 'ppc_account_id', 'aff_network_id',
        'aff_campaign_id', 'landing_page_id', 'keyword_id', 'utm_medium_id', 'utm_source_id',
        'utm_campaign_id', 'utm_term_id', 'utm_content_id', 'text_ad_id', 'click_referer_site_url_id',
        'country_id', 'region_id', 'city_id', 'isp_id', 'browser_id', 'device_id', 'platform_id', 'ip_id',
        'c1_id', 'c2_id', 'c3_id', 'c4_id', 'variable_set_id', 'rotator_id', 'rule_id', 'rule_redirect_id',
        'click_lead', 'click_filtered', 'click_bot', 'click_alp', 'clicks', 'click_out', 'leads', 'payout',
        'income',
    ];

    private function __construct(
        private readonly LedgerCost $ledger,
        private readonly string $table,
        private readonly string $currency,
    ) {
    }

    /**
     * The ledger's part in a page over [$from, $to] of $userId's clicks, or
     * null when it has none there. A page reading every account's clicks
     * ($userId null, DataScope) is costed at CPC: the ledger is an account's.
     */
    public static function forPage(\mysqli $db, ?int $userId, int $from, int $to): ?self
    {
        if ($userId === null || $userId <= 0) {
            return null;
        }
        $conn = new Connection($db);
        $stmt = $conn->prepareRead('SELECT user_account_currency FROM 202_users_pref WHERE user_id = ? LIMIT 1');
        $conn->bind($stmt, 'i', [$userId]);
        $row = $conn->fetchOne($stmt);
        $currency = \Api\V3\Controllers\UsersController::normalizeCurrency($row['user_account_currency'] ?? null);

        $ledger = LedgerCost::forReport($conn, $userId, $from, $to, $currency, 'de');
        if ($ledger === null) {
            return null;
        }
        if ($ledger->join === '') {
            // No click carries a ledger ad: every cost is the CPC, and the
            // page says only how far the spend reaches and what is unallocated.
            return new self($ledger, self::TABLE, $currency);
        }

        $columns = implode(', ', array_map(static fn (string $c): string => 'de.' . $c, self::COLUMNS));
        $table = '(SELECT ' . $columns . ', ' . $ledger->costSql() . ' AS cost FROM ' . self::TABLE . ' AS de '
            . self::inline($db, $ledger->join, $ledger->types, $ledger->binds)
            . ' WHERE de.user_id = ' . $userId
            . ' AND de.click_time >= ' . $from . ' AND de.click_time <= ' . $to . ')';

        return new self($ledger, $table, $currency);
    }

    /** What a page reads in place of 202_dataengine: the table itself without a ledger. */
    public static function table(?self $ledger): string
    {
        return $ledger === null ? self::TABLE : $ledger->table;
    }

    /**
     * LedgerCost::meta() (the note, how far the spend reaches, what is
     * unallocated) and the account currency its money is in.
     *
     * @return array<string, mixed>
     */
    public function meta(string $timezone): array
    {
        return $this->ledger->meta($timezone) + ['currency' => $this->currency];
    }

    /**
     * $sql with each `?` replaced by its bind: an int as digits, a string
     * quoted through $db. The pages run their SQL as text. SQL holding a
     * quote is refused, since a `?` inside a literal is not a placeholder.
     *
     * @param list<int|string> $binds
     */
    public static function inline(\mysqli $db, string $sql, string $types, array $binds): string
    {
        $parts = explode('?', $sql);
        if (count($parts) - 1 !== count($binds) || strlen($types) !== count($binds)) {
            throw new \LogicException('The spend ledger join has ' . (count($parts) - 1) . ' placeholders for '
                . count($binds) . ' binds of types "' . $types . '"');
        }
        if (str_contains($sql, "'") || str_contains($sql, '"')) {
            throw new \LogicException('The spend ledger join holds a quoted literal: its ? cannot be inlined');
        }
        $out = $parts[0];
        foreach ($binds as $i => $value) {
            $out .= match ($types[$i]) {
                'i' => (string) (int) $value,
                's' => "'" . $db->real_escape_string((string) $value) . "'",
                default => throw new \LogicException('No bind type ' . $types[$i]),
            };
            $out .= $parts[$i + 1];
        }

        return $out;
    }
}
