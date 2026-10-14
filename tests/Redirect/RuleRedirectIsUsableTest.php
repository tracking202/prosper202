<?php

/**
 * Which of a rotator rule's redirects can take a click, read the same way by
 * rtr.php and offrtr.php. Their joins are tied to the account, so a campaign
 * or landing page of another account comes back as none, as a deleted one
 * does: such a redirect names nothing, and a rule left with none passes the
 * click on to a later rule or the rotator's default
 * (tests/live/offer-rotator-routing.sh drives both entry points).
 */

declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;

final class RuleRedirectIsUsableTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!function_exists('p202RuleRedirectIsUsable')) {
            require_once __DIR__ . '/../Config/fixtures/lift-function.php';
            eval(lift_function(__DIR__ . '/../../202-config/connect2.php', 'p202RuleRedirectIsUsable'));
        }
    }

    /** @return array<string, array{array<string, mixed>, bool}> */
    public static function redirects(): array
    {
        $none = ['redirect_campaign' => null, 'aff_campaign_id' => null, 'redirect_lp' => null,
            'landing_page_id' => null, 'redirect_url' => null, 'auto_monetizer' => null];

        return [
            'the account\'s campaign' => [['redirect_campaign' => '7', 'aff_campaign_id' => '7'] + $none, true],
            'a campaign the join did not find' => [['redirect_campaign' => '7'] + $none, false],
            'the account\'s landing page' => [['redirect_lp' => '3', 'landing_page_id' => '3'] + $none, true],
            'a landing page the join did not find' => [['redirect_lp' => '3'] + $none, false],
            'a URL' => [['redirect_url' => 'https://offer.example/'] + $none, true],
            'the monetizer' => [['auto_monetizer' => '1'] + $none, true],
            'a rule with no redirect (the LEFT JOIN\'s row)' => [$none, false],
        ];
    }

    /**
     * @dataProvider redirects
     * @param array<string, mixed> $redirect
     */
    public function testARedirectNamingNothingOfTheAccountsTakesNoClick(array $redirect, bool $usable): void
    {
        self::assertSame($usable, p202RuleRedirectIsUsable($redirect));
    }
}
