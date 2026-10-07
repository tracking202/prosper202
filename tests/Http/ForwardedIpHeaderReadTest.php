<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\VisitorIp;
use Tests\Support\SourceScan;

/**
 * Only VisitorIp reads a forwarding header (X-Forwarded-For, X-Real-IP,
 * Client-IP, CF-Connecting-IP and the rest).
 *
 * Before it existed, connect.php and connect2.php each rewrote
 * $_SERVER['HTTP_X_FORWARDED_FOR'] in place, and a dozen sites read it back
 * with their own fallback — `?? REMOTE_ADDR ?? '0.0.0.0'` in dl.php,
 * `?? '0.0.0.0'` on the login page, nothing at all in the offer rotator.
 * They agreed only because the bootstrap had already run; none validated the
 * value, so `X-Forwarded-For: nope` went into 202_ips and the GEO, ISP and
 * filter lookups as an address. One reader means one answer, and a new
 * endpoint cannot pick a different rule without this test naming the line.
 *
 * What the scan reads: every string in a served PHP file — a single- or
 * double-quoted literal, the text parts of an interpolated string or heredoc,
 * and an unquoted key in `"$_SERVER[HTTP_X_FORWARDED_FOR]"` — normalised
 * (upper case, `-` as `_`) and matched on the headers' distinctive stems
 * between word boundaries, so every way of naming one is seen:
 * `$_SERVER['…']` and `$_SERVER["…"]`, getenv(), filter_input(INPUT_SERVER,
 * …), a getallheaders() key in header form (`X-Forwarded-For`), a key list a
 * loop reads, a closure's argument. What it cannot see, and does not claim
 * to: a name assembled from pieces that split a stem (`'X_FORWARD' .
 * 'ED_FOR'`) or computed by a transform — a deliberate evasion, not the slip
 * this guards against.
 */
final class ForwardedIpHeaderReadTest extends TestCase
{
    /** @var array<string, string> repo-relative path => why it may read the headers */
    private const ALLOWED = [
        '202-config/Http/VisitorIp.php' => 'the one reader: precedence, leftmost hop, validation, REMOTE_ADDR fallback',
    ];

    public function testEveryHeaderVisitorIpReadsIsOneTheScanKnows(): void
    {
        foreach (VisitorIp::FORWARDING_HEADERS as $key) {
            self::assertTrue(
                self::namesForwardingHeader($key),
                $key . ' is read by VisitorIp but the scan would not see a read of it elsewhere'
            );
        }
    }

    public function testTheScanSeesTheAllowedReader(): void
    {
        // Without this a scan that matched nothing would pass the tree test.
        $files = SourceScan::phpFiles();
        self::assertArrayHasKey('202-config/Http/VisitorIp.php', $files);
        self::assertGreaterThanOrEqual(
            count(VisitorIp::FORWARDING_HEADERS),
            count(self::headerNames($files['202-config/Http/VisitorIp.php'])),
            'the scan finds VisitorIp\'s own reads'
        );
    }

    public function testNoServedFileReadsAForwardingHeaderOutsideVisitorIp(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (isset(self::ALLOWED[$path])) {
                continue;
            }
            foreach (self::headerNames($source) as [$line, $text]) {
                $found[] = sprintf('%s:%d  %s', $path, $line, $text);
            }
        }

        self::assertSame([], $found, "A forwarding header is read outside VisitorIp:\n  "
            . implode("\n  ", $found)
            . "\nUse \\Prosper202\\Http\\VisitorIp::fromServer(\$_SERVER) for the visitor's address"
            . ' (validated, one rule for every endpoint), or forwardedForAsSent() for a log line'
            . ' that shows the raw header.');
    }

    /** @return iterable<string, array{string}> */
    public static function spellings(): iterable
    {
        yield 'single-quoted $_SERVER key' => ['<?php $ip = $_SERVER[\'HTTP_X_FORWARDED_FOR\'];'];
        yield 'double-quoted $_SERVER key' => ['<?php $ip = $_SERVER["HTTP_X_FORWARDED_FOR"];'];
        yield 'getenv()' => ['<?php $ip = getenv(\'HTTP_X_FORWARDED_FOR\');'];
        yield 'filter_input(INPUT_SERVER)' => [
            '<?php $ip = filter_input(INPUT_SERVER, \'HTTP_X_FORWARDED_FOR\', FILTER_VALIDATE_IP);',
        ];
        yield 'unquoted key inside a string' => ['<?php $line = "ip=$_SERVER[HTTP_X_FORWARDED_FOR]";'];
        yield 'braced key inside a string' => ['<?php $line = "ip={$_SERVER[\'HTTP_CLIENT_IP\']}";'];
        yield 'heredoc text' => ["<?php \$k = <<<EOT\nHTTP_X_REAL_IP\nEOT;\n"];
        yield 'getallheaders() key in header form' => ['<?php $ip = getallheaders()[\'X-Forwarded-For\'] ?? \'\';'];
        yield 'lower-case header form' => ['<?php $ip = $headers[\'x-real-ip\'];'];
        yield 'a key list a loop reads' => [
            '<?php foreach ([\'REMOTE_ADDR\', \'HTTP_CF_CONNECTING_IP\'] as $k) { $v = $_SERVER[$k]; }',
        ];
        yield 'a closure argument' => [
            '<?php $h = fn ($k) => $_SERVER[$k] ?? \'\'; $x = $h(\'HTTP_X_CLUSTER_CLIENT_IP\');',
        ];
        yield 'Sucuri, no underscore before IP' => ['<?php $ip = $_SERVER[\'HTTP_X_SUCURI_CLIENTIP\'];'];
        yield 'RFC 7239 Forwarded' => ['<?php $f = $_SERVER[\'HTTP_FORWARDED\'];'];
        yield 'True-Client-IP' => ['<?php $f = $_SERVER[\'HTTP_TRUE_CLIENT_IP\'];'];
        yield 'a piece split at a word boundary' => ['<?php $k = \'HTTP_X_\' . \'FORWARDED_FOR\';'];
        yield 'a hex escape in a double-quoted key' => ['<?php $ip = $_SERVER["HTTP_X_FORWARDED\x5fFOR"];'];
        yield '$GLOBALS spelling' => ['<?php $ip = $GLOBALS[\'_SERVER\'][\'HTTP_X_FORWARDED_FOR\'];'];
    }

    /** @dataProvider spellings */
    public function testEverySpellingOfAReadIsSeen(string $source): void
    {
        self::assertNotSame([], self::headerNames($source), 'not seen: ' . $source);
    }

    /** @return iterable<string, array{string}> */
    public static function nonReads(): iterable
    {
        yield 'X-Forwarded-Proto' => ['<?php $p = $_SERVER[\'HTTP_X_FORWARDED_PROTO\'] ?? \'\';'];
        yield 'X-Forwarded-SSL and -Port' => [
            '<?php $a = $_SERVER[\'HTTP_X_FORWARDED_SSL\']; $b = $_SERVER[\'HTTP_X_FORWARDED_PORT\'];',
        ];
        yield 'a function whose name contains ClientIp' => [
            '<?php $ip = p202ClientIp($_SERVER); if (function_exists(\'p202ClientIp\')) {}',
        ];
        yield 'a method named client_ip' => ['<?php $ip = AUTH::client_ip();'];
        yield 'a comment that names the header' => ["<?php // X-Forwarded-For is read by VisitorIp\n\$a = 1;"];
        yield 'REMOTE_ADDR' => ['<?php $ip = $_SERVER[\'REMOTE_ADDR\'];'];
    }

    /** @dataProvider nonReads */
    public function testWhatIsNotAForwardingHeaderIsNotReported(string $source): void
    {
        self::assertSame([], self::headerNames($source));
    }

    /**
     * Every string in $source that names a forwarding header.
     *
     * @return list<array{int, string}> [line, text]
     */
    private static function headerNames(string $source): array
    {
        $tokens = token_get_all($source);
        $found = [];
        foreach ($tokens as $i => $t) {
            if (!is_array($t)) {
                continue;
            }
            $texts = [];
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $inner = substr($t[1], 1, -1);
                $texts = [$inner, $t[1][0] === '"' ? stripcslashes($inner) : $inner];
            } elseif ($t[0] === T_ENCAPSED_AND_WHITESPACE) {
                $texts = [$t[1], stripcslashes($t[1])];
            } elseif ($t[0] === T_STRING && self::isArrayKey($tokens, $i)) {
                // "$_SERVER[HTTP_X_FORWARDED_FOR]": an unquoted key inside a
                // string (or a constant used as a key outside one).
                $texts = [$t[1]];
            }
            foreach ($texts as $text) {
                if (self::namesForwardingHeader($text)) {
                    $found[] = [$t[2], trim($t[1])];
                    break;
                }
            }
        }

        return $found;
    }

    /** @param array<int, string|array{0:int,1:string,2:int}> $tokens */
    private static function isArrayKey(array $tokens, int $i): bool
    {
        return ($tokens[$i - 1] ?? null) === '[' && ($tokens[$i + 1] ?? null) === ']';
    }

    private static function namesForwardingHeader(string $text): bool
    {
        $n = strtoupper(str_replace('-', '_', $text));
        // The stems, between word boundaries: X_FORWARDED_FOR, X_ORIGINAL_
        // FORWARDED_FOR, CF_CONNECTING_IP, X_REAL_IP, CLIENT_IP, X_CLIENT_IP,
        // X_CLUSTER_CLIENT_IP, TRUE_CLIENT_IP, FASTLY_CLIENT_IP and Sucuri's
        // X_SUCURI_CLIENTIP. The boundary keeps p202ClientIp out.
        if (preg_match('/(?<![A-Z0-9])(?:FORWARDED_FOR|CONNECTING_IP|REAL_IP|CLIENT_?IP)(?![A-Z0-9])/', $n) === 1) {
            return true;
        }
        // Names with no distinctive stem, matched whole (X-Forwarded-Proto,
        // -SSL and -Port are not addresses and are not matched).
        $n = (string) preg_replace('/^HTTP_/', '', trim($n));

        return in_array($n, ['FORWARDED', 'X_FORWARDED', 'X_PROXYUSER_IP', 'X_APPENGINE_USER_IP'], true);
    }
}
