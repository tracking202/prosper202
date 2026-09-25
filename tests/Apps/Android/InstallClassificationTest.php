<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use Api\V3\Apps\Android\InstallClassifier;
use Api\V3\Apps\Android\InstallPayload;
use Api\V3\Apps\Android\InstallToken;
use Api\V3\Apps\Android\InstallVerdict;
use Api\V3\Apps\Android\MatchState;
use Api\V3\Apps\Android\MissingInstallKey;
use Api\V3\Apps\Android\ReferrerParser;
use Api\V3\Apps\AppPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The pure half of the Android intake (plan §5.3, §7.1): the referrer
 * parser, the MatchState classification with its timing rules, and what
 * each state is worth under a policy.
 */
final class InstallClassificationTest extends TestCase
{
    private const CLICK_TIME = 1_727_200_000;

    private static function key(): string
    {
        return (string) hex2bin(str_repeat('ab', 32));
    }

    /** @param array<string, mixed> $referrer */
    private static function payload(array $referrer = [], string $installReferrer = 'utm_source=google-play&utm_medium=organic', bool $test = false): InstallPayload
    {
        return InstallPayload::fromDecoded([
            'install_uuid' => '11111111-2222-4333-8444-555555555555',
            'app_key' => 'com.example.app',
            'store' => 'google_play',
            'test' => $test,
            'referrer' => $referrer + [
                'status' => 'ok',
                'install_referrer' => $installReferrer,
                'referrer_click_timestamp_seconds' => self::CLICK_TIME + 5,
                'install_begin_timestamp_seconds' => self::CLICK_TIME + 60,
                'referrer_click_timestamp_server_seconds' => self::CLICK_TIME + 6,
                'install_begin_timestamp_server_seconds' => self::CLICK_TIME + 61,
            ],
        ]);
    }

    /** @return array{state: MatchState|null, reason: string, click_id: int|null} */
    private static function first(InstallPayload $p): array
    {
        return InstallClassifier::fromReferrer($p, $p->referrerStatus === 'ok' ? ReferrerParser::parse((string) $p->installReferrer) : null, self::key());
    }

    /** @param array<string, mixed>|null $click */
    private static function second(InstallPayload $p, ?array $click, bool $hasInstall = false, int $window = 7, int $now = self::CLICK_TIME + 100): MatchState
    {
        return InstallClassifier::withClick($p, 42, $click, 1, 5, $window, $hasInstall, self::CLICK_TIME + 90, $now)['state'];
    }

    /** @return array{user_id: int, campaign_id: int, click_time: int, campaign_registration_id: int|null} */
    private static function click(int $user = 1, ?int $linked = null, int $time = self::CLICK_TIME): array
    {
        return ['user_id' => $user, 'campaign_id' => 3, 'click_time' => $time, 'campaign_registration_id' => $linked];
    }

    public function testTheReferrerClasses(): void
    {
        self::assertSame('organic', ReferrerParser::parse('')['class']);
        self::assertSame('organic', ReferrerParser::parse('utm_source=google-play&utm_medium=organic')['class']);
        self::assertSame('third_party', ReferrerParser::parse('utm_source=google-play&utm_medium=organic&utm_campaign=x')['class'],
            'the organic marker plus a campaign is somebody\'s link');
        self::assertSame('third_party', ReferrerParser::parse('gclid=Cj0KCQ')['class']);
        self::assertSame('third_party', ReferrerParser::parse('utm_source=apps.facebook.com&utm_content=' . rawurlencode('{"app":1,"t":2,"source":{"data":"x","nonce":"y"}}'))['class']);
        self::assertTrue(ReferrerParser::parse('utm_content=' . rawurlencode('{"source":{"data":"x","nonce":"y"}}'))['meta_envelope']);
        $ours = ReferrerParser::parse('p202=42.abc&utm_source=news%20letter&gclid=g1');
        self::assertSame('ours', $ours['class']);
        self::assertSame(['42.abc'], $ours['tokens']);
        self::assertSame('news letter', $ours['fields']['utm_source']);
        self::assertSame('g1', $ours['fields']['gclid']);
        self::assertSame(['1', '2'], ReferrerParser::parse('p202=1&p202=2')['tokens']);
    }

    public function testAReferrerIsNeverRejectedOnlyCutAndScrubbed(): void
    {
        $long = 'utm_source=' . str_repeat('é', 1500);
        $parsed = ReferrerParser::parse($long);
        self::assertTrue($parsed['truncated']);
        self::assertLessThanOrEqual(ReferrerParser::RAW_LIMIT, strlen($parsed['raw']));
        self::assertTrue(mb_check_encoding($parsed['raw'], 'UTF-8'), 'cut on a character boundary');
        self::assertLessThanOrEqual(ReferrerParser::FIELD_LIMIT, strlen((string) $parsed['fields']['utm_source']));
        $bytes = ReferrerParser::parse('utm_source=%FF%FEbad');
        self::assertTrue(mb_check_encoding((string) $bytes['fields']['utm_source'], 'UTF-8'), 'percent-decoded bytes that are not UTF-8 are scrubbed');
    }

    public function testTheReferrerAloneDecidesEverythingButAValidToken(): void
    {
        self::assertSame(MatchState::ORGANIC, self::first(self::payload())['state']);
        self::assertSame(MatchState::ORGANIC, self::first(self::payload([], ''))['state']);
        self::assertSame(MatchState::THIRD_PARTY, self::first(self::payload([], 'gclid=x'))['state']);
        self::assertSame(MatchState::UNAVAILABLE, self::first(InstallPayload::fromDecoded([
            'install_uuid' => '11111111-2222-4333-8444-555555555555', 'app_key' => 'com.example.app', 'store' => 'google_play',
            'referrer' => ['status' => 'service_unavailable'],
        ]))['state']);
        self::assertSame(MatchState::BAD_TOKEN, self::first(self::payload([], 'p202=42'))['state'], 'malformed');
        self::assertSame(MatchState::BAD_TOKEN, self::first(self::payload([], 'p202=' . InstallToken::forClick(42, self::key()) . '&p202=x'))['state'], 'two tokens');
        $forged = InstallToken::forClick(42, (string) hex2bin(str_repeat('cd', 32)));
        self::assertSame(MatchState::BAD_TOKEN, self::first(self::payload([], 'p202=' . $forged))['state'], 'signed with another key');
        $good = self::first(self::payload([], 'p202=' . InstallToken::forClick(42, self::key())));
        self::assertNull($good['state']);
        self::assertSame(42, $good['click_id']);
    }

    public function testATokenWithNoKeyIsNeverJudged(): void
    {
        $p = self::payload([], 'p202=' . InstallToken::forClick(42, self::key()));
        $this->expectException(MissingInstallKey::class);
        InstallClassifier::fromReferrer($p, ReferrerParser::parse((string) $p->installReferrer), null);
    }

    public function testAnOrganicInstallNeedsNoKey(): void
    {
        $p = self::payload();
        self::assertSame(MatchState::ORGANIC, InstallClassifier::fromReferrer($p, ReferrerParser::parse((string) $p->installReferrer), null)['state']);
    }

    public function testTheClickDecidesTheRest(): void
    {
        $p = self::payload();
        self::assertSame(MatchState::ATTRIBUTED, self::second($p, self::click()));
        self::assertSame(MatchState::ATTRIBUTED, self::second($p, self::click(1, 5)), 'linked to this registration');
        self::assertSame(MatchState::FOREIGN_CLICK, self::second($p, self::click(2)), 'another account\'s click');
        self::assertSame(MatchState::FOREIGN_CLICK, self::second($p, self::click(1, 6)), 'a campaign linked to another app');
        self::assertSame(MatchState::DUPLICATE_CLICK, self::second($p, self::click(), true));
        self::assertSame(MatchState::PENDING_CLICK, self::second($p, null));
        self::assertSame(MatchState::BAD_TOKEN, self::second($p, null, false, 7, self::CLICK_TIME + 90 + InstallClassifier::PENDING_CLICK_TTL),
            'a click never recorded within 24 h');
    }

    public function testTheTimingRules(): void
    {
        $at = static fn (int $googleClick, int $installBegin): InstallPayload => self::payload([
            'referrer_click_timestamp_server_seconds' => $googleClick, 'install_begin_timestamp_server_seconds' => $installBegin,
        ]);
        $t = self::CLICK_TIME;
        self::assertSame(MatchState::IMPLAUSIBLE, self::second($at($t + 100, $t + 50), self::click()), 'install before the store click: injection');
        self::assertSame(MatchState::IMPLAUSIBLE, self::second($at($t - InstallClassifier::CLOCK_SKEW - 1, $t + 50), self::click()), 'store click before our click');
        self::assertSame(MatchState::ATTRIBUTED, self::second($at($t - InstallClassifier::CLOCK_SKEW, $t + 50), self::click()), 'within the skew');
        self::assertSame(MatchState::IMPLAUSIBLE, self::second($at($t + InstallClassifier::MAX_STORE_DELAY + 1, $t + 7200), self::click()), 'a harvested click');
        self::assertSame(MatchState::ATTRIBUTED, self::second($at($t + InstallClassifier::MAX_STORE_DELAY, $t + 7200), self::click()));
        self::assertSame(MatchState::IMPLAUSIBLE, self::second($at(0, 0), self::click()), 'no server timestamps');
        self::assertSame(MatchState::OUTSIDE_WINDOW, self::second($at($t + 10, $t + 7 * 86400), self::click()), 'the window is half-open');
        self::assertSame(MatchState::ATTRIBUTED, self::second($at($t + 10, $t + 7 * 86400 - 1), self::click()));
        self::assertSame(MatchState::OUTSIDE_WINDOW, self::second($at($t + 10, $t + 20), self::click(), false, 0), 'an unreadable window admits nothing');
    }

    public function testWhatEachStateIsWorth(): void
    {
        $bits = [];
        foreach (MatchState::cases() as $state) {
            $bits[$state->value] = $state->trustBit(AppPolicy::untrusting());
        }
        self::assertSame([
            'attributed' => 1, 'organic' => null, 'third_party' => null, 'unavailable' => null, 'pending_click' => null,
            'bad_token' => 0, 'foreign_click' => 0, 'implausible' => 0, 'outside_window' => null, 'duplicate_click' => null,
            'pending_integrity' => null, 'integrity_failed' => 0, 'integrity_unverified' => null,
        ], $bits);
        $accepting = AppPolicy::fromRow(['accept_test_signals' => 1]);
        $refusing = AppPolicy::fromRow(['accept_test_signals' => 0]);
        self::assertSame(1, (new InstallVerdict(MatchState::ATTRIBUTED, true))->trustBit($accepting));
        self::assertNull((new InstallVerdict(MatchState::ATTRIBUTED, true))->trustBit($refusing), 'a test install counts only under accept_test_signals');
        self::assertSame(0, (new InstallVerdict(MatchState::BAD_TOKEN, true))->trustBit($accepting), 'a test flag never vouches for a forgery');
        self::assertSame(1, (new InstallVerdict(MatchState::ATTRIBUTED, false))->trustBit($refusing));
    }

    public function testAnUnreadablePolicyIsTheUntrustingOne(): void
    {
        foreach ([[], null, ['accept_test_signals' => 1, 'attribution_window_days' => '7.0'], ['accept_test_signals' => 1, 'attribution_window_days' => 366],
            ['accept_test_signals' => 1, 'attribution_window_days' => '07'], ['accept_test_signals' => 1, 'attribution_window_days' => null]] as $row) {
            self::assertSame(0, AppPolicy::fromRow($row)->attributionWindowDays, json_encode($row));
        }
        foreach ([true, 2, '1 ', 'yes', null] as $flag) {
            self::assertFalse(AppPolicy::fromRow(['accept_test_signals' => 0, 'trust_client_revenue' => $flag])->trustClientRevenue, var_export($flag, true));
        }
        $read = AppPolicy::fromRow(['accept_test_signals' => '0', 'attribution_window_days' => '30', 'trust_client_revenue' => '1']);
        self::assertSame(30, $read->attributionWindowDays);
        self::assertTrue($read->trustClientRevenue);
    }
}
