<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * The chat box (202-js/p202-chat.js) builds every element it shows, so its
 * classes never appear in a class="" attribute and ComponentClassIsConsumedTest
 * cannot see them. This holds the script and its stylesheet
 * (202-css/p202-chat.css) together in both directions: every p202-chat class
 * the script writes has a rule, and every p202-chat class a rule names is one
 * the script writes (CLAUDE.md #19: a class name is not a contract). The
 * script spells each modifier out (AUTHOR_CLASS, SEND_CLASS) so none is
 * assembled where this cannot read it.
 *
 * And message text — the chat service's bodies, names and subjects, which
 * come from people — reaches the page through textContent only: the script
 * writes no markup at all (no innerHTML, outerHTML, insertAdjacentHTML or
 * document.write), so no value it shows can become one.
 */
final class ChatBoxClassesAreStyledTest extends TestCase
{
    private const SCRIPT = '202-js/p202-chat.js';
    private const SHEET = '202-css/p202-chat.css';

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<string> the p202-chat classes in the script's string literals */
    private static function scriptClasses(string $script): array
    {
        preg_match_all('/([\'"])((?:(?!\1).)*)\1/', $script, $strings);
        $classes = [];
        foreach ($strings[2] as $literal) {
            foreach (preg_split('/\s+/', trim($literal)) ?: [] as $token) {
                if (preg_match('/^p202-chat(?:__[a-z0-9-]+)?(?:--[a-z0-9-]+)?$/', $token) === 1) {
                    $classes[$token] = true;
                }
            }
        }
        ksort($classes);

        return array_keys($classes);
    }

    /** @return list<string> the p202-chat classes the sheet's selectors name */
    private static function sheetClasses(string $sheet): array
    {
        $sheet = (string) preg_replace('~/\*.*?\*/~s', '', $sheet);
        preg_match_all('/\.(p202-chat(?:__[a-z0-9-]+)?(?:--[a-z0-9-]+)?)(?![a-z0-9_-])/', $sheet, $m);
        $classes = array_values(array_unique($m[1]));
        sort($classes);

        return $classes;
    }

    public function testTheScannersFindWhatTheyLookFor(): void
    {
        $script = self::scriptClasses((string) file_get_contents(self::repoRoot() . '/' . self::SCRIPT));
        $sheet = self::sheetClasses((string) file_get_contents(self::repoRoot() . '/' . self::SHEET));
        foreach (['p202-chat', 'p202-chat__launcher', 'p202-chat__badge', 'p202-chat__msg--mine', 'p202-chat__msg--failed'] as $class) {
            self::assertContains($class, $script, "the script scan found $class");
            self::assertContains($class, $sheet, "the sheet scan found $class");
        }
        self::assertGreaterThan(30, count($script));
        // Planted: a class assembled from a literal prefix is not one the scan reads.
        self::assertSame(['p202-chat__x'], self::scriptClasses("el('div', 'p202-chat__x'); var k = 'p202.chat.pass';"));
        self::assertSame(['p202-chat__y--z'], self::sheetClasses(".p202-chat__y--z:hover { } /* .p202-chat__gone */ @keyframes p202-chat-spin {}"));
    }

    public function testEveryClassTheScriptWritesHasARule(): void
    {
        $missing = array_values(array_diff(
            self::scriptClasses((string) file_get_contents(self::repoRoot() . '/' . self::SCRIPT)),
            self::sheetClasses((string) file_get_contents(self::repoRoot() . '/' . self::SHEET))
        ));
        self::assertSame([], $missing, 'classes the chat box writes that ' . self::SHEET . ' has no rule for');
    }

    public function testEveryRuleNamesAClassTheScriptWrites(): void
    {
        $unused = array_values(array_diff(
            self::sheetClasses((string) file_get_contents(self::repoRoot() . '/' . self::SHEET)),
            self::scriptClasses((string) file_get_contents(self::repoRoot() . '/' . self::SCRIPT))
        ));
        self::assertSame([], $unused, 'rules in ' . self::SHEET . ' for classes the chat box never writes (a typo, or dead CSS)');
    }

    public function testTheScriptWritesNoMarkup(): void
    {
        $script = (string) file_get_contents(self::repoRoot() . '/' . self::SCRIPT);
        $script = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $script);
        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'createContextualFragment', 'DOMParser', 'srcdoc'] as $sink) {
            self::assertStringNotContainsString($sink, $script, self::SCRIPT . " uses $sink; message text goes in with textContent");
        }
        self::assertStringContainsString('textContent', $script);
    }
}
