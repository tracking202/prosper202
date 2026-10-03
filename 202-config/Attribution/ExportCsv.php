<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/**
 * The CSV of an attribution breakdown: one builder for the dashboard's
 * "Download CSV" of what is on screen and for export jobs, so the file a
 * webhook receives and the file a person downloads have the same columns.
 *
 * Money and credit sums are written exactly as MySQL computed them
 * (DECIMAL strings), not through a float. Every text cell that begins with
 * =, +, - or @ gets a leading apostrophe: keywords and c1–c4 values come
 * from click URLs anyone can craft, and a spreadsheet would otherwise run
 * them as formulas. fputcsv's escape is off (RFC 4180 quoting only), as in
 * the Mobile Apps report, so a backslash-quote cannot split a row.
 */
final class ExportCsv
{
    /**
     * @param list<array<string, mixed>> $rows breakdown rows
     * @param bool $compare whether the rows carry compare_* columns
     */
    public static function build(array $rows, bool $compare): string
    {
        $header = ['key', 'name', 'clicks', 'cost', 'attributed_conversions', 'attributed_revenue', 'roi', 'assisted_conversions'];
        if ($compare) {
            $header = array_merge($header, ['compare_attributed_conversions', 'compare_attributed_revenue', 'compare_roi']);
        }

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('could not open a buffer for the CSV');
        }
        try {
            self::put($handle, $header);
            foreach ($rows as $row) {
                $line = [];
                foreach ($header as $column) {
                    $value = $row[$column] ?? null;
                    $line[] = match (true) {
                        $value === null => '',
                        is_bool($value) => $value ? '1' : '0',
                        is_int($value), is_float($value) => (string) $value,
                        default => self::cell((string) $value),
                    };
                }
                self::put($handle, $line);
            }
            if (!rewind($handle)) {
                throw new \RuntimeException('could not rewind the CSV buffer');
            }
            $body = stream_get_contents($handle);
            if ($body === false) {
                throw new \RuntimeException('could not read the CSV buffer');
            }

            return $body;
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private static function put($handle, array $fields): void
    {
        if (fputcsv($handle, $fields, ',', '"', '') === false) {
            throw new \RuntimeException('could not write a CSV row');
        }
    }

    /** A text cell a spreadsheet will not run as a formula. */
    public static function cell(string $value): string
    {
        if ($value !== '' && strpos("=+-@\t\r", $value[0]) !== false && !is_numeric($value)) {
            return "'" . $value;
        }

        return $value;
    }
}
