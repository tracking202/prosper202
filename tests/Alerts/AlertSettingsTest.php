<?php

declare(strict_types=1);

namespace Tests\Alerts;

use PHPUnit\Framework\TestCase;
use Prosper202\Alerts\AlertSettings;
use Prosper202\Alerts\AlertText;

/**
 * The money-loss alert settings' one rule (AlertSettings::fromInput()), which
 * the Personal settings card asks: off by default, every value held to its
 * range, and a malformed one refused by name rather than read as a default.
 */
final class AlertSettingsTest extends TestCase
{
    /** @return array<string, string> */
    private static function valid(): array
    {
        return ['enabled' => '1', 'notify_slack' => '1', 'notify_email' => '0', 'notify_webhook' => '1',
            'gap_multiple' => '6', 'min_conversions' => '25', 'daily_cost_cap' => '150.5'];
    }

    public function testTheDefaultsAreOff(): void
    {
        $defaults = AlertSettings::defaults();

        self::assertFalse($defaults->enabled);
        self::assertFalse($defaults->hasCostCap());
        self::assertSame(5, $defaults->gapMultiple);
        self::assertSame(10, $defaults->minConversions);
    }

    public function testAValidFormIsReadAsSent(): void
    {
        [$settings, $errors] = AlertSettings::fromInput(self::valid());

        self::assertSame([], $errors);
        self::assertNotNull($settings);
        self::assertSame([
            'enabled' => true, 'notify_slack' => true, 'notify_email' => false, 'notify_webhook' => true,
            'gap_multiple' => 6, 'min_conversions' => 25, 'daily_cost_cap' => '150.50',
        ], $settings->toArray());
        self::assertTrue($settings->hasCostCap());
    }

    public function testAnEmptyCapIsNoCap(): void
    {
        [$settings] = AlertSettings::fromInput(['daily_cost_cap' => ' '] + self::valid());

        self::assertNotNull($settings);
        self::assertSame('0.00', $settings->dailyCostCap);
        self::assertFalse($settings->hasCostCap());
    }

    /** @return iterable<string, array{string, mixed, string}> */
    public static function refused(): iterable
    {
        yield 'a multiple of 1' => ['gap_multiple', '1', 'from 2 to 20'];
        yield 'a multiple of 21' => ['gap_multiple', '21', 'from 2 to 20'];
        yield 'a fractional multiple' => ['gap_multiple', '2.5', 'whole number'];
        yield 'a list for the multiple' => ['gap_multiple', ['5'], 'whole number'];
        yield 'two conversions' => ['min_conversions', '2', 'from 3 to 1000'];
        yield 'a negative cap' => ['daily_cost_cap', '-5', 'with at most two decimals'];
        yield 'a cap of three decimals' => ['daily_cost_cap', '1.005', 'with at most two decimals'];
        yield 'a cap in words' => ['daily_cost_cap', 'ten', 'with at most two decimals'];
        yield 'a switch that is not 0 or 1' => ['enabled', 'yes', 'on or off'];
        yield 'a missing switch' => ['notify_slack', null, 'on or off'];
    }

    /** @dataProvider refused */
    public function testAMalformedValueIsRefusedByName(string $field, mixed $value, string $why): void
    {
        $input = self::valid();
        if ($value === null) {
            unset($input[$field]);
        } else {
            $input[$field] = $value;
        }

        [$settings, $errors] = AlertSettings::fromInput($input);

        self::assertNull($settings);
        self::assertArrayHasKey($field, $errors);
        self::assertStringContainsString($why, $errors[$field]);
    }

    public function testAlertsOnWithNowhereToGoAreRefused(): void
    {
        [$settings, $errors] = AlertSettings::fromInput(
            ['notify_slack' => '0', 'notify_email' => '0', 'notify_webhook' => '0'] + self::valid()
        );

        self::assertNull($settings);
        self::assertStringContainsString('choose where they go', $errors['notify_email']);

        [$off] = AlertSettings::fromInput(
            ['enabled' => '0', 'notify_slack' => '0', 'notify_email' => '0', 'notify_webhook' => '0'] + self::valid()
        );
        self::assertNotNull($off, 'alerts off need no channel');
    }

    /** @return iterable<string, array{int, string}> */
    public static function durations(): iterable
    {
        yield 'under a minute' => [20, '1 min'];
        yield 'minutes' => [45 * 60, '45 min'];
        yield 'whole hours' => [3 * 3600, '3 h'];
        yield 'hours and minutes' => [11 * 3600 + 20 * 60, '11 h 20 min'];
        yield 'days' => [50 * 3600, '2 days 2 h'];
    }

    /** @dataProvider durations */
    public function testDurationsAreWrittenForPeople(int $seconds, string $expected): void
    {
        self::assertSame($expected, AlertText::duration($seconds));
    }
}
