<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * Nothing decides the scheme from SERVER_PROTOCOL.
 *
 * SERVER_PROTOCOL is the HTTP version, "HTTP/1.1" or "HTTP/2.0", over TLS or
 * not. Four places asked it whether the request was HTTPS
 * (`stripos($_SERVER['SERVER_PROTOCOL'], 'https')`), so every one answered
 * http: the account home's deeplink pixel, and the install address the
 * autocron, daily-email and DNI registrations hand the hosted service, which
 * calls the install back there. They take p202TrackingBaseUrl() now; the
 * scheme of a request is p202_request_is_https() (request-https.php).
 *
 * A read is the key as a quoted string, or the offset of a simple
 * interpolation ("$_SERVER[SERVER_PROTOCOL]"), in any served PHP file;
 * comments are not reads. A use that needs the protocol version itself (a
 * status line) goes in ALLOWED with its reason.
 */
final class RequestSchemeSourceTest extends TestCase
{
    /** @var array<string, string> repo-relative file => why it reads SERVER_PROTOCOL */
    private const ALLOWED = [];

    /** @return list<int> the lines of $source that read SERVER_PROTOCOL */
    private static function reads(string $source): array
    {
        $lines = [];
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                continue;
            }
            [$id, $text, $line] = $token;
            $name = match ($id) {
                T_CONSTANT_ENCAPSED_STRING => substr($text, 1, -1),
                T_STRING => $text, // "$_SERVER[SERVER_PROTOCOL]": the bare offset
                default => null,
            };
            if ($name === 'SERVER_PROTOCOL') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    public function testNothingReadsTheSchemeFromServerProtocol(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (str_starts_with($path, 'tests/')) {
                continue;
            }
            $lines = self::reads($source);
            if ($lines !== [] && !isset(self::ALLOWED[$path])) {
                $found[] = $path . ':' . implode(',', $lines);
            }
        }

        self::assertSame([], $found, 'SERVER_PROTOCOL is the HTTP version ("HTTP/1.1"), never the scheme: '
            . 'p202_request_is_https() says whether a request is HTTPS, and p202TrackingBaseUrl() is this '
            . "install's address. A use that needs the version itself goes in ALLOWED with its reason.");
    }

    /** The shapes the scan reads, and the ones it does not count. */
    public function testTheScanReadsEverySpelling(): void
    {
        $read = [
            "<?php \$p = \$_SERVER['SERVER_PROTOCOL'];",
            '<?php $p = $_SERVER["SERVER_PROTOCOL"];',
            '<?php $p = "$_SERVER[SERVER_PROTOCOL]";',
            "<?php \$p = getenv('SERVER_PROTOCOL');",
            "<?php \$p = filter_input(INPUT_SERVER, 'SERVER_PROTOCOL');",
        ];
        foreach ($read as $source) {
            self::assertNotSame([], self::reads($source), $source);
        }
        $notRead = [
            "<?php // \$_SERVER['SERVER_PROTOCOL']",
            "<?php /* 'SERVER_PROTOCOL' */",
            "<?php \$p = 'SERVER_PROTOCOLS';",
        ];
        foreach ($notRead as $source) {
            self::assertSame([], self::reads($source), $source);
        }
    }
}
