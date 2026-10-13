<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickCloaking;
use Tests\Support\SourceScan;

/**
 * Whether a click is cloaked, and what the click keeps of its tracker's
 * setting, when the tracker may not be there at all.
 *
 * A landing page reached without a t202id has no tracker row to merge, so
 * record_simple.php and lp.php read a click_cloaking key that was not there
 * (an "Undefined array key" warning on every such click, measured in the
 * server log) and reached the campaign's setting through `!isset()`; and
 * record_adv.php stored -1 ("the campaign decides") for a link whose
 * cloaking is set to 0 ("off for this link"), so off.php cloaked it whenever
 * the campaign did. The rows below are the shapes those pages hand in: a
 * tracker's integer column as mysqli returns it (a string), NULL, and no key.
 */
final class ClickCloakingTest extends TestCase
{
    /** The hand-written decision's "campaign decides" arm. */
    private const HAND_WRITTEN = "/\\[\\s*'click_cloaking'\\s*\\]\\s*==\\s*-\\s*1/";

    /** @return iterable<string, array{array<string, mixed>, bool, int}> row, cloaked, kept */
    public static function rows(): iterable
    {
        yield 'no tracker, campaign on' => [['aff_campaign_cloaking' => '1'], true, -1];
        yield 'no tracker, campaign off' => [['aff_campaign_cloaking' => '0'], false, -1];
        yield 'a NULL setting, campaign on' => [['click_cloaking' => null, 'aff_campaign_cloaking' => '1'], true, -1];
        yield 'the campaign decides: on' => [['click_cloaking' => '-1', 'aff_campaign_cloaking' => '1'], true, -1];
        yield 'the campaign decides: off' => [['click_cloaking' => '-1', 'aff_campaign_cloaking' => '0'], false, -1];
        yield 'the link turns it on' => [['click_cloaking' => '1', 'aff_campaign_cloaking' => '0'], true, 1];
        yield 'the link turns it off' => [['click_cloaking' => '0', 'aff_campaign_cloaking' => '1'], false, 0];
        yield 'integers, as the API hands them' => [['click_cloaking' => 0, 'aff_campaign_cloaking' => 1], false, 0];
        yield 'no campaign setting either' => [[], false, -1];
    }

    /**
     * @dataProvider rows
     * @param array<string, mixed> $row
     */
    public function testTheDecision(array $row, bool $cloaked, int $kept): void
    {
        self::assertSame($cloaked, ClickCloaking::isOn($row));
        self::assertSame($kept, ClickCloaking::trackerSetting($row));
    }

    /**
     * The condition dl.php, lp.php, off.php and record_simple.php each spelled
     * out, run on every row a present key can hold: the helper answers the
     * same, so moving the four onto it changed no click that had a tracker.
     */
    public function testItAnswersAsTheHandWrittenConditionDidWheneverTheKeyIsThere(): void
    {
        $old = static fn (array $r): bool => ($r['click_cloaking'] == 1)
            || (($r['click_cloaking'] == -1) && ($r['aff_campaign_cloaking'] == 1))
            || ((!isset($r['click_cloaking'])) && ($r['aff_campaign_cloaking'] == 1));
        foreach ([null, '-1', '0', '1', '2', -1, 0, 1] as $tracker) {
            foreach (['0', '1', 0, 1] as $campaign) {
                $row = ['click_cloaking' => $tracker, 'aff_campaign_cloaking' => $campaign];
                self::assertSame($old($row), ClickCloaking::isOn($row), var_export($row, true));
            }
        }
    }

    /**
     * No click endpoint spells the decision out by hand again: its tell is
     * the "campaign decides" arm, `['click_cloaking'] == -1`. (pci.php's
     * `== 1` reads the decision a simple landing page already stored, and the
     * Get Links page compares labels; neither is this.)
     */
    public function testTheEndpointsAskTheHelper(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (!str_starts_with($path, 'tracking202/redirect/') && !str_starts_with($path, 'tracking202/static/')) {
                continue;
            }
            if (preg_match_all(self::HAND_WRITTEN, $source, $m, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($m[0] as [, $offset]) {
                    $found[] = $path . ':' . (substr_count(substr($source, 0, $offset), "\n") + 1);
                }
            }
        }
        self::assertSame([], $found, 'decide cloaking with ClickCloaking::isOn(): a row with no tracker has no'
            . ' click_cloaking key');
    }
}
