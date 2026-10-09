<?php

declare(strict_types=1);

namespace Tests\License;

use PHPUnit\Framework\TestCase;
use Prosper202\License\ClickServerKeyValidator;

/**
 * Only a well-formed, authoritative answer decides; every outage shape is
 * "unknown" (null), so callers fail open and never cache a denial.
 */
final class ClickServerKeyValidatorInterpretTest extends TestCase
{
    /** @return array<string, array{int, string, ?array}> */
    public static function answers(): array
    {
        return [
            'valid + paid' => [200, '{"code":200,"msg":"Key valid","paid":true}', ['valid' => true, 'paid' => true]],
            'valid + unpaid' => [200, '{"code":200,"msg":"Key valid","paid":false}', ['valid' => true, 'paid' => false]],
            'valid, no paid field (older backend / edge fallback)' => [200, '{"code":200,"msg":"Key valid"}', ['valid' => true, 'paid' => null]],
            'paid must be literally true' => [200, '{"code":200,"msg":"Key valid","paid":"yes"}', ['valid' => true, 'paid' => false]],
            '200 with another msg is invalid' => [200, '{"code":200,"msg":"Key expired"}', ['valid' => false, 'paid' => false]],
            '404 invalid key' => [404, '{"code":404,"msg":"Key not found"}', ['valid' => false, 'paid' => false]],
            '404 CPM* required' => [404, '{"code":403,"msg":"API key requires an active CPM* Newsletter subscription."}', ['valid' => false, 'paid' => false]],
            '500 with JSON is an outage' => [500, '{"code":500,"msg":"Server error"}', null],
            '502 HTML error page' => [502, '<html><body>Bad gateway</body></html>', null],
            '200 HTML page' => [200, '<!doctype html><title>Maintenance</title>', null],
            '302 redirect' => [302, '', null],
            '200 JSON without msg' => [200, '{"code":200}', null],
            '200 JSON msg not a string' => [200, '{"msg":true}', null],
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
}
