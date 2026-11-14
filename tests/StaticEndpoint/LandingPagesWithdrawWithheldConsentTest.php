<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;

/**
 * record_simple.php and record_adv.php, for a click the tracking template's
 * hit already made, store the page's withheld consent with that click's
 * visit and take back the click's browser marks
 * (ClickIdentity::withdrawIfWithheld()), before they attach the page's own
 * signals: the address a destination sends is then not that of a visitor
 * whose page refused.
 *
 * Held by reading each script's tokens: the withdrawal is called once; it is
 * the first statement of the block `if ($<x>ParallelClick !== null) {`, and
 * is `$clickIdentity->withdrawIfWithheld($conn, $click_id,
 * $<x>ParallelClick->clickTime);` and nothing more; and the statement
 * directly after it is the script's first `$clickIdentity->attach(`
 * (CLAUDE.md #21, #22).
 *
 * Not read here: that $click_id is the click the template made.
 */
final class LandingPagesWithdrawWithheldConsentTest extends TestCase
{
    private const SCRIPTS = [
        'tracking202/static/record_simple.php' => '$rsParallelClick',
        'tracking202/static/record_adv.php' => '$raParallelClick',
    ];

    public function testEachPageWithdrawsBeforeItAttaches(): void
    {
        foreach (self::SCRIPTS as $script => $found) {
            $code = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $script);
            self::assertSame([], self::problems($code, $found), $script);
        }
    }

    public function testTheScanRefusesEachWayTheCallCanGo(): void
    {
        $if = 'if ($pc !== null) {';
        $withdraw = ' $clickIdentity->withdrawIfWithheld($conn, $click_id, $pc->clickTime);';
        $attach = ' $clickIdentity->attach($conn, 1, $click_id, $pc->clickTime);';
        $control = '<?php ' . $if . $withdraw . $attach . ' }';
        self::assertSame([], self::problems($control, '$pc'), 'the positive control');
        $swap = static fn (string $old, string $new): string => str_replace($old, $new, $withdraw);
        $planted = [
            'no withdrawal' => [$if . $attach . ' }', 'called once'],
            'called twice' => [$if . $withdraw . $attach . $withdraw . ' }', 'called once'],
            'after the attach' => [$if . $attach . $withdraw . ' }', 'first statement'],
            'outside the block' => [$withdraw . $if . $attach . ' }', 'first statement'],
            'in another block' => ['if ($other !== null) {' . $withdraw . $attach . ' }', 'first statement'],
            'the block of another test' => ['if ($pc === null) {' . $withdraw . $attach . ' }', 'first statement'],
            'under a braceless if' => [$if . ' if ($x)' . $withdraw . $attach . ' }', 'first statement'],
            'another click' => [$if . $swap('$click_id', '$other') . $attach . ' }', 'and nothing more'],
            'another visit' => [$if . $swap('$pc->clickTime', '$o->clickTime') . $attach . ' }', 'and nothing more'],
            'no visit' => [$if . $swap(', $pc->clickTime', '') . $attach . ' }', 'and nothing more'],
            'another identity' => [$if . $swap('$clickIdentity', '$other') . $attach . ' }', 'called once'],
            'its answer overridden' => [$if . $swap(');', ') ?? x();') . $attach . ' }', 'and nothing more'],
            'a statement between' => [$if . $withdraw . ' $click_id = 7;' . $attach . ' }', 'directly after'],
            'an attach before the block' => [$attach . $if . $withdraw . $attach . ' }', 'directly after'],
            'named in a string' => [$if . ' $m = "withdrawIfWithheld";' . $attach . ' }', 'called once'],
        ];
        foreach ($planted as $why => [$code, $expected]) {
            $problems = self::problems('<?php ' . $code, '$pc');
            self::assertCount(1, $problems, $why . ' trips one check: ' . implode(' | ', $problems));
            self::assertStringContainsString($expected, $problems[0], $why . ' is refused by its own check');
        }
    }

    /** @return list<string> */
    private static function problems(string $code, string $found): array
    {
        $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG];
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], $skip, true)
        ));
        $text = static fn (int $i): string => isset($tokens[$i])
            ? (is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i])
            : '';
        $run = static function (int $from, int $count) use ($text): string {
            $s = '';
            for ($i = $from; $i < $from + $count; $i++) {
                $s .= $text($i);
            }

            return $s;
        };
        $calls = [];
        $attaches = [];
        foreach ($tokens as $i => $t) {
            if ($text($i) !== '$clickIdentity' || !in_array($text($i + 1), ['->', '?->'], true)) {
                continue;
            }
            if (strcasecmp($text($i + 2), 'withdrawIfWithheld') === 0) {
                $calls[] = $i;
            } elseif (strcasecmp($text($i + 2), 'attach') === 0) {
                $attaches[] = $i;
            }
        }
        if (count($calls) !== 1) {
            return ['$clickIdentity->withdrawIfWithheld() is not called once: ' . count($calls) . ' calls'];
        }
        $call = $calls[0];
        if ($run($call - 7, 7) !== 'if(' . $found . '!==null){') {
            return ['the withdrawal is not the first statement of the block if (' . $found . ' !== null) {'];
        }
        if ($run($call, 13) !== '$clickIdentity->withdrawIfWithheld($conn,$click_id,' . $found . '->clickTime);') {
            return ['the statement is not $clickIdentity->withdrawIfWithheld($conn, $click_id, ' . $found
                . '->clickTime); and nothing more'];
        }
        if ($attaches === [] || $attaches[0] !== $call + 13) {
            return ['the script\'s first $clickIdentity->attach() is not the statement directly after the withdrawal'];
        }

        return [];
    }
}
