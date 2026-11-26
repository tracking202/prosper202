<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

use Prosper202\Database\Connection;

/**
 * Which sale a transaction id names on a click, for the source naming it.
 *
 * A network sends one id per sale, and its payment notification and its
 * postback for that sale both name it, so a sale is keyed tx:<id> whatever
 * path reports it and the second report is the first's duplicate. Two
 * payment platforms are two namespaces, though: a JVZoo sale and a Zaxaa
 * sale on one click can carry the same id, and under one key the second
 * was refused (another amount) or merged into the first (the same amount),
 * and its refund took back the other platform's sale. So a platform's sale
 * is keyed tx:<id> unless another platform's sale holds that key on the
 * click, and ptx:<platform>:<id> then (key()); and what a platform's
 * reversal takes back is its own sale under the id, or, with none of its
 * own, one no other platform recorded (restriction(), order()).
 *
 * What cannot be told apart: a postback, an API call or an upload names no
 * platform, so one recorded first holds tx:<id> for every platform, and a
 * second platform's sale under the id is merged into it as the first
 * platform's was; and a reversal from such a path takes back the click's
 * first sale under the id, whichever platform it was.
 */
final class TransactionSale
{
    private function __construct()
    {
    }

    /**
     * The key a sale under this transaction id takes on the click. Read under
     * the click's lock (the writer's), so two platforms' first sales cannot
     * both read the key free.
     */
    public static function key(Connection $conn, int $clickId, string $transactionId, ConversionSource $source): string
    {
        $shared = DedupeKey::transaction($transactionId);
        if (!$source->isPaymentProvider()) {
            return $shared;
        }
        // From the primary, as the writer reads its duplicates; deleted rows
        // keep their key, so they are read too.
        $stmt = $conn->prepareWrite('SELECT source FROM 202_conversion_logs WHERE click_id = ? AND dedupe_key = ? LIMIT 1');
        $conn->bind($stmt, 'is', [$clickId, $shared]);
        $holder = $conn->fetchOne($stmt);
        if ($holder === null) {
            return $shared;
        }
        $holderSource = ConversionSource::tryFrom((string) $holder['source']);
        if ($holderSource === null || $holderSource === $source || !$holderSource->isPaymentProvider()) {
            return $shared;
        }

        return DedupeKey::providerTransaction($source, $transactionId);
    }

    /**
     * ' AND …' leaving out the rows another payment platform recorded, for a
     * query of the sales (or the reversals) a transaction id names on a
     * click; '' for a source that names no platform, which reads them all.
     * $column is the source column as the query names it ("s.source").
     */
    public static function restriction(string $column, ConversionSource $source): string
    {
        if (!$source->isPaymentProvider()) {
            return '';
        }
        $platforms = array_values(array_filter(
            ConversionSource::cases(),
            static fn (ConversionSource $s): bool => $s->isPaymentProvider()
        ));

        return ' AND (' . $column . ' = ' . self::quoted($source) . ' OR ' . $column . ' NOT IN ('
            . implode(', ', array_map(self::quoted(...), $platforms)) . '))';
    }

    /**
     * The ORDER BY that picks the sale: the platform's own first, then the
     * earliest. $prefix is the table's alias with its dot ("s."), or ''.
     */
    public static function order(string $prefix, ConversionSource $source): string
    {
        $own = $source->isPaymentProvider() ? $prefix . 'source = ' . self::quoted($source) . ' DESC, ' : '';

        return $own . $prefix . 'conv_id';
    }

    /** A source as an SQL string: its values are fixed lower-case words. */
    private static function quoted(ConversionSource $source): string
    {
        if (preg_match('/^[a-z_]+$/', $source->value) !== 1) {
            throw new \LogicException('source "' . $source->value . '" is not a plain word');
        }

        return "'" . $source->value . "'";
    }
}
