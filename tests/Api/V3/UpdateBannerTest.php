<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * The update banner under the header (202-config/functions-update-banner.php),
 * rendered in every state it has.
 *
 * It was Bootstrap 3 panels loaded by the classic shell's custom.php, so no
 * v2 page drew it at all until U8 moved it to the component layer. What can
 * go wrong without a browser noticing is pinned here: a state that renders
 * nothing when it should say something, the kit's flash losing its parts
 * (error pattern #19), and the release feed's text reaching the page as
 * markup. tests/browser/specs/update-banner.spec.js drives the drawing,
 * the dismissal and the snooze in a browser.
 */
final class UpdateBannerTest extends TestCase
{
    private const BASE = 'https://example.test/p202/';

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3) . '/202-config/functions-update-banner.php';
    }

    public function testNothingIsDrawnWhenHiddenOrUpToDate(): void
    {
        self::assertSame('', p202_update_banner([], self::BASE), 'no state: nothing');
        self::assertSame('', p202_update_banner(['show' => true], self::BASE), 'up to date: nothing');
        self::assertSame('', p202_update_banner(['show' => true, 'managed' => true], self::BASE), 'a managed install that is up to date: nothing');
        self::assertSame('', p202_update_banner(['show' => false, 'update_needed' => true], self::BASE), 'snoozed: nothing, though an update is out');
    }

    /**
     * @return array<string, array{array<string, mixed>, list<string>, list<string>}>
     */
    public static function states(): array
    {
        return [
            'an update, 1-click possible' => [
                ['update_needed' => true, 'premium' => true],
                ['A new version of Prosper202 is available.', 'href="https://example.test/p202/202-account/auto-upgrade-premium.php">1-Click Upgrade</a>', 'Download the upgrade files'],
                ['Personal Settings'],
            ],
            'an update, 1-click impossible' => [
                ['update_needed' => true, 'not_possible' => true],
                ['writable 202-config/ directory', 'Download the upgrade files'],
                ['1-Click Upgrade</a>'],
            ],
            'a managed deployment' => [
                ['update_needed' => true, 'not_possible' => true, 'managed' => true],
                ['managed deployment built from git', 'redeploy'],
                ['1-Click Upgrade</a>', 'Download the upgrade files'],
            ],
            'a premium release, no customer key' => [
                ['premium' => true, 'premium_details' => ['headline' => 'Prosper202 Pro 2.0', 'body' => 'Faster reports.', 'release-date' => '9/1/2026', 'register-link' => 'https://my.tracking202.com/register', 'register-button-text' => 'Get Started']],
                ['Prosper202 Pro 2.0', 'Faster reports. Released 9/1/2026.', 'Customer API key in Personal Settings', 'href="https://my.tracking202.com/register" target="_blank" rel="noopener">Get Started</a>', 'href="https://example.test/p202/202-account/account.php">Personal Settings</a>'],
                ['auto-upgrade-premium.php'],
            ],
            'a premium release, key on file' => [
                ['premium' => true, 'has_customer_key' => true, 'premium_details' => ['headline' => 'Prosper202 Pro 2.0', 'order-button-text' => 'Upgrade Now', 'upgrade-price' => '0.00']],
                ['Prosper202 Pro 2.0', 'auto-upgrade-premium.php">Upgrade Now ($0.00)</a>'],
                ['Personal Settings'],
            ],
        ];
    }

    /**
     * @dataProvider states
     * @param array<string, mixed> $state
     * @param list<string> $says
     * @param list<string> $doesNotSay
     */
    public function testEachStateSaysWhatToDo(array $state, array $says, array $doesNotSay): void
    {
        $html = p202_update_banner(['show' => true] + $state, self::BASE);
        self::assertNotSame('', $html);
        foreach ($says as $text) {
            self::assertStringContainsString($text, $html);
        }
        foreach ($doesNotSay as $text) {
            self::assertStringNotContainsString($text, $html);
        }
        // The kit's dismissible flash, parts included: the icon, the body the
        // text lives in, and Bootstrap 5's own close button.
        self::assertMatchesRegularExpression('~^<div class="alert alert-warning p202-flash alert-dismissible" role="status" data-p202-update-banner><i class="bi bi-arrow-up-circle"></i><div class="p202-flash__body"><strong>[^<]+</strong>~', $html);
        self::assertStringEndsWith('</div><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Hide for an hour"></button></div>', $html);
    }

    public function testTheReleaseFeedReachesThePageAsText(): void
    {
        $html = p202_update_banner(['show' => true, 'premium' => true, 'premium_details' => [
            'headline' => '<script>alert(1)</script>New',
            'body' => '<b onmouseover="x()">Bold</b>',
            'register-link' => 'javascript:alert(1)',
            'register-button-text' => '<img src=x>',
            'order-button-text' => '"><svg onload=x>',
            'upgrade-price' => '1; DROP',
        ]], self::BASE);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<b ', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;New', $html);
        self::assertStringNotContainsString('javascript:', $html, 'a register link that is not http(s) is dropped, not linked');
        self::assertStringNotContainsString('<img', $html);

        $keyed = p202_update_banner(['show' => true, 'premium' => true, 'has_customer_key' => true, 'premium_details' => [
            'order-button-text' => '"><svg onload=x>',
            'upgrade-price' => '1; DROP',
        ]], self::BASE);
        self::assertStringNotContainsString('<svg', $keyed);
        self::assertStringNotContainsString('DROP', $keyed, 'a price that is not a number is left off');
    }
}
