<?php

declare(strict_types=1);

namespace Tests\Fraud;

use PHPUnit\Framework\TestCase;
use Prosper202\Fraud\IpqsClient;
use Prosper202\Fraud\IpqsLookupFailed;

/**
 * IpqsClient against a stand-in IPQualityScore on loopback (IpqsStub): what
 * one lookup sends, and that every way it can fail is a failure whose
 * message never holds the key.
 */
final class IpqsClientTest extends TestCase
{
    private const KEY = 'k3y/With+Odd&Chars';

    private static ?IpqsStub $stub = null;

    public static function setUpBeforeClass(): void
    {
        self::$stub = new IpqsStub();
    }

    public static function tearDownAfterClass(): void
    {
        self::$stub?->stop();
        self::$stub = null;
    }

    protected function setUp(): void
    {
        self::$stub->forget();
    }

    private static function client(): IpqsClient
    {
        return new IpqsClient(self::$stub->origin);
    }

    public function testALookupSendsTheAddressAndTheFixedFlagsOnly(): void
    {
        self::$stub->scores(['203.0.113.7' => 97]);

        self::assertSame(97, self::client()->fraudScore(self::KEY, '203.0.113.7'));

        $requests = self::$stub->requests();
        self::assertCount(1, $requests);
        self::assertSame('/api/json/ip/' . rawurlencode(self::KEY) . '/203.0.113.7', $requests[0]['path']);
        self::assertSame(
            ['strictness' => '0', 'allow_public_access_points' => 'true', 'fast' => 'true'],
            $requests[0]['query'],
            'no user agent, language, referer or click data'
        );
        self::assertSame('Prosper202', $requests[0]['user_agent'], 'our agent, not the visitor\'s');
        self::assertNull($requests[0]['referer']);
    }

    public function testAnIpv6AddressIsOnePathSegment(): void
    {
        self::$stub->scores(['2001:db8::1' => 3]);

        self::assertSame(3, self::client()->fraudScore(self::KEY, '2001:db8::1'));
        self::assertSame(['2001:db8::1'], self::$stub->addresses());
    }

    /** @return iterable<string, array{string, string}> */
    public static function failures(): iterable
    {
        yield 'success false, quoting the key' => ['refused-k3y', 'refused the lookup: Invalid or unauthorized key …'];
        yield 'a 503' => ['down', 'answered HTTP 503'];
        yield 'a redirect, not followed' => ['moved', 'answered HTTP 302'];
        yield 'not JSON' => ['garbled', 'other than JSON'];
        yield 'past the size cap' => ['big', 'more than 65536 bytes'];
    }

    /** @dataProvider failures */
    public function testEachFailureIsOneWithoutTheKey(string $key, string $says): void
    {
        try {
            self::client()->fraudScore($key, '203.0.113.9');
            self::fail('no failure');
        } catch (IpqsLookupFailed $e) {
            self::assertStringContainsString($says, $e->getMessage());
            self::assertStringNotContainsString($key, $e->getMessage(), 'the key is never in the message');
        }
    }

    public function testALookupThatOutlastsTheTimeoutFails(): void
    {
        $started = microtime(true);
        try {
            self::client()->fraudScore('slow', '203.0.113.10');
            self::fail('no failure');
        } catch (IpqsLookupFailed $e) {
            self::assertStringContainsString('could not be reached (curl 28', $e->getMessage());
        }
        self::assertLessThan(IpqsClient::TIMEOUT + 2, microtime(true) - $started);
    }

    public function testAnUnreachableHostFailsWithoutTheKey(): void
    {
        try {
            (new IpqsClient('http://127.0.0.1:1'))->fraudScore('secret-key-123', '203.0.113.11');
            self::fail('no failure');
        } catch (IpqsLookupFailed $e) {
            self::assertStringContainsString('could not be reached', $e->getMessage());
            self::assertStringNotContainsString('secret-key-123', $e->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function notLoopback(): iterable
    {
        yield 'another host' => ['http://203.0.113.5:8080'];
        yield 'a path' => ['http://127.0.0.1:8080/api'];
        yield 'another scheme' => ['ftp://127.0.0.1'];
    }

    /** @dataProvider notLoopback */
    public function testOnlyALoopbackOriginStandsIn(string $origin): void
    {
        $client = new IpqsClient($origin);
        $property = new \ReflectionProperty($client, 'override');
        self::assertNull($property->getValue($client), 'the real origin is used');
    }

    public function testTheRealOriginIsHttps(): void
    {
        self::assertStringStartsWith('https://', IpqsClient::ORIGIN);
    }
}
