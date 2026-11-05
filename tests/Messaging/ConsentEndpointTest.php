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

    public function test_disclosure_page_exists_and_links_are_live(): void
    {
        // The disclosure is load-bearing for the privacy posture (spec §10.1,
        // D2/D3): the "Learn more" link must resolve on every install — a
        // local page, reached via get_absolute_url() (never a root-absolute
        // path, which 404s on subdirectory installs).
        $this->assertFileExists(__DIR__ . '/../../202-account/disclosure.php');

        $account = file_get_contents(__DIR__ . '/../../202-account/account.php');
        $this->assertStringNotContainsString('href="/disclosure"', $account,
            'account.php must not link a nonexistent root-absolute /disclosure route');
        $this->assertStringContainsString('202-account/disclosure.php', $account);

        // The EU prompt is a consent surface too — it must carry the same link.
        $prompt = file_get_contents(__DIR__ . '/../../202-js/consent-prompt.js');
        $this->assertStringContainsString('202-account/disclosure.php', $prompt);
    }
}
