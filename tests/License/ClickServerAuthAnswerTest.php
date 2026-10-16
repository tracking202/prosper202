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
            "if ((\$csKeyError = p202_check_clickserver_key((string) \$_POST['clickserver_api_key'])) !== null) {",
            $account
        );
        $appstore = (string)file_get_contents(self::root() . '/202-appstore/index.php');
        self::assertStringContainsString('if (($keyError = p202_check_clickserver_key($key)) !== null) {', $appstore);
        foreach ([$account, $appstore] as $page) {
            self::assertStringNotContainsString('!clickserver_api_key_validate(', $page);
        }
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

    public function testTheKeyPageSaysWhenNoAnswerCame(): void
    {
        $page = (string)file_get_contents(self::root() . '/202-account/ajax/validate-apikey.php');
        self::assertStringContainsString(
            "echo api_key_validate((string) \$_POST['apikey']) ?: "
                . "'{\"code\":503,\"msg\":\"The key could not be checked; try again.\"}';",
            $page
        );
    }
}
