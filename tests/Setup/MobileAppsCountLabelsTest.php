<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Tracking202\Setup\MobileAppsController;

/**
 * The one place Setup › Mobile Apps says how many apps it is showing.
 *
 * The page counts the same list twice — the "Your apps" pill and the
 * "Getting started" checklist — and the two wordings were written as two
 * copies of the rule, one of which printed a bare count. On a truncated read
 * that would have put "500 registered" beside "500 of 620 apps": one list,
 * two numbers, and the smaller one asserted as the total. Both wordings come
 * from appCountLabels() now, and these are every branch of it.
 */
final class MobileAppsCountLabelsTest extends TestCase
{
    /**
     * The controller sits outside the PSR-4 roots, so the suite loads it by
     * path. Inside the hook rather than at file scope: a require there makes
     * this a file that both declares a class and has a side effect, which is
     * the one thing phpcs objects to.
     */
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/tracking202/setup/MobileAppsController.php';
    }

    /**
     * @return array<string, array{0: int, 1: int|null, 2: bool, 3: string, 4: string}>
     */
    public static function counts(): array
    {
        return [
            // shown, total, truncated, pill, checklist
            'none' => [0, 0, false, '0 apps', '0 registered'],
            'one is singular' => [1, 1, false, '1 app', '1 registered'],
            'a few' => [7, 7, false, '7 apps', '7 registered'],
            // The whole point of the pair: the ceiling is visible in both.
            'cut, total known' => [500, 620, true, '500 of 620 apps', '500 of 620'],
            // The count could not be read. "500 of 500" would be a number
            // the reader believes; "first 500" is only what is on screen.
            'cut, total unknown' => [500, null, true, 'first 500 apps', 'first 500 shown'],
            // Contract only: RegisteredApps reports truncated=false when the
            // total it got back equals the rows in hand, so the page does not
            // reach this. Pinned anyway — the helper is told the read was cut
            // and must not then print a figure that reads as complete.
            'cut, total equals shown' => [500, 500, true, '500 of 500 apps', '500 of 500'],
            // An untruncated read never needs the total, so it never
            // mentions it even when one is to hand.
            'not cut, total to hand' => [3, 3, false, '3 apps', '3 registered'],
            'not cut, total unknown' => [3, null, false, '3 apps', '3 registered'],
        ];
    }

    /**
     * @dataProvider counts
     */
    public function testBothWordingsComeFromOneRule(
        int $shown,
        ?int $total,
        bool $truncated,
        string $pill,
        string $checklist
    ): void {
        $labels = MobileAppsController::appCountLabels($shown, $total, $truncated);

        $this->assertSame($pill, $labels['pill']);
        $this->assertSame($checklist, $labels['checklist']);
    }

    /**
     * A truncated read must never render a figure that reads as the total.
     *
     * The failure being guarded is a wording one, not an arithmetic one: any
     * new branch that answers a cut list with a bare "N registered" is a
     * number the page does not know, however it was computed.
     */
    public function testATruncatedReadNeverRendersABareCount(): void
    {
        foreach ([[500, 620], [500, null], [1, null], [0, null]] as [$shown, $total]) {
            $labels = MobileAppsController::appCountLabels($shown, $total, true);
            foreach ($labels as $where => $text) {
                $this->assertMatchesRegularExpression(
                    '/\bof\b|^first /',
                    $text,
                    "the $where wording states a cut list as a plain total: '$text'"
                );
            }
        }
    }

    /**
     * The template reads both wordings from the helper and computes neither.
     *
     * A second copy is what put two numbers on the page; this is the check
     * that a third does not appear. It reads the template as text because the
     * file cannot be included here — it calls template_top() and renders.
     */
    public function testTheTemplateDerivesNeitherWordingItself(): void
    {
        $template = file_get_contents(
            dirname(__DIR__, 2) . '/tracking202/setup/templates/mobile_apps.php'
        );
        $this->assertIsString($template);

        $this->assertStringContainsString('MobileAppsController::appCountLabels(', $template);
        $this->assertStringContainsString("\$appCount['pill']", $template);
        $this->assertStringContainsString("\$appCount['checklist']", $template);

        // The wordings themselves live in the helper, so none of their pieces
        // may be spelled in the markup. Not "' of '": the receiver summary's
        // own script builds "2 of 2 ready" from it, a different count
        // entirely, and a guard that fires on correct code gets deleted.
        foreach (["' registered'", "' apps'", "'first '"] as $fragment) {
            $this->assertStringNotContainsString(
                $fragment,
                $template,
                "the template spells part of a count wording ($fragment) instead of reading appCountLabels()"
            );
        }
    }
}
