<?php
// tests/Messaging/TrackEndpointGuardTest.php
use PHPUnit\Framework\TestCase;

final class TrackEndpointGuardTest extends TestCase
{
    public function test_track_php_consults_consent_policy(): void
    {
        $src = file_get_contents(__DIR__ . '/../../202-account/ajax/messaging/track.php');
        $this->assertStringContainsString('ConsentPolicy::captureFence', $src,
            'track.php must gate analytics writes through ConsentPolicy');
        $this->assertStringContainsString("'consent_unavailable'", $src,
            'a consent that cannot be read is answered as such, not as a refusal');
    }

    public function test_track_php_rejects_malformed_json_explicitly(): void
    {
        $src = file_get_contents(__DIR__ . '/../../202-account/ajax/messaging/track.php');
        // must NOT silently swallow bad JSON with "?? []"
        $this->assertDoesNotMatchRegularExpression('/json_decode\([^;]*\)\s*\?\?\s*\[\]/', $src);
    }
}
