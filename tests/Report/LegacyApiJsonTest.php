<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * The legacy API prints every answer through legacy_api_json(). Its print
 * sites called json_encode() and printed whatever came back: a value that
 * could not be encoded made it false, and the answer an empty 200.
 */
final class LegacyApiJsonTest extends TestCase
{
    private const DIRS = ['api/v1', 'api/v2', 'api/legacy'];

    public function testBytesThatAreNotUtf8AreReplacedAndTheRestKept(): void
    {
        [$status, $out] = self::encode('["msg" => "caf\xE9 / ok", "n" => 1]');

        self::assertSame('false', $status, 'no status set: the answer is the one asked for');
        self::assertSame(['msg' => "caf\u{FFFD} / ok", 'n' => 1], json_decode($out, true), $out);
        self::assertStringContainsString(' / ok', $out, 'slashes unescaped, as the API always printed them');
    }

    public function testAnAnswerThatCannotBeEncodedIsTheApisErrorUnder500(): void
    {
        [$status, $out] = self::encode('["ratio" => NAN]');

        self::assertSame('500', $status);
        self::assertSame(
            ['msg' => 'The answer could not be encoded', 'error' => true, 'status' => 500],
            json_decode($out, true),
            $out
        );
    }

    /** No answer in the legacy API is encoded anywhere else. */
    public function testJsonEncodeIsCalledOnlyInsideLegacyApiJson(): void
    {
        $root = dirname(__DIR__, 2);
        $calls = [];
        foreach (self::DIRS as $dir) {
            $files = new \RecursiveIteratorIterator(SourceScan::tree("$root/$dir"));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($root) + 1);
                foreach (self::encodeCallsIn((string) file_get_contents($file->getPathname())) as $function) {
                    $calls[] = "$relative: $function";
                }
            }
        }

        self::assertSame(['api/legacy/functions.php: legacy_api_json'], $calls);
    }

    /**
     * The function each json_encode() call is made in ('' outside every
     * function), whatever its spelling: \json_encode and any case.
     *
     * @return list<string>
     */
    private static function encodeCallsIn(string $source): array
    {
        $tokens = \PhpToken::tokenize($source);
        $found = [];
        $functions = [];
        $pending = null;
        $depth = 0;
        foreach ($tokens as $i => $token) {
            if ($token->is(T_FUNCTION)) {
                $pending = '{closure}';
                for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j]->is([T_WHITESPACE, T_COMMENT]); $j++) {
                }
                if (isset($tokens[$j]) && $tokens[$j]->is(T_STRING)) {
                    $pending = $tokens[$j]->text;
                }
            } elseif ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                if ($pending !== null && $token->text === '{') {
                    $functions[] = [$pending, $depth];
                    $pending = null;
                }
            } elseif ($token->text === '}') {
                if ($functions !== [] && end($functions)[1] === $depth) {
                    array_pop($functions);
                }
                $depth--;
            } elseif ($token->text === ';') {
                $pending = null;
            } elseif (
                $token->is([T_STRING, T_NAME_FULLY_QUALIFIED])
                && strtolower(ltrim($token->text, '\\')) === 'json_encode'
            ) {
                $found[] = $functions === [] ? '' : end($functions)[0];
            }
        }

        return $found;
    }

    /** @return array{string, string} the status set, and what legacy_api_json() answered */
    private static function encode(string $data): array
    {
        $file = dirname(__DIR__, 2) . '/api/legacy/functions.php';
        $script = 'declare(strict_types=1); require ' . var_export($file, true) . ';'
            . ' $out = legacy_api_json(' . $data . ');'
            . ' echo var_export(http_response_code(), true), "\n", $out;';
        $php = escapeshellarg(PHP_BINARY);
        $ini = ' -d display_errors=stderr -d error_log=/dev/null';
        $out = (string) shell_exec($php . $ini . ' -r ' . escapeshellarg($script));
        [$status, $json] = explode("\n", $out, 2) + ['', ''];

        return [$status, $json];
    }
}
