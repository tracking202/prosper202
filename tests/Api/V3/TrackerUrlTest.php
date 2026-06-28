<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\TrackersController;
use Tests\TestCase;

/**
 * Unit tests for tracker redirect-endpoint selection.
 *
 * The promoted tracking URL must point at the redirect handler that matches the
 * tracker's type, mirroring the UI link generator (tracking202/ajax/generate_tracking_link.php):
 *   - landing-page tracker -> the landing page's own URL + ?t202id=
 *   - rotator tracker       -> rtr.php
 *   - direct-link tracker   -> dl.php
 */
class TrackerUrlTest extends TestCase
{
    private const BASE = 'https://trk.example.com';

    public function testDirectLinkTrackerUsesDlPhp(): void
    {
        $url = TrackersController::buildDirectUrl(self::BASE, 555, [
            'rotator_id'      => 0,
            'landing_page_id' => 0,
        ]);

        $this->assertSame(self::BASE . '/tracking202/redirect/dl.php?t202id=555', $url);
    }

    public function testRotatorTrackerUsesRtrPhp(): void
    {
        $url = TrackersController::buildDirectUrl(self::BASE, 555, [
            'rotator_id'      => 4,
            'landing_page_id' => 0,
        ]);

        $this->assertSame(self::BASE . '/tracking202/redirect/rtr.php?t202id=555', $url);
    }

    public function testLandingPageTrackerUsesLandingPageUrl(): void
    {
        $url = TrackersController::buildDirectUrl(self::BASE, 555, [
            'rotator_id'        => 0,
            'landing_page_id'   => 12,
            'landing_page_url'  => 'https://lander.example.com/offer',
        ]);

        $this->assertSame('https://lander.example.com/offer?t202id=555', $url);
    }

    public function testLandingPageWithExistingQueryAppendsT202id(): void
    {
        $url = TrackersController::buildDirectUrl(self::BASE, 555, [
            'rotator_id'        => 0,
            'landing_page_id'   => 12,
            'landing_page_url'  => 'https://lander.example.com/offer?utm=fb',
        ]);

        $this->assertSame('https://lander.example.com/offer?utm=fb&t202id=555', $url);
    }

    public function testLandingPageTakesPrecedenceOverRotator(): void
    {
        // A tracker carrying both a landing page and a rotator promotes the landing page,
        // matching the UI (get_trackers.php checks landing_page_id first).
        $url = TrackersController::buildDirectUrl(self::BASE, 555, [
            'rotator_id'        => 4,
            'landing_page_id'   => 12,
            'landing_page_url'  => 'https://lander.example.com/offer',
        ]);

        $this->assertSame('https://lander.example.com/offer?t202id=555', $url);
    }

    public function testLandingPagePreservesFragment(): void
    {
        $url = TrackersController::buildDirectUrl(self::BASE, 555, [
            'rotator_id'        => 0,
            'landing_page_id'   => 12,
            'landing_page_url'  => 'https://lander.example.com/offer#section',
        ]);

        $this->assertSame('https://lander.example.com/offer?t202id=555#section', $url);
    }

    public function testLandingPagePreservesNonStandardPort(): void
    {
        $url = TrackersController::buildDirectUrl(self::BASE, 555, [
            'rotator_id'        => 0,
            'landing_page_id'   => 12,
            'landing_page_url'  => 'http://localhost:8000/lander',
        ]);

        $this->assertSame('http://localhost:8000/lander?t202id=555', $url);
    }

    public function testBaseUrlTrailingSlashDoesNotDoubleUp(): void
    {
        $url = TrackersController::buildDirectUrl(self::BASE . '/', 555, [
            'rotator_id'      => 0,
            'landing_page_id' => 0,
        ]);

        $this->assertSame(self::BASE . '/tracking202/redirect/dl.php?t202id=555', $url);
    }
}
