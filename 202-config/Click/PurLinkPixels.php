<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Conversion\Ledger\MysqlConversionLedger;
use Prosper202\Conversion\TrafficSourcePixels;
use Prosper202\Database\Connection;
use Prosper202\Http\PrivacyMode;
use Prosper202\Http\PrivacySetting;
use Prosper202\Identity\RequestSignals;

/**
 * The deferred pixel: a landing page that sent its visitor straight to an
 * offer asks whether that click has since converted, and fires the traffic
 * source's browser pixels for it there. As in 1.9.56 any conversion of the
 * click counts (postbacks, payment notifications, the API, uploads), except
 * one a browser pixel reported, which already rendered its own markup.
 * Each conversion is handed out at most once, under the click lock.
 *
 * A Meta Purchase (type 6) passes the gate a conversion request's does
 * (TrafficSourcePixels::visitorMayBeTracked()), read under the lock so a
 * failed read rolls the poll back and the next poll retries it. A poll that
 * refuses consent fires and marks nothing: the visitor may consent later.
 */
final class PurLinkPixels
{
    /** Conversions given to one poll; any more wait for the next. */
    public const BATCH = 10;

    /** @var \Closure(Connection, int): string */
    private readonly \Closure $privacy;

    /**
     * @param (\Closure(Connection, int): string)|null $privacy the privacy
     *        setting for an account's visitors; throws when it cannot be read
     */
    public function __construct(private readonly Connection $conn, ?\Closure $privacy = null)
    {
        $this->privacy = $privacy ?? PrivacySetting::forAccountOrFail(...);
    }

    /**
     * @param \Closure(): bool $visitorMayBeInEu the polling visitor's (p202VisitorMayBeInEu())
     * @param array<string, mixed> $signals the poll's fields: p202_consent=0 defers every pixel
     * @return array{status: 'refused'|'waiting'|'fired', markup: string, conversions: int}
     */
    public function poll(string $proof, int $now, string $key, \Closure $visitorMayBeInEu, array $signals = []): array
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
                : $this->pollLocked($claims, $visitorMayBeInEu, $signals)
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
     * @param \Closure(): bool $visitorMayBeInEu
     * @param array<string, mixed> $signals
     * @return array{status: 'refused'|'waiting'|'fired', markup: string, conversions: int}
     */
    private function pollLocked(array $claims, \Closure $visitorMayBeInEu, array $signals): array
    {
        // Read after the lock: a re-click onto another page is visible here.
        $stmt = $this->conn->prepareWrite(
            'SELECT c.landing_page_id, ac.identity_signals FROM ' . LatestVisit::of('?') . ' AS c'
            . ' LEFT JOIN 202_aff_campaigns AS ac ON ac.aff_campaign_id = c.aff_campaign_id AND ac.user_id = c.user_id'
        );
        $this->conn->bind($stmt, 'i', [$claims['click_id']]);
        $visit = $this->conn->fetchOne($stmt);
        if ($visit === null || (int) $visit['landing_page_id'] !== $claims['landing_page_id']) {
            return ['status' => 'refused', 'markup' => '', 'conversions' => 0];
        }

        // The row's currency, once the upgrade has added the column: this runs
        // for a visitor, who is not kept waiting for the upgrade.
        $currency = \Prosper202\Conversion\Ledger\RowCurrency::columnsPresent($this->conn) ? ', cl.currency' : '';
        $stmt = $this->conn->prepareWrite(
            'SELECT cl.conv_id, cl.transaction_id, cl.dedupe_key, cl.click_payout' . $currency
            . ' FROM 202_conversion_logs AS cl'
            . ' LEFT JOIN 202_purlink_pixel_fires AS f ON f.conv_id = cl.conv_id AND f.user_id = cl.user_id'
            . ' WHERE cl.click_id = ? AND cl.user_id = ? AND cl.deleted = 0 AND cl.payable = 1'
            // Not a browser pixel's (gpx/px 1, upx 3), an install, or a carried
            // pre-ledger value; goal outcomes only for PurLink offers (pgoal:),
            // since a click's own goal events may have come from a browser.
            . " AND cl.pixel_type NOT IN (1, 3, 4) AND cl.source NOT IN ('app_install', 'legacy_baseline')"
            . " AND (cl.source <> 'goal' OR cl.dedupe_key LIKE 'pgoal:%')"
            . ' AND cl.reverses_conv_id IS NULL AND cl.superseded_by IS NULL'
            . ' AND f.conv_id IS NULL'
            . ' AND NOT EXISTS (SELECT 1 FROM 202_conversion_logs AS r'
            . ' WHERE r.reverses_conv_id = cl.conv_id AND r.user_id = cl.user_id AND r.deleted = 0)'
            . ' ORDER BY cl.conv_id LIMIT ' . self::BATCH
        );
        $this->conn->bind($stmt, 'ii', [$claims['click_id'], $claims['user_id']]);
        $due = $this->conn->fetchAll($stmt);
        // Consent is the visitor's to give later; account privacy and campaign
        // capture are the owner's, so their refusal below consumes the conversion.
        if ($due === [] || !RequestSignals::consentGiven($signals)) {
            return ['status' => 'waiting', 'markup' => '', 'conversions' => 0];
        }

        $source = TrafficSourcePixels::clickTokens($this->conn, $claims['user_id'], $claims['click_id'], true);
        $mayTrack = null;
        $visitorMayBeTracked = function () use (&$mayTrack, $claims, $visit, $visitorMayBeInEu, $signals): bool {
            return $mayTrack ??= FacebookPixelAssistantScript::mayTrack(
                PrivacyMode::tracksInFull(($this->privacy)($this->conn, $claims['user_id']), $visitorMayBeInEu),
                $signals,
                $visit['identity_signals'] ?? null
            );
        };
        $markup = '';
        foreach ($due as $conversion) {
            if ($source !== null && $source['ppc_account_id'] > 0) {
                $tokens = $source['tokens'];
                $tokens['transactionid'] = (string) ($conversion['transaction_id'] ?? '') !== ''
                    ? (string) $conversion['transaction_id']
                    : (string) $conversion['dedupe_key'];
                $tokens['payout'] = (string) $conversion['click_payout'];
                // The currency the row recorded, for a pixel that states one.
                $tokens['row_currency'] = \Prosper202\Conversion\Ledger\RowCurrency::read($conversion['currency'] ?? null);
                // Browser markup only: server postbacks went out with the conversion.
                $markup .= TrafficSourcePixels::fire(
                    $this->conn,
                    $source['ppc_account_id'],
                    $tokens,
                    null,
                    true,
                    false,
                    $visitorMayBeTracked
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
