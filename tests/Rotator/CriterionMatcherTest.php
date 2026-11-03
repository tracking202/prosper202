<?php

declare(strict_types=1);

namespace Tests\Rotator;

use PHPUnit\Framework\TestCase;
use Prosper202\Rotator\CriterionMatcher;
use Prosper202\Rotator\VisitorFacts;
use Tests\Support\SourceScan;

/**
 * Every rule type the paid 1.9.56 redirector read, against one visitor, as
 * `is` and `is_not`: a rule of a type the redirects dropped kept its row on
 * upgrade and never matched, with nothing said.
 */
final class CriterionMatcherTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private static function visitor(array $overrides = []): VisitorFacts
    {
        $o = $overrides + [
            'ip' => '203.0.113.45',
            'geo' => ['country' => 'Germany', 'country_code' => 'DE', 'region' => 'Berlin', 'city' => 'Berlin', 'is_european_union' => true],
            'isp' => 'Example Telecom',
            'query' => [
                'c1' => 'spring', 'c2' => 'banner a', 'c3' => '', 'utm_source' => 'newsletter', 'utm_medium' => 'email',
                'utm_campaign' => 'launch', 'utm_term' => 'crm software', 'utm_content' => 'hero', 't202kw' => 'crm trial',
            ],
            'server' => ['HTTP_REFERER' => 'https://www.search.example/results?q=x'],
            'filtered' => false,
            'fraud' => false,
            'refererPreference' => null,
        ];

        return VisitorFacts::fromRequest(
            $o['query'],
            $o['server'],
            'bidded',
            $o['refererPreference'],
            $o['ip'],
            $o['geo'],
            $o['isp'],
            'Windows',
            'Desktop',
            'Chrome',
            static fn (): bool => $o['filtered'],
            static fn (): bool => $o['fraud'],
        );
    }

    /** @return iterable<string, array{string, string, bool}> type, value, whether `is` matches the visitor */
    public static function criteria(): iterable
    {
        yield 'country listed' => ['country', 'France(FR), Germany(DE)', true];
        yield 'country not listed' => ['country', 'France(FR)', false];
        yield 'region' => ['region', 'Berlin(DE)', true];
        yield 'city' => ['city', 'Hamburg(DE)', false];
        yield 'isp' => ['isp', 'Example Telecom', true];
        yield 'ip' => ['ip', '203.0.113.45', true];
        yield 'platform' => ['platform', 'Windows', true];
        yield 'browser' => ['browser', 'Firefox', false];
        yield 'device, lower case' => ['device', 'desktop', true];
        yield 'eu true' => ['eu', 'true', true];
        yield 'eu false' => ['eu', 'false', false];
        yield 'eu in any case' => ['eu', 'TRUE', true];
        yield 'fraud false' => ['fraud', 'false', true];
        yield 'fraud true' => ['fraud', 'true', false];
        yield 'visitor unique' => ['visitor', 'unique', true];
        yield 'visitor filtered' => ['visitor', 'filtered', false];
        yield 'iprange CIDR' => ['iprange', '203.0.113.0/24', true];
        yield 'iprange, one of several' => ['iprange', '198.51.100.0/24, 203.0.113.0/25', true];
        yield 'iprange outside' => ['iprange', '198.51.100.0/24', false];
        yield 'iprange wildcard' => ['iprange', '203.0.113.*', true];
        yield 'iprange span' => ['iprange', '203.0.113.40-203.0.113.50', true];
        yield 'c1' => ['c1', 'spring,summer', true];
        yield 'c1 other' => ['c1', 'summer', false];
        yield 'c2 with a space' => ['c2', 'banner a', true];
        yield 't202kw' => ['t202kw', 'crm trial', true];
        yield 'utm_source' => ['utm_source', 'newsletter', true];
        yield 'utm_medium' => ['utm_medium', 'cpc', false];
        yield 'utm_campaign' => ['utm_campaign', 'launch', true];
        yield 'utm_term' => ['utm_term', 'crm software', true];
        yield 'utm_content' => ['utm_content', 'hero', true];
        yield 'utm_* compared exactly' => ['utm_source', 'Newsletter', false];
        yield 'referer pattern' => ['referer', 'search\.example', true];
        yield 'referer in any case' => ['referer', 'SEARCH.EXAMPLE', true];
        yield 'referer, one of several' => ['referer', 'social.example,search.example', true];
        yield 'referer elsewhere' => ['referer', 'social.example', false];
    }

    /** @dataProvider criteria */
    public function testEachTypeMatchesAsIsAndIsNot(string $type, string $value, bool $is): void
    {
        $visitor = self::visitor();
        self::assertSame($is, CriterionMatcher::criterion($type, 'is', $value, $visitor), "$type is $value");
        self::assertSame(!$is, CriterionMatcher::criterion($type, 'is_not', $value, $visitor), "$type is not $value");
    }

    public function testEveryTypeIsOneTheRedirectsRead(): void
    {
        self::assertSame(
            ['country', 'region', 'eu', 'city', 'isp', 'fraud', 'ip', 'iprange', 'browser', 'platform', 'device',
                'visitor', 'c1', 'c2', 'c3', 'c4', 't202kw', 'referer', 'utm_source', 'utm_medium', 'utm_campaign',
                'utm_term', 'utm_content'],
            array_keys(CriterionMatcher::TYPES)
        );
    }

    public function testAllMatchesEveryVisitorWhateverTheType(): void
    {
        foreach (array_keys(CriterionMatcher::TYPES) as $type) {
            self::assertTrue(CriterionMatcher::criterion($type, 'is_not', 'all', self::visitor(['query' => []])), $type);
        }
    }

    public function testAnUnknownTypeNeverMatches(): void
    {
        self::assertFalse(CriterionMatcher::criterion('weekday', 'is', 'Monday', self::visitor()));
        self::assertFalse(CriterionMatcher::criterion('weekday', 'is_not', 'Monday', self::visitor()));
    }

    /** 1.9.56 matched a c1-c4 rule only when the link carried the value, whatever the statement. */
    public function testACVariableRuleNeedsTheValueOnTheLink(): void
    {
        self::assertFalse(CriterionMatcher::criterion('c3', 'is_not', 'spring', self::visitor()));
        self::assertFalse(CriterionMatcher::criterion('c4', 'is_not', 'spring', self::visitor()));
        self::assertTrue(CriterionMatcher::criterion('utm_source', 'is_not', 'newsletter', self::visitor(['query' => []])));
        self::assertTrue(CriterionMatcher::criterion('t202kw', 'is_not', 'crm trial', self::visitor(['query' => []])));
    }

    public function testFilteredAndFraudVisitorsAndTheirChecksAreAskedOnlyWhenNeeded(): void
    {
        $asked = 0;
        $visitor = VisitorFacts::fromRequest([], [], null, null, '203.0.113.45', [], null, '', '', '',
            static function () use (&$asked): bool {
                $asked++;
                return true;
            },
            static function () use (&$asked): bool {
                $asked += 10;
                return true;
            });
        self::assertTrue(CriterionMatcher::criterion('country', 'is_not', 'France(FR)', $visitor));
        self::assertSame(0, $asked, 'a country rule costs no filter check and no IPQS call');
        self::assertTrue(CriterionMatcher::criterion('visitor', 'is', 'filtered', $visitor));
        self::assertTrue(CriterionMatcher::criterion('visitor', 'is_not', 'unique', $visitor));
        self::assertTrue(CriterionMatcher::criterion('fraud', 'is', 'true', $visitor));
        self::assertTrue(CriterionMatcher::criterion('fraud', 'is', 'TRUE', $visitor));
        self::assertSame(11, $asked, 'each is asked once');
    }

    public function testEuReadsGeoIpsUnknown(): void
    {
        $unknown = self::visitor(['geo' => ['is_european_union' => 'Unknown']]);
        self::assertTrue(CriterionMatcher::criterion('eu', 'is', 'Unknown', $unknown));
        self::assertFalse(CriterionMatcher::criterion('eu', 'is', 'true,false', $unknown));
        self::assertTrue(CriterionMatcher::criterion('eu', 'is', 'false', self::visitor(['geo' => ['is_european_union' => false]])));
    }

    public function testTheRefererIsTheT202refTheAccountPrefersThenTheReferringPagesUrl(): void
    {
        $server = ['HTTP_REFERER' => 'https://ads.example/frame?url=https%3A%2F%2Fpublisher.example%2Fpost'];
        $query = ['t202ref' => 'https://partner.example/'];
        $byPage = self::visitor(['server' => $server, 'query' => $query]);
        self::assertSame('https://publisher.example/post', $byPage->referer);
        $byLink = self::visitor(['server' => $server, 'query' => $query, 'refererPreference' => 't202ref']);
        self::assertTrue(CriterionMatcher::criterion('referer', 'is', 'partner\.example', $byLink));
        self::assertSame('', self::visitor(['server' => []])->referer);
        self::assertFalse(CriterionMatcher::criterion('referer', 'is', 'example', self::visitor(['server' => []])));
    }

    public function testARefererValueThatIsNoPatternIsFoundAsText(): void
    {
        $visitor = self::visitor(['server' => ['HTTP_REFERER' => 'https://shop.example/a(b']]);
        self::assertTrue(CriterionMatcher::criterion('referer', 'is', 'a(b', $visitor));
        self::assertTrue(CriterionMatcher::criterion('referer', 'is', 'shop.example/a', $visitor), 'a slash needs no escape');
        self::assertFalse(CriterionMatcher::criterion('referer', 'is', 'x(y', $visitor));
    }

    public function testTheKeywordIsTheAccountsPreference(): void
    {
        $visitor = VisitorFacts::fromRequest(['t202kw' => 't202var_kw', 'kw' => 'crm%20demo'], [], 'bidded', null, '', [], null, '', '', '',
            static fn (): bool => false, static fn (): bool => false);
        self::assertTrue(CriterionMatcher::criterion('t202kw', 'is', 'crm demo', $visitor));
    }

    public function testARuleMatchesWhenEveryCriterionDoes(): void
    {
        $visitor = self::visitor();
        self::assertTrue(CriterionMatcher::rule([], $visitor), 'a rule with no criteria matches, as it did');
        self::assertTrue(CriterionMatcher::rule([
            ['type' => 'utm_source', 'statement' => 'is', 'value' => 'newsletter'],
            ['type' => 'iprange', 'statement' => 'is', 'value' => '203.0.113.0/24'],
        ], $visitor));
        self::assertFalse(CriterionMatcher::rule([
            ['type' => 'utm_source', 'statement' => 'is', 'value' => 'newsletter'],
            ['type' => 'c1', 'statement' => 'is', 'value' => 'summer'],
        ], $visitor));
    }

    public function testNoIspWithoutMaxMindMatchesOnlyIsNot(): void
    {
        $visitor = self::visitor(['isp' => null]);
        self::assertFalse(CriterionMatcher::criterion('isp', 'is', 'Example Telecom', $visitor));
        self::assertTrue(CriterionMatcher::criterion('isp', 'is_not', 'Example Telecom', $visitor));
    }

    /** One matcher: both redirect scripts ask it, and neither keeps a copy of the rule types. */
    public function testBothRedirectScriptsAskTheMatcher(): void
    {
        foreach (['tracking202/redirect/rtr.php', 'tracking202/redirect/offrtr.php'] as $file) {
            $source = (string) file_get_contents(SourceScan::repoRoot() . '/' . $file);
            self::assertStringContainsString('CriterionMatcher::rule(', $source, $file);
            self::assertDoesNotMatchRegularExpression("/case\\s+'(?:country|browser|utm_source)'\\s*:/", $source, $file);
        }
    }
}
