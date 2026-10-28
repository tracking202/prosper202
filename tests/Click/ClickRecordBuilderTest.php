<?php

declare(strict_types=1);

namespace Tests\Click;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\ClickRecordBuilder;
use Prosper202\Click\RotatorRoute;

/**
 * The builder reads the click scripts' $mysql values strictly: an absent or
 * empty value is its column's default, and anything else that is not what
 * the column holds is refused naming the key. The cast it replaced stored
 * '12abc' as 12 and 'abc' as 0.
 */
final class ClickRecordBuilderTest extends TestCase
{
    /**
     * $mysql key => [ClickRecord property, value given, value expected]. No
     * two ids share a value, so a field read from another's key is seen; the
     * flags, which can only be 0 or 1, are each set alone below.
     */
    private const FIELDS = [
        'user_id' => ['userId', '42', 42],
        'click_time' => ['clickTime', '1709251200', 1709251200],
        'aff_campaign_id' => ['affCampaignId', '101', 101],
        'landing_page_id' => ['landingPageId', '102', 102],
        'ppc_account_id' => ['ppcAccountId', '103', 103],
        'click_cpc' => ['clickCpc', '1.50000', '1.50000'],
        'click_payout' => ['clickPayout', '3.00', '3.00'],
        'click_filtered' => ['clickFiltered', '1', 1],
        'click_bot' => ['clickBot', '0', 0],
        'click_alp' => ['clickAlp', 1, 1],
        'text_ad_id' => ['textAdId', '110', 110],
        'keyword_id' => ['keywordId', '111', 111],
        'ip_id' => ['ipId', '112', 112],
        'country_id' => ['countryId', '113', 113],
        'region_id' => ['regionId', '114', 114],
        'isp_id' => ['ispId', '115', 115],
        'city_id' => ['cityId', '116', 116],
        'platform_id' => ['platformId', '117', 117],
        'browser_id' => ['browserId', '118', 118],
        'device_id' => ['deviceId', '119', 119],
        'c1_id' => ['c1Id', '121', 121],
        'c2_id' => ['c2Id', '122', 122],
        'c3_id' => ['c3Id', '123', 123],
        'c4_id' => ['c4Id', '124', 124],
        'variable_set_id' => ['variableSetId', '130', 130],
        'gclid' => ['gclid', 'CjwKCAtest', 'CjwKCAtest'],
        'gbraid' => ['gbraid', '0AAAAAgbraid', '0AAAAAgbraid'],
        'wbraid' => ['wbraid', 'Cj4KCAwbraid', 'Cj4KCAwbraid'],
        'ttclid' => ['ttclid', 'E.C.P.ttclid', 'E.C.P.ttclid'],
        'utm_source_id' => ['utmSourceId', '131', 131],
        'utm_medium_id' => ['utmMediumId', '132', 132],
        'utm_campaign_id' => ['utmCampaignId', '133', 133],
        'utm_term_id' => ['utmTermId', '134', 134],
        'utm_content_id' => ['utmContentId', '135', 135],
        'click_id_public' => ['clickIdPublic', '31429', '31429'],
        'click_cloaking' => ['clickCloaking', -1, -1],
        'click_in' => ['clickIn', 0, 0],
        'click_out' => ['clickOut', 1, 1],
        'click_referer_site_url_id' => ['clickRefererSiteUrlId', '141', 141],
        'click_landing_site_url_id' => ['clickLandingSiteUrlId', '142', 142],
        'click_outbound_site_url_id' => ['clickOutboundSiteUrlId', '143', 143],
        'click_cloaking_site_url_id' => ['clickCloakingSiteUrlId', '144', 144],
        'click_redirect_site_url_id' => ['clickRedirectSiteUrlId', '145', 145],
    ];

    private const FLAGS = [
        'click_filtered' => 'clickFiltered', 'click_bot' => 'clickBot', 'click_alp' => 'clickAlp',
        'click_in' => 'clickIn', 'click_out' => 'clickOut',
    ];

    /** @return array<string, mixed> */
    private static function given(): array
    {
        return array_map(static fn (array $f): mixed => $f[1], self::FIELDS);
    }

    public function testEveryFieldIsRead(): void
    {
        $click = ClickRecordBuilder::fromLegacyArray(self::given(), 9001);

        self::assertSame(9001, $click->clickId);
        foreach (self::FIELDS as $key => [$property, , $expected]) {
            self::assertSame($expected, $click->$property, $key);
        }
        self::assertTrue($click->recordsCampaignRows);
        self::assertNull($click->rotator);
        self::assertNull($click->identity);
    }

    /** Every key the builder reads is one FIELDS lists, so the test above covers each. */
    public function testTheListCoversEveryProperty(): void
    {
        $properties = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass(ClickRecord::class))->getProperties()
        );
        $listed = array_column(self::FIELDS, 0);
        self::assertSame(
            ['clickId', 'recordsCampaignRows', 'rotator', 'identity'],
            array_values(array_diff($properties, $listed))
        );
    }

    /** Each flag set alone: only its own property is 1. */
    public function testEachFlagIsReadFromItsOwnKey(): void
    {
        foreach (self::FLAGS as $key => $property) {
            $mysql = array_merge(self::given(), array_fill_keys(array_keys(self::FLAGS), '0'), [$key => '1']);
            $click = ClickRecordBuilder::fromLegacyArray($mysql, 1);
            foreach (self::FLAGS as $other) {
                self::assertSame($other === $property ? 1 : 0, $click->$other, "$key set alone: $other");
            }
        }
    }

    public function testAnAbsentOrEmptyValueIsItsColumnsDefault(): void
    {
        $required = ['user_id' => '42', 'click_time' => '1709251200'];
        $keys = array_keys(self::FIELDS);
        foreach ([[], array_fill_keys($keys, ''), array_fill_keys($keys, null)] as $i => $mysql) {
            $click = ClickRecordBuilder::fromLegacyArray(array_merge($mysql, $required), 1);
            self::assertSame(0, $click->affCampaignId, "case $i");
            self::assertSame('0', $click->clickCpc, "case $i");
            self::assertSame('0', $click->clickPayout, "case $i");
            self::assertSame('', $click->gclid, "case $i");
            self::assertSame(['', '', ''], [$click->gbraid, $click->wbraid, $click->ttclid], "case $i: none is ''");
            self::assertSame(1, $click->clickIn, "case $i: a click is in unless it says otherwise");
            self::assertSame(0, $click->clickOut, "case $i");
            self::assertSame(0, $click->clickRedirectSiteUrlId, "case $i");
        }
    }

    /**
     * dl.php writes '' for an uncloaked click's public id: under a strict
     * sql_mode the BIGINT UNSIGNED column refused it and the click was lost.
     */
    public function testAnEmptyPublicIdIsStoredAsZero(): void
    {
        $click = ClickRecordBuilder::fromLegacyArray(['click_id_public' => ''] + self::given(), 1);

        self::assertSame('0', $click->clickIdPublic);
    }

    /** Past a 64-bit PHP's int range, as a 32-bit one passes it at tens of millions of clicks. */
    public function testAPublicIdStaysWhole(): void
    {
        $click = ClickRecordBuilder::fromLegacyArray(['click_id_public' => '18446744073709551615'] + self::given(), 1);

        self::assertSame('18446744073709551615', $click->clickIdPublic);
    }

    /** A gclid as 202_google.gclid, a VARCHAR(150), keeps it: characters, not bytes. */
    public function testAGclidIsCutToItsColumn(): void
    {
        $long = str_repeat('é', 149) . 'xyz';
        $click = ClickRecordBuilder::fromLegacyArray(['gclid' => $long] + self::given(), 1);

        self::assertSame(str_repeat('é', 149) . 'x', $click->gclid);
        self::assertSame(ClickRecord::storedGclid($long), $click->gclid);
    }

    /**
     * gbraid and wbraid are cut at 150 characters, as the gclid; a ttclid at
     * 1,000, since the real ones run to 340 and the gclid's 150 would have
     * cut every one. Characters, not bytes, as each column counts them.
     */
    public function testEachAdClickIdIsCutToItsColumn(): void
    {
        $widths = ['gbraid' => 150, 'wbraid' => 150, 'ttclid' => 1000];
        foreach ($widths as $key => $width) {
            $long = str_repeat('é', $width - 1) . 'xyz';
            $click = ClickRecordBuilder::fromLegacyArray([$key => $long] + self::given(), 1);

            self::assertSame($width, ClickRecord::AD_CLICK_IDS[$key], $key);
            self::assertSame(str_repeat('é', $width - 1) . 'x', $click->$key, $key);
            self::assertSame(ClickRecord::storedAdClickId($key, $long), $click->$key, $key);
        }
        // A real ttclid, measured at 337 characters, is kept whole.
        $ttclid = 'E.C.P.' . str_repeat('Cs0BrH-fsgVGkAOH', 20) . 'wv7TIPFQW9W';
        self::assertSame(337, strlen($ttclid));
        $click = ClickRecordBuilder::fromLegacyArray(['ttclid' => $ttclid] + self::given(), 1);
        self::assertSame($ttclid, $click->ttclid);
    }

    /** What a URL's tokens send for a click about to be stored: each id as its column keeps it. */
    public function testStoredAdClickIdsAreEveryIdCut(): void
    {
        $stored = ClickRecord::storedAdClickIds(['gclid' => str_repeat('g', 200), 'ttclid' => 'E.C.P.x']);

        self::assertSame(['gclid', 'gbraid', 'wbraid', 'ttclid'], array_keys($stored));
        self::assertSame([str_repeat('g', 150), '', '', 'E.C.P.x'], array_values($stored));
    }

    public function testANameThatIsNoAdClickIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('msclkid is not an ad click id a click keeps');

        ClickRecord::storedAdClickId('msclkid', 'x');
    }

    /** One the builder did not cut, past its column, is refused by ClickRecord itself, naming it. */
    public function testAnAdClickIdPastItsColumnIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("A click's ttclid is at most 1000 characters");

        new ClickRecord(clickId: 1, userId: 1, clickTime: 1, ttclid: str_repeat('t', 1001));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function refusedValues(): iterable
    {
        foreach (['user_id', 'keyword_id', 'click_redirect_site_url_id', 'click_cloaking'] as $key) {
            yield "$key '12abc'" => [$key, '12abc'];
            yield "$key 'abc'" => [$key, 'abc'];
            yield "$key ' 42'" => [$key, ' 42'];
            yield "$key '007'" => [$key, '007'];
            yield "$key '+1'" => [$key, '+1'];
            yield "$key '1.5'" => [$key, '1.5'];
            yield "$key past PHP_INT_MAX" => [$key, '9223372036854775808'];
            yield "$key a float" => [$key, 1.0];
            yield "$key a bool" => [$key, true];
            yield "$key a list" => [$key, ['1']];
        }
        foreach (['click_cpc', 'click_payout'] as $key) {
            yield "$key 'abc'" => [$key, 'abc'];
            yield "$key '1e3'" => [$key, '1e3'];
            yield "$key '1,5'" => [$key, '1,5'];
            yield "$key ' 1.5'" => [$key, ' 1.5'];
            yield "$key '.5'" => [$key, '.5'];
            yield "$key a float" => [$key, 1.5];
            yield "$key a list" => [$key, []];
        }
        yield "click_id_public '-1'" => ['click_id_public', '-1'];
        yield "click_id_public 'x1'" => ['click_id_public', 'x1'];
        yield 'click_id_public negative' => ['click_id_public', -1];
        yield 'click_id_public a list' => ['click_id_public', ['1']];
        foreach (['gclid', 'gbraid', 'wbraid', 'ttclid'] as $key) {
            yield "$key a list" => [$key, ['abc']];
            yield "$key a number" => [$key, 5];
        }
    }

    /** @dataProvider refusedValues */
    public function testAValueItsColumnCannotHoldIsRefusedNamingItsKey(string $key, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The click's $key is ");

        ClickRecordBuilder::fromLegacyArray([$key => $value] + self::given(), 1);
    }

    /** @return iterable<string, array{array<string, mixed>, int, string}> */
    public static function refusedRecords(): iterable
    {
        yield 'no user' => [['user_id' => ''], 1, 'userId'];
        yield 'no time' => [['click_time' => null], 1, 'clickTime'];
        yield 'no click id' => [[], 0, 'clickId'];
        yield 'a negative id' => [['keyword_id' => '-5'], 1, 'keywordId'];
        yield 'a flag of 2' => [['click_filtered' => '2'], 1, 'clickFiltered'];
        yield 'a cloaking of 2' => [['click_cloaking' => 2], 1, 'clickCloaking'];
    }

    /**
     * What parses and is still not a click is refused by ClickRecord itself.
     *
     * @param array<string, mixed> $mysql
     * @dataProvider refusedRecords
     */
    public function testAValueOutsideItsColumnsRangeIsRefused(array $mysql, int $clickId, string $field): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("A click's $field ");

        ClickRecordBuilder::fromLegacyArray($mysql + self::given(), $clickId);
    }

    public function testARedirectorClickHasItsRouteAndNoCampaignRows(): void
    {
        $mysql = ['rotator_id' => '7', 'rule_id' => '8', 'rule_redirect_id' => '9'] + self::given();
        $click = ClickRecordBuilder::forRedirector($mysql, 9002);

        self::assertEquals(new RotatorRoute(7, 8, 9), $click->rotator);
        self::assertFalse($click->recordsCampaignRows);
        self::assertSame(
            [0, '', '', '', '', 0, 0, 0, 0, 0],
            [
                $click->variableSetId, $click->gclid, $click->gbraid, $click->wbraid, $click->ttclid,
                $click->utmSourceId, $click->utmMediumId, $click->utmCampaignId, $click->utmTermId,
                $click->utmContentId,
            ],
            'the redirector records no ad click ids, UTM values or tracking variables'
        );
        self::assertSame(42, $click->userId);
        self::assertSame(111, $click->keywordId);
    }

    /** The rotator's default has no rule and no redirect. */
    public function testARedirectorDefaultHasRuleAndRedirectZero(): void
    {
        $route = ['rotator_id' => '7', 'rule_id' => '0', 'rule_redirect_id' => '0'];
        $click = ClickRecordBuilder::forRedirector($route + self::given(), 1);

        self::assertEquals(new RotatorRoute(7, 0, 0), $click->rotator);
    }

    public function testARedirectorClickWithNoRotatorIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("A redirector click's rotator is a positive id, not 0");

        ClickRecordBuilder::forRedirector(['rule_id' => '8', 'rule_redirect_id' => '9'] + self::given(), 1);
    }

    /** @return iterable<string, array{string}> */
    public static function adClickIdNames(): iterable
    {
        foreach (array_keys(ClickRecord::AD_CLICK_IDS) as $name) {
            yield $name => [$name];
        }
    }

    /** @dataProvider adClickIdNames */
    public function testARedirectorClickGivenCampaignRowsIsRefused(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('records no gclid, gbraid, wbraid, ttclid');

        new ClickRecord(
            ...['clickId' => 1, 'userId' => 1, 'clickTime' => 1, 'recordsCampaignRows' => false, $name => 'abc']
        );
    }
}
