<?php

declare(strict_types=1);

namespace Prosper202\Click;

/**
 * Whether a click is cloaked, from its tracker's setting and its campaign's.
 *
 * A tracker's click_cloaking overrides the campaign: 1 on, 0 off, -1 the
 * campaign decides (Get Links' default; TrackersController). A click with no
 * tracker — a landing page reached without a t202id, or one whose t202id
 * names none — has nothing to override with, so the campaign decides: the
 * row has no click_cloaking key at all. dl.php, lp.php, off.php and
 * record_simple.php each wrote this decision out by hand, reading the
 * missing key directly (an "Undefined array key" warning on every
 * tracker-less landing-page click) and reaching the campaign through
 * `!isset()`.
 */
final class ClickCloaking
{
    public const CAMPAIGN_DECIDES = -1;

    private function __construct()
    {
    }

    /**
     * The tracker's setting on a row that may have none: -1 when the row has
     * no click_cloaking (no tracker) or it is NULL, 1 and -1 as stored, and
     * anything else 0, "off for this link", as isOn() has always read it: the
     * TINYINT column holds whatever Get Links was posted, and record_adv.php
     * stores this on the click, which takes -1, 0 or 1 (ClickRecord).
     *
     * record_adv.php stores this on the click for off.php to decide with, and
     * stored -1 for 0 as well (`!$row['click_cloaking']`), so a link set to
     * "off" was cloaked whenever its campaign was.
     *
     * @param array<string, mixed> $row
     */
    public static function trackerSetting(array $row): int
    {
        $value = $row['click_cloaking'] ?? null;
        if ($value === null) {
            return self::CAMPAIGN_DECIDES;
        }

        return match ((int) $value) {
            1 => 1,
            self::CAMPAIGN_DECIDES => self::CAMPAIGN_DECIDES,
            default => 0,
        };
    }

    /**
     * Whether the click is cloaked: the tracker says on, or it leaves the
     * decision to a campaign that says on.
     *
     * @param array<string, mixed> $row the tracker's click_cloaking (when there
     *        is a tracker) and the campaign's aff_campaign_cloaking
     */
    public static function isOn(array $row): bool
    {
        $tracker = self::trackerSetting($row);

        return $tracker === 1
            || ($tracker === self::CAMPAIGN_DECIDES && (int) ($row['aff_campaign_cloaking'] ?? 0) === 1);
    }
}
