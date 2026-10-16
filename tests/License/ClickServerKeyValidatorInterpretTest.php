<?php

declare(strict_types=1);

namespace Tests\License;

use PHPUnit\Framework\TestCase;
use Prosper202\License\ClickServerKeyValidator;

/**
 * Only one of the service's four answers decides; every other shape is
 * "unknown" (null), so callers fail open and nothing is cached from it. A
 * 'paid' that is not a boolean is unknown on its own (CLAUDE.md #11: a
 * malformed value never resolves to the denial).
 */
final class ClickServerKeyValidatorInterpretTest extends TestCase
{
    /** @return array<string, array{int, string, ?array}> */
    public static function answers(): array
    {
        return [
            // The service's answers.
            'valid + paid' => [
                200, '{"code":200,"msg":"Key valid","paid":true}', ['valid' => true, 'paid' => true],
            ],
            'valid + unpaid' => [
                200, '{"code":200,"msg":"Key valid","paid":false}', ['valid' => true, 'paid' => false],
            ],
            'valid, no paid field (service DB down / edge fallback)' => [
                200, '{"code":200,"msg":"Key valid"}', ['valid' => true, 'paid' => null],
            ],
            '404 no such key' => [
                404, '{"code":404,"msg":"Key not found"}', ['valid' => false, 'paid' => false],
            ],
            '404 key refused (CPM* required)' => [
                404,
                '{"code":403,"msg":"API key requires an active CPM* Newsletter subscription."}',
                ['valid' => false, 'paid' => false],
            ],
            // A malformed 'paid' is unknown, never "not paid".
            'paid as a string' => [
                200, '{"code":200,"msg":"Key valid","paid":"yes"}', ['valid' => true, 'paid' => null],
            ],
            'paid as "true"' => [
                200, '{"code":200,"msg":"Key valid","paid":"true"}', ['valid' => true, 'paid' => null],
            ],
            'paid as 1' => [200, '{"code":200,"msg":"Key valid","paid":1}', ['valid' => true, 'paid' => null]],
            'paid as null' => [200, '{"code":200,"msg":"Key valid","paid":null}', ['valid' => true, 'paid' => null]],
            // Shapes the service never produces are outages, not verdicts.
            '200 with a message the service never sends' => [200, '{"code":200,"msg":"Temporarily unavailable"}', null],
            '200 with "Key expired"' => [200, '{"code":200,"msg":"Key expired","paid":false}', null],
            '200 whose envelope code is not 200' => [200, '{"code":503,"msg":"Key valid","paid":true}', null],
            '404 without the envelope (a gateway\'s own body)' => [404, '{"message":"Not Found"}', null],
            '404 with the envelope but a gateway\'s message' => [404, '{"code":404,"msg":"Not Found"}', null],
            '404 with the envelope and a router\'s message' => [404, '{"code":404,"msg":"Route not found"}', null],
            '404 with code 403 but not the refusal text' => [404, '{"code":403,"msg":"Forbidden"}', null],
            '404 with code 403 and only the refusal\'s prefix' => [
                404, '{"code":403,"msg":"API key requires a header"}', null,
            ],
            '404 with code 403 and the refusal text plus a suffix' => [
                404, '{"code":403,"msg":"API key requires an active CPM* Newsletter subscription. Renew at ..."}', null,
            ],
            '404 with the refusal text under the wrong code' => [
                404, '{"code":404,"msg":"API key requires an active CPM* Newsletter subscription."}', null,
            ],
            '404 with msg but no code' => [404, '{"msg":"Key not found"}', null],
            '404 with a string code' => [404, '{"code":"404","msg":"Key not found"}', null],
            '404 whose envelope code is not a refusal' => [404, '{"code":200,"msg":"Key valid","paid":false}', null],
            '500 with JSON is an outage' => [500, '{"code":500,"msg":"Server error"}', null],
            '502 HTML error page' => [502, '<html><body>Bad gateway</body></html>', null],
            '200 HTML page' => [200, '<!doctype html><title>Maintenance</title>', null],
            '302 redirect' => [302, '', null],
            '403 with the refusal envelope but the wrong status' => [
                403, '{"code":403,"msg":"API key requires an active CPM* Newsletter subscription."}', null,
            ],
            '200 JSON without msg' => [200, '{"code":200}', null],
            '200 JSON without code' => [200, '{"msg":"Key valid","paid":true}', null],
            '200 JSON msg not a string' => [200, '{"code":200,"msg":true}', null],
            '200 JSON array' => [200, '["Key valid"]', null],
            'empty body' => [200, '', null],
            'status 0 (no response)' => [0, '', null],
        ];
    }

    /**
     * @dataProvider answers
     */
    public function testInterpret(int $status, string $body, ?array $expected): void
    {
        self::assertSame($expected, ClickServerKeyValidator::interpret($status, $body));
    }

    /**
     * The denials a caller may cache are exactly the service's own: a valid
     * key the account has not paid for, and a key the service does not know
     * or refuses. Everything in answers() that is not one of those is null.
     */
    public function testOnlyTheServicesOwnAnswersDecide(): void
    {
        $decided = 0;
        foreach (self::answers() as $name => [$status, $body, $expected]) {
            if ($expected === null) {
                continue;
            }
            $decided++;
            $data = json_decode($body, true);
            $theServices = ($status === 200 && $data['code'] === 200 && $data['msg'] === 'Key valid')
                || ($status === 404 && (
                    ($data['code'] === 404 && $data['msg'] === 'Key not found')
                    || ($data['code'] === 403
                        && $data['msg'] === 'API key requires an active CPM* Newsletter subscription.')
                ));
            self::assertTrue($theServices, "$name decided without being one of the service's answers");
        }
        self::assertSame(9, $decided);
    }

    /** @return array<string, array{?string, ?string}> */
    public static function redirects(): array
    {
        $same = 'https://my.tracking202.com/api/v2/validate-customers-key/';
        return [
            'same host, https' => [$same, $same],
            'same host, another path' => [
                'https://my.tracking202.com/v3/validate', 'https://my.tracking202.com/v3/validate',
            ],
            'host in capitals' => ['https://MY.TRACKING202.COM/x', 'https://MY.TRACKING202.COM/x'],
            'same host over http' => ['http://my.tracking202.com/api/v2/validate-customers-key', null],
            'another host' => ['https://evil.example/api/v2/validate-customers-key', null],
            'a look-alike host' => ['https://my.tracking202.com.evil.example/', null],
            'a subdomain' => ['https://api.my.tracking202.com/', null],
            'a port' => ['https://my.tracking202.com:8443/', null],
            'credentials in the URL' => ['https://user:pw@my.tracking202.com/', null],
            'relative (curl always resolves; refused if it did not)' => ['/api/v2/validate-customers-key', null],
            'garbage' => ['https://', null],
            'empty' => ['', null],
            'none' => [null, null],
        ];
    }

    /**
     * Every reader of validate-customers-key asks through answer(), which
     * follows a redirect only to the service itself: the installer's and the
     * account page's readers posted the key on to wherever a redirect
     * pointed. Nothing in the served tree re-POSTs through a redirect.
     */
    public function testEveryReaderOfTheEndpointAsksThroughTheValidator(): void
    {
        $root = dirname(__DIR__, 2);
        $legacy = (string)file_get_contents($root . '/202-config/functions-tracking202.php');
        foreach (['api_key_validate', 'validateCustomersApiKey'] as $function) {
            $pattern = '/function ' . $function . '\(\$key\)\s*\{(.*?)\n\}/s';
            self::assertSame(1, preg_match($pattern, $legacy, $m), $function);
            self::assertStringContainsString('ClickServerKeyValidator::answer((string) $key)', $m[1], $function);
            self::assertStringNotContainsString('curl_', $m[1], $function);
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $offenders = [];
        foreach ($files as $file) {
            $path = substr($file->getPathname(), strlen($root) + 1);
            if ($file->getExtension() !== 'php' || preg_match('#^(vendor|tests|node_modules|go-cli)/#', $path) === 1) {
                continue;
            }
            $src = (string)file_get_contents($file->getPathname());
            if (str_contains($src, 'validate-customers-key') && !str_contains($path, 'ClickServerKeyValidator.php')) {
                $offenders[] = $path . ' names validate-customers-key outside ClickServerKeyValidator';
            }
            if (preg_match('/CURLOPT_POSTREDIR|CURL_REDIR_POST_/', $src) === 1) {
                $offenders[] = $path . ' re-POSTs through redirects';
            }
        }
        self::assertSame([], $offenders);
    }

    /**
     * @dataProvider redirects
     */
    public function testARedirectIsFollowedOnlyToTheServiceItselfOverHttps(?string $location, ?string $expected): void
    {
        self::assertSame($expected, ClickServerKeyValidator::redirectTarget($location));
    }
}
