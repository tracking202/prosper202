<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Tests\Support\CallArgs;
use Tests\Support\SourceScan;

/**
 * Every click endpoint (tracking202/redirect/, tracking202/static/) names
 * whose click it is — p202ApplyOwnerPrivacy(), the stricter of the install's
 * privacy setting and that account's — before anything the setting governs:
 * storing the visitor's address (p202StoredVisitorIp()), setting a click
 * cookie on the tracker's site (setClickIdCookie() and its siblings, a raw
 * setcookie(), the identity cookie through p202ClickIdentity() or
 * sendCookie()) or on the landing page's (p202ClickCookieJs(), the
 * personalization token), or asking trackingEnabled() directly.
 *
 * The click path applied the install's setting alone: measured live, an
 * account set to 'all' under an install set to 'disabled' had its visitor's
 * address stored as it arrived and was set every click cookie, and the
 * identity cookie, the landing-page script's click cookies and go.php's 202v
 * cookies were set under every setting, the install's included.
 *
 * Two shapes are refused outright, because they would set a cookie without
 * asking: ClickIdentity::fromRequest() called directly (p202ClickIdentity()
 * reads, mints and sends no p202vid for a visitor held back), and a
 * createCookie('…') call written into an endpoint's script
 * (p202ClickCookieJs() writes none for a visitor held back).
 *
 * What this reads is order in the file, not reachability: an apply inside a
 * branch the request does not take, or one naming the wrong account, passes
 * here. The runtime is the backstop for the first — until an endpoint has
 * applied an owner, trackingEnabled() holds back (masks the address, sets
 * nothing) and logs the script that asked (OwnerPrivacyTest) — and the
 * second is for a reader of each call site: the account is the tracker's,
 * the landing page's, the click's or the campaign's.
 */
final class OwnerPrivacyAppliedFirstTest extends TestCase
{
    private const APPLY = 'p202applyownerprivacy';

    /** Calls the privacy setting governs (lower case). */
    private const GOVERNED = [
        'p202storedvisitorip', 'trackingenabled', 'setclickidcookie', 'setclickidcookieforlp', 'setpcidcookie',
        'setoutboundcookie', 'p202clickcookiejs', 'p202clickidentity', 'p202mintpersonalizationcookiejs',
        'setcookie', 'setrawcookie', 'sendcookie',
    ];

    /** A cookie written into a landing page's script by hand. */
    private const SCRIPT_COOKIE = '/\bcreateCookie\(\s*[\'"]/';

    private static function inScope(string $path): bool
    {
        return str_starts_with($path, 'tracking202/redirect/') || str_starts_with($path, 'tracking202/static/');
    }

    /**
     * The governed calls made before the first apply, and those made in a
     * file that applies none.
     *
     * @return array{list<string>, int} problems, governed calls read
     */
    private static function unapplied(string $path, string $source): array
    {
        $calls = CallArgs::calls($source, array_merge([self::APPLY], self::GOVERNED));
        usort($calls, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);
        $applied = null;
        $problems = [];
        $read = 0;
        foreach ($calls as $call) {
            if ($call['name'] === self::APPLY) {
                $applied ??= $call['line'];
                continue;
            }
            // ClickIdentity's own sendCookie() is the method; a function of
            // that name is not one.
            if ($call['name'] === 'sendcookie' && $call['operator'] === '') {
                continue;
            }
            $read++;
            if ($applied === null || $applied > $call['line']) {
                $problems[] = $path . ':' . $call['line'] . '  ' . $call['name']
                    . '() before any p202ApplyOwnerPrivacy()';
            }
        }

        return [$problems, $read];
    }

    public function testEveryEndpointNamesTheOwnerBeforeWhatThePrivacySettingGoverns(): void
    {
        $problems = [];
        $read = 0;
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (!self::inScope($path)) {
                continue;
            }
            [$found, $calls] = self::unapplied($path, $source);
            array_push($problems, ...$found);
            $read += $calls;
        }

        // dl, rtr, off, pci, go, the two recorders, gpx, upx, px, pb, gpb.
        self::assertGreaterThanOrEqual(20, $read, 'the scan finds the governed calls');
        self::assertSame([], $problems, "A click endpoint stores an address or sets a cookie before it names whose"
            . " click this is:\n  " . implode("\n  ", $problems)
            . "\nCall p202ApplyOwnerPrivacy(<the account whose tracker, landing page, click or campaign this is>)"
            . ' first: until then the visitor is held back.');
    }

    public function testNoEndpointSetsACookieWithoutAsking(): void
    {
        $problems = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (!str_starts_with($path, 'tracking202/')) {
                continue;
            }
            foreach (CallArgs::calls($source, ['fromRequest']) as $call) {
                if ($call['operator'] === '::' && str_ends_with($call['receiver'], 'ClickIdentity')) {
                    $problems[] = $path . ':' . $call['line']
                        . '  ClickIdentity::fromRequest(): use p202ClickIdentity()';
                }
            }
            if (!self::inScope($path)) {
                continue;
            }
            $carriers = [T_INLINE_HTML, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];
            foreach (token_get_all($source) as $t) {
                $text = is_array($t) && in_array($t[0], $carriers, true) ? $t[1] : '';
                if ($text !== '' && preg_match(self::SCRIPT_COOKIE, $text) === 1) {
                    $problems[] = $path . ':' . $t[2] . '  createCookie() in the script: use p202ClickCookieJs()';
                }
            }
            foreach (CallArgs::calls($source, ['p202MintPersonalizationCookieJs']) as $call) {
                if (CallArgs::text($call['args'][4] ?? []) !== 'trackingEnabled()') {
                    $problems[] = $path . ':' . $call['line'] . '  p202MintPersonalizationCookieJs() without'
                        . ' trackingEnabled() as its fifth argument';
                }
            }
        }

        self::assertSame([], $problems, "A cookie is set without asking the privacy setting:\n  "
            . implode("\n  ", $problems));
    }

    /** @return iterable<string, array{string, int}> source, problems */
    public static function shapes(): iterable
    {
        yield 'applied first' => ["<?php\np202ApplyOwnerPrivacy(\$r['user_id']);\n\$ip = p202StoredVisitorIp();", 0];
        yield 'stored before the owner' => ["<?php\n\$ip = p202StoredVisitorIp();\np202ApplyOwnerPrivacy(1);", 1];
        yield 'never applied' => ["<?php\nsetClickIdCookie(\$id, \$c);", 1];
        yield 'a raw cookie' => ["<?php\nsetcookie('tracking202subid', '1');", 1];
        yield 'the identity cookie' => ["<?php\n\$identity->sendCookie(\$_SERVER);", 1];
        yield 'a method named like a setter is still one' => ["<?php\n\$x->setPCIdCookie(1);", 1];
        yield 'the landing page\'s cookies' => ["<?php\necho p202ClickCookieJs(['tracking202subid' => '1']);", 1];
        yield 'asked directly' => ["<?php\nif (trackingEnabled()) {}", 1];
    }

    /** @dataProvider shapes */
    public function testTheShapesTheScanReads(string $source, int $problems): void
    {
        self::assertCount($problems, self::unapplied('x.php', $source)[0]);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function scriptCookies(): iterable
    {
        yield 'single quotes' => ["createCookie('tracking202subid',subid,0);", true];
        yield 'double quotes' => ['createCookie("tracking202pci", pci, 0);', true];
        yield 'the definition' => ['function createCookie(name, value, days) {', false];
        yield 'a variable name' => ['createCookie(name, "", -1);', false];
    }

    /** @dataProvider scriptCookies */
    public function testTheScriptCookiePattern(string $text, bool $matches): void
    {
        self::assertSame($matches, preg_match(self::SCRIPT_COOKIE, $text) === 1);
    }
}
