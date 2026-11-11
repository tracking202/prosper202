<?php

declare(strict_types=1);

namespace Prosper202\Fraud;

use Prosper202\Click\Filter\AddressRange;
use Prosper202\Click\Filter\ClickFilterEntry;
use Prosper202\Click\Filter\ClickFilterListRefusal;
use Prosper202\Click\Filter\ClickFilterVerdict;
use Prosper202\Click\Filter\MysqlClickFilterStore;
use Prosper202\Click\LatestVisit;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Database\Connection;
use Prosper202\DataEngine\ClickRollupSql;
use Prosper202\DataEngine\IpAddressSql;

/**
 * "Not fraud": the account says IPQualityScore's verdict on a click was
 * wrong. The score is the address's, so the verdict is undone for the
 * address:
 *
 * - the address goes on the account's click filter list as "always count"
 *   (My Account › Personal Settings), first, so scoring cannot filter a
 *   click from it again in between (IpqsScoring passes over a listed one);
 * - the click, and the account's other clicks from the address that were
 *   filtered for their score, count again (MysqlClickRepository::
 *   unfilterFraud(), each under its lock), at most MAX_CLICKS a call, and
 *   each one's report row is rolled up again, since the reports sum the
 *   filter flag.
 *
 * Only a click filtered with the reason fraud_score is undone here: a
 * click a rule of the account's filtered is that rule's to change.
 */
final class FraudVerdictUndo
{
    public const MAX_CLICKS = 200;

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * @return array{address: string, clicks: int, more: bool, exempted: bool, exempt_problem: string|null}|null
     *         null when the click is not the account's, or was not filtered for its score
     */
    public function undo(int $userId, int $clickId): ?array
    {
        // Whose click it is is the same at every visit; its address and reason are the click's.
        $repository = new MysqlClickRepository($this->conn);
        if (!$repository->isAccountsClick($userId, $clickId)) {
            return null;
        }
        $stmt = $this->conn->prepareWrite(
            'SELECT a.ip_id, ' . IpAddressSql::address('i', 'v6') . ' AS ip_address'
            . ' FROM 202_clicks_filter_reason AS fr'
            . ' JOIN 202_clicks_advance AS a ON a.click_id = fr.click_id'
            . ' LEFT JOIN 202_ips AS i ON i.ip_id = a.ip_id'
            . IpAddressSql::join('i', 'v6')
            . ' WHERE fr.click_id = ? AND fr.filter_reason = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'is', [$clickId, ClickFilterVerdict::FRAUD_SCORE]);
        $click = $this->conn->fetchOne($stmt);
        if ($click === null) {
            return null;
        }
        $ipId = (int) $click['ip_id'];
        $address = is_string($click['ip_address'] ?? null) ? $click['ip_address'] : '';

        $exemptProblem = $this->exempt($userId, $address, $clickId);

        $clickIds = $ipId > 0 ? $this->scoredClicksFrom($userId, $ipId) : [];
        if (!in_array($clickId, $clickIds, true)) {
            array_unshift($clickIds, $clickId);
        }
        $more = count($clickIds) > self::MAX_CLICKS;
        $counted = 0;
        foreach (array_slice($clickIds, 0, self::MAX_CLICKS) as $id) {
            if (!$repository->unfilterFraud($userId, $id)) {
                continue;
            }
            $counted++;
            try {
                ClickRollupSql::rollUpClick($this->conn, $id);
            } catch (\Throwable $e) {
                error_log('Not fraud: click ' . $id . ' counts again but its report row was not rolled up: '
                    . $e->getMessage() . '; ' . ClickRollupSql::queueRetry($this->conn, $id));
            }
        }

        return ['address' => $address, 'clicks' => $counted, 'more' => $more, 'exempted' => $exemptProblem === null, 'exempt_problem' => $exemptProblem];
    }

    /** Null once the address is "always count" on the list; else why it is not. */
    private function exempt(int $userId, string $address, int $clickId): ?string
    {
        $packed = $address === '' ? null : AddressRange::packedForMatch($address);
        if ($packed === null) {
            return 'the click\'s address is not on record, so it could not be put on your click filter list';
        }
        $store = new MysqlClickFilterStore($this->conn);
        if ($store->match($userId, $packed)?->action === ClickFilterEntry::COUNT) {
            return null;
        }
        try {
            $store->addEntry($userId, AddressRange::parse($address), ClickFilterEntry::COUNT, 'Not fraud: marked on click ' . $clickId);
        } catch (ClickFilterListRefusal | \InvalidArgumentException $e) {
            return $address . ' could not be put on your click filter list (' . $e->getMessage()
                . '), so a later click from it can be scored again';
        }

        return null;
    }

    /**
     * The account's clicks from the address that are filtered for their
     * score, newest first, one more than MAX_CLICKS at most.
     *
     * @return list<int>
     */
    private function scoredClicksFrom(int $userId, int $ipId): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT DISTINCT a.click_id FROM 202_clicks_advance AS a'
            . ' JOIN 202_clicks_filter_reason AS fr ON fr.click_id = a.click_id'
            . ' JOIN 202_clicks AS c ON c.click_id = a.click_id'
            . ' WHERE a.ip_id = ? AND fr.filter_reason = ? AND c.user_id = ?'
            . ' ORDER BY a.click_id DESC LIMIT ' . (self::MAX_CLICKS + 1)
        );
        $this->conn->bind($stmt, 'isi', [$ipId, ClickFilterVerdict::FRAUD_SCORE, $userId]);

        return array_map(static fn (array $row): int => (int) $row['click_id'], $this->conn->fetchAll($stmt));
    }

    /**
     * The account's scoring over the last day: the addresses scored (scores
     * are kept IpqsScoring::CACHE_SECONDS) and the clicks now filtered for
     * their score.
     *
     * @return array{scored: int, filtered: int}
     */
    public static function lastDay(Connection $conn, int $userId, ?int $now = null): array
    {
        $since = ($now ?? time()) - IpqsScoring::CACHE_SECONDS;
        $scored = $conn->prepareRead('SELECT COUNT(*) AS n FROM 202_ipqs_scores WHERE user_id = ? AND scored_at >= ?');
        $conn->bind($scored, 'ii', [$userId, $since]);
        $filtered = $conn->prepareRead(
            'SELECT COUNT(*) AS n FROM 202_clicks AS c FORCE INDEX (user_click_time)'
            . ' JOIN 202_clicks_filter_reason AS fr ON fr.click_id = c.click_id'
            . ' WHERE c.user_id = ? AND c.click_time >= ? AND c.click_filtered = 1 AND fr.filter_reason = ?'
            . ' AND ' . LatestVisit::isLatest('c')
        );
        $conn->bind($filtered, 'iis', [$userId, $since, ClickFilterVerdict::FRAUD_SCORE]);

        return [
            'scored' => (int) ($conn->fetchOne($scored)['n'] ?? 0),
            'filtered' => (int) ($conn->fetchOne($filtered)['n'] ?? 0),
        ];
    }
}
