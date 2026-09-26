<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;

/**
 * Every POST the Setup family makes carries the session token, and every
 * endpoint it posts to checks one (error pattern #5).
 *
 * Two holes of exactly this shape were open until U4, and neither was a
 * missing check on the server:
 *
 *  - Setup › Traffic Sources' "add a traffic source" form put its token field
 *    inside the `if ($network_editing)` branch, so the add form posted none
 *    and every new source was refused with "Invalid or expired form token".
 *    A form is only guarded if the token is inside THAT form (#21: the claim
 *    is "the form that posts carries the token", not "the page mentions it").
 *  - tracking202/ajax/custom_variables.php and delete_tracker.php wrote
 *    without asking for a token at all, beside endpoints that did.
 *
 * So this reads each <form method="post"> in the Setup sources, bounds it at
 * its own </form>, and asserts the token field is rendered inside it; and it
 * derives the AJAX endpoints from the Setup sources themselves (every
 * tracking202/ajax/*.php they name) and asserts each carries the session
 * token comparison. It is a source check — tests/live/setup-pages.sh is the
 * proof over HTTP that the refusals really happen (403 or "ERROR", and no
 * row written) for the endpoints that write.
 */
final class SetupPostsRequireTokenTest extends TestCase
{
    /** The spellings a Setup form renders its token field with. */
    private const TOKEN_FIELDS = [
        'p202_setup_token_field(',            // the v2 Setup pages
        "\$mobileApps['csrf']",               // Setup › Mobile Apps (SetupController::renderCsrfField)
        '$this->renderCsrfField()',
        'name="token"',                       // a literal field
        'name="csrf_token"',
    ];

    /** The session-token comparison an endpoint must make. */
    private const GUARD = "hash_equals((string) (\$_SESSION['token'] ?? ''), (string) (\$_POST['token'] ?? ''))";

    /** The classic page U4 left alone (the MTA rewrite replaces it). */
    private const NOT_MIGRATED = ['tracking202/setup/attribution_models.php', 'tracking202/setup/templates/attribution_models.php', 'tracking202/setup/AttributionController.php'];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> repo-relative Setup sources */
    private static function setupSources(): array
    {
        $root = self::root();
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/tracking202/setup', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() === 'php') {
                $relative = ltrim(substr($file->getPathname(), strlen($root)), '/');
                if (!in_array($relative, self::NOT_MIGRATED, true)) {
                    $files[] = $relative;
                }
            }
        }
        $files[] = '202-js/p202-setup.js';
        sort($files);
        return $files;
    }

    /**
     * Each post form in a source: its opening tag and body, bounded at its
     * own closing tag.
     *
     * @return list<array{line: int, form: string}>
     */
    private static function postForms(string $source): array
    {
        $forms = [];
        // An echo block inside the tag (action="…") ends in a question mark
        // and a bracket that do not end the tag; read past it, or a
        // method="post" after it goes unread and the form is skipped.
        if (!preg_match_all('~<form\b(?:<\?php.*?\?>|[^>])*?\bmethod="post"(?:<\?php.*?\?>|[^>])*>~is', $source, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        foreach ($matches[0] as [$tag, $offset]) {
            $end = stripos($source, '</form>', $offset);
            self::assertNotFalse($end, 'a post form at offset ' . $offset . ' is closed');
            $forms[] = [
                'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                'form' => substr($source, $offset, $end - $offset),
            ];
        }
        return $forms;
    }

    public function testEveryPostFormCarriesTheTokenInsideIt(): void
    {
        $checked = 0;
        $missing = [];
        foreach (self::setupSources() as $file) {
            $source = (string) file_get_contents(self::root() . '/' . $file);
            foreach (self::postForms($source) as $form) {
                $checked++;
                // A form nested inside another would end the scan at the
                // inner </form>; HTML forbids nesting, so refuse it by name.
                self::assertSame(0, preg_match('~<form\b~i', substr($form['form'], 5)), "$file:{$form['line']}: a form inside a form");
                $carried = false;
                foreach (self::TOKEN_FIELDS as $spelling) {
                    if (str_contains($form['form'], $spelling)) {
                        $carried = true;
                    }
                }
                if (!$carried) {
                    $missing[] = "$file:{$form['line']}";
                }
            }
        }
        self::assertGreaterThanOrEqual(12, $checked, 'the Setup post forms were found (a scanner that finds none passes vacuously)');
        self::assertSame([], $missing, 'these Setup forms post without the session token inside them');
    }

    public function testEveryEndpointTheSetupPagesPostToChecksTheToken(): void
    {
        $endpoints = [];
        foreach (self::setupSources() as $file) {
            $source = (string) file_get_contents(self::root() . '/' . $file);
            if (preg_match_all('~tracking202/ajax/([a-z_]+)\.php~', $source, $matches)) {
                foreach ($matches[1] as $name) {
                    $endpoints[$name] = true;
                }
            }
        }
        $endpoints = array_keys($endpoints);
        sort($endpoints);
        foreach (['custom_variables', 'delete_tracker', 'generate_tracking_link', 'get_landing_code', 'get_adv_landing_code', 'rotator'] as $expected) {
            self::assertContains($expected, $endpoints, "the Setup sources name $expected, so the scan found the endpoints");
        }

        $unguarded = [];
        foreach ($endpoints as $name) {
            $source = (string) file_get_contents(self::root() . '/tracking202/ajax/' . $name . '.php');
            // Spacing is not part of the comparison: `(string)(…)` and
            // `(string) (…)` are the same expression.
            $squeeze = static fn (string $code): string => (string) preg_replace('~\s+~', '', $code);
            if (!str_contains($squeeze($source), $squeeze(self::GUARD))) {
                $unguarded[] = $name;
            }
        }
        self::assertSame([], $unguarded, 'these endpoints a Setup page posts to do not compare the session token');
    }

    /**
     * "The file compares the token" is not "every branch that acts does"
     * (#21). dni_get_offers.php compared it in one of its five branches, so
     * the check above stayed green while request_offer_access and
     * setup_offer made my.tracking202.com act for the account on a bare GET
     * (#164). So this reads the file branch by branch: every top-level
     * branch that calls a DNI function other than the two readers opens
     * with p202_dni_require_token(), and that function is exactly a POST
     * check plus the session-token comparison, ending the request when
     * either fails. The acting functions are derived from the calls, and
     * the set is pinned, so a new one has to be classified here.
     */
    public function testEveryDniBranchThatActsRequiresAPostWithTheToken(): void
    {
        $file = 'tracking202/ajax/dni_get_offers.php';
        $source = (string) file_get_contents(self::root() . '/' . $file);
        $readers = ['getDniOffers', 'getDniOfferById'];

        preg_match_all('/\b(\w*Dni\w*)\(/', $source, $calls);
        $acting = array_values(array_unique(array_diff($calls[1], $readers, ['p202_dni_require_token', 'p202_dni_row', 'p202_dni_token_param'])));
        sort($acting);
        self::assertSame(['requestDniOfferAccess', 'setupDniOffer', 'submitDniOfferAnswers'], $acting,
            'the DNI calls that make the network act; a new one must be classified as a reader or an action here');

        // Top-level `if (…) {` branches, each bounded at its own brace.
        $tokens = \PhpToken::tokenize($source);
        $branches = [];
        $depth = 0;
        foreach ($tokens as $i => $token) {
            if ($token->text === '{') {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
            } elseif ($depth === 0 && $token->is(T_IF)) {
                $j = $i + 1;
                $paren = 0;
                for (; $j < count($tokens); $j++) {
                    $paren += match ($tokens[$j]->text) { '(' => 1, ')' => -1, default => 0 };
                    if ($paren === 0 && $tokens[$j]->text === ')') {
                        break;
                    }
                }
                $open = $j + 1;
                while ($tokens[$open]->is(T_WHITESPACE)) {
                    $open++;
                }
                self::assertSame('{', $tokens[$open]->text, "$file: a top-level branch at line {$token->line} has braces");
                $level = 0;
                $body = '';
                for ($k = $open; $k < count($tokens); $k++) {
                    $level += match ($tokens[$k]->text) { '{' => 1, '}' => -1, default => 0 };
                    $body .= $tokens[$k]->text;
                    if ($level === 0) {
                        break;
                    }
                }
                $branches[] = ['line' => $token->line, 'body' => $body];
            }
        }
        $acts = 0;
        foreach ($branches as $branch) {
            $callsHere = preg_match_all('/\b(' . implode('|', $acting) . ')\(/', $branch['body']);
            if ($callsHere === 0) {
                continue;
            }
            $acts++;
            $code = '';
            foreach (\PhpToken::tokenize('<?php ' . $branch['body']) as $t) {
                if (!$t->is([T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_OPEN_TAG])) {
                    $code .= $t->text;
                }
            }
            self::assertStringStartsWith('{p202_dni_require_token();', $code, "$file:{$branch['line']}: a branch that makes the network act opens with the token requirement");
        }
        self::assertSame(3, $acts, 'every acting branch was found');

        // The requirement itself: POST, the session token, or the request ends.
        self::assertSame(1, preg_match('/function p202_dni_require_token\(\): void\s*\{(.*?)\n\}/s', $source, $fn), 'the requirement is a function of the file');
        $squeezed = (string) preg_replace('/\s+/', '', $fn[1]);
        self::assertSame(
            "if((\$_SERVER['REQUEST_METHOD']??'')!=='POST'||!hash_equals((string)(\$_SESSION['token']??''),(string)(\$_POST['token']??''))){http_response_code(403);die('Invalidtoken,pleasereloadthepageandtryagain.');}",
            $squeezed,
            'p202_dni_require_token() is exactly a POST check and the session-token comparison, and ends the request'
        );
        self::assertSame(1, substr_count($source, 'function p202_dni_require_token('), 'declared once');

        // And the page asks for those actions with a POST, which the shell's
        // prefilter gives the token.
        $js = (string) file_get_contents(self::root() . '/202-js/p202-setup.js');
        $at = strpos($js, "offerRequest('request_offer_access&");
        self::assertNotFalse($at, 'the page asks for access');
        $depth = 0;
        $call = '';
        for ($i = $at + strlen('offerRequest'); $i < strlen($js); $i++) {
            $depth += match ($js[$i]) { '(' => 1, ')' => -1, default => 0 };
            $call .= $js[$i];
            if ($depth === 0) {
                break;
            }
        }
        self::assertStringEndsWith(', true)', $call, 'asking for access is an action: offerRequest(…, true)');
        self::assertStringContainsString("(act ? jq.post(url + '?' + query, {}, null, 'html') : jq.get(", $js, 'and an action is posted');
        self::assertSame(1, preg_match("/jq\.post\(url \+ '\?setup_offer&/", $js), 'setting an offer up is posted');
        self::assertSame(1, preg_match("/jq\.post\(url \+ '\?submit_offer_questions&/", $js), 'answering questions is posted');
    }
}
