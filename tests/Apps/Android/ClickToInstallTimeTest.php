<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use Api\V3\Apps\Android\ClickToInstallTime;
use Api\V3\Apps\AppLimits;
use Tests\TestCase;

final class ClickToInstallTimeTest extends TestCase
{
    public function testItIsGooglesInstallBeginMinusTheClickOrTheReceiptWithoutOne(): void
    {
        self::assertSame(61, ClickToInstallTime::seconds(1061, 5000, 1000));
        self::assertSame(4000, ClickToInstallTime::seconds(null, 5000, 1000));
        self::assertSame(-30, ClickToInstallTime::seconds(970, 5000, 1000), 'within clock skew, stored as it is');
    }

    public function testTheTailsAreStrictlyOutsideTheThresholds(): void
    {
        $limits = AppLimits::fromRow(['ctit_min_seconds' => 10, 'ctit_max_seconds' => 86400, 'install_cap_per_minute' => 300, 'event_cap_per_minute' => 200]);
        self::assertSame(
            ['short', 'short', 'ok', 'ok', 'ok', 'long'],
            array_map(static fn (int $s): string => ClickToInstallTime::flag($s, $limits), [-1, 9, 10, 61, 86400, 86401])
        );
        $zero = AppLimits::fromRow(['ctit_min_seconds' => 0, 'ctit_max_seconds' => 60, 'install_cap_per_minute' => 1, 'event_cap_per_minute' => 100]);
        self::assertSame(['short', 'ok'], [ClickToInstallTime::flag(-1, $zero), ClickToInstallTime::flag(0, $zero)], '0 flags only an install before its click');
    }
}
