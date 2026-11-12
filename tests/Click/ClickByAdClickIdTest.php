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

    /**
     * What a page or a template writes where the id was meant to go: shared by
     * every visitor it was written for, so it would put one visitor's sale on
     * another's click.
     */
    public function testAValueNoPlatformIssuesSendsNone(): void
    {
        $notIds = ['undefined', 'NULL', 'None', 'NaN', 'false', '0', '(not set)', '[object Object]', '__CLICKID__',
            '__GCLID__', '-', '--', '.'];
        foreach ($notIds as $value) {
            self::assertTrue(ClickByAdClickId::notAnId($value), $value);
            self::assertSame([], ClickByAdClickId::sent(['gclid' => $value])['ids'], $value);
            self::assertSame([], ClickByAdClickId::sent(['aff_sub3' => " $value "], ['aff_sub3'])['ids'], $value);
        }
        $ids = ['Cj0KCQiA-demo_Shop', '0123456789abcdef0123456789abcdef', 'IwAR0-demo', 'E.1', 'nullable-1',
            '__x', 'x__'];
        foreach ($ids as $value) {
            self::assertFalse(ClickByAdClickId::notAnId($value), $value);
        }
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

    /**
     * A click as its latest visit has it. Every default is the same, so two
     * clicks differ only in what a test changes.
     *
     * @param array<string, int|bool> $with
     * @return array{click_id: int, user_id: int, campaign_id: int, ppc_account_id: int, click_time: int, lead: bool}
     */
    private static function click(int $id, array $with = []): array
    {
        return $with + [
            'click_id' => $id, 'user_id' => 1, 'campaign_id' => 5, 'ppc_account_id' => 9, 'click_time' => 1000,
            'lead' => false,
        ];
    }

    public function testOneClickCarryingTheIdIsTheClick(): void
    {
        $found = ClickByAdClickId::decide(['gclid' => [self::click(41)]]);

        self::assertSame(
            [
                'status' => ClickByAdClickId::ONE, 'click_id' => 41, 'matched' => ['gclid'], 'why' => '', 'of' => 1,
                'account' => 1,
            ],
            $found
        );
    }

    public function testTwoIdsOfOneClickAreThatClick(): void
    {
        $found = ClickByAdClickId::decide(['gclid' => [self::click(41)], 'fbclid' => [self::click(41)]]);

        self::assertSame(ClickByAdClickId::ONE, $found['status']);
        self::assertSame(41, $found['click_id']);
        self::assertSame(['gclid', 'fbclid'], $found['matched']);
        self::assertSame(1, $found['of']);
    }

    public function testAnIdNoClickCarriesIsLeftOut(): void
    {
        $found = ClickByAdClickId::decide(['gclid' => [], 'msclkid' => [self::click(41)]]);

        self::assertSame(ClickByAdClickId::ONE, $found['status']);
        self::assertSame(['msclkid'], $found['matched']);
    }

    public function testNoClickIsNone(): void
    {
        self::assertSame(ClickByAdClickId::NONE, ClickByAdClickId::decide(['gclid' => [], 'fbclid' => []])['status']);
        self::assertSame(ClickByAdClickId::NONE, ClickByAdClickId::decide([])['status']);
    }

    public function testOneVisitorsClicksOnOneCampaignGiveTheLatest(): void
    {
        // By the latest visit's time, not the id: ids are not in time order.
        $found = ClickByAdClickId::decide(['gclid' => [
            self::click(43, ['click_time' => 1000]),
            self::click(41, ['click_time' => 3000]),
            self::click(42, ['click_time' => 2000]),
        ]]);
        self::assertSame(ClickByAdClickId::ONE, $found['status']);
        self::assertSame(41, $found['click_id']);
        self::assertSame(3, $found['of']);

        // In one second, the greater id.
        $found = ClickByAdClickId::decide(['gclid' => [self::click(41), self::click(44), self::click(42)]]);
        self::assertSame(44, $found['click_id']);

        // Found by two ids, still one visitor's clicks.
        $found = ClickByAdClickId::decide([
            'gclid' => [self::click(41, ['click_time' => 5000])],
            'fbclid' => [self::click(42)],
        ]);
        self::assertSame(41, $found['click_id']);
        self::assertSame(['gclid', 'fbclid'], $found['matched']);
    }

    public function testAClickThatAlreadyHoldsTheSaleIsTakenBeforeALaterOne(): void
    {
        $clicks = ['gclid' => [
            self::click(41, ['click_time' => 1000]),
            self::click(42, ['click_time' => 2000]),
            self::click(43, ['click_time' => 3000]),
        ]];

        self::assertSame(43, ClickByAdClickId::decide($clicks)['click_id']);
        self::assertSame(41, ClickByAdClickId::decide($clicks, [41])['click_id']);
        self::assertSame(42, ClickByAdClickId::decide($clicks, [41, 42])['click_id'], 'the latest of those holding it');
        $held = ClickByAdClickId::decide($clicks, [99])['click_id'];
        self::assertSame(43, $held, 'a click that is not one of them holds nothing');
    }

    /** @return iterable<string, array{string, array<string, int>}> */
    public static function differences(): iterable
    {
        yield 'another account' => ['account', ['user_id' => 2]];
        yield 'another campaign' => ['campaign', ['campaign_id' => 6]];
        yield 'another traffic source account' => ['traffic source account', ['ppc_account_id' => 10]];
    }

    /**
     * @dataProvider differences
     * @param array<string, int> $difference the one thing the second click differs in
     */
    public function testClicksThatDifferInWhatAReportShowsAreNobodysToChoose(string $what, array $difference): void
    {
        // One id, so the ids cannot disagree with each other.
        $found = ClickByAdClickId::decide(['gclid' => [self::click(41), self::click(42, $difference)]]);

        self::assertSame(ClickByAdClickId::AMBIGUOUS, $found['status']);
        self::assertNull($found['click_id']);
        self::assertStringContainsString('are on more than one ' . $what . ',', $found['why']);
        self::assertSame($what === 'account' ? null : 1, $found['account'], 'the one account that can settle it');
    }

    public function testIdsNamingClicksOfDifferentCampaignsNameNone(): void
    {
        // Each id has exactly one click, so neither is ambiguous alone.
        $found = ClickByAdClickId::decide([
            'gclid' => [self::click(41)],
            'fbclid' => [self::click(42, ['campaign_id' => 6])],
        ]);

        self::assertSame(ClickByAdClickId::AMBIGUOUS, $found['status']);
        self::assertNull($found['click_id']);
        self::assertStringContainsString('The clicks carrying the gclid, fbclid sent', $found['why']);
    }

    public function testMoreClicksThanCanBeToldApartNameNone(): void
    {
        $found = ClickByAdClickId::decide(['gclid' => null]);

        self::assertSame(ClickByAdClickId::AMBIGUOUS, $found['status']);
        self::assertStringContainsString('Too many clicks', $found['why']);
        self::assertNull($found['account'], 'no click was read');
    }

    public function testAConversionFoundThisWayIsOneItsRequestNamedAndHasItsOwnLabel(): void
    {
        $method = \Prosper202\Conversion\Ledger\MatchMethod::AD_CLICK_ID;

        self::assertSame('ad_click_id', $method->value);
        self::assertLessThanOrEqual(16, strlen($method->value), 'match_method is varchar(16)');
        self::assertSame('Ad click id', $method->label());
        self::assertNull(\Prosper202\OfflineConversion\LeftOut::forMatch($method), 'the offline files send it');
    }

    public function testNoAnswerCarriesAClicksId(): void
    {
        $cases = [
            ['gclid' => [self::click(41), self::click(42, ['campaign_id' => 6])]],
            ['gclid' => [self::click(41)], 'fbclid' => [self::click(42, ['user_id' => 2])]],
            ['gclid' => null],
        ];
        foreach ($cases as $clicks) {
            self::assertDoesNotMatchRegularExpression('/4[12]/', ClickByAdClickId::decide($clicks)['why']);
        }
    }
}
