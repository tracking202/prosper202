<?php
// tests/Messaging/UrlScrubberTest.php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../202-config/Messaging/UrlScrubber.class.php';

final class UrlScrubberTest extends TestCase
{
    public function test_redacts_email_value(): void
    {
        $out = UrlScrubber::scrub('https://x.com/o?s2=foo%40bar.com&c=1');
        $this->assertStringNotContainsString('bar.com', $out);
        $this->assertStringContainsString('s2=%5Bredacted%5D', $out); // [redacted] urlencoded
        $this->assertStringContainsString('c=1', $out);
    }

    public function test_redacts_phone_value(): void
    {
        $out = UrlScrubber::scrub('https://x.com/o?p=+14155551234');
        $this->assertStringNotContainsString('4155551234', $out);
    }

    public function test_leaves_macro_token_untouched(): void
    {
        $url = 'https://x.com/o?s1={clickid}&aff=42';
        $this->assertSame($url, UrlScrubber::scrub($url));
    }

    public function test_no_query_returned_unchanged(): void
    {
        $url = 'https://x.com/landing';
        $this->assertSame($url, UrlScrubber::scrub($url));
    }

    public function test_an_unparseable_url_is_never_sent(): void
    {
        $this->assertSame('', UrlScrubber::scrub('https://h:99999/x?email=a@b.co&p=+15551234567'));
        $this->assertSame('', UrlScrubber::scrub('http:///x?email=a@b.co'));
    }

    public function test_array_values_are_redacted(): void
    {
        $out = UrlScrubber::scrub('https://offer.example/x?arr[]=a@b.co&n[k][]=+15551234567&c=1');
        $this->assertStringNotContainsString('a@b.co', urldecode($out));
        $this->assertStringNotContainsString('15551234567', urldecode($out));
        $this->assertStringContainsString('c=1', $out);
    }
}
