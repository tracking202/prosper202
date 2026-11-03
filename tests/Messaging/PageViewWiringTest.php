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
        // From the connecting peer only: a forwarding header would let the
        // client choose its country (AuthClassTest holds the behaviour).
        $this->assertStringContainsString('VisitorIp::unforwardedPeer($server)', $src);
        $this->assertStringNotContainsString("\$_SESSION['is_european_union']", $src);
    }
}
