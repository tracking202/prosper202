<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * Nothing in the served tree builds a URL of this install out of the Host
 * header or the server name by hand: every read of HTTP_HOST or SERVER_NAME
 * is one of the readers listed here, each with its reason.
 *
 * Twenty-three sites wrote `'http://' . $_SERVER['SERVER_NAME'] . …`: the
 * port dropped (SERVER_NAME has none), http forced on an HTTPS install,
 * nginx's configured name in place of the host the browser asked for, and in
 * dl.php and lp.php the install directory left out — so the cloaked
 * redirects, the tracking202outbound cookie and the URLs stored with a click
 * pointed at port 80 on every install served from another port. They now
 * take TrackingBaseUrl::forRequest() / requestUrl(), which read the Host
 * header through RequestHost (validated) and the scheme through
 * p202_request_is_https().
 *
 * A read is a token naming either key: a quoted string (`$_SERVER['…']`,
 * `$server["…"]`, `getenv('…')`, `filter_input(INPUT_SERVER, '…')`, a list
 * of keys) or the bare offset of a simple interpolation (`"$_SERVER[…]"`).
 * Comments are not reads. A key computed at run time (`'HTTP_' . 'HOST'`)
 * or a loop over $_SERVER names neither, and is not seen.
 */
final class RequestHostSourceTest extends TestCase
{
    private const KEYS = ['HTTP_HOST', 'SERVER_NAME'];

    /**
     * Repo-relative file => how many reads it holds, and why each is not a
     * hand-built URL of this install on the request's origin.
     *
     * @var array<string, array{int, string}>
     */
    private const ALLOWED = [
        '202-config/Http/RequestHost.php' => [1, 'the reader of the Host header'],
        '202-config/Click/TrackingBaseUrl.php' => [2, 'the server-name fallback when the Host header is not a host'],
        '202-config/connect.php' => [3, "maps nginx's catch-all server name `_` to the request's host"],
        '202-config/connect2.php' => [
            6,
            "the same `_` mapping (3); the install fingerprint hash (1); the server_name column of 202_mysql_errors"
            . ' and the error email (2)',
        ],
        '202-config/functions-tracking202.php' => [
            4,
            'the server_name column of 202_mysql_errors (1); the license'
            . " service's click-server id, the Host header as sent (2); the install fingerprint hash (1)",
        ],
        // getTrackingDomain() (both copies) read SERVER_NAME and SERVER_PORT
        // as its fallback, the address the server listens on rather than the
        // one a browser reaches; it is TrackingBaseUrl::domainForResponse()
        // now, and its callers are TRACKING_DOMAIN_CALLERS below.
        '202-account/clickservers.php' => [
            1,
            "compared with the license service's click-server id, which is the Host header as sent",
        ],
        '202-config/functions-auth.php' => [2, 'the list of request fields a security log records'],
        '202-config/Slack.class.php' => [4, "the Slack message's sender name (senderName() and payload()'s check for one)"],
        // 202-lost-pass.php read SERVER_NAME for the reset link, which goes to
        // someone other than the requester; it reads the stored address now
        // (PasswordResetLink), and a read there fails this test.
    ];

    /**
     * getTrackingDomain() is the stored tracking domain, or — with none
     * stored — the host this request arrived at (TrackingBaseUrl::
     * domainForResponse()). That fallback was SERVER_NAME and SERVER_PORT:
     * behind a reverse proxy or a published container port every link a page
     * showed carried an address nobody outside could reach. The Host header
     * is right for a URL that goes back to whoever asked (a forged one
     * misleads only the forger) and wrong for anything sent to someone else
     * (CLAUDE.md #16), so every call is listed, file => how many and why its
     * URL goes back to the requester. A URL for anyone else — an email, the
     * hosted service, the server's own outbound request — is
     * p202TrackingBaseUrl(), the stored domain or the server's own name.
     *
     * @var array<string, array{int, string}>
     */
    private const TRACKING_DOMAIN_CALLERS = [
        '202-config/functions-tracking202.php' => [
            1,
            'generateTrackingLoaderSnippet(): the landing-page loader snippet shown to the signed-in user',
        ],
        '202-config/template.php' => [
            1,
            "the cron beacon a signed-in page has that user's own browser send",
        ],
        '202-account/administration.php' => [1, 'the GeoIP directory shown to the signed-in administrator'],
        '202-account/api-integrations.php' => [
            1,
            'the INS / IPN / ZPN / webhook endpoints shown to the signed-in user to paste into a network',
        ],
        'tracking202/ajax/generate_tracking_link.php' => [2, 'the tracking link built for the signed-in user'],
        'tracking202/ajax/get_adv_landing_code.php' => [1, 'the landing-page code shown to the signed-in user'],
        'tracking202/ajax/get_landing_code.php' => [1, 'the landing-page code shown to the signed-in user'],
        'tracking202/setup/get_postback.php' => [1, 'the pixel and postback URLs shown to the signed-in user'],
        'tracking202/setup/get_trackers.php' => [2, 'the tracker links listed for the signed-in user'],
        'tracking202/static/landing.php' => [
            1,
            "the landing-page script calls back the host the visitor's browser loaded it from (no-store)",
        ],
        // 202-cronjobs/process_dataengine_job.php built the URLs the server
        // fetches from itself with it, reachable from a request to
        // 202-cronjobs/ with any Host: it takes p202TrackingBaseUrl() now,
        // and a call there fails this test.
    ];

    /**
     * The lines that call getTrackingDomain() (not its definitions).
     *
     * @return list<int>
     */
    private static function trackingDomainCalls(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $lines = [];
        foreach ($tokens as $i => $token) {
            $named = is_array($token)
                && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                && ltrim($token[1], '\\') === 'getTrackingDomain';
            if (!$named || ($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }
            $before = $tokens[$i - 1] ?? null;
            $notTheFunction = [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW];
            if (is_array($before) && in_array($before[0], $notTheFunction, true)) {
                continue;
            }
            $lines[] = $token[2];
        }

        return $lines;
    }

    public function testEveryCallerOfGetTrackingDomainAnswersTheRequester(): void
    {
        $unlisted = [];
        $miscounted = [];
        $seen = 0;
        foreach (SourceScan::phpFiles() as $path => $source) {
            $lines = self::trackingDomainCalls($source);
            if ($lines === []) {
                continue;
            }
            $seen += count($lines);
            if (!isset(self::TRACKING_DOMAIN_CALLERS[$path])) {
                $unlisted[] = $path . ' line ' . implode(', ', $lines);
            } elseif (self::TRACKING_DOMAIN_CALLERS[$path][0] !== count($lines)) {
                $miscounted[] = $path . ': ' . count($lines) . ' calls (lines ' . implode(', ', $lines) . '), '
                    . self::TRACKING_DOMAIN_CALLERS[$path][0] . ' listed';
            }
        }
        self::assertSame([], $unlisted, 'getTrackingDomain() falls back to the Host header: a URL that goes to anyone'
            . ' but the requester takes p202TrackingBaseUrl(); one that goes back to the requester is listed with why');
        self::assertSame([], $miscounted, 'a listed file gained or lost a call: list it, or route it');
        $listed = array_sum(array_map(static fn (array $entry): int => $entry[0], self::TRACKING_DOMAIN_CALLERS));
        self::assertSame($listed, $seen, 'a listed file no longer calls it');
    }

    /** The license service's deeplink-cookie pixel: it names this install's address to the service. */
    private const DEEPLINK_PIXEL = 'dni/deeplink/cookie/set/';

    /** What every deeplink pixel encodes: the stored address, or the server's own name. */
    private const DEEPLINK_ADDRESS = 'p202TrackingBaseUrl()';

    /**
     * Each deeplink pixel in one source: its line, and the argument of the
     * base64_encode() that completes its URL — the first one after the URL
     * text, in the statement (or the `<?php … ?>` block) that holds it or
     * follows it. '' when there is none to read: a URL completed some other
     * way is reported, not passed.
     *
     * @return list<array{int, string}>
     */
    private static function deeplinkPixels(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $carriers = [T_INLINE_HTML, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];
        $pixels = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || !in_array($token[0], $carriers, true)) {
                continue;
            }
            $at = strrpos($token[1], self::DEEPLINK_PIXEL);
            if ($at === false) {
                continue;
            }
            $line = $token[2] + substr_count(substr($token[1], 0, $at), "\n");
            $html = $token[0] === T_INLINE_HTML;
            // In HTML the URL runs on into the PHP block only when nothing
            // ends it first (a quote, a space, the tag's close).
            $rest = substr($token[1], $at + strlen(self::DEEPLINK_PIXEL));
            if ($html && preg_match('/["\'\s>]/', $rest) === 1) {
                $pixels[] = [$line, ''];
                continue;
            }
            $pixels[] = [$line, self::encodedInStatement($tokens, $i + 1, $html)];
        }

        return $pixels;
    }

    /**
     * The argument of the first base64_encode() from $from to the end of the
     * statement: the PHP block after inline HTML, or the rest of a PHP
     * string's statement. '' when there is none.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function encodedInStatement(array $tokens, int $from, bool $afterHtml): string
    {
        $opens = [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO];
        for ($j = $from, $n = count($tokens); $j < $n; $j++) {
            $t = $tokens[$j];
            if ($afterHtml && is_array($t) && in_array($t[0], $opens, true)) {
                continue;
            }
            if ($t === ';' || (is_array($t) && in_array($t[0], [T_CLOSE_TAG, T_INLINE_HTML], true))) {
                return '';
            }
            $named = is_array($t) && in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                && strtolower(ltrim($t[1], '\\')) === 'base64_encode';
            if ($named && ($tokens[$j + 1] ?? null) === '(') {
                return self::parenthesized($tokens, $j + 1);
            }
        }

        return '';
    }

    /**
     * The text inside the parentheses that open at $open, whitespace dropped.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function parenthesized(array $tokens, int $open): string
    {
        $text = '';
        $depth = 0;
        for ($k = $open, $n = count($tokens); $k < $n; $k++) {
            $part = $tokens[$k];
            if ($part === '(' && ++$depth === 1) {
                continue;
            }
            if ($part === ')' && --$depth === 0) {
                break;
            }
            $text .= is_array($part) ? $part[1] : $part;
        }

        return $text;
    }

    /**
     * The deeplink pixel hands this install's address to the license
     * service, which sets its cookie for that address: a URL for someone
     * other than the requester, so the stored address (or the server's own
     * name), never the Host header (CLAUDE.md #16). The account home sent
     * p202TrackingBaseUrl(); the license-key pages (202-config/get_apikey.php
     * and api-key-required.php) sent TrackingBaseUrl::forRequest(), the
     * address the request named, so one install was registered at two
     * addresses depending on which page loaded the pixel.
     *
     * What this does not see: an address handed to the service any other
     * way — callAutoCron(), registerDailyEmail() and getDNIHost() build
     * theirs from p202TrackingBaseUrl() in functions-tracking202.php, and the
     * Landing Page Optimizer's pairing sends the request's origin (an open
     * question for that service's owner, not settled here).
     */
    public function testEveryDeeplinkPixelNamesTheStoredAddress(): void
    {
        $wrong = [];
        $seen = 0;
        foreach (SourceScan::phpFiles() as $path => $source) {
            foreach (self::deeplinkPixels($source) as [$line, $argument]) {
                $seen++;
                if ($argument !== self::DEEPLINK_ADDRESS) {
                    $shown = $argument === '' ? '(nothing readable)' : $argument;
                    $wrong[] = $path . ':' . $line . '  encodes ' . $shown;
                }
            }
        }
        // The account home, the license-key step of the installer and the
        // license-missing page.
        self::assertGreaterThanOrEqual(3, $seen, 'the scan finds the deeplink pixels');
        self::assertSame([], $wrong, "A deeplink pixel names an address other than the stored one:\n  "
            . implode("\n  ", $wrong) . "\nEncode " . self::DEEPLINK_ADDRESS . ': the service, not the requester,'
            . ' is who the URL is for.');
    }

    /** @return iterable<string, array{string, list<array{int, string}>}> */
    public static function deeplinkShapes(): iterable
    {
        $url = 'https://x/api/v2/dni/deeplink/cookie/set/';
        yield 'inline HTML completed by an echo block' => [
            '<img src="' . $url . '<?php echo base64_encode(p202TrackingBaseUrl()); ?>">',
            [[1, 'p202TrackingBaseUrl()']],
        ];
        yield 'escaped around the encoding' => [
            '<img src="' . $url . '<?php echo htmlspecialchars(base64_encode(p202TrackingBaseUrl()), ENT_QUOTES); ?>">',
            [[1, 'p202TrackingBaseUrl()']],
        ];
        yield 'the request\'s origin' => [
            '<img src="' . $url . '<?= base64_encode(\Prosper202\Click\TrackingBaseUrl::forRequest($_SERVER)) ?>">',
            [[1, '\Prosper202\Click\TrackingBaseUrl::forRequest($_SERVER)']],
        ];
        yield 'on a later line of the HTML' => [
            "<p>\n</p>\n<img src=\"" . $url . '<?= base64_encode($x) ?>">',
            [[3, '$x']],
        ];
        yield 'a PHP string concatenated' => [
            "<?php\n\$u = '" . $url . "' . base64_encode(\$base);",
            [[2, '$base']],
        ];
        yield 'completed some other way' => [
            "<?php\n\$u = '" . $url . "' . \$encoded;\n\$v = base64_encode(p202TrackingBaseUrl());",
            [[2, '']],
        ];
        yield 'an encoding in a later block is not this one' => [
            '<img src="' . $url . '"><?php echo base64_encode(p202TrackingBaseUrl()); ?>',
            [[1, '']],
        ];
        yield 'a comment is not a pixel' => ["<?php // dni/deeplink/cookie/set/\n", []];
    }

    /**
     * @dataProvider deeplinkShapes
     * @param list<array{int, string}> $expected
     */
    public function testTheShapesADeeplinkPixelTakes(string $source, array $expected): void
    {
        self::assertSame($expected, self::deeplinkPixels($source));
    }

    /** @return iterable<string, array{string, int}> */
    public static function trackingDomainShapes(): iterable
    {
        yield 'a call' => ['<?php $u = "http://" . getTrackingDomain() . "/x";', 1];
        yield 'fully qualified' => ['<?php $u = \getTrackingDomain();', 1];
        yield 'interpolated in a template' => ['<?php ?><a href="//<?php echo getTrackingDomain(); ?>/">', 1];
        yield 'the definition is not a call' => ['<?php function getTrackingDomain(): string { return ""; }', 0];
        yield 'a method of that name is not it' => ['<?php $o->getTrackingDomain(); X::getTrackingDomain();', 0];
        yield 'a comment is not a call' => ["<?php // getTrackingDomain()\n", 0];
    }

    /** @dataProvider trackingDomainShapes */
    public function testTheShapesACallTakes(string $source, int $expected): void
    {
        self::assertCount($expected, self::trackingDomainCalls($source));
    }

    /**
     * Executed, both copies: with no domain stored the answer is the host the
     * request came in on, port included, not the server's name and port; a
     * stored domain still wins. Each definition is run from its own source
     * in a child PHP, with the database read stubbed.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function definitions(): iterable
    {
        yield 'functions-tracking202.php' => [
            '202-config/functions-tracking202.php',
            'function p202StoredTrackingDomain(): string { return $GLOBALS["stored"]; }',
        ];
        yield 'connect2.php' => [
            '202-config/connect2.php',
            'final class StubResult extends \mysqli_result { public function __construct() {} '
                . 'public function fetch_assoc(): array|null|false '
                . '{ return ["user_tracking_domain" => $GLOBALS["stored"]]; } }'
                . ' function _mysqli_query($db, $sql) { return new StubResult(); }',
        ];
    }

    /** @dataProvider definitions */
    public function testGetTrackingDomainAnswersWithTheRequestsHostWhenNoneIsStored(string $file, string $stubs): void
    {
        $source = (string) file_get_contents(SourceScan::repoRoot() . '/' . $file);
        $found = preg_match('/^function getTrackingDomain\(\): string\n\{\n.*?^\}\n/ms', $source, $m);
        self::assertSame(1, $found, $file);
        $autoload = SourceScan::repoRoot() . '/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . '; ' . $stubs . ' ' . $m[0] . <<<'PHP'
            $_SERVER = ['SERVER_NAME' => 'internal', 'SERVER_PORT' => '8080', 'HTTP_HOST' => 'proxy.example:9443'];
            $out = [];
            $GLOBALS['stored'] = '';
            $out['none stored'] = getTrackingDomain();
            unset($_SERVER['HTTP_HOST']);
            $out['no Host header'] = getTrackingDomain();
            $_SERVER['HTTP_HOST'] = 'evil.example/x?';
            $out['a Host that is not a host'] = getTrackingDomain();
            $_SERVER['HTTP_HOST'] = 'proxy.example:9443';
            $GLOBALS['stored'] = 'https://track.example.com/';
            $out['stored'] = getTrackingDomain();
            echo json_encode($out);
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($process);
        $seen = json_decode($out, true);
        self::assertIsArray($seen, "$file answered: $out $err");
        self::assertSame([
            'none stored' => 'proxy.example:9443',
            'no Host header' => 'internal:8080',
            'a Host that is not a host' => 'internal:8080',
            'stored' => 'track.example.com',
        ], $seen, $file);
    }

    /**
     * Every read of either key in one source, by line.
     *
     * @return list<int>
     */
    private static function reads(string $source): array
    {
        $lines = [];
        $tokens = token_get_all($source);
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $value = substr($token[1], 1, -1);
            } elseif ($token[0] === T_STRING && self::isInterpolatedOffset($tokens, $i)) {
                $value = $token[1];
            } else {
                continue;
            }
            if (in_array($value, self::KEYS, true)) {
                $lines[] = $token[2];
            }
        }

        return $lines;
    }

    /** @param list<array{int, string, int}|string> $tokens */
    private static function isInterpolatedOffset(array $tokens, int $i): bool
    {
        return ($tokens[$i - 1] ?? null) === '['
            && is_array($tokens[$i - 2] ?? null) && $tokens[$i - 2][0] === T_VARIABLE
            && ($tokens[$i + 1] ?? null) === ']';
    }

    public function testEveryReadOfTheHostOrServerNameIsAListedReader(): void
    {
        $unlisted = [];
        $miscounted = [];
        $seen = 0;
        foreach (SourceScan::phpFiles() as $path => $source) {
            $reads = self::reads($source);
            if ($reads === []) {
                continue;
            }
            $seen += count($reads);
            if (!isset(self::ALLOWED[$path])) {
                $unlisted[] = $path . ' line ' . implode(', ', $reads);
            } elseif (self::ALLOWED[$path][0] !== count($reads)) {
                $miscounted[] = $path . ': ' . count($reads) . ' reads (lines ' . implode(', ', $reads) . '), '
                    . self::ALLOWED[$path][0] . ' listed';
            }
        }
        self::assertSame(
            [],
            $unlisted,
            'build URLs of this install with TrackingBaseUrl::forRequest()/requestUrl(), and read the Host header'
                . ' through RequestHost; a read that is neither belongs in ALLOWED with its reason'
        );
        self::assertSame([], $miscounted, 'a listed file gained or lost a read: route it, or update ALLOWED');
        $listed = array_sum(array_map(static fn (array $entry): int => $entry[0], self::ALLOWED));
        self::assertSame($listed, $seen, 'a listed file no longer exists or reads nothing');
    }

    /** @return iterable<string, array{string, int}> */
    public static function shapes(): iterable
    {
        yield 'a superglobal read' => ['<?php $u = "http://" . $_SERVER[\'SERVER_NAME\'] . "/x";', 1];
        yield 'double quotes' => ['<?php $h = $_SERVER["HTTP_HOST"];', 1];
        yield 'any array' => ['<?php $h = $server[\'HTTP_HOST\'] ?? null;', 1];
        yield 'getenv' => ['<?php $h = getenv(\'HTTP_HOST\');', 1];
        yield 'filter_input' => ['<?php $h = filter_input(INPUT_SERVER, "SERVER_NAME");', 1];
        yield 'interpolated' => ['<?php $u = "http://$_SERVER[HTTP_HOST]/x";', 1];
        yield 'interpolated in braces' => ['<?php $u = "http://{$_SERVER[\'HTTP_HOST\']}/x";', 1];
        yield 'a heredoc' => ["<?php \$u = <<<EOT\nhttp://{\$_SERVER['SERVER_NAME']}/x\nEOT;\n", 1];
        yield 'both on one line' => ['<?php $_SERVER[\'SERVER_NAME\'] = $_SERVER[\'HTTP_HOST\'];', 2];
        yield 'a comment is not a read' => ["<?php // \$_SERVER['HTTP_HOST']\n/* SERVER_NAME */ \$x = 1;", 0];
        yield 'another key is not a read' => ['<?php $h = $_SERVER[\'HTTP_HOSTNAME\'] . HTTP_HOST;', 0];
    }

    /** @dataProvider shapes */
    public function testTheShapesAReadTakes(string $source, int $expected): void
    {
        self::assertCount($expected, self::reads($source));
    }
}
