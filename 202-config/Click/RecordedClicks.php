<?php

declare(strict_types=1);

namespace Prosper202\Click;

/**
 * The click ids THIS request allocated, and nothing else.
 *
 * A click id reaches the redirect code from two kinds of place: the counter
 * insert this request made (dl.php, rtr.php, record_simple.php,
 * record_adv.php), and the visitor (the non-httponly `tracking202subid*`
 * cookies that lp.php, lpc.php, off.php and offrtr.php read, a pixel's
 * cookie, a postback's `subid`). Both look the same by the time they are a
 * `$click_id` string. The install token (plan §5.1) must only ever sign the
 * first kind, or anyone can obtain a signed token for another visitor's
 * sequential click id by setting a cookie (CLAUDE.md #16): so the one thing
 * that knows — the allocation — says so here, and the signer asks.
 *
 * Every `INSERT INTO 202_clicks_counter` in the tree notes its id
 * (RecordedClicksAreNotedTest). The set lives for one PHP request.
 */
final class RecordedClicks
{
    /** @var array<int, true> */
    private static array $ids = [];

    private function __construct()
    {
    }

    public static function note(int $clickId): void
    {
        if ($clickId > 0) {
            self::$ids[$clickId] = true;
        }
    }

    /**
     * Whether this request allocated the click. Only a canonical positive
     * integer (as an int or its exact decimal string) can be one: "042",
     * "42 " and "42.0" never match 42.
     */
    public static function has(mixed $clickId): bool
    {
        if (is_int($clickId)) {
            return isset(self::$ids[$clickId]);
        }
        if (!is_string($clickId) || preg_match('/^[1-9][0-9]{0,18}$/D', $clickId) !== 1 || (string) (int) $clickId !== $clickId) {
            return false;
        }

        return isset(self::$ids[(int) $clickId]);
    }

    /** For tests: forget every noted click. */
    public static function reset(): void
    {
        self::$ids = [];
    }
}
