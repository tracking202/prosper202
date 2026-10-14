<?php

declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;

/**
 * The four click scripts read a keyword from the referring page's q and from
 * the parameter a t202var_ keyword names. Either can be a list (q[]=1,
 * x[]=1), and a list reaching findOrCreateKeyword(string) is a TypeError:
 * the click is lost and the visitor gets a 500. rtr.php took a list as no
 * keyword; dl.php, record_simple.php and record_adv.php did not (measured:
 * dl.php answered 500 to both). Each script reads either value only after
 * is_string() of that same value.
 */
final class KeywordSourcesAreStringsTest extends TestCase
{
    private const SCRIPTS = [
        'tracking202/redirect/dl.php',
        'tracking202/redirect/rtr.php',
        'tracking202/static/record_simple.php',
        'tracking202/static/record_adv.php',
    ];

    private const SOURCES = [
        'referer q' => '$referer_query[\'q\']',
        't202var' => '$_GET[$t202var]',
    ];

    public function testEachKeywordSourceIsReadOnlyAsAString(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (self::SCRIPTS as $script) {
            $source = file_get_contents($root . '/' . $script);
            self::assertIsString($source, $script);
            foreach (self::SOURCES as $name => $read) {
                $assignment = '/\$keyword\s*=\s*(?:\(string\)\s*)?' . preg_quote($read, '/') . '\s*;/';
                $count = preg_match_all($assignment, $source, $found, PREG_OFFSET_CAPTURE);
                self::assertGreaterThan(0, $count, "$script assigns the $name keyword");
                foreach ($found[0] as [, $offset]) {
                    $before = substr($source, 0, $offset);
                    $condition = substr($before, (int) strrpos($before, 'if ('));
                    self::assertStringContainsString(
                        'is_string(' . $read,
                        $condition,
                        "$script reads the $name keyword without is_string() of it in the condition above"
                    );
                }
            }
        }
    }
}
