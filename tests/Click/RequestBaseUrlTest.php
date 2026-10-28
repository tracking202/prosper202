<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\TrackingBaseUrl;

/**
 * This install's own URLs on the origin a request arrived at
 * (TrackingBaseUrl::forRequest(), requestUrl()): the cloaked redirects, the
 * tracking202outbound cookie, the URLs stored with a click.
 */
final class RequestBaseUrlTest extends TestCase
{
    private const HOST = 'track.example.test';

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * A request to the scratch server's address, with $server on top.
     *
     * @param array<string, string> $server
     * @return array<string, string>
     */
    private static function request(array $server): array
    {
        return $server + ['SERVER_NAME' => '127.0.0.1', 'SERVER_PORT' => '8112', 'DOCUMENT_ROOT' => self::root()];
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function origins(): iterable
    {
        $h = self::HOST;
        yield 'the Host header, its port kept' => [['HTTP_HOST' => "$h:8112"], "http://$h:8112/"];
        yield 'an address and a port' => [['HTTP_HOST' => '127.0.0.1:8112'], 'http://127.0.0.1:8112/'];
        yield 'a default port, none written' => [['HTTP_HOST' => $h, 'SERVER_PORT' => '80'], "http://$h/"];
        yield 'HTTPS' => [['HTTP_HOST' => "$h:8443", 'HTTPS' => 'on'], "https://$h:8443/"];
        yield 'HTTPS off' => [['HTTP_HOST' => $h, 'HTTPS' => 'off'], "http://$h/"];
        yield 'a TLS-terminating proxy' => [
            ['HTTP_HOST' => "$h:8122", 'HTTP_X_FORWARDED_PROTO' => 'https'],
            "https://$h:8122/",
        ];
        yield 'a forwarded host is not believed' => [
            ['HTTP_HOST' => $h, 'HTTP_X_FORWARDED_HOST' => 'evil.example', 'SERVER_PORT' => '80'],
            "http://$h/",
        ];
        yield 'nginx catch-all name, real Host' => [
            ['HTTP_HOST' => "$h:8080", 'SERVER_NAME' => '_'],
            "http://$h:8080/",
        ];
        yield 'a Host that is not a host: the server name' => [
            ['HTTP_HOST' => 'evil.example@other.example'],
            'http://127.0.0.1:8112/',
        ];
        yield 'a Host with a path: the server name' => [['HTTP_HOST' => 'evil.example/x'], 'http://127.0.0.1:8112/'];
        yield 'no Host header: the server name' => [[], 'http://127.0.0.1:8112/'];
        yield 'nothing at all: localhost' => [['SERVER_NAME' => '', 'SERVER_PORT' => '80'], 'http://localhost/'];
    }

    /**
     * @dataProvider origins
     * @param array<string, string> $server
     */
    public function testForRequest(array $server, string $expected): void
    {
        self::assertSame($expected, TrackingBaseUrl::forRequest(self::request($server)));
    }

    public function testTheInstallDirectoryIsKept(): void
    {
        $server = self::request(['HTTP_HOST' => self::HOST . ':8112', 'DOCUMENT_ROOT' => dirname(self::root())]);
        self::assertSame(
            'http://' . self::HOST . ':8112/' . basename(self::root()) . '/',
            TrackingBaseUrl::forRequest($server)
        );
    }

    public function testRequestUrl(): void
    {
        $origin = 'http://' . self::HOST . ':8112';
        $server = self::request([
            'HTTP_HOST' => self::HOST . ':8112',
            'REQUEST_URI' => '/tracking202/redirect/dl.php?t202id=1',
        ]);
        self::assertSame("$origin/tracking202/redirect/dl.php?t202id=1", TrackingBaseUrl::requestUrl($server));
        $server['REQUEST_URI'] = 'http://evil.example/x';
        self::assertSame("$origin/", TrackingBaseUrl::requestUrl($server), 'an absolute-form target is not a path');
        unset($server['REQUEST_URI']);
        self::assertSame("$origin/", TrackingBaseUrl::requestUrl($server));
    }

    public function testRequestHost(): void
    {
        $server = self::request(['HTTP_HOST' => 'Track.Example.Test:8112']);
        self::assertSame(self::HOST . ':8112', TrackingBaseUrl::requestHost($server));
        self::assertSame('127.0.0.1:8112', TrackingBaseUrl::requestHost(self::request(['HTTP_HOST' => 'a b'])));
    }

    public function testTheInstallerBaseUrlIsTheSameOrigin(): void
    {
        require_once self::root() . '/202-config/functions-install-helpers.php';
        $server = self::request(['HTTP_HOST' => 'evil.example/x', 'HTTP_X_FORWARDED_PROTO' => 'https']);
        self::assertSame('https://127.0.0.1:8112/p/', install_request_base_url($server, '/p/'));
    }
}
