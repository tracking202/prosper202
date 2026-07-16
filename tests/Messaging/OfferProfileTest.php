<?php
// tests/Messaging/OfferProfileTest.php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../202-config/Messaging/UrlScrubber.class.php';
require_once __DIR__ . '/../../202-config/Messaging/OfferProfile.class.php';

final class OfferProfileTest extends TestCase
{
    public function test_offer_row_url_is_scrubbed(): void
    {
        // OfferProfile::buildOfferRow is a pure helper that assembles one offer
        // entry from a raw DB row; it must run the url through UrlScrubber and
        // derive the domain from the scrubbed url.
        $raw = [
            'aff_campaign_name' => 'Keto - US',
            'aff_campaign_url'  => 'https://getfit-keto.com/o?s2=foo%40bar.com',
            'aff_network_name'  => 'MaxBounty',
            'aff_campaign_payout' => '42.00',
            'aff_campaign_currency' => 'USD',
            'income' => '210.0', 'clicks' => '100', 'leads' => '5',
        ];
        $row = OfferProfile::buildOfferRow($raw);
        $this->assertStringNotContainsString('bar.com', $row['url']);
        $this->assertSame('getfit-keto.com', $row['domain']);
        $this->assertSame('MaxBounty', $row['network']);
        $this->assertEqualsWithDelta(2.10, $row['epc'], 0.001);     // income/clicks
        $this->assertEqualsWithDelta(0.05, $row['conv_rate'], 0.001); // leads/clicks
    }

    public function test_plan_limit_pct_buckets(): void
    {
        $this->assertSame(88, OfferProfile::planLimitPct(44000, 50000));
        $this->assertTrue(OfferProfile::nearPlanLimit(88));
        $this->assertFalse(OfferProfile::nearPlanLimit(40));
    }

    public function test_login_cadence_buckets(): void
    {
        // Spec §8: login_cadence (daily/weekly/dormant), derived from the
        // essential-tier login events recorded on this install.
        $this->assertSame('dormant', OfferProfile::loginCadence(0));
        $this->assertSame('occasional', OfferProfile::loginCadence(1));
        $this->assertSame('occasional', OfferProfile::loginCadence(3));
        $this->assertSame('weekly', OfferProfile::loginCadence(4));
        $this->assertSame('weekly', OfferProfile::loginCadence(19));
        $this->assertSame('daily', OfferProfile::loginCadence(20));
        $this->assertSame('daily', OfferProfile::loginCadence(90));
    }

    public function test_compute_covers_spec8_lifecycle_attributes(): void
    {
        // first_click_at / days_since_signup / login_cadence must be part of
        // the computed profile (spec §8 rows previously missing).
        $src = file_get_contents(__DIR__ . '/../../202-config/Messaging/OfferProfile.class.php');
        foreach (['first_click_at', 'days_since_signup', 'login_cadence'] as $key) {
            $this->assertStringContainsString("'$key'", $src, "OfferProfile missing spec §8 attribute $key");
        }
    }

    public function test_ltv_privacy_boundary_no_per_customer_surfaces(): void
    {
        // The profile may use ONLY account-level LTV aggregates. Guard against
        // anyone later joining per-customer tables into the synced profile.
        $src = file_get_contents(__DIR__ . '/../../202-config/Messaging/OfferProfile.class.php');
        foreach (['202_customers', '202_customer_aliases', '202_customer_fields',
                  '202_customer_field_values', '202_revenue_events', 'first_name'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src,
                "OfferProfile must not touch per-customer surface: $forbidden");
        }
    }
}
