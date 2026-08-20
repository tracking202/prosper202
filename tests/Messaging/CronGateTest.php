<?php
// tests/Messaging/CronGateTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../202-config/Messaging/MessagingService.class.php';

final class CronGateTest extends TestCase
{
    public function test_cron_gates_profile_on_consent(): void
    {
        $src = file_get_contents(__DIR__ . '/../../202-cronjobs/sync-messaging.php');
        $this->assertStringContainsString('ConsentPolicy::analyticsAllowed', $src);
        $this->assertStringContainsString('OfferProfile::compute', $src);
    }

    /**
     * The offer profile is mostly nested data (networks[], traffic_source_types[],
     * top_geos[], device_mix{}, offers[], ltv{}). updateAttributes must accept
     * those arrays — a scalars-only filter would silently drop the feature's
     * core payload before it ever reached 202_messaging_attributes.data.
     */
    public function test_update_attributes_accepts_nested_profile_values(): void
    {
        $profileShapedValues = [
            'clicks_30d'           => 123,
            'income_30d'           => 45.6,
            'near_plan_limit'      => false,
            'networks'             => ['MaxBounty', 'ClickBank'],
            'traffic_source_types' => ['Facebook Ads'],
            'top_geos'             => ['US', 'GB'],
            'device_mix'           => ['mobile' => 60, 'desktop' => 35, 'tablet' => 5],
            'offers'               => [['name' => 'Keto - US', 'url' => 'https://x.example/o', 'epc' => 2.1]],
            'ltv'                  => ['customers' => 10, 'avg_ltv' => 99.0],
        ];
        foreach ($profileShapedValues as $key => $value) {
            $this->assertTrue(
                MessagingService::isPersistableAttributeValue($value),
                "profile key '{$key}' must be persistable by updateAttributes"
            );
        }

        // Objects/resources stay out of the attribute contract.
        $this->assertFalse(MessagingService::isPersistableAttributeValue(new stdClass()));
    }
}
