<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

use Prosper202\Attribution\WebhookSender;

/**
 * Google Ads click conversions (ConversionUploadService.UploadClickConversions)
 * with the operator's own developer token and OAuth client: one conversion
 * per request, posted through WebhookSender::post(). An access token is
 * minted from the stored refresh token (GoogleAdsOAuth) once per run and
 * kept only in memory. A test fire sets validateOnly, so nothing is recorded.
 *
 * Google matches a conversion to its click by gclid, wbraid or gbraid (one,
 * in that order), or, for enhanced conversions for leads, by hashed email and
 * phone. A conversion with neither is withheld. Google refuses a click less
 * than 6 hours old (TOO_RECENT_EVENT): such a row waits, uncounted.
 */
final class GoogleAdsDestination implements DestinationType, AuthorisedDestination
{
    public const TYPE = 'google_ads';
    public const API_VERSION = 'v26';
    public const HOST = 'googleads.googleapis.com';
    /** Google takes a click's conversion 6 hours after the click (TOO_RECENT_EVENT). */
    public const CLICK_HOLD = 6 * 3600;
    /** No conversion action's click-through window is longer than 90 days. */
    public const CLICK_MAX_AGE = 90 * 86400;
    /** How long a destination that needs its operator waits before it is tried again. */
    public const AUTH_WAIT = 3600;
    /** A test's made-up click id: Google checks everything else, then refuses the click, which is expected. */
    public const TEST_GCLID = 'Prosper202-test-click-id';
    private const TEST_CLICK_ERRORS = ['UNPARSEABLE_GCLID', 'EVENT_NOT_FOUND', 'CLICK_NOT_FOUND', 'TOO_RECENT_EVENT', 'EXPIRED_EVENT'];
    /** Google already holds this conversion (the same order id, or the same click and time). */
    private const ALREADY_THERE = ['ORDER_ID_ALREADY_IN_USE', 'CLICK_CONVERSION_ALREADY_EXISTS'];
    private const RETRY_FAMILIES = ['quotaError', 'internalError'];
    private const NOT_ALLOWLISTED = 'CUSTOMER_NOT_ALLOWLISTED_FOR_THIS_FEATURE';
    private const ACTION = '/\Acustomers\/([0-9]{10})\/conversionActions\/([1-9][0-9]{0,18})\z/D';

    /** @var array<string, array{0: string, 1: int}> access tokens minted this run, by client and grant */
    private array $tokens = [];
    private GoogleAdsOAuth $oauth;

    public function __construct(private ?WebhookSender $sender = null, ?GoogleAdsOAuth $oauth = null)
    {
        $this->oauth = $oauth ?? new GoogleAdsOAuth($sender);
    }

    public function settingKeys(): array
    {
        return ['customer_id', 'login_customer_id', 'conversion_action', 'campaign_conversion_actions'];
    }

    /** The grant (refresh_token) is not one: only the consent screen fills it. */
    public function credentialKeys(): array
    {
        return ['developer_token', 'oauth_client_id', 'oauth_client_secret'];
    }

    public function host(array $settings): string
    {
        return self::HOST;
    }

    public function authorised(array $credentials): bool
    {
        return is_string($credentials[self::GRANT] ?? null) && $credentials[self::GRANT] !== '';
    }

    public function keptOnReplace(array $stored, array $given): array
    {
        if ($this->authorised($stored) && ($stored['oauth_client_id'] ?? null) === ($given['oauth_client_id'] ?? null)) {
            $given[self::GRANT] = $stored[self::GRANT];
        }

        return $given;
    }

    public function validate(array $settings, ?array $credentials): array
    {
        $out = ['customer_id' => self::customerId($settings['customer_id'] ?? null, 'customer_id', true)];
        $login = self::customerId($settings['login_customer_id'] ?? null, 'login_customer_id', false);
        if ($login !== null) {
            $out['login_customer_id'] = $login;
        }
        $out['conversion_action'] = self::action($settings['conversion_action'] ?? null, 'settings.conversion_action');
        $byCampaign = $settings['campaign_conversion_actions'] ?? [];
        if (!is_array($byCampaign) || count($byCampaign) > 500) {
            throw new DestinationInputException('settings.campaign_conversion_actions', 'campaign_conversion_actions must be an object of campaign id to conversion action, at most 500.');
        }
        $map = [];
        foreach ($byCampaign as $campaignId => $action) {
            if (!is_int($campaignId) || $campaignId <= 0) {
                throw new DestinationInputException('settings.campaign_conversion_actions', 'campaign_conversion_actions is keyed by campaign id (p202 campaign list); "' . $campaignId . '" is not one.');
            }
            $map[$campaignId] = self::action($action, 'settings.campaign_conversion_actions');
        }
        if ($map !== []) {
            $out['campaign_conversion_actions'] = $map;
        }
        if ($credentials === null) {
            return [$out, null];
        }
        if (array_key_exists(self::GRANT, $credentials)) {
            throw new DestinationInputException('credentials.' . self::GRANT, 'The refresh token is not typed in: connect Google Ads from Setup › Destinations, which stores it.');
        }
        $patterns = [
            'developer_token' => ['/\A[A-Za-z0-9_\-]{10,100}\z/D', 'credentials.developer_token must be your Google Ads API developer token (your manager account\'s API Center).'],
            'oauth_client_id' => ['/\A[A-Za-z0-9\-_.]{5,200}\.apps\.googleusercontent\.com\z/D', 'credentials.oauth_client_id must be an OAuth client ID from Google Cloud Console, ending .apps.googleusercontent.com.'],
            'oauth_client_secret' => ['/\A[A-Za-z0-9_\-]{10,200}\z/D', 'credentials.oauth_client_secret must be that OAuth client\'s secret.'],
        ];
        $creds = [];
        foreach ($patterns as $key => [$pattern, $message]) {
            $value = $credentials[$key] ?? '';
            if (!is_string($value) || preg_match($pattern, trim($value)) !== 1) {
                throw new DestinationInputException('credentials.' . $key, $message);
            }
            $creds[$key] = trim($value);
        }

        return [$out, $creds];
    }

    public function send(array $settings, array $credentials, ConversionFacts $facts, bool $test, int $now): SendResult
    {
        $secrets = [$credentials['developer_token'] ?? null, $credentials['oauth_client_secret'] ?? null, $credentials[self::GRANT] ?? null];
        if (!$this->authorised($credentials)) {
            return new SendResult(false, true, null, 'waiting: Google Ads is not connected yet; press Connect Google Ads on Setup › Destinations', waitUntil: $now + self::AUTH_WAIT, destinationWaits: true);
        }
        if ($test) {
            $facts = self::withTestClick($facts);
        }
        [$conversion, $clickType] = self::conversion($settings, $facts, $test ? $now : null);
        if ($conversion === null) {
            return new SendResult(false, false, null, 'withheld: nothing Google can match: the click has no gclid, wbraid or gbraid, and no hashed email or phone was sent', withheld: true);
        }
        $summary = self::summary($conversion, $clickType, $test);
        if (!$test && $clickType !== '' && $facts->clickTime !== null) {
            if ($now - $facts->clickTime > self::CLICK_MAX_AGE) {
                return new SendResult(false, false, null, 'not sent: the click is more than 90 days old, longer than any Google Ads conversion window', $summary);
            }
            if ($now < $facts->clickTime + self::CLICK_HOLD) {
                return new SendResult(false, true, null, 'waiting: Google takes a click\'s conversion 6 hours after the click', $summary, waitUntil: $facts->clickTime + self::CLICK_HOLD);
            }
        }
        $token = $this->accessToken($credentials, $now);
        if ($token instanceof SendResult) {
            return self::redacted($token, $secrets, $summary);
        }
        $secrets[] = $token;
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'developer-token: ' . $credentials['developer_token'], 'User-Agent: Prosper202-Destinations/1'];
        if (isset($settings['login_customer_id'])) {
            $headers[] = 'login-customer-id: ' . $settings['login_customer_id'];
        }
        $body = ['conversions' => [$conversion], 'partialFailure' => true] + ($test ? ['validateOnly' => true] : []);
        $this->sender ??= new WebhookSender();
        $sent = $this->sender->post(
            'https://' . self::HOST . '/' . self::API_VERSION . '/customers/' . $settings['customer_id'] . ':uploadClickConversions',
            json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            $headers,
            [443]
        );
        if ($sent['refused'] !== null) {
            return self::redacted(new SendResult(false, false, null, 'not sent: ' . $sent['refused']), $secrets, $summary);
        }
        if ($sent['strayed'] !== null) {
            return new SendResult(false, false, null, 'not sent: the connection went to ' . $sent['strayed'] . ' instead of the checked address ' . $sent['pinned'], $summary);
        }
        if ($sent['status'] === 0) {
            return self::redacted(new SendResult(false, true, null, 'no answer: ' . ($sent['error'] ?? 'the request failed')), $secrets, $summary);
        }
        if ($sent['status'] === 401) {
            // The access token went stale mid-run: the next try mints another.
            unset($this->tokens[self::tokenKey($credentials)]);
        }

        return self::redacted(self::answer($sent['status'], $sent['body'], $test, $facts, $now), $secrets, $summary);
    }

    /**
     * What Google's answer means. A test passes when Google found nothing
     * wrong but its made-up click.
     */
    public static function answer(int $status, string $body, bool $test, ConversionFacts $facts, int $now): SendResult
    {
        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }
        $decoded = is_array($decoded) ? $decoded : null;
        if ($status < 200 || $status >= 300) {
            $errors = self::errors($decoded['error'] ?? null);
            $families = array_column($errors, 0);
            $retry = $status >= 500 || $status === 408 || $status === 429 || $status === 401
                || array_intersect($families, self::RETRY_FAMILIES) !== [];
            $text = $errors !== [] ? self::describe($errors)
                : trim(mb_substr((string) preg_replace('/\s+/', ' ', (string) ($decoded['error']['message'] ?? $body)), 0, 200));

            return new SendResult(false, $retry && !$test, $status, 'Google Ads answered ' . $status . ($text !== '' ? ': ' . $text : '') . self::allowlistNote($errors), messages: $test ? self::messages($errors) : []);
        }
        if ($decoded === null) {
            // orderId makes a second try safe; a test has nothing to retry.
            return new SendResult(false, !$test, $status, 'Google Ads answered ' . $status . ' with something that is not its JSON');
        }
        $partial = $decoded['partialFailureError'] ?? null;
        $errors = self::errors($partial);
        if ($errors === [] && is_array($partial) && (int) ($partial['code'] ?? 0) !== 0) {
            $errors = [['', '', (string) ($partial['message'] ?? 'partial failure')]];
        }
        $codes = array_column($errors, 1);
        if ($test) {
            $unexpected = array_values(array_filter($errors, static fn (array $e): bool => !in_array($e[1], self::TEST_CLICK_ERRORS, true)));

            return new SendResult($unexpected === [], false, $status, $unexpected === [] ? '' : self::describe($unexpected) . self::allowlistNote($unexpected), messages: self::messages($unexpected));
        }
        if ($errors === []) {
            return new SendResult(true, false, $status, '');
        }
        if (array_intersect($codes, self::ALREADY_THERE) !== []) {
            return new SendResult(true, false, $status, 'already in Google Ads: ' . self::describe($errors));
        }
        // Still "too recent" a day after the click is not the click's age: an ordinary retry, counted.
        if (in_array('TOO_RECENT_EVENT', $codes, true) && $now - ($facts->clickTime ?? $now) > 4 * self::CLICK_HOLD) {
            return new SendResult(false, true, $status, 'Google Ads answered ' . self::describe($errors));
        }
        if (in_array('TOO_RECENT_EVENT', $codes, true)) {
            return new SendResult(false, true, $status, 'waiting: ' . self::describe($errors), waitUntil: max($now + 900, ($facts->clickTime ?? $now) + self::CLICK_HOLD));
        }
        if (in_array('TOO_RECENT_CONVERSION_ACTION', $codes, true)) {
            return new SendResult(false, true, $status, 'waiting: ' . self::describe($errors), waitUntil: $now + self::CLICK_HOLD);
        }

        return new SendResult(false, false, $status, 'Google Ads refused it: ' . self::describe($errors) . self::allowlistNote($errors));
    }

    /**
     * The ClickConversion, and which click id it carries ('' for none). Null
     * when Google has nothing to match it by. $testTime replaces the
     * conversion's time on a test fire.
     *
     * @param array<string, mixed> $settings
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    public static function conversion(array $settings, ConversionFacts $facts, ?int $testTime = null): array
    {
        $clickType = '';
        $conversion = [];
        // Google's own precedence; gbraid and wbraid together are refused (GBRAID_WBRAID_BOTH_SET).
        foreach (['gclid', 'wbraid', 'gbraid'] as $type) {
            $id = trim((string) ($facts->click[$type] ?? ''));
            if ($id !== '') {
                $clickType = $type;
                $conversion[$type] = $id;
                break;
            }
        }
        $identifiers = [];
        // Google ignores user identifiers sent with a wbraid or gbraid alone, so they are not sent.
        if ($clickType === '' || $clickType === 'gclid') {
            foreach ($facts->emailHashes as $hash) {
                $identifiers[] = ['hashedEmail' => $hash];
            }
            foreach ($facts->phoneHashes as $hash) {
                $identifiers[] = ['hashedPhoneNumber' => $hash];
            }
            $identifiers = array_slice($identifiers, 0, 5);
        }
        if ($clickType === '' && $identifiers === []) {
            return [null, ''];
        }
        $conversion += [
            'conversionAction' => self::actionResource($settings, $facts->campaignId),
            'conversionDateTime' => self::dateTime($testTime ?? $facts->convTime, $facts->zone),
            'conversionValue' => round((float) $facts->value, 2),
            'currencyCode' => $facts->currency,
            'orderId' => $facts->transactionId,
        ];
        $consent = [];
        foreach (['ad_user_data' => 'adUserData', 'ad_personalization' => 'adPersonalization'] as $signal => $field) {
            $stated = $settings['consent'][$signal] ?? null;
            if (is_string($stated)) {
                $consent[$field] = strtoupper($stated);
            }
        }
        if ($consent !== []) {
            $conversion['consent'] = $consent;
        }
        if ($identifiers !== []) {
            $conversion['userIdentifiers'] = $identifiers;
        }

        return [$conversion, $clickType];
    }

    /** yyyy-mm-dd hh:mm:ss+|-hh:mm in $zone, with the offset in force at that instant. */
    public static function dateTime(int $timestamp, string $zone): string
    {
        try {
            $tz = new \DateTimeZone($zone);
        } catch (\Exception) {
            $tz = new \DateTimeZone('UTC');
        }

        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($tz)->format('Y-m-d H:i:sP');
    }

    /** @param array<string, mixed> $settings */
    public static function actionResource(array $settings, int $campaignId): string
    {
        $action = (string) (((array) ($settings['campaign_conversion_actions'] ?? []))[$campaignId] ?? $settings['conversion_action'] ?? '');

        return str_starts_with($action, 'customers/') ? $action : 'customers/' . ($settings['customer_id'] ?? '') . '/conversionActions/' . $action;
    }

    /**
     * What the log keeps: which click id and identifiers were sent, never their values.
     *
     * @param array<string, mixed> $conversion
     * @return array<string, mixed>
     */
    public static function summary(array $conversion, string $clickType, bool $test): array
    {
        return [
            'conversion_action' => $conversion['conversionAction'] ?? null,
            'click_id' => $clickType === '' ? null : $clickType,
            'conversion_date_time' => $conversion['conversionDateTime'] ?? null,
            'value' => $conversion['conversionValue'] ?? null,
            'currency' => $conversion['currencyCode'] ?? null,
            'order_id' => $conversion['orderId'] ?? null,
            'user_identifiers' => array_map(static fn (array $i): string => isset($i['hashedEmail']) ? 'email' : 'phone', $conversion['userIdentifiers'] ?? []),
            'consent' => $conversion['consent'] ?? null,
            'validate_only' => $test,
        ];
    }

    /** An access token from the stored grant, or why there is none. */
    private function accessToken(array $credentials, int $now): string|SendResult
    {
        $key = self::tokenKey($credentials);
        if (isset($this->tokens[$key]) && $this->tokens[$key][1] > $now + 60) {
            return $this->tokens[$key][0];
        }
        $answer = $this->oauth->token([
            'grant_type' => 'refresh_token',
            'client_id' => (string) ($credentials['oauth_client_id'] ?? ''),
            'client_secret' => (string) ($credentials['oauth_client_secret'] ?? ''),
            'refresh_token' => (string) $credentials[self::GRANT],
        ]);
        $token = $answer['json']['access_token'] ?? null;
        if ($answer['error'] === null && is_string($token) && $token !== '') {
            $this->tokens[$key] = [$token, $now + max(60, (int) ($answer['json']['expires_in'] ?? 3600))];

            return $token;
        }
        $error = $answer['error'] ?? 'Google\'s token endpoint gave no access token';
        if (!$answer['answered']) {
            return new SendResult(false, true, null, $error);
        }
        if ($answer['retry'] || $answer['status'] === 200) {
            return new SendResult(false, true, $answer['status'], $error);
        }
        // Revoked, expired or a wrong client: nothing sends until the operator connects again, so nothing is lost meanwhile.
        $fix = ($answer['json']['error'] ?? '') === 'invalid_grant'
            ? 'the Google authorisation was revoked or expired; press Connect Google Ads on Setup › Destinations again'
            : 'check the OAuth client ID and secret, then connect Google Ads again';

        return new SendResult(false, true, $answer['status'], 'waiting: ' . $error . ' (' . $fix . ')', waitUntil: $now + self::AUTH_WAIT, destinationWaits: true);
    }

    private static function tokenKey(array $credentials): string
    {
        return hash('sha256', (string) ($credentials['oauth_client_id'] ?? '') . "\0" . (string) ($credentials[self::GRANT] ?? ''));
    }

    /**
     * The errors of a google.rpc.Status carrying GoogleAdsFailure details:
     * [error code family, code, message].
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function errors(mixed $status): array
    {
        $out = [];
        foreach ((is_array($status) && is_array($status['details'] ?? null) ? $status['details'] : []) as $detail) {
            foreach ((is_array($detail) && is_array($detail['errors'] ?? null) ? $detail['errors'] : []) as $error) {
                if (!is_array($error)) {
                    continue;
                }
                $code = is_array($error['errorCode'] ?? null) ? $error['errorCode'] : [];
                $family = (string) array_key_first($code);
                $out[] = [$family, is_scalar($code[$family] ?? null) ? (string) $code[$family] : '', is_string($error['message'] ?? null) ? $error['message'] : ''];
            }
        }

        return $out;
    }

    /** @param list<array{0: string, 1: string, 2: string}> $errors */
    private static function describe(array $errors): string
    {
        return implode(' ', self::messages($errors));
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $errors
     * @return list<string>
     */
    private static function messages(array $errors): array
    {
        return array_map(static fn (array $e): string => trim($e[1] . ': ' . mb_substr($e[2], 0, 200), ': '), $errors);
    }

    /** @param list<array{0: string, 1: string, 2: string}> $errors */
    private static function allowlistNote(array $errors): string
    {
        return in_array(self::NOT_ALLOWLISTED, array_column($errors, 1), true)
            ? ' (Google takes click conversion uploads only from developer tokens that made such uploads between 17 December 2025 and 15 June 2026.)'
            : '';
    }

    /** @param list<mixed> $secrets */
    private static function redacted(SendResult $result, array $secrets, array $summary): SendResult
    {
        return new SendResult(
            $result->delivered,
            $result->retry,
            $result->status,
            GoogleAdsOAuth::redact($result->reason, $secrets),
            $result->summary !== [] ? $result->summary : $summary,
            array_map(static fn (string $m): string => GoogleAdsOAuth::redact($m, $secrets), $result->messages),
            $result->waitUntil,
            $result->destinationWaits,
            $result->withheld,
        );
    }

    private static function withTestClick(ConversionFacts $facts): ConversionFacts
    {
        return new ConversionFacts(
            $facts->convId,
            $facts->clickId,
            $facts->campaignId,
            $facts->convTime,
            $facts->value,
            $facts->currency,
            $facts->transactionId,
            ['gclid' => self::TEST_GCLID] + $facts->click,
            $facts->customerRef,
            $facts->emailHashes,
            $facts->phoneHashes,
            $facts->zone,
            $facts->clickTime,
        );
    }

    private static function customerId(mixed $value, string $key, bool $required): ?string
    {
        if (($value === null || $value === '') && !$required) {
            return null;
        }
        $digits = is_string($value) || is_int($value) ? (string) preg_replace('/[\s\-]/', '', (string) $value) : '';
        if (preg_match('/\A[0-9]{10}\z/D', $digits) !== 1) {
            throw new DestinationInputException('settings.' . $key, $key . ' must be a Google Ads customer ID, 10 digits (123-456-7890), as shown at the top of Google Ads.');
        }

        return $digits;
    }

    /** A conversion action's id, or its resource name when another account (a manager) owns it. */
    private static function action(mixed $value, string $field): string
    {
        $text = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : '');
        if (preg_match('/\A[1-9][0-9]{0,18}\z/D', $text) === 1 || preg_match(self::ACTION, $text) === 1) {
            return $text;
        }
        throw new DestinationInputException($field, 'A conversion action is its ID (the ctId in the address of the action in Google Ads › Goals › Conversions), or customers/<id>/conversionActions/<id> for one a manager account owns.');
    }
}
