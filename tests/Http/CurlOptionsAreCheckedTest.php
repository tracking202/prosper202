<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\CurlOptionRefused;
use Prosper202\Http\CurlOptions;
use Prosper202\Http\FixedHostRoute;
use Tests\Support\SourceScan;

/**
 * Every curl option this tree sets goes through CurlOptions::set(), which
 * throws when curl refuses one (6W9E). curl_setopt() and
 * curl_setopt_array() answer false for a value libcurl will not take (a
 * negative connect limit, a total limit past what it can hold, an unknown
 * proxy type), and about 240 calls in 21 files read nothing: the request
 * went without its time limit or its size cap, and curl_setopt_array()
 * left every option after the refused one unset, the URL included.
 *
 * Read as tokens (#20, #21): a name in any case, with or without a leading
 * backslash, called, taken as a first-class callable or imported by
 * `use function`, and a string literal naming one (joined across `.`), is
 * refused anywhere but the helper. A method of that name (`->`, `?->`,
 * `::`) is not the function. tests/ sets options freely, since tests build
 * the failures on purpose.
 */
final class CurlOptionsAreCheckedTest extends TestCase
{
    private const HELPER = '202-config/Http/CurlOptions.php';

    /** The functions that set an option and answer false when curl refuses it. */
    private const SETTERS = ['curl_setopt', 'curl_setopt_array', 'curl_multi_setopt', 'curl_share_setopt'];

    public function testNoFileButTheHelperSetsACurlOption(): void
    {
        $files = SourceScan::phpFiles();
        self::assertArrayHasKey(self::HELPER, $files, self::HELPER . ' is gone; update this test');
        self::assertArrayHasKey('202-config/functions-tracking202.php', $files, 'the scan read the tree');

        $found = [];
        foreach ($files as $path => $source) {
            if ($path === self::HELPER) {
                continue;
            }
            foreach (self::setterNames($source) as [$line, $name]) {
                $found[] = "$path:$line $name";
            }
        }
        self::assertSame([], $found, 'a curl option set here is not checked: pass the options to'
            . ' \Prosper202\Http\FixedHostRoute::exec($ch, [...]) for a fixed host, or call'
            . ' \Prosper202\Http\CurlOptions::set($ch, [...]) and catch CurlOptionRefused where the request fails');

        self::assertSame(
            [[self::line($files[self::HELPER], 'if (curl_setopt($ch, $option, $value)) {'), 'curl_setopt']],
            self::setterNames($files[self::HELPER]),
            'the helper sets each option with one curl_setopt() whose answer it reads'
        );
    }

    public function testTheScanSeesEverySpelling(): void
    {
        $refused = '<?php
            curl_setopt($ch, CURLOPT_URL, $u);
            \curl_setopt_array($ch, $o);
            CURL_SETOPT($ch, CURLOPT_URL, $u);
            namespace\curl_setopt($ch, 1, 2);
            $f = curl_setopt(...);
            use function curl_setopt as quiet;
            call_user_func(\'curl_setopt\', $ch, 1, 2);
            array_map("\\\\curl_setopt_array", [$ch], [$o]);
            $g = \'curl_\' . \'setopt\';
            curl_multi_setopt($mh, 3, 1);
            curl_share_setopt($sh, 1, 2);';
        self::assertSame([
            [2, 'curl_setopt'], [3, 'curl_setopt_array'], [4, 'curl_setopt'], [5, 'curl_setopt'],
            [6, 'curl_setopt'], [7, 'curl_setopt'], [8, 'curl_setopt'], [9, 'curl_setopt_array'],
            [10, 'curl_setopt'], [11, 'curl_multi_setopt'], [12, 'curl_share_setopt'],
        ], self::setterNames($refused));

        $accepted = '<?php
            // curl_setopt($ch, 1, 2);
            /** curl_setopt_array() stops at the first refused option */
            $client->curl_setopt($ch);
            $client?->curl_setopt($ch);
            Wrapper::curl_setopt_array($ch, $o);
            function curl_setopt_checked() {}
            $s = \'curl_setopt_x\';
            \Prosper202\Http\CurlOptions::set($ch, [CURLOPT_URL => $u]);';
        self::assertSame([], self::setterNames($accepted));
    }

    public function testTheHelperRefusesAnOptionCurlWillNotTakeAndSetsNothingAfterIt(): void
    {
        $ch = curl_init();
        self::assertNotFalse($ch);
        try {
            CurlOptions::set($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => -1,
                CURLOPT_URL => 'http://127.0.0.1:9/after',
            ]);
            self::fail('a connect limit of -1 was taken');
        } catch (CurlOptionRefused $e) {
            self::assertSame(CURLOPT_CONNECTTIMEOUT, $e->option);
            self::assertStringStartsWith('curl refused an option: CURLOPT_CONNECTTIMEOUT', $e->getMessage());
            self::assertStringNotContainsString('-1', $e->getMessage(), 'the message names the option, not its value');
        }
        $url = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        self::assertSame('', $url, 'the URL after the refused option was not set');

        CurlOptions::set($ch, [CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_URL => 'http://127.0.0.1:9/taken']);
        $url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        self::assertSame('http://127.0.0.1:9/taken', $url, 'options curl takes are set');
        curl_close($ch);

        self::assertSame('CURLOPT_TIMEOUT', CurlOptions::name(CURLOPT_TIMEOUT));
        self::assertSame('curl option -7', CurlOptions::name(-7));
    }

    public function testAFixedHostRequestWithARefusedOptionIsNotSent(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($server, "a local listener: $error");
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        $log = (string) tempnam(sys_get_temp_dir(), 'p202-curl-log-');
        $before = ini_set('error_log', $log);

        $ch = curl_init();
        self::assertNotFalse($ch);
        try {
            // The URL and a bound come first, so a request that went anyway would reach the listener.
            $answer = FixedHostRoute::exec($ch, [
                CURLOPT_URL => 'http://127.0.0.1:' . $port . '/',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_PROXY => '',
                CURLOPT_TIMEOUT => 2,
                CURLOPT_MAXFILESIZE => -5,
            ]);
        } finally {
            ini_set('error_log', $before === false ? '' : $before);
        }
        stream_set_blocking($server, false);
        $connection = @stream_socket_accept($server, 0);
        fclose($server);
        $logged = (string) file_get_contents($log);
        unlink($log);

        self::assertFalse($answer, 'exec() answers false, the failure its callers already handle');
        self::assertFalse($connection, 'nothing reached the listener');
        self::assertStringContainsString(
            'FixedHostRoute: not sent: curl refused an option: CURLOPT_MAXFILESIZE',
            $logged
        );
    }

    /**
     * Each use of a setter's name as code or as a string, outside a
     * method's name: [line, name].
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function setterNames(string $source): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])
        ));
        $method = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];
        $out = [];
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];
            if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                $line = $token->line;
                $text = self::literal($token->text);
                while (
                    ($tokens[$i + 1] ?? null)?->text === '.'
                    && ($tokens[$i + 2] ?? null)?->is(T_CONSTANT_ENCAPSED_STRING)
                ) {
                    $text .= self::literal($tokens[$i + 2]->text);
                    $i += 2;
                }
                $name = strtolower(ltrim($text, '\\'));
                if (in_array($name, self::SETTERS, true)) {
                    $out[] = [$line, $name];
                }
                continue;
            }
            $name = SourceScan::callableName($token->id, $token->text);
            $afterArrow = ($tokens[$i - 1] ?? null)?->is($method) ?? false;
            if ($name !== null && in_array($name, self::SETTERS, true) && !$afterArrow) {
                $out[] = [$token->line, $name];
            }
        }

        return $out;
    }

    /** A quoted literal's text, unescaped as PHP reads it. */
    private static function literal(string $quoted): string
    {
        $body = substr($quoted, 1, -1);

        return $quoted[0] === "'"
            ? strtr($body, ['\\\\' => '\\', "\\'" => "'"])
            : stripcslashes($body);
    }

    private static function line(string $source, string $needle): int
    {
        $at = strpos($source, $needle);
        self::assertNotFalse($at, "the helper no longer reads: $needle");

        return substr_count(substr($source, 0, $at), "\n") + 1;
    }
}
