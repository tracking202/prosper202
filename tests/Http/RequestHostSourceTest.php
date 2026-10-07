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
            7,
            "the same `_` mapping (3); the install fingerprint hash (1); the server_name column of 202_mysql_errors"
            . ' and the error email (2); getTrackingDomain()\'s fallback, the tracking domain and not this request (1)',
        ],
        '202-config/functions-tracking202.php' => [
            5,
            'the server_name column of 202_mysql_errors (1); getTrackingDomain()\'s fallback (1); the license'
            . " service's click-server id, the Host header as sent (2); the install fingerprint hash (1)",
        ],
        '202-account/clickservers.php' => [
            1,
            "compared with the license service's click-server id, which is the Host header as sent",
        ],
        '202-config/functions-auth.php' => [2, 'the list of request fields a security log records'],
        '202-config/Slack.class.php' => [1, "the Slack message's sender name"],
        '202-lost-pass.php' => [
            1,
            'NOT a self-URL: the reset link goes to the account\'s email, someone other than the requester, so its host'
            . ' must not come from the request at all (an open finding: SERVER_NAME is the Host header under Apache\'s'
            . ' default UseCanonicalName Off). Routing it through the request-origin helper would make that official.',
        ],
    ];

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
