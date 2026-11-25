<?php

declare(strict_types=1);

namespace Prosper202\Setup;

/**
 * How many characters a Setup page's name field holds: its column's width,
 * which the v3 API's fields() also declares as max_length.
 *
 * The pages wrote whatever was posted, and under the app's empty sql_mode
 * MySQL cuts a VARCHAR value to its width without a word: an ad nickname of
 * 150 characters was saved as its first 100, and the forms invited 255
 * (SetupFieldLengthsTest holds this list to the controllers and the pages
 * to this list). The limit counts characters, as a VARCHAR does.
 */
final class FieldLength
{
    public const MAX = [
        'aff_network_name' => 50,
        'aff_campaign_name' => 50,
        'ppc_network_name' => 50,
        'ppc_account_name' => 50,
        'landing_page_nickname' => 50,
        'text_ad_name' => 100,
        'text_ad_headline' => 100,
        'text_ad_description' => 100,
        'text_ad_display_url' => 100,
    ];

    public static function of(string $field): int
    {
        if (!isset(self::MAX[$field])) {
            throw new \InvalidArgumentException("No length is kept for $field");
        }
        return self::MAX[$field];
    }

    /**
     * The sentence refusing a value too long for $field, or null when it fits.
     * $label names the field as the page does ("The ad nickname").
     */
    public static function refusal(string $field, string $value, string $label): ?string
    {
        $max = self::of($field);
        $length = mb_strlen($value, 'UTF-8');
        if ($length <= $max) {
            return null;
        }
        return "$label can be at most $max characters; this one has $length.";
    }
}
