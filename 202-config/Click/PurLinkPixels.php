<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Conversion\Ledger\MysqlConversionLedger;
use Prosper202\Conversion\TrafficSourcePixels;
use Prosper202\Database\Connection;

/**
 * The deferred pixel: a landing page that sent its visitor straight to an
 * offer asks whether that click has since converted, and fires the traffic
 * source's browser pixels for it there. Only conversions reported by a server
 * postback qualify, since a browser pixel conversion already rendered its own
 * markup. Each conversion is handed out at most once, under the click lock.
 */
final class PurLinkPixels
{
    /** Conversions given to one poll; any more wait for the next. */
    public const BATCH = 10;

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * @return array{status: 'refused'|'waiting'|'fired', markup: string, conversions: int}
     */
    public function poll(string $proof, int $now, string $key): array
    {
        $claims = PurLinkProof::verify($proof, PurLinkProof::PIXELS, $now, $key);
        if ($claims === null) {
            return ['status' => 'refused', 'markup' => '', 'conversions' => 0];
        }
        $work = fn (): array => (new MysqlConversionLedger($this->conn))->clickTransaction(
            $claims['click_id'],
            $claims['user_id'],
            fn (?array $click): array => $click === null
                ? ['status' => 'refused', 'markup' => '', 'conversions' => 0]
                : $this->pollLocked($claims)
        );
        try {
            return $work();
        } catch (\Throwable $error) {
            if (!Connection::isRetryableLockError($error)) {
                throw $error;
            }
            return $work();
        }
    }

    /**
     * @param array{click_id: int, landing_page_id: int, user_id: int, expires: int} $claims
     * @return array{status: 'refused'|'waiting'|'fired', markup: string, conversions: int}
     */
    private function pollLocked(array $claims): array
    {
        // Read after the lock: a re-click onto another page is visible here.
        $stmt = $this->conn->prepareWrite('SELECT c.landing_page_id FROM ' . LatestVisit::of('?') . ' AS c');
        $this->conn->bind($stmt, 'i', [$claims['click_id']]);
        $visit = $this->conn->fetchOne($stmt);
        if ($visit === null || (int) $visit['landing_page_id'] !== $claims['landing_page_id']) {
            return ['status' => 'refused', 'markup' => '', 'conversions' => 0];
        }

        $stmt = $this->conn->prepareWrite(
            'SELECT cl.conv_id, cl.transaction_id, cl.dedupe_key, cl.click_payout'
            . ' FROM 202_conversion_logs AS cl'
            . ' LEFT JOIN 202_purlink_pixel_fires AS f ON f.conv_id = cl.conv_id AND f.user_id = cl.user_id'
            . ' WHERE cl.click_id = ? AND cl.user_id = ? AND cl.deleted = 0 AND cl.payable = 1'
            . ' AND cl.pixel_type = 2 AND cl.reverses_conv_id IS NULL AND cl.superseded_by IS NULL'
            . ' AND f.conv_id IS NULL'
            . ' AND NOT EXISTS (SELECT 1 FROM 202_conversion_logs AS r'
            . ' WHERE r.reverses_conv_id = cl.conv_id AND r.user_id = cl.user_id AND r.deleted = 0)'
            . ' ORDER BY cl.conv_id LIMIT ' . self::BATCH
        );
        $this->conn->bind($stmt, 'ii', [$claims['click_id'], $claims['user_id']]);
        $due = $this->conn->fetchAll($stmt);
        if ($due === []) {
            return ['status' => 'waiting', 'markup' => '', 'conversions' => 0];
        }

        $source = TrafficSourcePixels::clickTokens($this->conn, $claims['user_id'], $claims['click_id'], true);
        $markup = '';
        foreach ($due as $conversion) {
            if ($source !== null && $source['ppc_account_id'] > 0) {
                $tokens = $source['tokens'];
                $tokens['transactionid'] = (string) ($conversion['transaction_id'] ?? '') !== ''
                    ? (string) $conversion['transaction_id']
                    : (string) $conversion['dedupe_key'];
                $tokens['payout'] = (string) $conversion['click_payout'];
                // Browser markup only: server postbacks went out with the conversion.
                $markup .= TrafficSourcePixels::fire(
                    $this->conn,
                    $source['ppc_account_id'],
                    $tokens,
                    null,
                    true,
                    false
                )['markup'];
            }
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_purlink_pixel_fires (conv_id, click_id, user_id, expires_at) VALUES (?, ?, ?, ?)'
            );
            $this->conn->bind($stmt, 'iiii', [
                (int) $conversion['conv_id'], $claims['click_id'], $claims['user_id'], $claims['expires'],
            ]);
            $this->conn->executeUpdate($stmt);
        }

        return ['status' => 'fired', 'markup' => $markup, 'conversions' => count($due)];
    }
}
