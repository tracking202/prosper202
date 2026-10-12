<?php

declare(strict_types=1);

namespace Prosper202\Integration;

use Prosper202\Conversion\Ledger\DedupeKey;
use Prosper202\Conversion\Ledger\ReversalException;
use Prosper202\Database\Connection;

/**
 * A sale or a reversal that a payment platform's notification reports for a
 * click, recorded through the legacy endpoints' writer
 * (p202RecordLegacyConversion(), static-endpoint-helpers.php) as a
 * server-to-server conversion.
 *
 * The notification names a click and was verified with the secret of one or
 * more accounts; it records on the account whose click it is, and on no
 * other. Every answer a platform gets is 200 unless the database failed, so
 * that a click this install does not have, or a refund of a sale it never
 * saw, does not make the platform retry for days (PayKickstart suspends the
 * URL after 13 failures); the body and the error log say what happened.
 */
final class SaleNotification
{
    /**
     * @param list<int> $accounts the accounts whose secret verified the notification
     * @param array{
     *     source: string, transaction_id: string, amount: string|null, reversal: bool,
     *     reversal_ref?: string, source_ref?: string, user_agent?: string
     * } $sale amount null on a reversal: the sale's own amount
     * @return array{recorded: bool, duplicate: bool, conv_id: int, reason: string}
     *     reason '' when recorded; unknown_click, foreign_click, duplicate,
     *     nothing_to_reverse or already_reversed otherwise
     */
    public static function record(\mysqli $db, array $accounts, int $clickId, array $sale): array
    {
        $outcome = ['recorded' => false, 'duplicate' => false, 'conv_id' => 0, 'reason' => 'unknown_click'];
        foreach ($accounts as $userId) {
            $outcome = self::recordFor($db, $userId, $clickId, $sale);
            if ($outcome['reason'] !== 'foreign_click') {
                break;
            }
        }
        if (!$outcome['recorded'] && !$outcome['duplicate']) {
            error_log(sprintf(
                '%s notification for click %d, transaction "%s": not recorded (%s)',
                $sale['source'],
                $clickId,
                $sale['transaction_id'],
                $outcome['reason']
            ));
        }

        return $outcome;
    }

    /**
     * @param array<string, mixed> $sale as record()
     * @return array{recorded: bool, duplicate: bool, conv_id: int, reason: string}
     */
    private static function recordFor(\mysqli $db, int $userId, int $clickId, array $sale): array
    {
        $opts = [
            'user_id' => $userId,
            'transaction_id' => (string) $sale['transaction_id'],
            'use_pixel_payout' => $sale['amount'] !== null,
            'payout' => (string) ($sale['amount'] ?? ''),
            'user_agent' => (string) ($sale['user_agent'] ?? ''),
            'source' => (string) $sale['source'],
        ];
        if (isset($sale['source_ref']) && $sale['source_ref'] !== '' && !$sale['reversal']) {
            $opts['source_ref'] = (string) $sale['source_ref'];
        }
        if ($sale['reversal']) {
            $opts['reversal'] = true;
            $opts['reversal_ref'] = (string) ($sale['reversal_ref'] ?? '');
        }

        try {
            return p202RecordLegacyConversion($db, $clickId, 2, ['ip' => ''] + $opts);
        } catch (ReversalException $e) {
            if ($e->kind === ReversalException::NO_TARGET && isset($sale['source_ref']) && $sale['source_ref'] !== '') {
                $target = self::purchaseSaleToReverse(
                    new Connection($db),
                    $userId,
                    $clickId,
                    (string) $sale['source'],
                    (string) $sale['source_ref'],
                    $opts['reversal_ref'] ?? ''
                );
                if (is_int($target)) {
                    return ['recorded' => false, 'duplicate' => true, 'conv_id' => $target, 'reason' => 'duplicate'];
                }
                if ($target !== null && $target !== $opts['transaction_id']) {
                    unset($sale['source_ref']);

                    return self::recordFor($db, $userId, $clickId, ['transaction_id' => $target] + $sale);
                }
            }

            return [
                'recorded' => false,
                'duplicate' => false,
                'conv_id' => 0,
                'reason' => $e->kind === ReversalException::CONFLICT ? 'already_reversed' : 'nothing_to_reverse',
            ];
        }
    }

    /**
     * What a refund that names a transaction of its own reverses: the newest
     * sale on the click from the same source and purchase (a PayKickstart
     * invoice) that is not yet reversed, by its transaction id. When this
     * refund already reversed one of the purchase's sales, the id of that
     * reversal instead, so a replay is a duplicate rather than a second
     * refund of another payment.
     *
     * @return int|string|null the reversal already on file, the transaction to reverse, or nothing
     */
    private static function purchaseSaleToReverse(
        Connection $conn,
        int $userId,
        int $clickId,
        string $source,
        string $purchase,
        string $reversalRef
    ): int|string|null {
        $stmt = $conn->prepareRead(
            'SELECT conv_id, transaction_id, deleted FROM 202_conversion_logs
             WHERE click_id = ? AND user_id = ? AND source = ? AND source_ref = ?
               AND reverses_conv_id IS NULL AND transaction_id IS NOT NULL
             ORDER BY conv_id DESC'
        );
        $conn->bind($stmt, 'iiss', [$clickId, $userId, $source, $purchase]);
        $sales = $conn->fetchAll($stmt);
        if ($sales === []) {
            return null;
        }

        $ref = $reversalRef !== '' ? $reversalRef : '1';
        $keys = array_map(static fn (array $sale): string => DedupeKey::reversal((int) $sale['conv_id'], $ref), $sales);
        $stmt = $conn->prepareRead(
            'SELECT conv_id FROM 202_conversion_logs WHERE click_id = ? AND dedupe_key IN ('
            . implode(', ', array_fill(0, count($keys), '?')) . ') LIMIT 1'
        );
        $conn->bind($stmt, 'i' . str_repeat('s', count($keys)), [$clickId, ...$keys]);
        $replayed = $conn->fetchOne($stmt);
        if ($replayed !== null) {
            return (int) $replayed['conv_id'];
        }

        $stmt = $conn->prepareRead(
            'SELECT reverses_conv_id FROM 202_conversion_logs
             WHERE click_id = ? AND user_id = ? AND reverses_conv_id IS NOT NULL AND deleted = 0'
        );
        $conn->bind($stmt, 'ii', [$clickId, $userId]);
        $reversed = array_map(static fn (array $row): int => (int) $row['reverses_conv_id'], $conn->fetchAll($stmt));
        foreach ($sales as $sale) {
            if ((int) $sale['deleted'] === 0 && !in_array((int) $sale['conv_id'], $reversed, true)) {
                return (string) $sale['transaction_id'];
            }
        }

        return null;
    }
}
