<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

use Prosper202\InboundLog\InboundLog;

/**
 * A sale stated in another currency than the account's, which records
 * nothing: what the answer says to do about it, and the count of them the
 * integration's card shows until the account's currency is theirs.
 */
final class CurrencyMismatch
{
    /** How far back a card counts. */
    public const WINDOW = 30 * 86400;
    /** The newest refusals read for the count; more than these read as "at least". */
    public const READ = 500;

    private const STATED = '/^The amount is in ([A-Z]{3}) and this account counts in [^;]*;/';

    private function __construct()
    {
    }

    /** What the provider's delivery log and the inbound log say of the refused sale. */
    public static function message(string $stated, string $accountCurrency): string
    {
        $stated = strtoupper($stated);

        return 'The amount is in ' . $stated . ' and this account counts in ' . $accountCurrency
            . '; Prosper202 does not convert it, so nothing was recorded. If this account sells in ' . $stated
            . ', set Account > Personal Settings > Account currency to ' . $stated . ', then send this'
            . ' notification again. It is listed on Setup > Inbound Log.';
    }

    /**
     * The account's sales refused for their currency since $since, by
     * endpoint: only those stated in another currency than the account
     * counts in now, so a card stops warning once the currency is changed.
     *
     * @return array<string, array{count: int, currencies: list<string>, latest: int, more: bool}>
     */
    public static function recent(InboundLog $log, int $userId, string $accountCurrency, int $since): array
    {
        $accountCurrency = strtoupper(trim($accountCurrency));
        $rows = $log->list($userId, false, ['outcome' => OutcomeKind::CURRENCY_MISMATCH->value], self::READ);
        $more = count($rows) === self::READ;
        $found = [];
        foreach ($rows as $row) {
            if (
                (int) $row['received_at'] < $since
                || preg_match(self::STATED, (string) $row['reason'], $m) !== 1 || $m[1] === $accountCurrency
            ) {
                continue;
            }
            $endpoint = (string) $row['endpoint'];
            $found[$endpoint] ??= ['count' => 0, 'currencies' => [], 'latest' => 0, 'more' => $more];
            $found[$endpoint]['count']++;
            $found[$endpoint]['latest'] = max($found[$endpoint]['latest'], (int) $row['received_at']);
            if (!in_array($m[1], $found[$endpoint]['currencies'], true)) {
                $found[$endpoint]['currencies'][] = $m[1];
            }
        }

        return $found;
    }
}
