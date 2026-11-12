<?php

declare(strict_types=1);

namespace Prosper202\User;

use Api\V3\Controllers\UsersController;
use Prosper202\Database\Connection;

/**
 * Re-pricing an account's campaigns into a new account currency, in two
 * steps a caller runs around its own transaction: plan() reads the
 * campaigns and asks for the exchange rates (a network call, so outside any
 * transaction — no lock is held across it), and apply() writes the planned
 * payouts inside the caller's transaction, next to the currency itself.
 *
 * Personal settings (p202_account_save_currency) and
 * PUT /users/{id}/preferences both go through here. The API used to write
 * the currency alone, so every payout kept its old number under the new
 * currency's label.
 */
final class CurrencyChange
{
    private function __construct()
    {
    }

    /**
     * The campaign updates a change from $storedCurrency to $currency needs;
     * none when the currency is not changing.
     *
     * @param callable(string, string): mixed $rate (the currency the payout is in, payout)
     *   => the service's answer, ['exchange_payout' => number]
     * @return list<array{0: string, 1: string, 2: list<int|string>}> [sql, types, values]
     * @throws \RuntimeException when the service gives no usable rate: then
     *   nothing may be written, since a missing rate divided out to a payout
     *   of 0 and that was stored
     */
    public static function plan(Connection $conn, int $userId, string $currency, string $storedCurrency, callable $rate): array
    {
        if ($storedCurrency === $currency) {
            return [];
        }
        $converted = static function (string $campaignCurrency, string $payout) use ($rate): string {
            if ((float) $payout == 0.0) {
                return '0';
            }
            $answer = $rate($campaignCurrency, $payout);
            $value = is_array($answer) ? ($answer['exchange_payout'] ?? null) : null;
            if (!is_numeric($value) || (float) $value <= 0) {
                throw new \RuntimeException('The exchange rate service did not answer with a payout for ' . $campaignCurrency . ' ' . $payout . '.');
            }
            return (string) $value;
        };

        $stmt = $conn->prepareWrite('SELECT `aff_campaign_id`, `aff_campaign_payout`, `aff_campaign_currency`, `aff_campaign_foreign_payout` FROM `202_aff_campaigns` WHERE `aff_campaign_deleted` = 0 AND `user_id` = ?');
        $conn->bind($stmt, 'i', [$userId]);
        $updates = [];
        foreach ($conn->fetchAll($stmt) as $row) {
            $id = (int) $row['aff_campaign_id'];
            $payout = (string) $row['aff_campaign_payout'];
            $foreign = (string) $row['aff_campaign_foreign_payout'];
            $campaignCurrency = (string) $row['aff_campaign_currency'];
            if (\Prosper202\Setup\CampaignCurrency::paidIn($row, $storedCurrency) === null) {
                // In the account currency (CampaignCurrency::store() keeps no
                // foreign payout for it; a row saved before carries the column
                // default, not its currency): keep the original in the old
                // account currency, which becomes the campaign's, and show
                // the converted.
                $updates[] = ['UPDATE `202_aff_campaigns` SET `aff_campaign_currency` = ?, `aff_campaign_foreign_payout` = ?, `aff_campaign_payout` = ? WHERE `aff_campaign_id` = ? AND `user_id` = ?', 'sssii', [$storedCurrency, $payout, $converted($storedCurrency, $payout), $id, $userId]];
            } elseif ($currency === strtoupper(trim($campaignCurrency))) {
                // Back to its own currency: the original returns (0 for one paid by its postbacks' amounts).
                $updates[] = ['UPDATE `202_aff_campaigns` SET `aff_campaign_payout` = ?, `aff_campaign_foreign_payout` = \'0.00\' WHERE `aff_campaign_id` = ? AND `user_id` = ?', 'sii', [(float) $foreign != 0.0 ? $foreign : $payout, $id, $userId]];
            } else {
                $updates[] = ['UPDATE `202_aff_campaigns` SET `aff_campaign_payout` = ? WHERE `aff_campaign_id` = ? AND `user_id` = ?', 'sii', [$converted($campaignCurrency, $foreign), $id, $userId]];
            }
        }

        return $updates;
    }

    /**
     * Write planned updates. Runs inside the caller's transaction, and is
     * refused outside one (Connection::joinTransaction()): the account's
     * currency and its campaigns' payouts change together or not at all.
     *
     * @param list<array{0: string, 1: string, 2: list<int|string>}> $updates
     */
    public static function apply(Connection $conn, array $updates): void
    {
        $conn->joinTransaction(static function () use ($conn, $updates): void {
            foreach ($updates as [$sql, $types, $values]) {
                $stmt = $conn->prepareWrite($sql);
                $conn->bind($stmt, $types, $values);
                $conn->executeUpdate($stmt);
            }
        });
    }

    /**
     * Record a change of the account's currency (202_currency_changes):
     * when, from and to. Inside the transaction that changes it, refused
     * outside one, so the record and the change land together. Nothing when
     * the currency is not changing. Both are read as every page reads them
     * (UsersController::normalizeCurrency()).
     */
    public static function record(Connection $conn, int $userId, string $from, string $to, int $now): void
    {
        $from = UsersController::normalizeCurrency($from);
        $to = UsersController::normalizeCurrency($to);
        if ($from === $to) {
            return;
        }
        $conn->joinTransaction(static function () use ($conn, $userId, $from, $to, $now): void {
            $stmt = $conn->prepareWrite(
                'INSERT INTO 202_currency_changes (user_id, changed_at, from_currency, to_currency) VALUES (?, ?, ?, ?)'
            );
            $conn->bind($stmt, 'iiss', [$userId, $now, $from, $to]);
            $conn->executeInsert($stmt);
        });
    }

    /**
     * The account's recorded changes of currency, newest first.
     *
     * @return list<array{changed_at: int, from: string, to: string}>
     */
    public static function history(Connection $conn, int $userId, int $limit = 20): array
    {
        $stmt = $conn->prepareRead(
            'SELECT changed_at, from_currency, to_currency FROM 202_currency_changes'
            . " WHERE user_id = ? AND kind = 'change' ORDER BY changed_at DESC, change_id DESC LIMIT ?"
        );
        $conn->bind($stmt, 'ii', [$userId, $limit]);

        return array_map(
            static fn (array $row): array => [
                'changed_at' => (int) $row['changed_at'],
                'from' => (string) $row['from_currency'],
                'to' => (string) $row['to_currency'],
            ],
            $conn->fetchAll($stmt)
        );
    }
}
