<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Database\Connection;
use Prosper202\PaymentNotification\PayKickstart\PayKickstartAdapter;
use Prosper202\PaymentNotification\Shopify\RefundSettlementStore;
use Prosper202\PaymentNotification\Shopify\SettlementCoordinator;
use Prosper202\PaymentNotification\Shopify\ShopifyAdapter;
use Prosper202\PaymentNotification\Zaxaa\ZaxaaAdapter;

/**
 * Records an account's held notifications of one provider once the account
 * counts in their currency, on the owner's say-so (Account › API
 * integrations): each goes through the Processor as it would have arrived,
 * for this account alone, in the order it arrived, so a sale is on file
 * before its refund.
 *
 * Pressing twice records once: a row is taken before it is recorded, goes
 * when its conversion is on file, and the writer itself answers a
 * transaction id it has as a duplicate (CLAUDE.md #13, #15). A row the
 * processor refuses as it would refuse it arriving now (its click is gone,
 * its transaction is on file at another amount) is held no longer, and the
 * answer and the server log say why; one that failed stays held.
 */
final class HeldReplay
{
    /** Rows recorded per press; the answer says how many are left. */
    public const BATCH = 200;
    public const USER_AGENT = 'Prosper202 held notification';
    /**
     * Outcomes that leave a row held, to be tried again: a failure, and a
     * currency the account stopped counting in while it was being recorded.
     */
    private const STILL_HELD = [OutcomeKind::FAILED, OutcomeKind::UPGRADE_PENDING, OutcomeKind::CURRENCY_MISMATCH];
    /** The ledger sources (and integration names) whose notifications are held. */
    public const SOURCES = ['paykickstart', 'shopify', 'zaxaa'];

    public function __construct(private readonly \mysqli $db)
    {
    }

    /** The provider whose notifications are held under a ledger source, or null. */
    public static function provider(string $source, \mysqli $db): ?ProviderAdapter
    {
        return match ($source) {
            ConversionSource::PAYKICKSTART->value => new PayKickstartAdapter(),
            ConversionSource::SHOPIFY->value => new ShopifyAdapter($db),
            ConversionSource::ZAXAA->value => new ZaxaaAdapter(),
            default => null,
        };
    }

    /**
     * @return array{recorded: int, on_file: int, refused: int, failed: int, left: int, first_refusal: string}
     *         recorded: conversions and reversals written; on_file: there already; refused: refused for
     *         good and dropped; failed: still held (it failed, or it is a refund whose sale is still
     *         held), to be tried again; left: held and not reached
     */
    public function replay(int $userId, string $source, ?int $now = null): array
    {
        $now ??= time();
        $provider = self::provider($source, $this->db);
        if ($provider === null) {
            throw new \InvalidArgumentException('no payment provider holds notifications under "' . $source . '"');
        }
        $currency = $this->accountCurrency($userId);
        $held = new HeldNotifications($this->db);
        $processor = new Processor($this->db);
        $done = ['recorded' => 0, 'on_file' => 0, 'refused' => 0, 'failed' => 0, 'left' => 0, 'first_refusal' => ''];
        $after = 0;
        $tried = 0;
        while ($tried < self::BATCH) {
            $rows = $held->next($userId, $source, $currency, $after, self::BATCH - $tried, $now);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $id = (int) $row['held_id'];
                $after = $id;
                // A refund waits while the sale it takes back is still held: recorded first, it would
                // find no sale, and the sale would later stand at its full amount.
                if (
                    NotificationKind::from((string) $row['kind'])->reverses()
                    && $row['original_transaction_id'] !== null
                    && $held->holdsSale($userId, $source, (string) $row['original_transaction_id'])
                ) {
                    $done['failed']++;
                    continue;
                }
                if (!$held->claim($userId, $id, $now)) {
                    continue;
                }
                $tried++;
                $outcome = $this->one($processor, $provider, $userId, $row);
                if (in_array($outcome->kind, [OutcomeKind::RECORDED, OutcomeKind::REVERSED], true)) {
                    $held->done($userId, $id);
                    $done['recorded']++;
                } elseif ($outcome->kind === OutcomeKind::DUPLICATE) {
                    $held->done($userId, $id);
                    $done['on_file']++;
                } elseif (in_array($outcome->kind, self::STILL_HELD, true)) {
                    $held->release($userId, $id, $outcome->message, $now);
                    $done['failed']++;
                } else {
                    error_log('Held ' . $provider->name() . ' notification ' . $id . ' of account ' . $userId
                        . ' was refused (' . $outcome->kind->value . ') and is held no longer: ' . $outcome->message);
                    $held->done($userId, $id);
                    $done['refused']++;
                    $done['first_refusal'] = $done['first_refusal'] !== '' ? $done['first_refusal'] : $outcome->message;
                }
            }
        }
        $left = $held->counts($userId)[$source][$currency]['count'] ?? 0;
        $done['left'] = max(0, $left - $done['failed']);

        return $done;
    }

    /**
     * One held row through the processor, as it would have arrived.
     *
     * @param array<string, mixed> $row
     */
    private function one(Processor $processor, ProviderAdapter $provider, int $userId, array $row): Outcome
    {
        try {
            $notification = HeldNotifications::notification($row);
            $request = new InboundRequest('POST', '', [], '', '', self::USER_AGENT, '', [], [
                'notification' => $row['notification_key'] === null ? null : (string) $row['notification_key'],
                'body' => (string) $row['body_key'],
            ]);
            $refunds = $provider instanceof ShopifyAdapter && $notification->kind->reverses() && $notification->transactionId !== null
                ? new RefundSettlementStore(new Connection($this->db))
                : null;
            // A Shopify refund settled since it was held (recorded, or closed by hand under Setup ›
            // Inbound Log) records nothing more: closed with nothing recorded means no money went back.
            if ($refunds !== null && $refunds->settled($userId, (string) $notification->transactionId)) {
                return new Outcome(OutcomeKind::DUPLICATE, 'Shopify refund ' . $notification->transactionId
                    . ' is settled already (recorded, or closed under Setup › Inbound Log); its held copy is dropped.', null, $userId);
            }
            $outcome = $processor->process($notification, new Verification([$userId => 0]), $provider, $request);
            $this->afterShopifySale($provider, $notification, $outcome, $request);
            // Recorded now: the refund is no longer one to settle by hand.
            if ($refunds !== null && in_array($outcome->kind, [OutcomeKind::REVERSED, OutcomeKind::DUPLICATE], true)) {
                try {
                    $refunds->settledByHand($userId, (string) $notification->transactionId, time());
                } catch (\Throwable $e) {
                    error_log('Held Shopify refund ' . $notification->transactionId . ' was recorded, but could not be'
                        . ' closed in the list of refunds to settle: ' . $e->getMessage());
                }
            }

            return $outcome;
        } catch (\Throwable $e) {
            error_log('Held notification ' . $row['held_id'] . ' could not be recorded: ' . $e->getMessage());

            return new Outcome(OutcomeKind::FAILED, 'It could not be recorded just now.');
        }
    }

    /** A Shopify order's pending refunds are settled once the order is on file, as Receiver does on arrival. */
    private function afterShopifySale(
        ProviderAdapter $provider,
        Notification $notification,
        Outcome $sale,
        InboundRequest $request
    ): void {
        if (
            !$provider instanceof ShopifyAdapter || !$notification->kind->records()
            || $notification->transactionId === null || $sale->accountId === null || $sale->conversionId === null
            || !in_array($sale->kind, [OutcomeKind::RECORDED, OutcomeKind::DUPLICATE], true)
        ) {
            return;
        }
        try {
            (new SettlementCoordinator($this->db, $provider))->afterSale($sale, $notification->transactionId, $request);
        } catch (\Throwable $e) {
            // The sale stands; Shopify's next webhook for the order settles its refunds.
            error_log('Held Shopify order ' . $notification->transactionId . ': its pending refunds could not be'
                . ' settled: ' . $e->getMessage());
        }
    }

    private function accountCurrency(int $userId): string
    {
        return \Prosper202\Conversion\Ledger\RowCurrency::knownOf(new Connection($this->db), $userId);
    }
}
