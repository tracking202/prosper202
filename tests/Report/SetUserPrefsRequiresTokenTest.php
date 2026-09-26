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
 *  - no page or script posts to it any more: the classic calendar's form,
 *    which carried the token, went with display_calendar() in U8 (and a new
 *    caller fails here until its form is read for the token too).
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

    /**
     * The classic calendar's form was the one caller that posted here, and
     * it carried the token; U8 removed it with display_calendar(). Nothing
     * in the tree posts to the endpoint any more, and a caller that comes
     * back has to be read the way that form was — this fails until it is.
     */
    public function testNoPageOrScriptPostsToTheEndpoint(): void
    {
        $skip = '#^(vendor|tests|node_modules|go-cli|sdk|documentation|docs)/|^\.#';
        $posters = [];
        $seen = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root(), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = substr($file->getPathname(), strlen(self::root()) + 1);
            if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'js', 'html'], true)
                || preg_match($skip, $path) === 1 || $path === self::ENDPOINT) {
                continue;
            }
            $seen++;
            $source = (string) file_get_contents($file->getPathname());
            // PHP is read without its comments; a script or page as it is.
            $code = $file->getExtension() === 'php' ? self::code($source) : $source;
            if (str_contains($code, 'set_user_prefs.php') || str_contains($code, 'id="user_prefs"') || str_contains($code, 'display_calendar(')) {
                $posters[] = $path;
            }
        }
        self::assertGreaterThan(300, $seen, 'the walk read the tree');
        self::assertSame([], $posters, 'a caller of set_user_prefs.php must carry the session token inside the form it posts; read it here as the calendar form was');
    }
}
