<?php

declare(strict_types=1);

namespace Prosper202\OfflineConversion;

use Prosper202\Conversion\Ledger\MatchMethod;

/**
 * Why a conversion in the window is not a line of the file. Every conversion
 * the file could have held is counted once, under the first of these that
 * applies, in the order of the cases (OfflineExport::reasonFor()), so the
 * counts and the file's lines add up to the conversions in the window.
 */
enum LeftOut: string
{
    case NOT_COUNTED = 'not_counted';
    case MATCHED_BY_VISITOR_ID = 'visitor_id';
    case MATCHED_BY_SAME_IP = 'same_ip';
    case MATCH_UNKNOWN = 'match_unknown';
    case NO_CLICK_ID = 'no_click_id';
    case GBRAID_WBRAID_ONLY = 'gbraid_wbraid_only';
    case MALFORMED_CLICK_ID = 'malformed_click_id';
    case CLICK_TOO_OLD = 'click_too_old';
    case BEFORE_CLICK = 'before_click';
    case NOT_ACCOUNTS_CLICK = 'not_accounts_click';
    case CLICK_TOO_RECENT = 'click_too_recent';
    case NEGATIVE_VALUE = 'negative_value';
    case NO_CONVERSION_NAME = 'no_conversion_name';
    case OVER_ROW_LIMIT = 'over_row_limit';

    /** The reason a conversion whose click was found this way is left out, or null when it was named. */
    public static function forMatch(MatchMethod $method): ?self
    {
        return match ($method) {
            MatchMethod::CLICK_ID, MatchMethod::COOKIE => null,
            MatchMethod::VISITOR_ID => self::MATCHED_BY_VISITOR_ID,
            MatchMethod::SAME_IP => self::MATCHED_BY_SAME_IP,
            MatchMethod::UNKNOWN => self::MATCH_UNKNOWN,
        };
    }

    /** The sentence the page, the API and the CLI give for it. */
    public function explanation(): string
    {
        return match ($this) {
            self::NOT_COUNTED => 'Does not count: deleted, refunded in full, replaced by a later conversion on its'
                . ' click, or not payable.',
            self::MATCHED_BY_VISITOR_ID => 'Its click was the one the landing page\'s visitor id was last seen on,'
                . ' not one the conversion named.',
            self::MATCHED_BY_SAME_IP => 'Nothing named its click; it was guessed from the visitor\'s IP address.'
                . ' A guess would teach the ad platform\'s bidding the wrong clicks.',
            self::MATCH_UNKNOWN => 'Recorded before 1.9.79, which kept no record of how its click was found.',
            self::NO_CLICK_ID => 'Its click came with no click id from this ad platform.',
            self::GBRAID_WBRAID_ONLY => 'Its click has only a gbraid or wbraid, which the Uploads template has no'
                . ' column for. The Data Manager file sends it.',
            self::MALFORMED_CLICK_ID => 'Its click id is not in the form the ad platform issues (an msclkid is 32'
                . ' hexadecimal digits), so it did not come from an ad click and matches none.',
            self::CLICK_TOO_OLD => 'Its click is more than 90 days old; Google and Microsoft import no conversion'
                . ' that long after its click.',
            self::BEFORE_CLICK => 'Its conversion time is before its click, which the platforms refuse.',
            self::NOT_ACCOUNTS_CLICK => 'It names a click of another account.',
            self::CLICK_TOO_RECENT => 'Its click is less than 6 hours old, which the platforms may not have processed'
                . ' yet. A later fetch whose window still covers it sends it.',
            self::NEGATIVE_VALUE => 'Its value is below zero, which the platforms refuse.',
            self::NO_CONVERSION_NAME => 'Its campaign has no conversion name for this platform, and the account has'
                . ' no default.',
            self::OVER_ROW_LIMIT => 'Past Microsoft\'s 100,000 rows an upload; the oldest conversions are the ones'
                . ' left out. Shorten the window.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
