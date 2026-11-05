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

    public function test_a_long_url_is_cut_at_a_part_boundary(): void
    {
        $url = 'https://store.example/' . implode('/', array_fill(0, 200, 'keto')) . '?s1={clickid}';

        $scrubbed = UrlScrubber::scrub($url);

        $this->assertLessThanOrEqual(512, strlen($scrubbed));
        $this->assertStringStartsWith('https://store.example/keto/keto/', $scrubbed);
        $this->assertStringEndsWith('/redacted', $scrubbed);
        $this->assertStringNotContainsString('{clickid}', $scrubbed);
    }

    public function test_a_url_within_the_cap_is_not_cut(): void
    {
        $url = 'https://store.example/' . implode('/', array_fill(0, 90, 'keto'));

        $this->assertSame($url, UrlScrubber::scrub($url));
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
            'a phone number split by dashes' => ['https://store.example/call/415-555-1234', 'https://store.example/call/redacted'],
            'a card number split by dashes' => ['https://store.example/c/4111-1111-1111-1111', 'https://store.example/c/redacted'],
            'a number split by an underscore' => ['https://store.example/a/123456_789012', 'https://store.example/a/redacted'],
            'a phone number split by dots' => ['https://store.example/call/415.555.1234', 'https://store.example/call/redacted'],
            'a phone number split by encoded spaces' => ['https://store.example/call/415%20555%201234', 'https://store.example/call/redacted'],
            'a phone number split by spaces' => ['https://store.example/call/415 555 1234', 'https://store.example/call/redacted'],
            'a phone number behind a word' => ['https://store.example/o/ord-415-555-1234', 'https://store.example/o/redacted'],
            'a phone number with a page extension' => ['https://store.example/o/415-555-1234.html', 'https://store.example/o/redacted'],
            'a phone number split by underscores in a key' => ['https://store.example/o?415_555_1234=1', 'https://store.example/o?redacted=1'],
            'a phone number split by dashes in a value' => ['https://store.example/o?p=415-555-1234', 'https://store.example/o?p=redacted'],
            'a phone number split across path segments' => [
                'https://store.example/call/415/555/1234/lp',
                'https://store.example/call/redacted/redacted/redacted/lp',
            ],
            'a number split by a word segment' => ['https://store.example/415/555/x/1234', 'https://store.example/redacted/redacted/x/redacted'],
            'a number split by word segments' => ['https://store.example/415/x/555/1234', 'https://store.example/redacted/x/redacted/redacted'],
            'a number split across a word and a segment' => ['https://store.example/alice4155/551234', 'https://store.example/redacted/redacted'],
            'a number split across a segment and a word' => ['https://store.example/p/415555/1234x', 'https://store.example/p/redacted/redacted'],
            'a card number split by word segments' => [
                'https://store.example/c/4111/x/1111/y/1111/z/1111',
                'https://store.example/c/redacted/x/redacted/y/redacted/z/redacted',
            ],
            'a number split across the path and a query key' => ['https://store.example/a4155?551234=x', 'https://store.example/redacted?redacted=redacted'],
            'a number split across query values' => [
                'https://store.example/o?a=415&b=555&c=1234&sub={clickid}',
                'https://store.example/o?a=redacted&b=redacted&c=redacted&sub={clickid}',
            ],
            'a number split across macros' => ['https://store.example/{415}/{555}/{1234}', 'https://store.example/redacted/redacted/redacted'],
            'a plain landing page' => ['https://lp.example/keto-diet/index.html', 'https://lp.example/keto-diet/index.html'],
            'short ids in neighbouring segments' => ['https://lp.example/offer/42/7/keto', 'https://lp.example/offer/42/7/keto'],
            'a long number as a macro' => ['https://store.example/o?s1={1234567890123}', 'https://store.example/o?s1=redacted'],
            'a dotted name as a macro' => ['https://store.example/o?s1={alice.smith}', 'https://store.example/o?s1=redacted'],
            'a split number as a macro' => ['https://store.example/o?s1=[[415-555-1234]]', 'https://store.example/o?s1=redacted'],
            'a token as a macro' => ['https://store.example/o?s1=#a1b2c3d4e5#', 'https://store.example/o?s1=redacted'],
            'a macro as a path segment' => ['https://store.example/{alice.smith}/{clickid}', 'https://store.example/redacted/{clickid}'],
            'tracker macros with a namespace' => [
                'https://store.example/o?c={campaign.id}&t={trafficsource.name}&p={p202:subid}',
                'https://store.example/o?c={campaign.id}&t={trafficsource.name}&p={p202:subid}',
            ],
            'a tel: link' => ['tel:+14155551234', ''],
            'a mailto: link' => ['mailto:alice@example.com', ''],
            'another scheme' => ['javascript://store.example/%0Aalert(1)', ''],
            'no host' => ['http:///x?email=a@b.co', ''],
            'a port out of range' => ['https://h:99999/x?email=a@b.co', ''],
            'not a URL' => ['alice@example.com', ''],
        ];
    }
}
