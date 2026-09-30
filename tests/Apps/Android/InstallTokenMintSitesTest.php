<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use PHPUnit\Framework\TestCase;

/**
 * Where an install token can be minted, across the whole tree.
 *
 * InstallToken signs whatever click id it is handed, so the question that
 * decides whether a token can be stolen is not in that class but at every
 * place that calls it: does that place know the click is the requester's?
 * Three places may sign, each for a reason it can state:
 *
 * - InstallTokenGrant: a click this request allocated, or one whose token
 *   the visitor already presents (the proof cookie);
 * - setClickIdCookie() in connect2.php: the proof cookie itself, only for a
 *   click RecordedClicks says this request allocated;
 * - AppInstallsController: an authenticated operator's own click.
 *
 * A fourth call site fails this test by name, whatever it signs. The live
 * pass (tests/live/android-intake.sh) is what proves the redirect refuses a
 * forged click cookie; this test is what keeps a new signer from appearing
 * beside the guarded one.
 */
final class InstallTokenMintSitesTest extends TestCase
{
    private const ALLOWED = [
        'api/v3/Apps/Android/InstallTokenGrant.php',
        'api/v3/Controllers/AppInstallsController.php',
        '202-config/connect2.php',
        // The class itself: forClick() is what expand() calls.
        'api/v3/Apps/Android/InstallToken.php',
    ];

    /** @return iterable<string, string> relative path => source */
    private static function sources(): iterable
    {
        $root = dirname(__DIR__, 3);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = substr((string) $file->getPathname(), strlen($root) + 1);
            if (!str_ends_with($path, '.php') || preg_match('#^(vendor|tests|node_modules|\.git|\.claude|sdk|go-cli)/#', $path) === 1) {
                continue;
            }
            // Code only: a comment that names a call or a table is not one.
            $code = '';
            foreach (token_get_all((string) file_get_contents((string) $file->getPathname())) as $token) {
                if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                    $code .= str_repeat("\n", substr_count($token[1], "\n"));
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }
            yield $path => $code;
        }
    }

    public function testOnlyTheGuardedPlacesSignAClick(): void
    {
        $found = [];
        foreach (self::sources() as $path => $src) {
            // Any spelling of a call into the signer: the class by any
            // qualification, a `use` alias would still end in the method name.
            if (preg_match('/\b(?:InstallToken|InstallTokenGrant)\s*::\s*(?:forClick|expand|forRequest)\s*\(/', $src) === 1
                || preg_match('/\buse\s+Api\\\\V3\\\\Apps\\\\Android\\\\InstallToken\s+as\b/i', $src) === 1) {
                $found[] = $path;
            }
        }
        sort($found);
        $unexpected = array_values(array_diff($found, self::ALLOWED));
        self::assertSame([], $unexpected, 'a new place signs install tokens; route it through p202ProvenInstallToken() or InstallTokenGrant');
        self::assertContains('202-config/connect2.php', $found);
    }

    public function testConnect2SignsOnlyThroughTheGrantAndTheRecordedProof(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/202-config/connect2.php');
        preg_match_all('/\b(InstallToken|InstallTokenGrant)\s*::\s*(forClick|expand|forRequest)\s*\(/', $src, $calls, PREG_OFFSET_CAPTURE);
        self::assertCount(2, $calls[0], 'connect2.php signs in exactly two places');

        $grant = self::functionBody($src, 'p202ProvenInstallToken');
        self::assertMatchesRegularExpression(
            '/^\s*return\s+\\\\Api\\\\V3\\\\Apps\\\\Android\\\\InstallTokenGrant::forRequest\(\s*\$clickId\s*,\s*\\\\Prosper202\\\\Click\\\\RecordedClicks::has\(\$clickId\)\s*,\s*\$_COOKIE\s*,\s*\'p202InstallTokenKey\'\s*\);\s*$/',
            $grant,
            'the redirect\'s token is the grant for this click id, recorded-here decided by RecordedClicks and the proof read from the request'
        );

        $cookie = self::functionBody($src, 'setClickIdCookie');
        $guard = strpos($cookie, 'if (\Prosper202\Click\RecordedClicks::has($click_id)) {');
        $sign = strpos($cookie, 'InstallToken::forClick((int) $click_id,');
        self::assertIsInt($guard, 'the proof cookie is set only for a click this request allocated');
        self::assertIsInt($sign);
        self::assertLessThan($sign, $guard);
        // Inside the block, not after it: from the guard's own brace to the
        // signing call, the depth never falls back to where it started.
        $inside = substr($cookie, $guard + strlen('if (\Prosper202\Click\RecordedClicks::has($click_id)) '), $sign - $guard);
        $depth = 0;
        foreach (str_split($inside) as $i => $ch) {
            $depth += $ch === '{' ? 1 : ($ch === '}' ? -1 : 0);
            if ($i > 0) {
                self::assertGreaterThan(0, $depth, 'the signing sits inside the RecordedClicks block, not after it');
            }
        }
    }

    public function testEveryClickIdAllocationIsNoted(): void
    {
        $sites = 0;
        foreach (self::sources() as $path => $src) {
            if (preg_match_all('/INSERT\s+INTO\s+`?202_clicks_counter`?/i', $src, $m, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($m[0] as [, $offset]) {
                ++$sites;
                // The id is read back and noted within the next few lines,
                // before anything can hand it to a redirect.
                $after = implode("\n", array_slice(explode("\n", substr($src, $offset)), 0, 8));
                self::assertMatchesRegularExpression(
                    '/RecordedClicks::note\(/',
                    $after,
                    $path . ': a click id allocated here is never noted, so the redirect cannot sign it (or, if it signs anyway, signs cookie ids too)'
                );
            }
        }
        self::assertGreaterThanOrEqual(4, $sites, 'the repository (2), rtr.php and getClickId() allocate click ids');
    }

    private static function functionBody(string $src, string $name): string
    {
        $start = strpos($src, 'function ' . $name . '(');
        self::assertIsInt($start, $name . ' exists');
        $open = strpos($src, '{', $start);
        $depth = 0;
        for ($i = $open, $n = strlen($src); $i < $n; ++$i) {
            if ($src[$i] === '{') {
                ++$depth;
            } elseif ($src[$i] === '}' && --$depth === 0) {
                return substr($src, $open + 1, $i - $open - 1);
            }
        }
        self::fail($name . ' has no closing brace');
    }
}
