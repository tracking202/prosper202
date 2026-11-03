<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\OutboundTimeouts;
use Tests\Support\SourceScan;

/**
 * Every request this install makes has a connect and a total time limit
 * (66H8). Without CURLOPT_CONNECTTIMEOUT curl waits 300 seconds for a
 * connection, and without CURLOPT_TIMEOUT forever for an answer: the DNI
 * calls, the ClickServer management calls and tagUserByNetwork() held the
 * page that made them for as long as my.tracking202.com took.
 *
 * Read per function: the function that calls curl_init() sets both limits
 * (or calls OutboundTimeouts::apply()), unless it is listed below with the
 * method of its class that builds its options. A literal 0 for either limit
 * is no limit and is refused.
 */
final class OutboundCallsHaveTimeoutsTest extends TestCase
{
    /** @var array<string, string> path => where the options holding both limits are built */
    private const OPTIONS_BUILT_ELSEWHERE = [
        '202-config/Attribution/WebhookSender.php' => 'curlOptions(): CONNECT_TIMEOUT and TIMEOUT',
        '202-config/Notifications/PostbackSender.php' => 'curlOptions(): CONNECT_TIMEOUT and TIMEOUT',
        '202-config/GeoIp/GuardedCurlTransport.php' =>
            "pinnedOptions(): PostbackSender::curlOptions()'s connect limit, its own TIMEOUT",
        'api/v3/Support/RemoteApiClient.php' => 'curlOptions(): CONNECT_TIMEOUT and REQUEST_TIMEOUT',
        '202-cronjobs/process_dataengine_job.php' => 'DataEngine\\SelfCall::curlOptions(): CONNECT_TIMEOUT and TIMEOUT',
    ];

    public function testEveryCurlRequestSetsAConnectAndATotalLimit(): void
    {
        $missing = [];
        $zero = [];
        $seen = 0;
        foreach (SourceScan::phpFiles() as $path => $source) {
            $tokens = self::significant($source);
            foreach (self::zeroLimits($tokens) as $line) {
                $zero[] = "$path:$line";
            }
            $calls = self::curlInits($tokens);
            if ($calls === []) {
                continue;
            }
            if (isset(self::OPTIONS_BUILT_ELSEWHERE[$path])) {
                $seen += count($calls);
                continue;
            }
            $seen += count($calls);
            foreach (self::unbounded($tokens) as $line) {
                $missing[] = "$path:$line";
            }
        }

        self::assertGreaterThan(40, $seen, 'the scan read the tree');
        self::assertSame([], $missing, 'a request here sets no connect or no total time limit: call'
            . ' \Prosper202\Http\OutboundTimeouts::apply($ch) or set both CURLOPT_CONNECTTIMEOUT and CURLOPT_TIMEOUT');
        self::assertSame([], $zero, 'a time limit of 0 is no limit');
    }

    public function testListedBuildersStillSetBothLimits(): void
    {
        $sources = SourceScan::phpFiles();
        $builders = [
            '202-config/Attribution/WebhookSender.php',
            '202-config/Notifications/PostbackSender.php',
            'api/v3/Support/RemoteApiClient.php',
            '202-config/DataEngine/SelfCall.php',
        ];
        foreach ($builders as $path) {
            self::assertMatchesRegularExpression('/CURLOPT_CONNECTTIMEOUT\s*=>/', $sources[$path], $path);
            self::assertMatchesRegularExpression('/CURLOPT_TIMEOUT\s*=>/', $sources[$path], $path);
        }
        self::assertStringContainsString(
            'PostbackSender::curlOptions(',
            $sources['202-config/GeoIp/GuardedCurlTransport.php']
        );
    }

    public function testTheScanFindsAnUnboundedRequest(): void
    {
        $tokens = self::significant('<?php function a() { $ch = curl_init(); curl_exec($ch); }'
            . ' function b() { $ch = curl_init(); \Prosper202\Http\OutboundTimeouts::apply($ch); }'
            . ' function c() { curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0); }'
            . ' function d() { $a = curl_init(); \Prosper202\Http\OutboundTimeouts::apply($a); $b = curl_init(); }');
        self::assertCount(4, self::curlInits($tokens));
        // a()'s request, and both of d()'s, which set one pair of limits for two.
        self::assertSame([1, 1, 1], self::unbounded($tokens));
        self::assertCount(1, self::zeroLimits($tokens));
    }

    public function testAServerThatNeverAnswersIsGivenUpOnWithinTheLimit(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($server, "a local listener: $error");
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

        $ch = curl_init('http://127.0.0.1:' . $port . '/');
        self::assertNotFalse($ch);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_PROXY, '');
        OutboundTimeouts::apply($ch, 1);
        $started = microtime(true);
        $answer = curl_exec($ch);
        $elapsed = microtime(true) - $started;
        $errno = curl_errno($ch);
        fclose($server);

        self::assertFalse($answer, 'the request failed rather than waiting');
        self::assertSame(CURLE_OPERATION_TIMEDOUT, $errno, 'on its time limit');
        self::assertLessThan(3.0, $elapsed, 'a silent listener holds the request for the limit, not longer');
    }

    /**
     * @param list<\PhpToken> $tokens
     * @return list<int> lines of the curl_init() calls whose function sets fewer limits than it makes requests
     */
    private static function unbounded(array $tokens): array
    {
        $functions = self::functionBodies($tokens);
        $calls = self::curlInits($tokens);
        $out = [];
        foreach ($calls as $at) {
            [$from, $to] = self::enclosing($functions, $at, count($tokens));
            $text = '';
            for ($i = $from; $i <= $to; $i++) {
                $text .= $tokens[$i]->text;
            }
            // Counted, so a function with two requests needs two sets of limits.
            $requests = count(array_filter($calls, static fn (int $c): bool => $c > $from && $c < $to));
            $applied = substr_count($text, 'OutboundTimeouts::apply(');
            $connect = preg_match_all('/\bCURLOPT_CONNECTTIMEOUT(_MS)?\b/', $text);
            $total = preg_match_all('/\bCURLOPT_TIMEOUT(_MS)?\b/', $text);
            if ($requests > $applied + min($connect, $total)) {
                $out[] = $tokens[$at]->line;
            }
        }

        return $out;
    }

    /** @return list<\PhpToken> */
    private static function significant(string $source): array
    {
        return array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])
        ));
    }

    /** @param list<\PhpToken> $tokens @return list<int> token indexes of curl_init() calls */
    private static function curlInits(array $tokens): array
    {
        $notACall = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST];
        $out = [];
        foreach ($tokens as $i => $token) {
            if (SourceScan::callableName($token->id, $token->text) !== 'curl_init') {
                continue;
            }
            $before = $tokens[$i - 1] ?? null;
            if (($tokens[$i + 1] ?? null)?->text === '(' && !($before !== null && $before->is($notACall))) {
                $out[] = $i;
            }
        }

        return $out;
    }

    /** @param list<\PhpToken> $tokens @return list<array{0: int, 1: int}> each named or anonymous function's body */
    private static function functionBodies(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $i => $token) {
            if (!$token->is(T_FUNCTION)) {
                continue;
            }
            $open = null;
            for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
                if ($tokens[$j]->text === ';') {
                    break;
                }
                if ($tokens[$j]->text === '{') {
                    $open = $j;
                    break;
                }
            }
            if ($open === null) {
                continue;
            }
            $depth = 0;
            for ($j = $open, $n = count($tokens); $j < $n; $j++) {
                $text = $tokens[$j]->text;
                if ($text === '{' || $tokens[$j]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    $depth++;
                } elseif ($text === '}' && --$depth === 0) {
                    $out[] = [$open, $j];
                    break;
                }
            }
        }

        return $out;
    }

    /** @param list<array{0: int, 1: int}> $functions @return array{0: int, 1: int} the innermost function around $at, else the file */
    private static function enclosing(array $functions, int $at, int $count): array
    {
        $best = [0, $count - 1];
        foreach ($functions as [$from, $to]) {
            if ($from < $at && $at < $to && $to - $from < $best[1] - $best[0]) {
                $best = [$from, $to];
            }
        }

        return $best;
    }

    /** @param list<\PhpToken> $tokens @return list<int> lines setting a limit to a literal 0 */
    private static function zeroLimits(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $i => $token) {
            $limits = ['CURLOPT_CONNECTTIMEOUT', 'CURLOPT_TIMEOUT', 'CURLOPT_CONNECTTIMEOUT_MS', 'CURLOPT_TIMEOUT_MS'];
            if (!in_array($token->text, $limits, true)) {
                continue;
            }
            $sep = $tokens[$i + 1] ?? null;
            $value = $tokens[$i + 2] ?? null;
            $end = $tokens[$i + 3] ?? null;
            if (
                $sep !== null && in_array($sep->text, [',', '=>'], true) && $value?->text === '0'
                && in_array($end?->text, [')', ',', ']'], true)
            ) {
                $out[] = $token->line;
            }
        }

        return $out;
    }
}
