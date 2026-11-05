<?php
// tests/Messaging/UrlScrubberTest.php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../202-config/Messaging/UrlScrubber.class.php';

final class UrlScrubberTest extends TestCase
{
    /** @dataProvider urls */
    public function test_only_the_shape_of_an_offer_url_is_kept(string $url, string $expected): void
    {
        $this->assertSame($expected, UrlScrubber::scrub($url));
    }

    /** @return array<string, array{string, string}> */
    public static function urls(): array
    {
        return [
            'a template keeps its macros and ids' => [
                'https://store.example/offer/keto-us?s1={clickid}&s2=[[subid]]&aff=42',
                'https://store.example/offer/keto-us?s1={clickid}&s2=[[subid]]&aff=42',
            ],
            'no query' => ['https://store.example/landing', 'https://store.example/landing'],
            'sign-in details' => ['https://alice:secret@store.example/x?c=1', 'https://store.example/x?c=1'],
            'an email in the path' => ['https://store.example/u/alice@example.com/keto', 'https://store.example/u/redacted/keto'],
            'an encoded email in the path' => ['https://store.example/u/alice%40example.com', 'https://store.example/u/redacted'],
            'an email inside other query text' => ['https://store.example/?customer=Alice%20alice%40example.com', 'https://store.example/?customer=redacted'],
            'a repeated key: each value' => [
                'https://store.example/?email=alice%40example.com&email=bob%40example.com',
                'https://store.example/?email=redacted&email=redacted',
            ],
            'two emails in one value' => ['https://store.example/?to=a@b.co,c@d.co', 'https://store.example/?to=redacted'],
            'a phone number' => ['https://store.example/o?p=+14155551234', 'https://store.example/o?p=redacted'],
            'a phone number in the path' => ['https://store.example/call/14155551234', 'https://store.example/call/redacted'],
            'a name in a value' => ['https://store.example/o?name=alice', 'https://store.example/o?name=redacted'],
            'a fragment' => ['https://store.example/o?c=1#customer=alice@example.com', 'https://store.example/o?c=1'],
            'an email in a key' => ['https://store.example/o?alice@example.com=1', 'https://store.example/o?redacted=1'],
            'nested keys' => ['https://store.example/o?arr[]=a@b.co&n[k][]=1', 'https://store.example/o?redacted=redacted&redacted=1'],
            'a 32-hex token in the path' => [
                'https://net.example/t/9f86d081884c7d659a2feaa0c55ad015?aff_id=jane',
                'https://net.example/t/redacted?aff_id=redacted',
            ],
            'a short hex token in the path' => ['https://net.example/t/a1b2c3d4', 'https://net.example/t/redacted'],
            'a base64url token in the path' => [
                'https://net.example/c/dGhpc2lzYXRva2Vu_x-Y2Z3/lp',
                'https://net.example/c/redacted/lp',
            ],
            'a firstname.lastname segment' => ['https://lp.example/u/alice.smith/keto', 'https://lp.example/u/redacted/keto'],
            'a 6-digit id in the path' => ['https://lp.example/offer/123456/keto-us', 'https://lp.example/offer/123456/keto-us'],
            'a 7-digit id in the path' => ['https://lp.example/offer/1234567', 'https://lp.example/offer/redacted'],
            'words with short numbers and a page extension' => [
                'https://lp.example/v2/keto-2026-us/index.php?sub_id2={clickid}',
                'https://lp.example/v2/keto-2026-us/index.php?sub_id2={clickid}',
            ],
            'a token as a query key' => ['https://lp.example/o?Zx9Kq2Lm8Np4=1', 'https://lp.example/o?redacted=1'],
            'a very long word' => ['https://lp.example/abcdefghijklmnopqrstuvwxyz', 'https://lp.example/redacted'],
            'a tel: link' => ['tel:+14155551234', ''],
            'a mailto: link' => ['mailto:alice@example.com', ''],
            'another scheme' => ['javascript://store.example/%0Aalert(1)', ''],
            'no host' => ['http:///x?email=a@b.co', ''],
            'a port out of range' => ['https://h:99999/x?email=a@b.co', ''],
            'not a URL' => ['alice@example.com', ''],
        ];
    }
}
