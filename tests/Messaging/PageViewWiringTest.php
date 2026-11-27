<?php
// tests/Messaging/PageViewWiringTest.php
use PHPUnit\Framework\TestCase;

final class PageViewWiringTest extends TestCase
{
    public function test_template_records_page_view(): void
    {
        $src = file_get_contents(__DIR__ . '/../../202-config/template.php');
        $this->assertStringContainsString("Analytics::event('page_viewed'", $src);
    }
    public function test_sign_in_looks_up_no_location(): void
    {
        // Usage data is opt-in everywhere, so where the user signs in from
        // decides nothing and is not looked up.
        $src = file_get_contents(__DIR__ . '/../../202-config/functions-auth.php');
        $this->assertStringNotContainsString('rememberGeo', $src);
        $this->assertStringNotContainsString('GeoIp2\\Database\\Reader', $src);
        $this->assertStringNotContainsString("\$_SESSION['is_european_union']", $src);
    }
}
