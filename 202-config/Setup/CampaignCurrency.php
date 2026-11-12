<?php

declare(strict_types=1);

namespace Prosper202\Setup;

/**
 * A campaign's payout in its own currency (Setup › Campaigns, as 1.9.56 kept
 * it): the payout typed is stored as aff_campaign_foreign_payout, and
 * aff_campaign_payout holds it converted into the account currency, which is
 * the amount a conversion at the campaign payout records. A campaign in the
 * account currency stores foreign_payout 0, which CurrencyChange reads as
 * "in its own currency" when the account currency later changes.
 */
final class CampaignCurrency
{
    /**
     * Whether a campaign row is paid in another currency than the account's,
     * as every reader asks it: its currency differs, and it keeps the payout
     * typed in that currency or has none (a campaign paid by its postbacks'
     * amounts). A row saved before the field returned carries the column
     * default (USD) with a payout in the account's currency and no foreign
     * payout: not foreign. Answers the currency, or null.
     *
     * @param array<string, mixed> $row aff_campaign_currency, aff_campaign_payout, aff_campaign_foreign_payout
     */
    public static function paidIn(array $row, string $accountCurrency): ?string
    {
        $currency = strtoupper(trim((string) ($row['aff_campaign_currency'] ?? '')));
        if (preg_match('/\A[A-Z]{3}\z/', $currency) !== 1 || $currency === $accountCurrency) {
            return null;
        }
        $foreign = $row['aff_campaign_foreign_payout'] ?? null;
        $payout = $row['aff_campaign_payout'] ?? null;

        return (is_numeric($foreign) && (float) $foreign != 0.0) || (is_numeric($payout) && (float) $payout == 0.0)
            ? $currency
            : null;
    }

    /** The same rule in SQL, over `$alias`'s columns, for a currency bound as one ?. */
    public static function paidInSql(string $alias): string
    {
        return "($alias.aff_campaign_currency <> ? AND ($alias.aff_campaign_foreign_payout <> 0 OR $alias.aff_campaign_payout = 0))";
    }

    /** aff_campaign_payout and aff_campaign_foreign_payout are decimal(8,2). */
    private const PAYOUT_LIMIT = 1000000.0;

    private function __construct()
    {
    }

    /**
     * What to store for $payout typed in $campaignCurrency.
     *
     * A save that leaves the foreign amount and currency as stored keeps the
     * stored conversion: renaming a campaign does not re-price it.
     *
     * @param callable(string $from, string $to, string $amount): ?string $convert
     *        $amount in $from, in $to; null when no rate could be had
     * @param array<string, mixed>|null $stored the campaign's row, when editing
     * @return array{currency: string, payout: string, foreign_payout: string}
     * @throws \RuntimeException when the payout cannot be converted; nothing may be stored then
     */
    public static function store(string $accountCurrency, string $campaignCurrency, string $payout, callable $convert, ?array $stored = null): array
    {
        if ($campaignCurrency === $accountCurrency) {
            return ['currency' => $accountCurrency, 'payout' => $payout, 'foreign_payout' => '0'];
        }
        // 0 is 0 in any currency and needs no rate; the campaign stays paid
        // in its own, so its postbacks' amounts are converted (paidIn()).
        if ((float) $payout == 0.0) {
            return ['currency' => $campaignCurrency, 'payout' => '0', 'foreign_payout' => '0'];
        }
        if ((float) $payout < 0 || (float) $payout >= self::PAYOUT_LIMIT) {
            throw new \RuntimeException('A payout in another currency must be more than 0 and less than 1,000,000.');
        }
        $payout = number_format((float) $payout, 2, '.', '');
        if ($stored !== null && (string) ($stored['aff_campaign_currency'] ?? '') === $campaignCurrency
            && is_numeric($stored['aff_campaign_foreign_payout'] ?? null) && number_format((float) $stored['aff_campaign_foreign_payout'], 2, '.', '') === $payout
            && is_numeric($stored['aff_campaign_payout'] ?? null) && (float) $stored['aff_campaign_payout'] > 0) {
            return ['currency' => $campaignCurrency, 'payout' => number_format((float) $stored['aff_campaign_payout'], 2, '.', ''), 'foreign_payout' => $payout];
        }
        $converted = $convert($campaignCurrency, $accountCurrency, $payout);
        if ($converted === null || !is_numeric($converted) || (float) $converted <= 0 || (float) $converted >= self::PAYOUT_LIMIT) {
            throw new \RuntimeException('No exchange rate from ' . $campaignCurrency . ' to ' . $accountCurrency . ' could be had just now.');
        }

        return ['currency' => $campaignCurrency, 'payout' => number_format((float) $converted, 2, '.', ''), 'foreign_payout' => $payout];
    }

    /**
     * The currency and payout the form shows for a stored campaign: its own
     * when it holds a foreign payout, else the account's. A row 1.9.80 saved
     * before this field returned carries the column default (USD) with no
     * foreign payout, and its payout is in the account currency.
     *
     * @param array<string, mixed> $row
     * @return array{currency: string, payout: string}
     */
    public static function shown(array $row, string $accountCurrency): array
    {
        $currency = self::paidIn($row, $accountCurrency);
        if ($currency !== null) {
            $foreign = (string) ($row['aff_campaign_foreign_payout'] ?? '0');
            return ['currency' => $currency, 'payout' => is_numeric($foreign) && (float) $foreign != 0.0 ? $foreign : '0'];
        }

        return ['currency' => $accountCurrency, 'payout' => (string) ($row['aff_campaign_payout'] ?? '')];
    }
}
