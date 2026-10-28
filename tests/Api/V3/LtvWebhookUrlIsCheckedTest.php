<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\LtvController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMysqliConnection;

/**
 * POST /ltv/webhooks refuses a URL the export webhooks refuse, as a 422 on
 * the field it was sent in, and writes nothing (J0F7). The URLs here are
 * ones the old guard stored and then sent deliveries to.
 */
final class LtvWebhookUrlIsCheckedTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function refused(): iterable
    {
        yield 'Azure WireServer' => ['https://168.63.129.16/hook', 'cloud metadata endpoint'];
        yield 'IPv4-mapped metadata' => ['https://[::ffff:169.254.169.254]/latest/meta-data/', 'link-local'];
        yield 'IPv4-mapped loopback' => ['https://[::ffff:127.0.0.1]:8443/hook', 'loopback'];
        yield 'IPv4-mapped metadata, hex' => ['https://[::ffff:a9fe:a9fe]/hook', 'link-local'];
        yield 'NAT64 metadata' => ['https://[64:ff9b::a9fe:a9fe]/hook', 'link-local'];
        yield 'a user name hiding the host' => ['https://hooks.example.com@10.0.0.1/hook', 'not in the form'];
        yield 'plain http' => ['http://hooks.example.com/hook', 'must start with https://'];
    }

    /** @dataProvider refused */
    public function testARefusedUrlIsA422OnItsFieldAndNothingIsWritten(string $url, string $why): void
    {
        foreach (['url', 'webhook_url'] as $field) {
            $conn = new FakeMysqliConnection();
            try {
                (new LtvController($conn, 7))->createWebhook([$field => $url]);
                self::fail($url . ' was accepted');
            } catch (ValidationException $e) {
                self::assertSame([$field], array_keys($e->getFieldErrors()));
                self::assertStringContainsString($why, $e->getFieldErrors()[$field]);
            }
            self::assertSame([], $conn->statementsContaining('INSERT'), 'nothing written');
        }
    }
}
