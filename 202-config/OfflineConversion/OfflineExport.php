<?php

declare(strict_types=1);

namespace Prosper202\OfflineConversion;

use Prosper202\Attribution\CountedAmount;
use Prosper202\Click\AdClickIdColumns;
use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Conversion\Ledger\MatchMethod;
use Prosper202\Database\Connection;
use Prosper202\Report\AccountZone;

/**
 * An account's conversions as an ad platform's offline conversion import
 * file (Format), and a count of every conversion it left out and why
 * (LeftOut). The one writer: the Setup page's download, GET
 * /conversions/offline-export, `p202 conversion offline-export` and the
 * scheduled fetch URL all come through write().
 *
 * Which conversions:
 *  - each conversion in the window that counts now, at the amount it counts
 *    for (CountedAmount: net of partial refunds; a conversion refunded in
 *    full, deleted, replaced on its click or not payable is left out), in
 *    the account's currency, which is what the ledger records amounts in;
 *  - only when the conversion named its click (MatchMethod CLICK_ID: the
 *    postback, pixel, API call or upload named it; COOKIE: a tracking
 *    cookie in the converting browser did). A click found by a visitor id or
 *    guessed from an address would teach the platform's bidding the wrong
 *    clicks, so those are counted and left out;
 *  - only a click this account recorded, with an id the platform issued,
 *    no more than 90 days old and at least 6 hours old (the platforms
 *    import nothing older, and may not have processed a newer one yet).
 *
 * The rows are read as the server sends them (Connection::eachRow()) and
 * written as they come (FileLines), newest conversion first, so a window of
 * any size holds one row in memory, and for Microsoft the msclkids of the
 * last hour of it (ClickSeconds). A refund or a deletion after the platform
 * fetched a conversion is not sent again: a fetch sends what counts now,
 * under the conversion's own Order ID, which Google reads as the same
 * conversion on every fetch.
 */
final class OfflineExport
{
    /** The platforms import no conversion more than 90 days after its click. */
    public const CLICK_MAX_AGE = 90 * 86400;

    /**
     * A click this recent may not be at the platform yet: Google's
     * TOO_RECENT_EVENT names 6 hours, and Microsoft counts nothing uploaded
     * within 5 hours of its click.
     */
    public const HOLD = 6 * 3600;

    public function __construct(private Connection $conn)
    {
    }

    /**
     * Write the file to $handle (null writes nothing and only counts).
     *
     * @param resource|null $handle
     */
    public function write(int $userId, ExportOptions $options, $handle): ExportSummary
    {
        $format = $options->format;
        $accountZone = AccountZone::read($this->conn, $userId);
        $zone = $format->zone($accountZone);
        $currency = $this->currency($userId);

        if ($handle !== null) {
            $preamble = $format->preamble($accountZone);
            if ($preamble !== null) {
                self::put($handle, $preamble);
            }
            self::put($handle, $format->header());
        }

        [$sql, $types, $values] = self::query($options, $userId);
        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, $types, $values);
        $lines = new FileLines($options, $zone, $currency, $handle);
        $this->conn->eachRow($stmt, static function (array $row) use ($lines): void {
            $lines->add($row);
        });

        return $lines->summary();
    }

    /**
     * Why a conversion read by query() is not a line, or null when it is:
     * the first LeftOut case that applies, in the enum's order.
     *
     * @param array<string, mixed> $row
     * @param string|null $name the conversion name it would be sent under
     */
    public static function reasonFor(array $row, ExportOptions $options, ?string $name): ?LeftOut
    {
        $counted = StoredRow::nullableText($row, 'counted_amount');
        if ($counted === null) {
            return LeftOut::NOT_COUNTED;
        }
        // A value no writer produces is not a method that named the click.
        $method = MatchMethod::tryFrom(StoredRow::text($row, 'match_method')) ?? MatchMethod::UNKNOWN;
        $matched = LeftOut::forMatch($method);
        if ($matched !== null) {
            return $matched;
        }
        $ids = self::ids($row, $options->format);
        if ($ids === []) {
            $braid = StoredRow::text($row, 'gbraid') !== '' || StoredRow::text($row, 'wbraid') !== '';

            return $options->format === Format::GOOGLE_UPLOADS && $braid
                ? LeftOut::GBRAID_WBRAID_ONLY
                : LeftOut::NO_CLICK_ID;
        }
        foreach ($ids as $column => $id) {
            if (!Format::wellFormedId($column, $id)) {
                return LeftOut::MALFORMED_CLICK_ID;
            }
        }
        $clickTime = StoredRow::int($row, 'click_time');
        if ($clickTime < $options->now - self::CLICK_MAX_AGE) {
            return LeftOut::CLICK_TOO_OLD;
        }
        if (StoredRow::int($row, 'conv_time') < $clickTime) {
            return LeftOut::BEFORE_CLICK;
        }
        // query() asks only of a named conversion with an id whose click is
        // in the file's range; anything but a yes is no.
        if (StoredRow::nullableInt($row, 'owned') !== 1) {
            return LeftOut::NOT_ACCOUNTS_CLICK;
        }
        if ($clickTime > $options->now - self::HOLD) {
            return LeftOut::CLICK_TOO_RECENT;
        }
        if (Amount::toUnits($counted) < 0) {
            return LeftOut::NEGATIVE_VALUE;
        }
        if ($name === null) {
            return LeftOut::NO_CONVERSION_NAME;
        }

        return null;
    }

    /**
     * The click's ids the layout writes, by column, without the empty ones.
     *
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    public static function ids(array $row, Format $format): array
    {
        $ids = [];
        foreach ($format->idColumns() as $column) {
            $id = StoredRow::text($row, $column);
            if ($id !== '') {
                $ids[$column] = $id;
            }
        }

        return $ids;
    }

    /**
     * The read: every conversion of the account in the window that is not a
     * reversal, newest first, with the amount it counts for (NULL when it
     * does not count), its click's ids, its conversion name, and whether its
     * click is the account's, asked only of a named conversion whose click
     * is in the file's range.
     *
     * @return array{0: string, 1: string, 2: list<int|string>}
     */
    public static function query(ExportOptions $options, int $userId): array
    {
        // Each id as GET /clicks reads it, one value per click however many
        // 202_google rows it has (AdClickIdColumns::sql()), so a conversion is
        // one row here and names the ids the API names.
        $ids = '';
        foreach (['gclid', 'gbraid', 'wbraid', 'msclkid'] as $id) {
            $ids .= ' COALESCE(' . AdClickIdColumns::sql($id, 'cl.click_id') . ", '') AS $id,";
        }
        $named = "'" . MatchMethod::CLICK_ID->value . "', '" . MatchMethod::COOKIE->value . "'";
        // Whose click: any visit's row answers (a click's visits share its
        // user_id). 202_clicks is partitioned by click_time, and a probe by
        // click_id alone reads every partition, the future weeks' too (19 s
        // for 150,000 rows, measured). It is asked only of a click in the
        // file's range, where the visit the conversion names is, so it is
        // bounded by that range and reads only those days' partitions.
        $oldest = $options->now - self::CLICK_MAX_AGE;
        $sql = 'SELECT cl.conv_id, cl.click_time, cl.conv_time, cl.match_method, '
            . CountedAmount::sqlOrNull('cl') . ' AS counted_amount,' . $ids
            . ' COALESCE(cn.conversion_name, dn.conversion_name) AS conversion_name,'
            . " CASE WHEN cl.match_method IN ($named) AND cl.click_time BETWEEN ? AND ?"
            . ' THEN EXISTS (SELECT 1 FROM 202_clicks AS oc WHERE oc.click_id = cl.click_id AND oc.user_id = cl.user_id'
            . ' AND oc.click_time BETWEEN ? AND ?) END AS owned'
            . ' FROM 202_conversion_logs AS cl'
            . ' LEFT JOIN 202_offline_conversion_names AS cn ON cn.user_id = cl.user_id AND cn.platform = ?'
            . ' AND cn.aff_campaign_id = cl.campaign_id'
            . ' LEFT JOIN 202_offline_conversion_names AS dn ON dn.user_id = cl.user_id AND dn.platform = ?'
            . ' AND dn.aff_campaign_id = 0'
            . ' WHERE cl.user_id = ? AND cl.reverses_conv_id IS NULL AND cl.conv_time >= ? AND cl.conv_time <= ?';
        $platform = $options->format->platform();
        $types = 'iiiissiii';
        $values = [
            $oldest, $options->now, $oldest, $options->now,
            $platform, $platform, $userId, $options->from(), $options->now,
        ];
        if ($options->campaignId !== null) {
            $sql .= ' AND cl.campaign_id = ?';
            $types .= 'i';
            $values[] = $options->campaignId;
        }
        // Within one second, a platform that counts one conversion a second
        // reads the conversion recorded first first: it keeps the second
        // (ClickSeconds).
        $sql .= ' ORDER BY cl.conv_time DESC, cl.conv_id '
            . ($options->format->oneConversionPerSecondOf() === null ? 'DESC' : 'ASC');

        return [$sql, $types, $values];
    }

    /** The account's currency, which the ledger records every amount in. */
    private function currency(int $userId): string
    {
        $stmt = $this->conn->prepareRead('SELECT user_account_currency FROM 202_users_pref WHERE user_id = ? LIMIT 1');
        $this->conn->bind($stmt, 'i', [$userId]);
        $row = $this->conn->fetchOne($stmt);

        return \Api\V3\Controllers\UsersController::normalizeCurrency($row['user_account_currency'] ?? null);
    }

    /**
     * One CSV line, CRLF-ended as the platforms' own templates are. A field
     * is quoted only when it holds a comma, a quote or a line break (RFC
     * 4180, quotes doubled, no backslash escape): fputcsv() also quotes a
     * field with a space, and a header written "Google Click ID" is not the
     * line Google's template starts with.
     *
     * @param resource $handle
     * @param list<string> $fields
     */
    public static function put($handle, array $fields): void
    {
        $line = implode(',', array_map(
            static fn (string $field): string => strpbrk($field, ",\"\r\n") === false
                ? $field
                : '"' . str_replace('"', '""', $field) . '"',
            $fields
        )) . "\r\n";
        if (fwrite($handle, $line) !== strlen($line)) {
            throw new \RuntimeException('could not write a line of the offline conversion file');
        }
    }
}
