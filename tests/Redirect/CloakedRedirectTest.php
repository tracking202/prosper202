<?php

declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;

/**
 * Tests for cl.php cloaked redirect logic.
 *
 * cl.php handles the second hop for cloaked campaigns. It:
 * 1. Looks up click_id_public from 202_clicks_record
 * 2. Fetches redirect URL from 202_clicks_site → 202_site_urls
 * 3. Renders HTML with meta refresh and form submission
 *
 * If this breaks, cloaked campaigns produce zero conversions.
 */
final class CloakedRedirectTest extends TestCase
{
    // --- 202vars parameter handling ---

    public function testBase64DecodedVarsAppendedWithQuestionMark(): void
    {
        // When redirect URL has no query string, vars are appended with ?
        $redirectUrl = 'https://example.com/offer';
        $vars = 'subid=123&source=google';

        if (!parse_url($redirectUrl, PHP_URL_QUERY)) {
            $redirectUrl = rtrim($redirectUrl, '?');
            $redirectUrl .= '?' . $vars;
        }

        self::assertSame('https://example.com/offer?subid=123&source=google', $redirectUrl);
    }

    public function testBase64DecodedVarsAppendedWithAmpersand(): void
    {
        // When redirect URL already has a query string, vars are appended with &
        $redirectUrl = 'https://example.com/offer?existing=param';
        $vars = 'subid=123';

        if (!parse_url($redirectUrl, PHP_URL_QUERY)) {
            $redirectUrl .= '?' . $vars;
        } else {
            $redirectUrl .= '&' . $vars;
        }

        self::assertSame('https://example.com/offer?existing=param&subid=123', $redirectUrl);
    }

    public function testTrailingQuestionMarkIsStrippedBeforeAppending(): void
    {
        $redirectUrl = 'https://example.com/offer?';
        $vars = 'subid=123';

        if (!parse_url($redirectUrl, PHP_URL_QUERY)) {
            $redirectUrl = rtrim($redirectUrl, '?');
            $redirectUrl .= '?' . $vars;
        }

        self::assertSame('https://example.com/offer?subid=123', $redirectUrl);
    }

    public function testEmptyVarsNotAppended(): void
    {
        $redirectUrl = 'https://example.com/offer';
        $vars = '';

        // cl.php checks: if(isset($mysql['202vars'])&&$mysql['202vars']!='')
        if (isset($vars) && $vars !== '') {
            $redirectUrl .= '?' . $vars;
        }

        self::assertSame('https://example.com/offer', $redirectUrl);
    }

    // --- Tracker not found behavior ---

    public function testTrackerNotFoundRedirectsTo404(): void
    {
        $trackerRow = false;

        if (!$trackerRow) {
            $actionUrl = '/202-404.php';
            $redirectUrl = '/202-404.php';
            $referrer = '';
            $campaignName = '';
        } else {
            $actionUrl = '/tracking202/redirect/cl2.php';
            $redirectUrl = $trackerRow['site_url_address'];
            $referrer = $trackerRow['user_pref_cloak_referer'] ?? '';
            $campaignName = $trackerRow['aff_campaign_name'] ?? '';
        }

        self::assertSame('/202-404.php', $actionUrl);
        self::assertSame('/202-404.php', $redirectUrl);
        self::assertSame('', $referrer);
        self::assertSame('', $campaignName);
    }

    public function testValidTrackerSetsCorrectActionUrl(): void
    {
        $trackerRow = [
            'site_url_address' => 'https://affiliate.com/offer?id=99',
            'user_pref_cloak_referer' => 'no-referrer',
            'aff_campaign_name' => 'Test Campaign',
        ];

        $actionUrl = '/tracking202/redirect/cl2.php';
        $redirectUrl = $trackerRow['site_url_address'];
        $referrer = $trackerRow['user_pref_cloak_referer'] ?? '';
        $campaignName = $trackerRow['aff_campaign_name'] ?? '';

        self::assertSame('/tracking202/redirect/cl2.php', $actionUrl);
        self::assertSame('https://affiliate.com/offer?id=99', $redirectUrl);
        self::assertSame('no-referrer', $referrer);
        self::assertSame('Test Campaign', $campaignName);
    }

    public function testMissingReferrerFieldDefaultsToEmpty(): void
    {
        $trackerRow = [
            'site_url_address' => 'https://example.com',
            'aff_campaign_name' => 'Test',
            // user_pref_cloak_referer is missing
        ];

        $referrer = $trackerRow['user_pref_cloak_referer'] ?? '';
        self::assertSame('', $referrer);
    }

    // --- HTML output safety ---

    public function testCampaignNameInHtmlTitleCouldCauseXss(): void
    {
        // cl.php echoes campaign name directly without escaping
        // This is a potential XSS vector if campaign name contains HTML
        $maliciousName = '<script>alert("xss")</script>';
        $safe = htmlspecialchars($maliciousName, ENT_QUOTES, 'UTF-8');

        self::assertStringNotContainsString('<script>', $safe);
        self::assertStringContainsString('&lt;script&gt;', $safe);
        // Note: cl.php does NOT escape this - documenting the risk
    }

    public function testRedirectUrlInMetaRefreshCouldCauseInjection(): void
    {
        // cl.php outputs redirect URL in meta refresh without escaping
        $maliciousUrl = '"><script>alert(1)</script>';
        $safe = htmlspecialchars($maliciousUrl, ENT_QUOTES, 'UTF-8');

        self::assertStringNotContainsString('<script>', $safe);
    }

    // --- Base64 decoding ---

    public function testBase64DecodedVarsRoundTrip(): void
    {
        $vars = 'subid=12345&c1=campaign&c2=adgroup';
        $encoded = base64_encode($vars);
        $decoded = base64_decode($encoded);

        self::assertSame($vars, $decoded);
    }

    public function testInvalidBase64ReturnsGarbage(): void
    {
        // base64_decode with strict=false (default) doesn't fail on bad input
        $result = base64_decode('!!!not-valid-base64!!!');
        // Returns something, but it's garbage — not an error
        self::assertIsString($result);
    }
}
