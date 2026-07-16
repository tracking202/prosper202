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
        // EU status must be computed from the client IP via the GeoIP reader.
        // $_SESSION['is_european_union'] is never assigned anywhere, so reading
        // it would persist analytics_geo_is_eu = 0 for every account; and
        // getGeoData() (connect2.php) is not loaded by the login bootstrap.
        $this->assertStringContainsString('GeoIp2\Database\Reader', $src);
        $this->assertStringContainsString('self::client_ip()', $src);
        $this->assertStringNotContainsString("\$_SESSION['is_european_union']", $src);
    }

    public function test_eu_detection_skips_persist_when_geo_is_inconclusive(): void
    {
        // rememberGeo must only run on a definitive lookup: detect_client_is_eu()
        // returns ?bool and the caller skips null, so an unknown result never
        // overwrites a previously known analytics_geo_is_eu value.
        $src = file_get_contents(__DIR__ . '/../../202-config/functions-auth.php');
        $this->assertStringContainsString('private static function detect_client_is_eu(): ?bool', $src);
        $this->assertStringContainsString('$is_eu !== null && !ConsentPolicy::rememberGeo', $src);
    }
}
