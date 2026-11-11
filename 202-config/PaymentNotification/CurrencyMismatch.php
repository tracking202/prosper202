<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

/**
 * A notification stated in another currency than the account's, which
 * records nothing and is held: what its answer says to do about it, and
 * what the integration's card (and GET /system/integrations) says of the
 * ones held.
 */
final class CurrencyMismatch
{
    private function __construct()
    {
    }

    /** What the provider's delivery log and the inbound log say of the refused notification. */
    public static function message(string $stated, string $accountCurrency, string $provider, bool $held): string
    {
        $stated = strtoupper($stated);
        $refused = 'The amount is in ' . $stated . ' and this account counts in ' . $accountCurrency
            . '; Prosper202 does not convert it, so nothing was recorded.';
        $setting = 'Account > Personal Settings > Account currency';

        return $held
            ? $refused . ' It is held: if this account sells in ' . $stated . ', set ' . $setting . ' to ' . $stated
                . ', then record the held notifications on Account > API integrations > ' . $provider . '.'
            : $refused . ' It could not be held: if this account sells in ' . $stated . ', set ' . $setting
                . ' to ' . $stated . ', then send this notification again.';
    }

    /**
     * What one source's card says, from the account's held notifications
     * (HeldNotifications::counts()): those it can record now, stated in
     * the currency the account counts in, and those still waiting.
     *
     * @param array<string, array<string, array{count: int, latest: int}>> $counts
     * @return array{recordable: int, waiting: int, waiting_currencies: list<string>}
     */
    public static function held(array $counts, string $source, string $accountCurrency): array
    {
        $accountCurrency = strtoupper(trim($accountCurrency));
        $held = ['recordable' => 0, 'waiting' => 0, 'waiting_currencies' => []];
        foreach ($counts[$source] ?? [] as $currency => $of) {
            if ($currency === $accountCurrency) {
                $held['recordable'] += $of['count'];
            } else {
                $held['waiting'] += $of['count'];
                $held['waiting_currencies'][] = $currency;
            }
        }

        return $held;
    }
}
