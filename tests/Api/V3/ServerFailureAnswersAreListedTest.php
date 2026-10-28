<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * Between deploying the files and running the upgrade, a failure that would
 * answer a bare 5xx answers 503 naming the upgrade
 * (Bootstrap::pendingUpgrade(), legacy_api_database_error()). The dispatcher's
 * last catches did; the Android install intake, the Apple postback receiver
 * and an AuthException answered 500 from their own ways out, which the claim
 * had never listed (found by review).
 *
 * So every answer in the API's served tree (api/, .well-known/) whose status
 * is a literal 5xx or an expression is listed here, with what it says while
 * the schema is behind: the upgrade, or why its own words stand. A new one
 * fails until it is decided. An answer is a call to errorResponse(),
 * jsonResponse() or http_response_code() under any receiver or spelling;
 * `header('HTTP/…')` and `header('Status: …')` are refused outright. What
 * this does not see: a status set any other way, and a 5xx a listed
 * expression carries from code that never named it (the reasons say where
 * each one's statuses come from). UpgradePendingAnswerServedIntegrationTest
 * serves the ones that name the upgrade.
 */
final class ServerFailureAnswersAreListedTest extends TestCase
{
    private const UPGRADE = 'names the upgrade while it is pending'
        . ' (served by UpgradePendingAnswerServedIntegrationTest)';
    private const NO_DATABASE = 'no database to ask: the connection, or the configuration, is not there';
    private const CARRIES = 'carries the status of the answer it is handed; ';

    /** "path | callee | message | status" => [how many, why] */
    private const LISTED = [
        '.well-known/postback-prelude.php | http_response_code |  | 503' => [1, self::NO_DATABASE],
        'api/legacy/functions.php | http_response_code |  | $status' => [1,
            'legacy_api_database_error(): ' . self::UPGRADE . ', else 500 "Database error"'],
        'api/legacy/functions.php | http_response_code |  | 500' => [1,
            'legacy_api_json(): the answer could not be encoded; no statement failed'],
        'api/v1/reports/index.php | http_response_code |  | 503' => [1, self::NO_DATABASE],
        'api/v2/categories/index.php | http_response_code |  | 503' => [1, self::NO_DATABASE],
        'api/v2/reports/index.php | http_response_code |  | 503' => [1, self::NO_DATABASE],
        'api/v3/Apps/Apple/PostbackIntake.php | errorresponse | $pending | 503' => [1, self::UPGRADE],
        "api/v3/Apps/Apple/PostbackIntake.php | jsonresponse | \$result['body'] | \$result['status']" => [1,
            self::CARRIES . 'the receiver\'s 5xx are answered by the line above while the upgrade is pending'],
        "api/v3/Apps/PublicIntake.php | errorresponse | 'Service unavailable' | 503"
            => [1, self::NO_DATABASE],
        "api/v3/Apps/PublicIntake.php | errorresponse | 'Failed to read request body' | 500"
            => [1, 'reading the request body failed; no statement ran'],
        'api/v3/Bootstrap.php | http_response_code |  | $status' => [1, 'jsonResponse() itself'],
        'api/v3/Controllers/OfflineConversionsController.php | errorresponse | $pending | 503' => [1, self::UPGRADE],
        "api/v3/Controllers/OfflineConversionsController.php | errorresponse | 'The file could not be written; try again later' | 500"
            => [2, 'the fetch URL\'s catches, after a pending upgrade was answered (the line above) before anything was read'],
        'api/v3/Bootstrap.php | http_response_code |  | 500'
            => [1, 'the answer could not be encoded; no statement failed'],
        'api/v3/Bootstrap.php | jsonresponse | $body | $status' => [1, 'errorResponse() itself'],
        "api/v3/index.php | errorresponse | 'Service unavailable' | 503" => [1, self::NO_DATABASE],
        "api/v3/index.php | errorresponse | \$pending ?? 'The install could not be recorded; retry later'"
            . ' | $pending !== null ? 503 : 500' => [1, 'the Android intake\'s catch: ' . self::UPGRADE],
        "api/v3/index.php | jsonresponse | \$androidResult['body'] | \$androidResult['status']" => [1, self::CARRIES
            . 'the intakes answer 503 only for a cause they name (the install-token key, a lock lost twice, an '
            . 'unreadable setting, a pending install\'s events), and throw every failure to the catch above'],
        'api/v3/index.php | jsonresponse | $connectBody | $connectStatus' => [2,
            self::CARRIES . 'AiConnect and ConnectController answer no 5xx; a failure throws to the last catches'],
        "api/v3/index.php | jsonresponse | \$schemaResult['body'] ?? [] | \$schemaResult['status']" => [1,
            self::CARRIES . 'AppSchemaController answers 200, 304 or a 4xx; a failure throws to the last catches'],
        'api/v3/index.php | jsonresponse | $response | $status' => [1,
            self::CARRIES . 'a handler\'s `_status`, never a 5xx; a failure throws to the last catches'],
        'api/v3/index.php | errorresponse | $message | $code' => [2,
            'the AuthException and HttpException catches: a 5xx ' . self::UPGRADE],
        'api/v3/index.php | errorresponse | $e->getMessage() | $e->getHttpStatus()' => [1,
            'WriteCommittedException: the write stands and the answer says so, and that a retry would make a '
            . 'second one (CLAUDE.md #13); a 503 would read as "send it again"'],
        "api/v3/index.php | errorresponse | \$pending ?? 'Internal server error' | \$pending !== null ? 503 : 500"
            => [1, 'the last catch: ' . self::UPGRADE],
    ];

    public function testEveryServerFailureAnswerIsListed(): void
    {
        $found = [];
        $lines = [];
        $files = 0;
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (!str_starts_with($path, 'api/') && !str_starts_with($path, '.well-known/')) {
                continue;
            }
            $files++;
            foreach (self::answers($path, $source) as [$key, $line]) {
                $found[$key] = ($found[$key] ?? 0) + 1;
                $lines[$key][] = $line;
            }
        }
        self::assertGreaterThan(100, $files, 'the walk read the API tree');

        $listed = array_map(static fn (array $entry): int => $entry[0], self::LISTED);
        ksort($found);
        ksort($listed);
        $unlisted = array_diff_key($found, $listed);
        self::assertSame($listed, $found, 'Every answer that can be a 5xx is listed with what it says'
            . " while the upgrade is pending.\n"
            . ($unlisted === [] ? '' : "Not listed:\n" . implode("\n", array_map(
                static fn (string $key): string => '  ' . $key . ' (line ' . implode(', ', $lines[$key]) . ')',
                array_keys($unlisted)
            )) . "\n") . 'Decide each: name the upgrade (Bootstrap::pendingUpgrade()) or say why its words stand.');
    }

    public function testTheScanReadsEverySpellingOfAnAnswer(): void
    {
        $source = <<<'PHP'
            <?php
            function errorResponse(string $m, int $s = 400): void {}
            Bootstrap::errorResponse('Nope', 400);
            \Api\V3\Bootstrap::ERRORRESPONSE('Broken', 500);
            $this->jsonResponse($body, $status);
            jsonResponse(['ok' => true]);
            \http_response_code(502);
            http_response_code(204);
            Bootstrap::errorResponse('Default status');
            PHP;
        self::assertSame([
            ["x.php | errorresponse | 'Broken' | 500", 4],
            ['x.php | jsonresponse | $body | $status', 5],
            ['x.php | http_response_code |  | 502', 7],
        ], self::answers('x.php', $source));
    }

    public function testAStatusLineSetByHandIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        self::answers('x.php', "<?php\nheader('HTTP/1.1 500 Internal Server Error');\n");
    }

    /**
     * The answers in $source whose status is not a literal below 500, keyed
     * "path | callee | message | status" (arguments with whitespace collapsed).
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function answers(string $path, string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $answers = [];
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_STRING && strtolower($token[1]) === 'header') {
                $open = self::next($tokens, $i);
                $first = $open !== null && $tokens[$open] === '(' ? self::next($tokens, $open) : null;
                $literal = $first !== null && is_array($tokens[$first])
                    && $tokens[$first][0] === T_CONSTANT_ENCAPSED_STRING ? $tokens[$first][1] : '';
                if (preg_match('/^[\'"]\s*(HTTP\/|Status:)/i', $literal) === 1) {
                    throw new \LogicException("$path:{$token[2]} sets a status line by hand; answer through Bootstrap");
                }
                continue;
            }
            $name = SourceScan::callableName($token[0], $token[1]);
            if (!in_array($name, ['errorresponse', 'jsonresponse', 'http_response_code'], true)) {
                continue;
            }
            $before = self::previous($tokens, $i);
            if ($before !== null && is_array($tokens[$before]) && $tokens[$before][0] === T_FUNCTION) {
                continue;
            }
            $open = self::next($tokens, $i);
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }
            $arguments = self::arguments($tokens, $open);
            $status = $name === 'http_response_code' ? $arguments[0] ?? '' : $arguments[1] ?? '';
            if ($status === '' || (ctype_digit($status) && (int) $status < 500)) {
                continue; // errorResponse() defaults to 400, jsonResponse() to 200
            }
            $message = $name === 'http_response_code' ? '' : $arguments[0] ?? '';
            $answers[] = ["$path | $name | $message | $status", $token[2]];
        }

        return $answers;
    }

    /**
     * The top-level arguments of the call whose `(` is at $open.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens
     * @return list<string>
     */
    private static function arguments(array $tokens, int $open): array
    {
        $arguments = [''];
        $depth = 0;
        $count = count($tokens);
        for ($k = $open; $k < $count; $k++) {
            $token = $tokens[$k];
            $text = is_array($token) ? $token[1] : $token;
            $opens = in_array($text, ['(', '['], true) || $text === '{'
                || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
            if ($opens) {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($depth === 1 && $text === ',') {
                $arguments[] = '';
                continue;
            }
            $arguments[count($arguments) - 1] .= $text;
        }

        return array_values(array_filter(
            array_map(
                static fn (string $argument): string => trim((string) preg_replace('/\s+/', ' ', $argument)),
                $arguments
            ),
            static fn (string $argument): bool => $argument !== ''
        ));
    }

    /** @param array<int, string|array{0: int, 1: string, 2: int}> $tokens */
    private static function next(array $tokens, int $i): ?int
    {
        for ($j = $i + 1, $count = count($tokens); $j < $count; $j++) {
            if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $j;
            }
        }

        return null;
    }

    /** @param array<int, string|array{0: int, 1: string, 2: int}> $tokens */
    private static function previous(array $tokens, int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $j;
            }
        }

        return null;
    }
}
