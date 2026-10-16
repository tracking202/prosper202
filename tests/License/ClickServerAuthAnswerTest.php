<?php

declare(strict_types=1);

namespace Tests\License;

use PHPUnit\Framework\TestCase;

/**
 * The ClickServer key check reports no answer as no answer: an outage or an
 * error page used to read as "invalid", and the forms said the key was
 * wrong. Its request encodes the key, is bounded and follows no redirect.
 */
final class ClickServerAuthAnswerTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function setUpBeforeClass(): void
    {
        require_once self::root() . '/202-config/functions-account-ui.php';
    }

    /** @return mixed what clickserver_auth_answer() returns, run in a child process */
    private static function answer(?string $body): mixed
    {
        $code = 'require ' . var_export(self::root() . '/vendor/autoload.php', true) . ';'
            . ' require ' . var_export(self::root() . '/202-config/functions-tracking202.php', true) . ';'
            . ' echo json_encode(clickserver_auth_answer(' . var_export($body, true) . '));';
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
        return json_decode(trim((string)$out), true, 2, JSON_THROW_ON_ERROR);
    }

    public function testAnAnswerIsReadOnlyFromTheServicesOwnFields(): void
    {
        self::assertTrue(self::answer('{"isValidKey":"true","isValidDomain":"true"}'));
        self::assertTrue(self::answer('{"isValidKey":true,"isValidDomain":true}'));
        self::assertFalse(self::answer('{"isValidKey":"false","isValidDomain":"true"}'));
        self::assertFalse(self::answer('{"isValidKey":"true","isValidDomain":"false"}'));
        foreach ([null, '', '<html>Bad gateway</html>', '{}', '{"isValidKey":"true"}', '[]', 'null'] as $none) {
            self::assertNull(self::answer($none), var_export($none, true));
        }
    }

    public function testTheFormsSayWhenAKeyCouldNotBeChecked(): void
    {
        self::assertNull(p202_clickserver_key_error(true));
        self::assertSame('This API Key appears invalid.', p202_clickserver_key_error(false));
        self::assertSame(P202_ACCOUNT_KEY_UNCHECKED, p202_clickserver_key_error(null));
        self::assertNull(p202_check_clickserver_key(''), 'an empty key removes the stored one, unchecked');
        $account = (string)file_get_contents(self::root() . '/202-account/account.php');
        self::assertStringContainsString(
            "if ((\$csKeyError = p202_check_clickserver_key(\$csKey)) !== null) {",
            $account
        );
        $appstore = (string)file_get_contents(self::root() . '/202-appstore/index.php');
        self::assertStringContainsString('if (($keyError = p202_check_clickserver_key($key)) !== null) {', $appstore);
        foreach ([$account, $appstore] as $page) {
            self::assertStringNotContainsString('!clickserver_api_key_validate(', $page);
        }
    }

    /** The ClickServer key form trims once, and checks and saves that one value. */
    public function testTheClickServerKeyCheckedIsTheKeySaved(): void
    {
        $page = (string)file_get_contents(self::root() . '/202-account/account.php');
        $start = strpos($page, "\$_POST['update_clickserver_api_key']");
        self::assertIsInt($start);
        $end = strpos($page, "if (!empty(\$_POST['", $start + 10);
        self::assertIsInt($end);
        $branch = substr($page, $start, $end - $start);
        $assign = "\$mysql['clickserver_api_key'] = \$db->real_escape_string("
            . "\$csKey = trim((string) \$_POST['clickserver_api_key']));";
        self::assertSame(1, substr_count($branch, $assign), 'trimmed once');
        $rest = str_replace($assign, '', $branch);
        self::assertSame(0, preg_match('/\$csKey\s*=(?!=)/', $rest), 'and not assigned again');
        foreach (
            [
                'p202_check_clickserver_key($csKey)',
                "\$mysql['clickserver_api_key'] = \$db->real_escape_string(\$csKey);",
                "if (\$slack && \$csKey != (\$user_row['clickserver_api_key'] ?? '')) {",
            ] as $use
        ) {
            self::assertStringContainsString($use, $branch);
        }
        // Only the masked-key test reads the posted value besides the trim.
        self::assertSame(2, substr_count($branch, "\$_POST['clickserver_api_key']"));
    }

    public function testTheRequestIsEncodedBoundedAndFollowsNoRedirect(): void
    {
        $src = (string)file_get_contents(self::root() . '/202-config/functions-tracking202.php');
        $pattern = '/function clickserver_api_key_validate\(\$key\): \?bool\s*\{(.*?)\n\}/s';
        self::assertSame(1, preg_match($pattern, $src, $m));
        foreach (
            [
                'curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);',
                'curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);',
                'curl_setopt($ch, CURLOPT_TIMEOUT, 10);',
                "'https://my.tracking202.com/api/v1/auth/?' . http_build_query([",
                'return clickserver_auth_answer(is_string($result) ? $result : null);',
            ] as $line
        ) {
            self::assertStringContainsString($line, $m[1]);
        }
    }

    public function testThePreLoginKeyPageSaysWhenNoAnswerCame(): void
    {
        $page = (string)file_get_contents(self::root() . '/api-key-required.php');
        self::assertStringContainsString(
            "\$error = \$validation_result === false ? P202_ACCOUNT_KEY_UNCHECKED : "
                . "'Invalid API key; check it and retry.';",
            $page
        );
        self::assertStringContainsString('$validation_result = api_key_validate($api_key);', $page);
        self::assertStringContainsString("require_once __DIR__ . '/202-config/functions-account-ui.php';", $page);
    }

    public function testAFailureAnswerCarriesItsStatus(): void
    {
        $code = 'require ' . var_export(self::root() . '/202-config/functions-account-ui.php', true) . ';'
            . ' $body = p202_status_body(503, "x"); echo http_response_code(), " ", $body;';
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
        self::assertSame('503 {"code":503,"msg":"x"}', trim((string)$out));
    }

    public function testTheKeyPageSaysWhenNoAnswerCame(): void
    {
        $page = (string)file_get_contents(self::root() . '/202-account/ajax/validate-apikey.php');
        self::assertStringContainsString(
            "?: p202_status_body(503, 'The key could not be checked.');",
            $page
        );
        self::assertStringContainsString("echo api_key_validate(trim((string) \$_POST['apikey']))", $page);
        self::assertStringContainsString("require_once __DIR__ . '/../../202-config/functions-account-ui.php';", $page);
        $src = (string)file_get_contents(self::root() . '/202-config/functions-tracking202.php');
        self::assertSame(1, preg_match('/function api_key_validate\(\$key\)\s*\{(.*?)\n\}/s', $src, $m));
        self::assertStringContainsString('ClickServerKeyValidator::interpret($answer[0], $answer[1]) === null', $m[1]);
    }
}
