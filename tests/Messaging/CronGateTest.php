<?php
// tests/Messaging/CronGateTest.php
use PHPUnit\Framework\TestCase;

final class CronGateTest extends TestCase
{
    public function test_cron_gates_profile_on_consent(): void
    {
        $src = file_get_contents(__DIR__ . '/../../202-cronjobs/sync-messaging.php');
        $this->assertStringContainsString('ConsentPolicy::analyticsAllowed', $src);
        $this->assertStringContainsString('OfferProfile::compute', $src);
    }
}
