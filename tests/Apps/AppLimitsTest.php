<?php

declare(strict_types=1);

namespace Tests\Apps;

use Api\V3\Apps\Android\ClickToInstallTime;
use Api\V3\Apps\AppLimits;
use Tests\TestCase;

/**
 * The abuse limits a registration row carries (plan §7.1): read strictly,
 * and a value that does not read resolves to the reading that trusts least
 * and is named — never to the defaults (CLAUDE.md #11).
 */
final class AppLimitsTest extends TestCase
{
    private const ROW = [
        'ctit_min_seconds' => 10, 'ctit_max_seconds' => 86400, 'install_cap_per_minute' => 300, 'event_cap_per_minute' => 200,
    ];

    public function testAStoredRowReadsAsWritten(): void
    {
        $limits = AppLimits::fromRow(self::ROW);
        self::assertSame([10, 86400, 300, 200, []], [$limits->ctitMinSeconds, $limits->ctitMaxSeconds, $limits->installCapPerMinute, $limits->eventCapPerMinute, $limits->unreadable]);
        // mysqli without native types hands back digit strings.
        $strings = AppLimits::fromRow(array_map('strval', self::ROW));
        self::assertSame([], $strings->unreadable);
        self::assertSame(300, $strings->installCapPerMinute);
        $prefixed = AppLimits::fromRow(['reg_ctit_min_seconds' => 1, 'reg_ctit_max_seconds' => 60, 'reg_install_cap_per_minute' => 1, 'reg_event_cap_per_minute' => 100], 'reg_');
        self::assertSame([], $prefixed->unreadable);
    }

    /** @return iterable<string, array{mixed}> */
    public static function unreadableValues(): iterable
    {
        yield 'missing' => [null];
        yield 'below range' => [0];
        yield 'above range' => [60001];
        yield 'leading zero' => ['0300'];
        yield 'float' => [300.0];
        yield 'exponent' => ['3e2'];
        yield 'bool' => [true];
        yield 'blank' => [''];
        yield 'negative' => ['-5'];
    }

    /** @dataProvider unreadableValues */
    public function testAnUnreadableCapIsNullAndNamed(mixed $value): void
    {
        $row = self::ROW;
        if ($value === null) {
            unset($row['install_cap_per_minute']);
        } else {
            $row['install_cap_per_minute'] = $value;
        }
        $limits = AppLimits::fromRow($row);
        self::assertNull($limits->installCapPerMinute, 'the intake answers a null cap with a 503 naming it');
        self::assertSame(['install_cap_per_minute'], $limits->unreadable);
        self::assertSame(200, $limits->eventCapPerMinute, 'the other columns still read');
    }

    public function testNoRowIsEveryColumnUnreadableAndFlagsEveryMeasuredInstall(): void
    {
        foreach ([AppLimits::unreadable(), AppLimits::fromRow('not a row'), AppLimits::fromRow([])] as $limits) {
            self::assertSame(array_keys(AppLimits::RANGES), $limits->unreadable);
            self::assertNull($limits->installCapPerMinute);
            self::assertNull($limits->eventCapPerMinute);
            foreach ([-5, 0, 10, 61, 3599, 3600, 86400, 10_000_000] as $ctit) {
                self::assertNotSame(ClickToInstallTime::OK, ClickToInstallTime::flag($ctit, $limits), 'CTIT ' . $ctit . ' is flagged under unreadable thresholds');
            }
        }
    }

    public function testTheDefaultsAreTheColumnDefaults(): void
    {
        $sql = \Prosper202\Database\Tables\AppTables::appRegistrations()->createStatement;
        $d = AppLimits::defaults();
        foreach ([
            'ctit_min_seconds' => $d->ctitMinSeconds, 'ctit_max_seconds' => $d->ctitMaxSeconds,
            'install_cap_per_minute' => $d->installCapPerMinute, 'event_cap_per_minute' => $d->eventCapPerMinute,
        ] as $column => $value) {
            self::assertMatchesRegularExpression('/`' . $column . "` [a-z]+\\(\\d+\\) unsigned NOT NULL DEFAULT '" . $value . "'/", $sql);
            self::assertNotNull(AppLimits::read($value, $column), $column . ' default is inside its range');
        }
        self::assertGreaterThanOrEqual(
            \Api\V3\Apps\Android\InstallEventsIntake::MAX_EVENTS,
            AppLimits::RANGES['event_cap_per_minute'][0],
            'a full batch always fits the event cap'
        );
    }
}
