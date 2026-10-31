<?php

declare(strict_types=1);

namespace Prosper202\OfflineConversion;

/**
 * What one offline conversion file holds: its layout, the window of
 * conversion times it covers (the last $days days to $now), and the
 * account's statements for the columns Prosper202 cannot read off a
 * conversion.
 *
 * - $campaignId: only that campaign's conversions;
 * - $conversionName: every conversion under this name, whatever its
 *   campaign's (a one-off file for one conversion action);
 * - $onlyConversionName: only the conversions whose name is this, for a
 *   platform that takes one file per conversion action (a fetch URL's);
 * - $adUserData, $adPersonalization: the consent columns of a Google file,
 *   empty when null.
 */
final class ExportOptions
{
    /** How far back a window may reach: the platforms import nothing older. */
    public const MAX_DAYS = 90;

    public function __construct(
        public readonly Format $format,
        public readonly int $days,
        public readonly int $now,
        public readonly ?int $campaignId = null,
        public readonly ?string $conversionName = null,
        public readonly ?string $onlyConversionName = null,
        public readonly ?Consent $adUserData = null,
        public readonly ?Consent $adPersonalization = null,
    ) {
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new \InvalidArgumentException('days must be 1 to ' . self::MAX_DAYS . ', got ' . $days);
        }
        if ($campaignId !== null && $campaignId < 1) {
            throw new \InvalidArgumentException('campaign id must be positive, got ' . $campaignId);
        }
        foreach ([$conversionName, $onlyConversionName] as $name) {
            if ($name !== null && ConversionNames::problem($name) !== null) {
                throw new \InvalidArgumentException((string) ConversionNames::problem($name));
            }
        }
        if (!$format->takesConsent() && ($adUserData !== null || $adPersonalization !== null)) {
            throw new \InvalidArgumentException('the ' . $format->value . ' file has no consent columns');
        }
    }

    /** The first conversion time the window covers. */
    public function from(): int
    {
        return $this->now - $this->days * 86400;
    }

    /**
     * A file's options from a request's parameters (the API's query, the
     * Setup page's download form), each read strictly: format required, days
     * a whole number from 1 to 90 ($defaultDays when absent or ''),
     * campaign_id a positive whole number, conversion_name a name
     * ConversionNames would store, the consent columns granted or denied and
     * only for a Google file. Every value that is not one of these is an
     * error naming its parameter; what is not named here is the caller's to
     * refuse.
     *
     * @param array<array-key, mixed> $params
     * @return array{0: self|null, 1: array<string, string>} the options, or the errors
     */
    public static function fromRequest(array $params, int $now, int $defaultDays = self::MAX_DAYS): array
    {
        $errors = [];
        $text = static function (string $key) use ($params, &$errors): ?string {
            if (!array_key_exists($key, $params) || $params[$key] === null || $params[$key] === '') {
                return null;
            }
            if (!is_string($params[$key])) {
                $errors[$key] = 'Takes one value, not a list.';
                return null;
            }

            return $params[$key];
        };
        $formatText = $text('format');
        $format = $formatText === null ? null : Format::tryFrom($formatText);
        if ($format === null && !isset($errors['format'])) {
            $errors['format'] = ($formatText === null ? 'Required: one of ' : 'One of ')
                . implode(', ', Format::values()) . ($formatText === null ? '.' : ', got ' . $formatText . '.');
        }
        $daysText = $text('days');
        $days = $defaultDays;
        if ($daysText !== null) {
            if (
                preg_match('/\A[0-9]{1,3}\z/D', $daysText) !== 1
                || (int) $daysText < 1
                || (int) $daysText > self::MAX_DAYS
            ) {
                $errors['days'] = 'A whole number, 1 to ' . self::MAX_DAYS . ': days of conversions, back from now.';
            } else {
                $days = (int) $daysText;
            }
        }
        $campaignText = $text('campaign_id');
        $campaignId = null;
        if ($campaignText !== null) {
            if (preg_match('/\A[1-9][0-9]{0,8}\z/D', $campaignText) !== 1) {
                $errors['campaign_id'] = 'A positive whole number: a campaign id from `p202 campaign list`.';
            } else {
                $campaignId = (int) $campaignText;
            }
        }
        $name = $text('conversion_name');
        if ($name !== null && ($problem = ConversionNames::problem($name)) !== null) {
            $errors['conversion_name'] = $problem;
        }
        $consent = [];
        foreach (['ad_user_data', 'ad_personalization'] as $key) {
            $value = $text($key);
            $consent[$key] = $value === null ? null : Consent::tryFrom($value);
            if ($value !== null && $consent[$key] === null) {
                $errors[$key] = 'granted or denied, or leave it out for the column to be empty.';
            } elseif ($consent[$key] !== null && $format === Format::MICROSOFT) {
                $errors[$key] = 'Microsoft Advertising\'s file has no consent columns; leave ' . $key . ' out.';
            }
        }
        if ($errors !== [] || $format === null) {
            return [null, $errors];
        }

        $options = new self(
            $format,
            $days,
            $now,
            $campaignId,
            $name,
            null,
            $consent['ad_user_data'],
            $consent['ad_personalization']
        );

        return [$options, []];
    }
}
