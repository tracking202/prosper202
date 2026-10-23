<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\SignInReturn;

/**
 * The sign-in page sends a person back to a page on this install, never to
 * another site, whatever the link's redirect parameter says.
 */
final class SignInReturnTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function local(): iterable
    {
        yield 'the account page' => ['/202-account/'];
        yield 'a page with a query' => ['/202-account/connect-ai.php?state=a.b&client_name=Claude%20(claude.ai)'];
        yield 'its own encoded query' => [
            '/202-account/connect-ai.php?redirect_uri=https%3A%2F%2Fmcp.tracking202.com%2Fcallback'
                . '&client_name=E2E+client',
        ];
        yield 'an install in a folder' => ['/p202/tracking202/Setup/'];
        yield 'the root' => ['/'];
    }

    /** @return iterable<string, array{string}> */
    public static function elsewhere(): iterable
    {
        yield 'protocol-relative' => ['//other.example/'];
        yield 'backslash host' => ['/\\other.example/'];
        yield 'backslash later' => ['/x\\..\\other'];
        yield 'absolute' => ['https://other.example/'];
        yield 'scheme without slash' => ['javascript:alert(1)'];
        yield 'relative' => ['202-account/'];
        yield 'header split' => ["/202-account/\r\nSet-Cookie: x=1"];
        yield 'tab' => ["/\t/other.example/"];
        yield 'space' => ['/ /other.example/'];
        yield 'non-ASCII' => ["/caf\u{e9}"];
        yield 'empty' => [''];
        yield 'too long' => ['/' . str_repeat('a', 2048)];
    }

    /** @dataProvider local */
    public function testAPathOnThisInstallIsKept(string $target): void
    {
        self::assertSame($target, SignInReturn::path($target));
    }

    /** @dataProvider elsewhere */
    public function testAnythingElseIsRefused(string $target): void
    {
        self::assertNull(SignInReturn::path($target));
    }

    public function testTheSignInPageAsksIt(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/202-login.php');
        self::assertStringContainsString('SignInReturn::path((string) $_GET[\'redirect\'])', $source);
        self::assertStringNotContainsString('urldecode', $source);
        self::assertStringNotContainsString("\$target[0] === '/'", $source);
    }
}
