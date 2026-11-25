<?php

declare(strict_types=1);

namespace Prosper202\Report;

/**
 * One cell of a tab-separated download (the .xls files the report pages
 * write), and one row of them. Every cell of those files goes through
 * text(), so a value as the visitor sent it stays on one line and in one
 * column, and a spreadsheet does not run it.
 *
 * A tab or a line break in a keyword, referer or c1 value moved every
 * column after it, and a value beginning with =, +, - or @ is a formula to
 * Excel, LibreOffice and Sheets. A run of tabs and line breaks becomes one
 * space; a text cell beginning with one of those characters gets a leading
 * apostrophe, as in the CSV exports (Attribution\ExportCsv), so it is read
 * as text. A plain number, a percentage or a number with thousands commas
 * is left as it is, so -12.50 and -100% stay numbers.
 *
 * The cell is plain text, not HTML: the files are downloaded, never shown
 * by a browser, and an entity there is read as the five characters it is.
 */
final class TsvCell
{
    public const SEPARATOR = "\t";

    private function __construct()
    {
    }

    public static function text(string|int|float|bool|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        $text = (string) preg_replace('/[\t\r\n]+/', ' ', (string) $value);
        if ($text !== '' && str_contains('=+-@', $text[0]) && !self::isNumber($text)) {
            return "'" . $text;
        }

        return $text;
    }

    /**
     * The cells joined by tabs, with no line break at the end.
     *
     * @param list<string|int|float|bool|null> $cells
     */
    public static function line(array $cells): string
    {
        return implode(self::SEPARATOR, array_map(self::text(...), $cells));
    }

    /**
     * One row of a download: line() and its line break.
     *
     * @param list<string|int|float|bool|null> $cells
     */
    public static function row(array $cells): string
    {
        return self::line($cells) . "\n";
    }

    private static function isNumber(string $text): bool
    {
        return is_numeric($text) || preg_match('/^[+-]?\d[\d,]*(?:\.\d+)?%?$/D', $text) === 1;
    }
}
