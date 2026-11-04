<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

use Prosper202\Attribution\WebhookSender;

/**
 * Google Analytics 4 through the Measurement Protocol: one event per
 * conversion, posted through WebhookSender::post() (the guarded, pinned
 * request every outbound webhook uses). /mp/collect answers 2xx even for an
 * event it drops, so a test fire goes to /debug/mp/collect, which says what
 * is wrong and records nothing.
 */
final class Ga4Destination implements DestinationType
{
    public const TYPE = 'ga4';
    public const HOST = 'www.google-analytics.com';
    /** GA4 takes events up to 72 hours old. */
    public const MAX_AGE_SECONDS = 72 * 3600;
    public const CLIENT_ID_SOURCES = ['click', 'c1', 'c2', 'c3', 'c4'];
    public const USER_ID_SOURCES = ['none', 'customer'];
    /** Click fields an event parameter may carry; c1-c4, keyword and utm_* hold what your links put there. */
    public const PARAM_FIELDS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'keyword', 'c1', 'c2', 'c3', 'c4', 'campaign_id', 'click_id'];
    private const RESERVED_EVENTS = [
        'ad_activeview', 'ad_click', 'ad_exposure', 'ad_query', 'ad_reward', 'adunit_exposure', 'app_background', 'app_clear_data',
        'app_exception', 'app_remove', 'app_store_refund', 'app_store_subscription_cancel', 'app_store_subscription_convert',
        'app_store_subscription_renew', 'app_update', 'app_upgrade', 'dynamic_link_app_open', 'dynamic_link_app_update',
        'dynamic_link_first_open', 'error', 'first_open', 'first_visit', 'in_app_purchase', 'notification_dismiss',
        'notification_foreground', 'notification_open', 'notification_receive', 'os_update', 'session_start',
        'session_start_with_rollout', 'user_engagement',
    ];
    private const NAME = '/\A[A-Za-z][A-Za-z0-9_]{0,39}\z/D';

    public function __construct(private ?WebhookSender $sender = null)
    {
    }

    public function settingKeys(): array
    {
        return ['measurement_id', 'event_name', 'client_id_source', 'user_id_source', 'params'];
    }

    public function credentialKeys(): array
    {
        return ['api_secret'];
    }

    public function host(array $settings): string
    {
        return self::HOST;
    }

    public function validate(array $settings, ?array $credentials): array
    {
        $id = strtoupper(trim((string) ($settings['measurement_id'] ?? '')));
        if (preg_match('/\AG-[A-Z0-9]{4,20}\z/D', $id) !== 1) {
            throw new DestinationInputException('settings.measurement_id', 'measurement_id must be the stream\'s Measurement ID, G- followed by letters and digits (Admin › Data streams).');
        }
        $event = trim((string) ($settings['event_name'] ?? 'purchase'));
        if (
            preg_match(self::NAME, $event) !== 1 || in_array(strtolower($event), self::RESERVED_EVENTS, true)
            || preg_match('/\A(firebase_|ga_|google_|gtag)/i', $event) === 1
        ) {
            throw new DestinationInputException('settings.event_name', 'event_name must be 1-40 letters, digits or underscores, start with a letter, and not be a name GA4 reserves.');
        }
        $out = [
            'measurement_id' => $id,
            'event_name' => $event,
            'client_id_source' => self::oneOf($settings, 'client_id_source', self::CLIENT_ID_SOURCES),
            'user_id_source' => self::oneOf($settings, 'user_id_source', self::USER_ID_SOURCES),
            'params' => [],
        ];
        $params = $settings['params'] ?? [];
        if (!is_array($params) || count($params) > 20) {
            throw new DestinationInputException('settings.params', 'params must be an object of at most 20 event parameter names, each naming a click field.');
        }
        foreach ($params as $name => $field) {
            if (!is_string($name) || preg_match(self::NAME, $name) !== 1 || in_array($name, ['value', 'currency', 'transaction_id'], true)) {
                throw new DestinationInputException('settings.params', 'Parameter "' . $name . '" is not a name GA4 takes (1-40 letters, digits or underscores), or is one Prosper202 sets itself (value, currency, transaction_id).');
            }
            if (!is_string($field) || !in_array($field, self::PARAM_FIELDS, true)) {
                throw new DestinationInputException('settings.params', 'Parameter "' . $name . '" must name one of: ' . implode(', ', self::PARAM_FIELDS) . '.');
            }
            $out['params'][$name] = $field;
        }
        if ($credentials === null) {
            return [$out, null];
        }
        $secret = trim((string) ($credentials['api_secret'] ?? ''));
        if (preg_match('/\A[A-Za-z0-9_\-]{8,100}\z/D', $secret) !== 1) {
            throw new DestinationInputException('credentials.api_secret', 'credentials.api_secret must be the stream\'s Measurement Protocol API secret (Admin › Data streams › Measurement Protocol API secrets).');
        }

        return [$out, ['api_secret' => $secret]];
    }

    public function send(array $settings, array $credentials, ConversionFacts $facts, bool $test, int $now): SendResult
    {
        $secret = (string) ($credentials['api_secret'] ?? '');
        try {
            $body = self::body($settings, $facts, $test ? $now : null);
        } catch (DestinationInputException $e) {
            return new SendResult(false, false, null, $e->getMessage());
        }
        $summary = self::summary($body);
        if (!$test && $now - $facts->convTime > self::MAX_AGE_SECONDS) {
            return new SendResult(false, false, null, 'not sent: the conversion is more than 72 hours old, which GA4 no longer takes', $summary);
        }
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $url = 'https://' . self::HOST . ($test ? '/debug/mp/collect' : '/mp/collect')
            . '?measurement_id=' . rawurlencode((string) $settings['measurement_id']) . '&api_secret=' . rawurlencode($secret);
        $this->sender ??= new WebhookSender();
        $sent = $this->sender->post($url, $json, ['Content-Type: application/json', 'User-Agent: Prosper202-Destinations/1'], [443]);
        $redact = static fn (string $text): string => $secret === '' ? $text : str_replace([$secret, rawurlencode($secret)], '…', $text);

        if ($sent['refused'] !== null) {
            return new SendResult(false, false, null, $redact('not sent: ' . $sent['refused']), $summary);
        }
        if ($sent['strayed'] !== null) {
            return new SendResult(false, false, null, 'not sent: the connection went to ' . $sent['strayed'] . ' instead of the checked address ' . $sent['pinned'], $summary);
        }
        $status = $sent['status'];
        if ($status === 0) {
            return new SendResult(false, true, null, $redact('no answer: ' . ($sent['error'] ?? 'the request failed')), $summary);
        }
        if ($status < 200 || $status >= 300) {
            $snippet = trim(mb_substr((string) preg_replace('/\s+/', ' ', $sent['body']), 0, 200));

            return new SendResult(
                false,
                $status >= 500 || $status === 408 || $status === 429,
                $status,
                $redact('GA4 answered ' . $status . ($snippet !== '' ? ': ' . $snippet : '')),
                $summary
            );
        }
        if (!$test) {
            return new SendResult(true, false, $status, '', $summary);
        }
        $messages = self::validationMessages($sent['body']);
        if ($messages === null) {
            return new SendResult(false, false, $status, 'GA4\'s validation endpoint gave an answer that is not its JSON', $summary);
        }

        return new SendResult($messages === [], false, $status, $messages === [] ? '' : implode(' ', $messages), $summary, $messages);
    }

    /**
     * The Measurement Protocol body. $testTime replaces the conversion's
     * time on a test fire, whose sample conversion happens now.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     * @throws DestinationInputException when the click carries no client id where the settings say it does
     */
    public static function body(array $settings, ConversionFacts $facts, ?int $testTime = null): array
    {
        $source = (string) ($settings['client_id_source'] ?? 'click');
        $clientId = $source === 'click' || $facts->convId === null
            ? 'p202.' . $facts->clickId
            : trim((string) ($facts->click[$source] ?? ''));
        if ($clientId === '') {
            throw new DestinationInputException('settings.client_id_source', 'not sent: the click has no GA client id in ' . $source);
        }
        $params = [
            'currency' => $facts->currency,
            'value' => round((float) $facts->value, 2),
            'transaction_id' => $facts->transactionId,
        ];
        $fields = ['keyword' => $facts->click['t202kw'] ?? '', 'campaign_id' => (string) $facts->campaignId, 'click_id' => (string) $facts->clickId] + $facts->click;
        foreach ((array) ($settings['params'] ?? []) as $name => $field) {
            $value = trim((string) ($fields[$field] ?? ''));
            if ($value !== '') {
                // GA4 keeps 100 characters of a parameter value.
                $params[$name] = mb_substr($value, 0, 100);
            }
        }
        $body = ['client_id' => $clientId];
        if (($settings['user_id_source'] ?? 'none') === 'customer' && $facts->customerRef !== null && $facts->customerRef !== '') {
            $body['user_id'] = mb_substr($facts->customerRef, 0, 256);
        }
        $body['timestamp_micros'] = ($testTime ?? $facts->convTime) * 1_000_000;
        $consent = [];
        foreach (['ad_user_data', 'ad_personalization'] as $signal) {
            $stated = $settings['consent'][$signal] ?? null;
            if (is_string($stated)) {
                $consent[$signal] = strtoupper($stated);
            }
        }
        if ($consent !== []) {
            $body['consent'] = $consent;
        }
        $userData = array_filter(['sha256_email_address' => $facts->emailHashes, 'sha256_phone_number' => $facts->phoneHashes]);
        if ($userData !== []) {
            $body['user_data'] = $userData;
        }
        $body['events'] = [['name' => (string) ($settings['event_name'] ?? 'purchase'), 'params' => $params]];

        return $body;
    }

    /**
     * What the log keeps of a body: everything but the identifiers' values.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function summary(array $body): array
    {
        $event = $body['events'][0] ?? [];

        return [
            'event' => $event['name'] ?? null,
            'params' => $event['params'] ?? [],
            'client_id' => $body['client_id'] ?? null,
            'user_id' => isset($body['user_id']),
            'user_data' => array_keys($body['user_data'] ?? []),
            'consent' => $body['consent'] ?? null,
        ];
    }

    /** @return list<string>|null the debug endpoint's messages, null when its answer is not its JSON */
    private static function validationMessages(string $answer): ?array
    {
        try {
            $decoded = json_decode($answer, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded) || !is_array($decoded['validationMessages'] ?? null)) {
            return null;
        }
        $out = [];
        foreach ($decoded['validationMessages'] as $m) {
            $out[] = trim((is_array($m) ? (string) ($m['fieldPath'] ?? '') . ': ' . (string) ($m['description'] ?? '') : ''), ': ');
        }

        return $out;
    }

    /** @param list<string> $allowed the first is the default */
    private static function oneOf(array $settings, string $key, array $allowed): string
    {
        $value = $settings[$key] ?? $allowed[0];
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new DestinationInputException('settings.' . $key, $key . ' must be one of: ' . implode(', ', $allowed) . '.');
        }

        return $value;
    }
}
