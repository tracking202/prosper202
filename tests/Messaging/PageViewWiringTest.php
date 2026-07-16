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
    public function test_login_persists_eu_geo(): void
    {
        $src = file_get_contents(__DIR__ . '/../../202-config/functions-auth.php');
        $this->assertStringContainsString('ConsentPolicy::rememberGeo', $src);
    }
}
