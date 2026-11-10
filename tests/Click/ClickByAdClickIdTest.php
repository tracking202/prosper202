<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickByAdClickId;
use Prosper202\Click\ClickRecord;

/**
 * Which click the ad click ids of a postback name: one, or none. Each case
 * that names none is built so that only the rule it is about refuses it.
 */
final class ClickByAdClickIdTest extends TestCase
{
    public function testEveryAdClickIdAClickKeepsIsReadByItsOwnName(): void
    {
        $request = [];
        foreach (array_keys(ClickRecord::AD_CLICK_IDS) as $name) {
            $request[$name] = "id-of-$name";
        }
        $sent = ClickByAdClickId::sent($request + ['subid' => '[[subid]]', 'amount' => '5', 'clickid' => 'x']);

        self::assertSame($request, $sent['ids']);
        self::assertNull($sent['unreadable']);
    }

    public function testAnAbsentBlankOrUnfilledIdSendsNone(): void
    {
        $sent = ClickByAdClickId::sent(
            ['gclid' => '', 'gbraid' => '  ', 'wbraid' => '{aff_sub3}', 'fbclid' => ' Fb.1 ']
        );

        self::assertSame(['fbclid' => 'Fb.1'], $sent['ids'], 'trimmed, case kept');
        self::assertNull($sent['unreadable']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function unreadable(): iterable
    {
        yield 'a list' => [['abc']];
        yield 'a number' => [12];
        yield 'bytes that are not text' => ["ab\xC3\x28"];
        yield 'a control character' => ["ab\x00cd"];
    }

    /** @dataProvider unreadable */
    public function testAValueThatIsNotAnIdIsNamedNotSkipped(mixed $value): void
    {
        $sent = ClickByAdClickId::sent(['gclid' => 'good', 'msclkid' => $value]);

        self::assertSame('msclkid', $sent['unreadable']);
    }

    public function testOneClickCarryingTheIdIsTheClick(): void
    {
        $found = ClickByAdClickId::decide(['gclid' => [41]]);

        self::assertSame(
            ['status' => ClickByAdClickId::ONE, 'click_id' => 41, 'matched' => ['gclid'], 'why' => ''],
            $found
        );
    }

    public function testTwoIdsOfOneClickAreThatClick(): void
    {
        $found = ClickByAdClickId::decide(['gclid' => [41], 'fbclid' => [41]]);

        self::assertSame(ClickByAdClickId::ONE, $found['status']);
        self::assertSame(41, $found['click_id']);
        self::assertSame(['gclid', 'fbclid'], $found['matched']);
    }

    public function testAnIdNoClickCarriesIsLeftOut(): void
    {
        $found = ClickByAdClickId::decide(['gclid' => [], 'msclkid' => [41]]);

        self::assertSame(ClickByAdClickId::ONE, $found['status']);
        self::assertSame(['msclkid'], $found['matched']);
    }

    public function testNoClickIsNone(): void
    {
        self::assertSame(ClickByAdClickId::NONE, ClickByAdClickId::decide(['gclid' => [], 'fbclid' => []])['status']);
        self::assertSame(ClickByAdClickId::NONE, ClickByAdClickId::decide([])['status']);
    }

    public function testTwoClicksCarryingOneIdAreNobodysToChoose(): void
    {
        // One id only, so the ids cannot disagree with each other.
        $found = ClickByAdClickId::decide(['gclid' => [41, 42]]);

        self::assertSame(ClickByAdClickId::AMBIGUOUS, $found['status']);
        self::assertNull($found['click_id']);
        self::assertStringContainsString('More than one click carries the gclid', $found['why']);
    }

    public function testIdsNamingDifferentClicksNameNone(): void
    {
        // Each id has exactly one click, so neither is ambiguous alone.
        $found = ClickByAdClickId::decide(['gclid' => [41], 'fbclid' => [42]]);

        self::assertSame(ClickByAdClickId::AMBIGUOUS, $found['status']);
        self::assertNull($found['click_id']);
        self::assertStringContainsString('name different clicks', $found['why']);
    }

    public function testMoreClicksThanCanBeToldApartNameNone(): void
    {
        $found = ClickByAdClickId::decide(['gclid' => null]);

        self::assertSame(ClickByAdClickId::AMBIGUOUS, $found['status']);
        self::assertStringContainsString('Too many clicks', $found['why']);
    }

    public function testAConversionFoundThisWayIsOneItsRequestNamedAndHasItsOwnLabel(): void
    {
        $method = \Prosper202\Conversion\Ledger\MatchMethod::AD_CLICK_ID;

        self::assertSame('ad_click_id', $method->value);
        self::assertLessThanOrEqual(16, strlen($method->value), 'match_method is varchar(16)');
        self::assertSame('Ad click id', $method->label());
        self::assertNull(\Prosper202\OfflineConversion\LeftOut::forMatch($method), 'the offline files send it');
    }

    public function testNoAnswerCarriesAnIdsValue(): void
    {
        foreach ([['gclid' => [41, 42]], ['gclid' => [41], 'fbclid' => [42]], ['gclid' => null]] as $clicks) {
            self::assertDoesNotMatchRegularExpression('/4[12]/', ClickByAdClickId::decide($clicks)['why']);
        }
    }
}
