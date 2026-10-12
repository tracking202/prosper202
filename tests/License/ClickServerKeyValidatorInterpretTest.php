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
            'valid + paid' => [200, '{"code":200,"msg":"Key valid","paid":true}', ['valid' => true, 'paid' => true]],
            'valid + unpaid' => [200, '{"code":200,"msg":"Key valid","paid":false}', ['valid' => true, 'paid' => false]],
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
            $theServices = ($status === 200 && str_contains($body, '"Key valid"'))
                || ($status === 404 && preg_match('/"code":40[34]/', $body) === 1);
            self::assertTrue($theServices, "$name decided without being one of the service's answers");
        }
        self::assertSame(9, $decided);
    }
}
