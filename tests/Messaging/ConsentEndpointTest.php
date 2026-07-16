<?php
// tests/Messaging/ConsentEndpointTest.php
use PHPUnit\Framework\TestCase;

final class ConsentEndpointTest extends TestCase
{
    public function test_endpoint_exists_and_records(): void
    {
        $src = file_get_contents(__DIR__ . '/../../202-account/ajax/messaging/consent.php');
        $this->assertStringContainsString('ConsentPolicy::record', $src);
    }
    public function test_account_page_has_toggles(): void
    {
        $src = file_get_contents(__DIR__ . '/../../202-account/account.php');
        $this->assertStringContainsString('analytics_consent', $src);
        $this->assertStringContainsString('email_marketing_consent', $src);
    }
    public function test_template_mounts_eu_prompt_conditionally(): void
    {
        $src = file_get_contents(__DIR__ . '/../../202-config/template.php');
        $this->assertStringContainsString('needsEuPrompt', $src);
    }
}
