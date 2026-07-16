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
}
