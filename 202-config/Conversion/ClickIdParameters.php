<?php

declare(strict_types=1);

namespace Prosper202\Conversion;

use Prosper202\Click\ClickRecord;
use Prosper202\Database\Connection;

/**
 * An account's own names for the postback parameter a network sends an ad
 * click id back in (202_users_pref.user_pref_click_id_params): a network
 * given the gclid in a sub id slot returns it as `aff_sub3`, not `gclid`.
 * The global postback reads a listed parameter when its SubID was not
 * filled in, and looks its value up among that account's clicks only, under
 * every kind of ad click id (Prosper202\Click\ClickByAdClickId).
 *
 * Stored as the names, lower case (as a request's names are read), joined
 * by commas; '' is none. The settings page, the API and both CLIs write it
 * through accepted(); the postback reads it through parse(), and a stored
 * value that is not a list accepted() would have written names no parameter.
 */
final class ClickIdParameters
{
    public const COLUMN = 'user_pref_click_id_params';
    public const MAX_NAMES = 5;
    public const MAX_NAME_LENGTH = 40;

    /** Parameters the postback and pixel endpoints read for something else. */
    private const RESERVED = [
        'subid', 'sid', 'cid', 'acip', 'amount', 'status', 'event', 'event_id', 'event_props', 'reversal_id',
        'txid', 'transaction_id', 'transactionid', 'order_id', 'orderid', 'oid', 't202txid',
        'cust', 'customer_ref', 'cust_type', 'customer_ref_type', 'cust_sig', 'sku', 'product_id',
        'external_product_id', 'product_name', 'qty', 'quantity', 'unit_price', 'currency',
        'p202_token', 'p202_ts', 'p202_sig', 'p202_consent',
    ];

    private function __construct()
    {
    }

    /**
     * What a settings form or the API sent, as it is stored.
     *
     * @throws \InvalidArgumentException saying what it takes
     */
    public static function accepted(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                'Must be a string: parameter names separated by commas, or "" for none'
            );
        }
        $names = [];
        foreach (preg_split('/[\s,]+/', strtolower(trim($value)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $name) {
            if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,' . (self::MAX_NAME_LENGTH - 1) . '}\z/D', $name) !== 1) {
                throw new \InvalidArgumentException('"' . mb_substr($name, 0, 60) . '" is not a parameter name:'
                    . ' use letters, digits, _ and -, at most ' . self::MAX_NAME_LENGTH
                    . ' characters, as it appears in your postback URL (aff_sub3, not {aff_sub3})');
            }
            if (array_key_exists($name, ClickRecord::AD_CLICK_IDS)) {
                throw new \InvalidArgumentException($name . ' is read without being listed, as are '
                    . implode(', ', array_keys(ClickRecord::AD_CLICK_IDS))
                    . '; list only your network\'s own parameter names');
            }
            if (in_array($name, self::RESERVED, true)) {
                throw new \InvalidArgumentException($name . ' already means something else on a postback;'
                    . ' send the click id in another parameter');
            }
            $names[$name] = true;
        }
        if (count($names) > self::MAX_NAMES) {
            throw new \InvalidArgumentException('At most ' . self::MAX_NAMES . ' parameter names');
        }

        return implode(',', array_keys($names));
    }

    /**
     * The names a stored value lists; none for '' and for a value
     * accepted() would not have written (CLAUDE.md #11).
     *
     * @return list<string>
     */
    public static function parse(mixed $stored): array
    {
        if (!is_string($stored) || $stored === '') {
            return [];
        }
        try {
            return self::accepted($stored) === $stored ? explode(',', $stored) : [];
        } catch (\InvalidArgumentException) {
            return [];
        }
    }

    /**
     * Every account that lists a parameter, with its names. Read on the
     * write handle, as the lookup that follows is. While the 1.9.80 upgrade
     * has not added the column, no account lists one.
     *
     * @return array<int, list<string>> by user id
     * @throws \RuntimeException when the read fails otherwise
     */
    public static function ofEveryAccount(Connection $conn): array
    {
        try {
            $stmt = $conn->prepareWrite(
                'SELECT user_id, ' . self::COLUMN . ' FROM 202_users_pref WHERE ' . self::COLUMN . " <> ''"
            );
            $rows = $conn->fetchAll($stmt);
        } catch (\RuntimeException $e) {
            for ($current = $e; $current !== null; $current = $current->getPrevious()) {
                $unknownColumn = Connection::isMysqlError($current, 1054, '');
                if ($unknownColumn && str_contains($current->getMessage(), self::COLUMN)) {
                    return [];
                }
            }
            throw $e;
        }
        $byAccount = [];
        foreach ($rows as $row) {
            $names = self::parse($row[self::COLUMN] ?? null);
            $userId = (int) ($row['user_id'] ?? 0);
            if ($names === [] || $userId <= 0) {
                error_log('p202 click id parameters: user ' . $userId . '\'s ' . self::COLUMN
                    . ' is not a list of parameter names; it names none until it is saved again');
                continue;
            }
            $byAccount[$userId] = $names;
        }

        return $byAccount;
    }
}
