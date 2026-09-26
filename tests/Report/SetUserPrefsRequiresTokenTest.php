<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;

/**
 * tracking202/ajax/set_user_prefs.php writes the report filters every page
 * opens with, and it was the one endpoint of its family that wrote without
 * the session token (#173, #163): any page a logged-in user visited could
 * post to it and rewrite what their reports are drawn under.
 *
 * Two halves, both read exactly rather than by "contains" (CLAUDE.md #21):
 *
 *  - the endpoint's code, comments dropped, is its includes, the login
 *    check and then the token comparison, which ends the request when it
 *    fails — nothing before it, so no write can precede it;
 *  - the classic calendar's form, the one set_user_prefs() in custom.php
 *    serializes, carries the session token inside its own bounds.
 */
final class SetUserPrefsRequiresTokenTest extends TestCase
{
    private const ENDPOINT = 'tracking202/ajax/set_user_prefs.php';

    private const PREAMBLE = "<?php"
        . "declare(strict_types=1);"
        . "include_once(substr(__DIR__,0,-17).'/202-config/connect.php');"
        . "require_once(substr(__DIR__,0,-17).'/202-config/functions-report-prefs.php');"
        . "AUTH::require_user();"
        . "if(!hash_equals((string)(\$_SESSION['token']??''),(string)(\$_POST['token']??''))){http_response_code(403);die('Invalid token');}";

    private const FIELD = '<input type="hidden" name="token" value="<?php echo htmlspecialchars((string) ($_SESSION[\'token\'] ?? \'\'), ENT_QUOTES, \'UTF-8\'); ?>">';

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Source without comments or whitespace, so layout is not part of the claim. */
    private static function code(string $source): string
    {
        $out = '';
        foreach (\PhpToken::tokenize($source) as $token) {
            if ($token->is([T_COMMENT, T_DOC_COMMENT, T_WHITESPACE])) {
                continue;
            }
            $out .= trim($token->text);
        }
        return $out;
    }

    public function testTheEndpointComparesTheTokenBeforeAnythingElse(): void
    {
        $code = self::code((string) file_get_contents(self::root() . '/' . self::ENDPOINT));
        self::assertStringStartsWith(self::PREAMBLE, $code, self::ENDPOINT . ' opens with its includes, the login check and the token comparison, and nothing else');
        // The comparison is decided by the session's own token and nothing
        // rebinds either side after it (#22: the range is not the scope).
        self::assertSame(1, substr_count($code, "\$_SESSION['token']"), 'the session token is read once, by the guard');
        foreach (['$$', 'extract(', 'eval(', 'parse_str(', '$GLOBALS', '=&'] as $alias) {
            self::assertStringNotContainsString($alias, $code, self::ENDPOINT . " uses $alias, which the guard cannot be read past");
        }
    }

    public function testTheCalendarFormCarriesTheTokenInsideIt(): void
    {
        $source = (string) file_get_contents(self::root() . '/202-config/functions-tracking202.php');
        $open = strpos($source, '<form id="user_prefs"');
        self::assertNotFalse($open, 'the classic calendar form was found');
        self::assertSame(1, substr_count($source, '<form id="user_prefs"'), 'one form posts the report filters');
        $close = strpos($source, '</form>', $open);
        self::assertNotFalse($close, 'the form is closed');
        $form = substr($source, $open, $close - $open);
        self::assertSame(0, preg_match('~<form\b~i', substr($form, 5)), 'no form inside the form');
        self::assertSame(1, substr_count($form, self::FIELD), 'the form carries the session token, once, in its own bounds');
        $before = substr($form, 0, (int) strpos($form, self::FIELD));
        self::assertSame(substr_count($before, '<!--'), substr_count($before, '-->'), 'the token field is not inside an HTML comment');
        self::assertStringNotContainsString(' disabled', substr($form, (int) strpos($form, self::FIELD), strlen(self::FIELD)));

        // The script that posts it serializes that form, not another.
        $js = (string) file_get_contents(self::root() . '/202-js/custom.php');
        self::assertSame(1, preg_match('~\$\.post\("[^"]*tracking202/ajax/set_user_prefs\.php",\s*\$\("#user_prefs"\)\.serialize\(true\)\)~', $js), 'set_user_prefs() posts the #user_prefs form');
    }
}
