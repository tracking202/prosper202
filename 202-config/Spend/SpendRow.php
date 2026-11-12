<?php

declare(strict_types=1);

namespace Prosper202\Spend;

/**
 * One hour of one external ad's cost as an ad platform reports it: the row
 * the spend ledger keeps (202_spend_ledger). Built only by parse(), which
 * refuses by field name anything it cannot read exactly (CLAUDE.md #4):
 * no value is cut, rounded or defaulted into one the sender did not send.
 */
final class SpendRow
{
    /** The fields a row carries; each is required unless OPTIONAL names it. */
    public const array FIELDS = [
        'platform', 'account_ref', 'campaign_ref', 'ad_group_ref', 'ad_ref',
        'hour_start', 'impressions', 'clicks', 'spend', 'currency',
    ];

    /** Fields a platform may not have: absent or null is none (''). */
    public const array OPTIONAL = ['campaign_ref', 'ad_group_ref'];

    /** The most rows one write takes (OpenAI's insights page is 2000). */
    public const int MAX_ROWS = 2000;

    /** The columns' widths, in characters. */
    public const int PLATFORM_WIDTH = 32;
    public const int ACCOUNT_WIDTH = 64;
    public const int REF_WIDTH = 128;

    /** The largest spend one hour of one ad may carry (decimal(16,6)). */
    private const string SPEND_PATTERN = '/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,6})?$/D';

    private function __construct(
        public readonly string $platform,
        public readonly string $accountRef,
        public readonly string $campaignRef,
        public readonly string $adGroupRef,
        public readonly string $adRef,
        public readonly int $hourStart,
        public readonly int $impressions,
        public readonly int $platformClicks,
        public readonly string $spend,
        public readonly string $currency,
    ) {
    }

    /**
     * The row a ledger writer sends, or why not: field errors keyed by the
     * field, behind $prefix (`rows.3.spend`).
     *
     * @param array<array-key, mixed> $row
     * @param int $now the hour a row may not start after (the current one)
     * @return array{0: ?self, 1: array<string, string>}
     */
    public static function parse(array $row, string $prefix, int $now): array
    {
        $errors = [];
        foreach (array_keys($row) as $key) {
            if (!in_array((string) $key, self::FIELDS, true)) {
                $errors[$prefix . $key] = 'is not a field of a spend row (accepted: ' . implode(', ', self::FIELDS) . ')';
            }
        }
        foreach (self::FIELDS as $field) {
            if (!in_array($field, self::OPTIONAL, true) && !array_key_exists($field, $row)) {
                $errors[$prefix . $field] = 'is required';
            }
        }

        $platform = self::text($row, 'platform', self::PLATFORM_WIDTH, $prefix, $errors, '/^[a-z][a-z0-9_]*$/D', 'lowercase letters, digits and _ (e.g. openai_ads)');
        $account = self::text($row, 'account_ref', self::ACCOUNT_WIDTH, $prefix, $errors);
        $campaign = self::text($row, 'campaign_ref', self::REF_WIDTH, $prefix, $errors);
        $adGroup = self::text($row, 'ad_group_ref', self::REF_WIDTH, $prefix, $errors);
        $ad = self::text($row, 'ad_ref', self::REF_WIDTH, $prefix, $errors);
        $hour = self::count($row, 'hour_start', $prefix, $errors);
        if ($hour !== null && $hour % 3600 !== 0) {
            $errors[$prefix . 'hour_start'] = 'must be the unix second an hour starts at, in UTC (a multiple of 3600); ' . $hour . ' is not';
            $hour = null;
        } elseif ($hour !== null && $hour > $now - $now % 3600) {
            $errors[$prefix . 'hour_start'] = 'is an hour that has not started yet';
            $hour = null;
        }
        $impressions = self::count($row, 'impressions', $prefix, $errors);
        $clicks = self::count($row, 'clicks', $prefix, $errors);
        $spend = self::spend($row, $prefix, $errors);
        $currency = $row['currency'] ?? null;
        if (array_key_exists('currency', $row) && (!is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1)) {
            $errors[$prefix . 'currency'] = 'must be a three-letter ISO 4217 code in capitals (e.g. USD)';
        }

        $read = $platform !== '' && $account !== '' && $ad !== '' && $hour !== null
            && $impressions !== null && $clicks !== null && $spend !== null && is_string($currency);
        if ($errors !== [] || !$read) {
            return [null, $errors];
        }

        return [new self($platform, $account, $campaign, $adGroup, $ad, $hour, $impressions, $clicks, $spend, $currency), []];
    }

    /**
     * What a row is stored under: two rows with one key are one row, so a
     * write naming one twice is refused rather than keeping either.
     * Length-prefixed, so no two keys join to one string (CLAUDE.md #17).
     */
    public function key(): string
    {
        $key = '';
        foreach ([$this->platform, $this->accountRef, $this->adRef, (string) $this->hourStart] as $part) {
            $key .= strlen($part) . ':' . $part;
        }

        return $key;
    }

    /**
     * A ref: a non-empty string of printable characters within $width, or
     * none ('') for an optional one given as null or not at all.
     *
     * @param array<array-key, mixed> $row
     * @param array<string, string> $errors
     */
    private static function text(
        array $row,
        string $field,
        int $width,
        string $prefix,
        array &$errors,
        ?string $pattern = null,
        string $patternWhat = ''
    ): string {
        $optional = in_array($field, self::OPTIONAL, true);
        if (!array_key_exists($field, $row) || ($optional && $row[$field] === null)) {
            return '';
        }
        $value = $row[$field];
        if (is_int($value) && !$pattern) {
            // An id a platform hands out as a number is the same id as text.
            $value = (string) $value;
        }
        $what = $optional ? 'a string, or null when the platform names none' : 'a non-empty string';
        if (!is_string($value) || ($value === '' && !$optional)) {
            $errors[$prefix . $field] = 'must be ' . $what;
            return '';
        }
        if (mb_check_encoding($value, 'UTF-8') === false || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            $errors[$prefix . $field] = 'must be printable UTF-8 text';
            return '';
        }
        if (mb_strlen($value) > $width) {
            $errors[$prefix . $field] = 'is longer than ' . $width . ' characters';
            return '';
        }
        if ($pattern !== null && preg_match($pattern, $value) !== 1) {
            $errors[$prefix . $field] = 'must be ' . $patternWhat;
            return '';
        }

        return $value;
    }

    /**
     * A whole number of at least 0: a JSON integer, or its digits as a string.
     *
     * @param array<array-key, mixed> $row
     * @param array<string, string> $errors
     */
    private static function count(array $row, string $field, string $prefix, array &$errors): ?int
    {
        if (!array_key_exists($field, $row)) {
            return null;
        }
        $value = $row[$field];
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,17})$/D', $value) === 1) {
            return (int) $value;
        }
        $errors[$prefix . $field] = 'must be a whole number of at least 0';

        return null;
    }

    /**
     * The spend in the currency's major units (12.5 is 12.50 dollars), with
     * up to six decimals, as the decimal string the column stores. A JSON
     * number is read only when it is exactly such a value; one with more
     * decimals is refused, never rounded.
     *
     * @param array<array-key, mixed> $row
     * @param array<string, string> $errors
     */
    private static function spend(array $row, string $prefix, array &$errors): ?string
    {
        if (!array_key_exists('spend', $row)) {
            return null;
        }
        $value = $row['spend'];
        $text = null;
        if (is_int($value)) {
            $text = (string) $value;
        } elseif (is_float($value) && is_finite($value)) {
            $fixed = sprintf('%.6F', $value);
            if ((float) $fixed === $value) {
                $text = rtrim(rtrim($fixed, '0'), '.');
            } else {
                $errors[$prefix . 'spend'] = 'must have at most six decimals; send it as a string to be exact';
                return null;
            }
        } elseif (is_string($value)) {
            $text = $value;
        }
        if ($text === null || preg_match(self::SPEND_PATTERN, $text) !== 1) {
            $errors[$prefix . 'spend'] = 'must be an amount of at least 0 in the currency\'s major units, up to six decimals (e.g. 12.5 or "12.50")';
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');

        return $whole . '.' . str_pad($fraction, 6, '0');
    }
}
