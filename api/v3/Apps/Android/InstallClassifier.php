<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android;

/**
 * Classifies an install into a MatchState (plan §5.3). Pure: no database,
 * no clock — the intake and the pending-click settler hand it what they
 * read, so the same inputs give the same state on both paths and in tests.
 *
 * Two steps, because only one of them needs the click:
 *
 *  1. fromReferrer(): the referrer alone. Everything that is not a valid
 *     install token ends here (organic, third_party, unavailable,
 *     bad_token), and a valid token yields the click it names.
 *  2. withClick(): that click (or its absence) against the registration:
 *     pending_click, bad_token (never recorded), foreign_click,
 *     implausible, outside_window, duplicate_click or attributed.
 *
 * Timing rules (plan §7.1), all on Google's server clock against ours:
 *  - Google's server timestamps must both be present: without them the
 *    install cannot be placed after the click, so it is implausible (Play
 *    Store 8.3.73+, the documented floor, always sends them);
 *  - install-begin before Google's click time is click injection;
 *  - Google's click time must fall within [our click − CLOCK_SKEW, our
 *    click + MAX_STORE_DELAY]: the store page opens seconds after our
 *    redirect, and a click harvested hours earlier is click spamming;
 *  - install-begin more than attribution_window_days after our click is
 *    outside the window (unvouched, not refuted: the click was real).
 */
final class InstallClassifier
{
    /** Tolerated difference between our clock and Google's, seconds. */
    public const CLOCK_SKEW = 120;
    /** Longest plausible gap from our redirect to Google's store click, seconds. */
    public const MAX_STORE_DELAY = 1800;
    /** How long a token whose click row is missing waits before it is a bad token. */
    public const PENDING_CLICK_TTL = 86400;

    private function __construct()
    {
    }

    /**
     * @param string|null $key the install-token key; required only when the
     *        referrer carries a token (MissingInstallKey otherwise)
     * @param array{class: string, tokens: list<string>} $parsed ReferrerParser::parse()
     * @return array{state: MatchState|null, reason: string, click_id: int|null}
     *         state null = "a valid token for click_id: look the click up"
     * @throws MissingInstallKey
     */
    public static function fromReferrer(InstallPayload $payload, ?array $parsed, ?string $key): array
    {
        if ($payload->referrerStatus !== 'ok') {
            return self::final(MatchState::UNAVAILABLE, 'The Play install referrer was not available on the device (' . $payload->referrerStatus . ').');
        }
        if ($parsed === null) {
            throw new \LogicException('an ok referrer is parsed before it is classified');
        }
        if ($parsed['class'] === 'organic') {
            return self::final(MatchState::ORGANIC, $payload->installReferrer === ''
                ? 'Play reported no referrer: the install did not come through a store link.'
                : 'Play reported its organic referrer: the install did not come through a campaign link.');
        }
        if ($parsed['class'] === 'third_party') {
            return self::final(MatchState::THIRD_PARTY, 'The referrer names another source (' . self::describeThirdParty($parsed) . '), not a Prosper202 link.');
        }
        if (count($parsed['tokens']) !== 1) {
            return self::final(MatchState::BAD_TOKEN, 'The referrer carries ' . count($parsed['tokens']) . ' p202 tokens; a link carries one.');
        }
        $token = $parsed['tokens'][0];
        if (InstallToken::clickIdOf($token) === null) {
            return self::final(MatchState::BAD_TOKEN, 'The p202 token is malformed: it is not <click id>.<16-character signature>.');
        }
        if ($key === null) {
            throw new MissingInstallKey();
        }
        $clickId = InstallToken::verify($token, $key);
        if ($clickId === null) {
            return self::final(MatchState::BAD_TOKEN, 'The p202 token\'s signature does not verify: it was not issued by this server for that click.');
        }

        return ['state' => null, 'reason' => '', 'click_id' => $clickId];
    }

    /**
     * @param array{user_id: int, campaign_id: int, click_time: int, campaign_registration_id: int|null}|null $click
     *        the click the token names, or null when no such row exists
     * @return array{state: MatchState, reason: string, click_id: int|null}
     */
    public static function withClick(
        InstallPayload $payload,
        int $clickId,
        ?array $click,
        int $userId,
        int $registrationId,
        int $windowDays,
        bool $clickHasInstall,
        int $receivedAt,
        int $now,
    ): array {
        if ($click === null) {
            if ($now - $receivedAt >= self::PENDING_CLICK_TTL) {
                return self::final(MatchState::BAD_TOKEN, 'Click ' . $clickId . ' was never recorded: the token verified but no click row appeared within 24 hours.');
            }

            return self::final(MatchState::PENDING_CLICK, 'Click ' . $clickId . ' has not been recorded yet; the install is settled when it is.');
        }
        if ($click['user_id'] !== $userId) {
            return self::final(MatchState::FOREIGN_CLICK, 'Click ' . $clickId . ' belongs to another account.');
        }
        if ($click['campaign_registration_id'] !== null && $click['campaign_registration_id'] !== $registrationId) {
            return self::final(MatchState::FOREIGN_CLICK, 'Click ' . $clickId . '\'s campaign is linked to app registration '
                . $click['campaign_registration_id'] . ', not this one (' . $registrationId . ').');
        }

        $googleClick = $payload->referrerClickServerAt;
        $installBegin = $payload->installBeginServerAt;
        $ours = $click['click_time'];
        if ($googleClick === null || $installBegin === null) {
            return self::final(MatchState::IMPLAUSIBLE, 'Google\'s server timestamps are missing, so the install cannot be placed after the click.');
        }
        if ($installBegin < $googleClick) {
            return self::final(MatchState::IMPLAUSIBLE, 'The install began (' . $installBegin . ') before the store click (' . $googleClick . ') on Google\'s clock: click injection.');
        }
        if ($googleClick < $ours - self::CLOCK_SKEW) {
            return self::final(MatchState::IMPLAUSIBLE, 'Google saw the store click ' . ($ours - $googleClick) . ' s before click ' . $clickId . ' was made.');
        }
        if ($googleClick > $ours + self::MAX_STORE_DELAY) {
            return self::final(MatchState::IMPLAUSIBLE, 'Google saw the store click ' . ($googleClick - $ours) . ' s after click ' . $clickId
                . '; a real redirect reaches the store within ' . self::MAX_STORE_DELAY . ' s.');
        }
        if ($windowDays <= 0 || $installBegin - $ours >= $windowDays * 86400) {
            return self::final(MatchState::OUTSIDE_WINDOW, 'The install began ' . intdiv(max(0, $installBegin - $ours), 3600)
                . ' h after click ' . $clickId . ', outside the ' . $windowDays . '-day attribution window.');
        }
        if ($clickHasInstall) {
            return self::final(MatchState::DUPLICATE_CLICK, 'Click ' . $clickId . ' already has an attributed install.');
        }

        return ['state' => MatchState::ATTRIBUTED, 'reason' => 'Attributed to click ' . $clickId . '.', 'click_id' => $clickId];
    }

    /** @return array{state: MatchState, reason: string, click_id: null} */
    private static function final(MatchState $state, string $reason): array
    {
        return ['state' => $state, 'reason' => $reason, 'click_id' => null];
    }

    /** @param array{fields?: array<string, string|null>, meta_envelope?: bool} $parsed */
    private static function describeThirdParty(array $parsed): string
    {
        $fields = $parsed['fields'] ?? [];
        if (!empty($parsed['meta_envelope'])) {
            return 'Meta\'s install referrer';
        }
        if (($fields['gclid'] ?? null) !== null) {
            return 'a Google Ads gclid';
        }
        if (($fields['utm_source'] ?? null) !== null) {
            return 'utm_source=' . mb_strimwidth((string) $fields['utm_source'], 0, 60, '…', 'UTF-8');
        }

        return 'an unrecognised referrer';
    }
}
