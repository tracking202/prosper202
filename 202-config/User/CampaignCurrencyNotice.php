<?php

declare(strict_types=1);

namespace Prosper202\User;

use Api\V3\Controllers\UsersController;
use Prosper202\Database\Connection;

/**
 * The campaigns the 1.9.80 upgrade found paid in another currency than their
 * account's (202_aff_campaigns.aff_campaign_currency_notice), most of them
 * left so by a change of the account's currency. A postback's amount on such
 * a campaign was recorded as sent before 1.9.80 and is converted from the
 * campaign's currency since, as paid 1.9.56 did. Personal settings lists
 * them until the owner answers: switch them to the account's currency (their
 * networks report in it now) or keep converting.
 */
final class CampaignCurrencyNotice
{
    /** The most a notice names; the rest are counted. */
    public const SHOWN = 20;

    private function __construct()
    {
    }

    /**
     * The account's marked campaigns still paid in another currency than
     * $accountCurrency, by name.
     *
     * @return array{campaigns: list<array{id: int, name: string, currency: string}>, more: int}
     */
    public static function listed(Connection $conn, int $userId, string $accountCurrency): array
    {
        $stmt = $conn->prepareRead(
            'SELECT aff_campaign_id, aff_campaign_name, aff_campaign_currency, aff_campaign_payout, aff_campaign_foreign_payout'
            . ' FROM 202_aff_campaigns WHERE user_id = ? AND aff_campaign_currency_notice = 1 AND aff_campaign_deleted = 0'
            . ' ORDER BY aff_campaign_name, aff_campaign_id'
        );
        $conn->bind($stmt, 'i', [$userId]);
        $account = UsersController::normalizeCurrency($accountCurrency);
        $campaigns = [];
        foreach ($conn->fetchAll($stmt) as $row) {
            $currency = \Prosper202\Setup\CampaignCurrency::paidIn($row, $account);
            if ($currency === null) {
                continue;
            }
            $campaigns[] = ['id' => (int) $row['aff_campaign_id'], 'name' => (string) $row['aff_campaign_name'], 'currency' => $currency];
        }

        return ['campaigns' => array_slice($campaigns, 0, self::SHOWN), 'more' => max(0, count($campaigns) - self::SHOWN)];
    }

    /**
     * Their networks report in the account's currency now: each marked
     * campaign is paid in it, keeping the payout it shows (already in that
     * currency), so its postback amounts are recorded as sent. Returns how
     * many campaigns changed.
     */
    public static function switchToAccount(Connection $conn, int $userId, string $accountCurrency): int
    {
        $account = UsersController::normalizeCurrency($accountCurrency);
        $changed = 0;
        $conn->transaction(static function () use ($conn, $userId, $account, &$changed): void {
            $stmt = $conn->prepareWrite(
                "UPDATE 202_aff_campaigns AS c SET c.aff_campaign_currency = ?, c.aff_campaign_foreign_payout = '0.00'"
                . ' WHERE c.user_id = ? AND c.aff_campaign_currency_notice = 1 AND c.aff_campaign_deleted = 0'
                . ' AND ' . \Prosper202\Setup\CampaignCurrency::paidInSql('c')
            );
            $conn->bind($stmt, 'sis', [$account, $userId, $account]);
            $changed = $conn->executeUpdate($stmt);
            self::answered($conn, $userId);
        });

        return $changed;
    }

    /** Their networks still report in each campaign's own currency: the notice goes, nothing else changes. */
    public static function keep(Connection $conn, int $userId): void
    {
        self::answered($conn, $userId);
    }

    private static function answered(Connection $conn, int $userId): void
    {
        $stmt = $conn->prepareWrite(
            'UPDATE 202_aff_campaigns SET aff_campaign_currency_notice = 0 WHERE user_id = ? AND aff_campaign_currency_notice = 1'
        );
        $conn->bind($stmt, 'i', [$userId]);
        $conn->executeUpdate($stmt);
    }
}
