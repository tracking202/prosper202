<?php

declare(strict_types=1);

namespace Prosper202\Conversion;

use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Conversion\Ledger\LedgerReportSql;
use Prosper202\Database\Connection;

/**
 * An account's conversions on clicks that carried a Google click id (gclid)
 * or a Microsoft click id (msclkid), as the CSV each platform's offline
 * conversion import takes ("Import conversions from ad clicks into Google
 * Ads using files"; Microsoft Advertising's offline conversion template).
 *
 * A row is a conversion that counts (LedgerReportSql: payable, live, not
 * superseded), at its value net of the reversals that count against it;
 * one netted to nothing, one whose click is more than 90 days older than it
 * (both platforms drop the click id by then) and one whose click id is not
 * one the platform issues are left out and counted by reason. Times are
 * written in UTC with the TimeZone parameter +0000, so no row depends on
 * which offset its zone had at the time.
 *
 * The file is built a page of rows at a time, never holding the rows, and
 * stops at MAX_ROWS, Microsoft's limit for one upload, saying where the
 * next export should start.
 */
final class OfflineConversionExport
{
    public const PLATFORMS = ['google', 'microsoft'];

    public const MAX_ROWS = 100000;

    public const MAX_CLICK_AGE_SECONDS = 90 * 86400;

    private const PAGE = 5000;

    /**
     * platform => [the click id's form, the click's id, that the click has
     * one]. The click path writes a row with an empty id when a click
     * carried only UTM values (insertGclid()), so '' is "no click id", never
     * a row's absence alone.
     */
    private const CLICK_IDS = [
        'google' => [
            '/^[A-Za-z0-9_-]{8,150}$/D',
            "(SELECT MIN(ci.gclid) FROM 202_google AS ci WHERE ci.click_id = cl.click_id AND ci.gclid <> '')",
            "EXISTS (SELECT 1 FROM 202_google AS cx WHERE cx.click_id = cl.click_id AND cx.gclid <> '')",
        ],
        'microsoft' => [
            '/^[A-Za-z0-9-]{8,64}$/D',
            "(SELECT MIN(ci.msclkid) FROM 202_bing AS ci WHERE ci.click_id = cl.click_id AND ci.msclkid <> '')",
            "EXISTS (SELECT 1 FROM 202_bing AS cx WHERE cx.click_id = cl.click_id AND cx.msclkid <> '')",
        ],
    ];

    public function __construct(
        private readonly Connection $conn,
        private readonly int $maxRows = self::MAX_ROWS,
        private readonly int $page = self::PAGE,
    ) {
    }

    /**
     * Why a conversion name cannot go in the file, or null when it can: the
     * platform matches it to a conversion action by exact spelling, and a
     * spreadsheet opening the file reads a leading =, +, - or @ as a formula.
     */
    public static function nameProblem(string $name): ?string
    {
        if ($name === '') {
            return 'is required: the conversion action\'s name, spelled exactly as in the ad platform';
        }
        if (strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            return 'must be at most 100 characters on one line';
        }
        if (trim($name) !== $name) {
            return 'must not begin or end with a space';
        }
        if (strpbrk($name[0], '=+-@') !== false) {
            return 'must not begin with =, +, - or @ (a spreadsheet reads that as a formula)';
        }

        return null;
    }

    /**
     * @return array{
     *     csv: string, rows: int, skipped: array{reversed: int, click_too_old: int, malformed_click_id: int},
     *     truncated: bool, next_time_from: int|null
     * }
     */
    public function export(
        int $userId,
        string $platform,
        int $from,
        int $to,
        string $conversionName,
        string $currency
    ): array {
        if (!isset(self::CLICK_IDS[$platform])) {
            throw new \InvalidArgumentException('platform must be one of: ' . implode(', ', self::PLATFORMS));
        }
        $problem = self::nameProblem($conversionName);
        if ($problem !== null) {
            throw new \InvalidArgumentException('conversion name ' . $problem);
        }
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new \InvalidArgumentException('currency must be a three-letter code such as USD');
        }
        [$form, $clickRef, $hasClickRef] = self::CLICK_IDS[$platform];

        $out = fopen('php://temp', 'w+');
        if ($out === false) {
            throw new \RuntimeException('no memory stream for the export');
        }
        $google = $platform === 'google';
        if ($google) {
            self::line($out, ['Parameters:TimeZone=+0000']);
            self::line($out, [
                'Google Click ID', 'Conversion Name', 'Conversion Time', 'Conversion Value', 'Conversion Currency',
                'Order ID',
            ]);
        } else {
            fwrite($out, "\xEF\xBB\xBF");
            self::line($out, ['### TEMPLATE ###', '', '', '', '', '', '', '']);
            self::line($out, ['Parameters:TimeZone=+0000', '', '', '', '', '', '', '']);
            self::line($out, [
                'Conversion Name', 'Conversion Time', 'Conversion Value', 'Conversion Currency',
                'Microsoft Click ID', 'Hashed Email Address', 'Hashed Phone Number', '',
            ]);
        }

        $sql = 'SELECT cl.conv_id, cl.conv_time, cl.click_time, cl.click_payout, ' . $clickRef . ' AS click_ref,'
            . ' (SELECT COALESCE(SUM(r.click_payout), 0) FROM 202_conversion_logs AS r'
            . '   WHERE r.click_id = cl.click_id AND r.user_id = cl.user_id AND r.reverses_conv_id = cl.conv_id'
            . '   AND ' . LedgerReportSql::countedPredicate('r') . ') AS reversed'
            . ' FROM 202_conversion_logs AS cl'
            . ' WHERE cl.user_id = ? AND cl.conv_time >= ? AND cl.conv_time <= ?'
            . ' AND (cl.conv_time > ? OR (cl.conv_time = ? AND cl.conv_id > ?))'
            . ' AND cl.reverses_conv_id IS NULL AND cl.source <> ?'
            . ' AND ' . $hasClickRef
            . ' AND ' . LedgerReportSql::countedPredicate('cl')
            . ' ORDER BY cl.conv_time, cl.conv_id LIMIT ' . max(1, $this->page);

        $rows = 0;
        $skipped = ['reversed' => 0, 'click_too_old' => 0, 'malformed_click_id' => 0];
        $truncated = false;
        $nextFrom = null;
        $afterTime = $from - 1;
        $afterId = 0;
        do {
            $stmt = $this->conn->prepareRead($sql);
            $this->conn->bind($stmt, 'iiiiiis', [
                $userId, $from, $to, $afterTime, $afterTime, $afterId, ConversionSource::LEGACY_BASELINE->value,
            ]);
            $batch = $this->conn->fetchAll($stmt);
            foreach ($batch as $row) {
                $afterTime = (int) $row['conv_time'];
                $afterId = (int) $row['conv_id'];
                if ($rows === $this->maxRows) {
                    $truncated = true;
                    $nextFrom = $afterTime;
                    break 2;
                }
                $ref = (string) $row['click_ref'];
                $net = Amount::toUnits((string) $row['click_payout']) + Amount::toUnits((string) $row['reversed']);
                if (preg_match($form, $ref) !== 1) {
                    $skipped['malformed_click_id']++;
                } elseif ((int) $row['conv_time'] - (int) $row['click_time'] > self::MAX_CLICK_AGE_SECONDS) {
                    $skipped['click_too_old']++;
                } elseif ($net <= 0) {
                    $skipped['reversed']++;
                } else {
                    $time = gmdate('Y-m-d H:i:s', (int) $row['conv_time']);
                    if ($google) {
                        $value = self::decimal($net, 2);
                        self::line($out, [$ref, $conversionName, $time, $value, $currency, 'p202-' . $row['conv_id']]);
                    } else {
                        self::line($out, [$conversionName, $time, self::decimal($net, 3), $currency, $ref, '', '', '']);
                    }
                    $rows++;
                }
            }
        } while (count($batch) === max(1, $this->page));

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        if ($csv === false) {
            throw new \RuntimeException('the export could not be read back');
        }

        return [
            'csv' => $csv,
            'rows' => $rows,
            'skipped' => $skipped,
            'truncated' => $truncated,
            'next_time_from' => $nextFrom,
        ];
    }

    /** The file name a download is offered under: the window's first and last day in the account's zone. */
    public static function filename(string $platform, int $from, int $to, \DateTimeZone $zone): string
    {
        $day = static fn (int $t): string => (new \DateTimeImmutable('@' . $t))->setTimezone($zone)->format('Ymd');

        return ($platform === 'google' ? 'google-ads' : 'microsoft-ads') . '-offline-conversions-'
            . $day($from) . '-' . $day($to) . '.csv';
    }

    /** Units of 0.00001 as a decimal with $places places, rounded half up. */
    private static function decimal(int $units, int $places): string
    {
        $step = 10 ** (Amount::SCALE - $places);
        $rounded = intdiv($units + intdiv($step, 2), $step);

        $fraction = str_pad((string) ($rounded % 10 ** $places), $places, '0', STR_PAD_LEFT);

        return intdiv($rounded, 10 ** $places) . '.' . $fraction;
    }

    /**
     * One CSV line, CRLF-terminated as both platforms' templates are.
     *
     * @param resource $out
     * @param list<string> $fields
     */
    private static function line($out, array $fields): void
    {
        $cells = array_map(
            static fn (string $f): string => strpbrk($f, ",\"\r\n") === false
                ? $f
                : '"' . str_replace('"', '""', $f) . '"',
            $fields
        );
        if (fwrite($out, implode(',', $cells) . "\r\n") === false) {
            throw new \RuntimeException('the export could not be written');
        }
    }
}
