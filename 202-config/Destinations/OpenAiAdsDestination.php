<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

use Prosper202\Attribution\WebhookSender;
use Prosper202\Http\PostedId;

/**
 * ChatGPT Ads through OpenAI's Conversions API: one event per request to
 * POST https://bzr.openai.com/v1/events?pid=<pixel id> with the Conversions
 * API key as a bearer token, through WebhookSender::post() (the guarded,
 * pinned request every outbound webhook uses). One event a request because
 * one bad event fails a whole batch, and the worker answers per row.
 *
 * OpenAI keeps the first event it receives under an id (with the pixel and
 * event name) and takes no adjustment, retraction or refund: a conversion
 * that restates one already sent goes under that one's id and is ignored,
 * which the delivery log says. Events older than 7 days, or more than 10
 * minutes ahead, are refused there, so they are failed here unsent.
 *
 * Which conversions go is the `scope` setting: `oppref` (only those whose
 * click carries OpenAI's click id), `source` (every conversion of one traffic
 * source account) or `all` (every conversion, which lets OpenAI match views).
 */
final class OpenAiAdsDestination implements DestinationType
{
    public const TYPE = 'openai_ads';
    public const HOST = 'bzr.openai.com';
    public const MAX_AGE_SECONDS = 7 * 86400;
    public const MAX_AHEAD_SECONDS = 600;
    public const INTEGRATION_SOURCE = 'prosper202';
    public const SCOPES = ['oppref', 'source', 'all'];
    /** OpenAI's standard event types, each with the `data.type` it carries. */
    public const EVENT_TYPES = [
        'order_created' => 'contents',
        'checkout_started' => 'contents',
        'items_added' => 'contents',
        'contents_viewed' => 'contents',
        'page_viewed' => 'contents',
        'lead_created' => 'customer_action',
        'registration_completed' => 'customer_action',
        'appointment_scheduled' => 'customer_action',
        'subscription_created' => 'plan_enrollment',
        'trial_started' => 'plan_enrollment',
        'custom' => 'custom',
    ];
    private const CUSTOM_NAME = '/\A[a-z0-9]([a-z0-9_-]{0,62}[a-z0-9])?\z/D';

    public function __construct(private ?WebhookSender $sender = null)
    {
    }

    /**
     * The click's OpenAI click id, as stored, unmodified; '' when it has
     * none. The one place the destination reads it.
     */
    public static function oppref(ConversionFacts $facts): string
    {
        $value = $facts->click['oppref'] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    public function settingKeys(): array
    {
        return ['pixel_id', 'event_type', 'custom_event_name', 'event_types', 'scope', 'ppc_account_id'];
    }

    public function credentialKeys(): array
    {
        return ['conversions_api_key'];
    }

    public function host(array $settings): string
    {
        return self::HOST;
    }

    public function backfillDays(): int
    {
        return intdiv(self::MAX_AGE_SECONDS, 86400);
    }

    public function takesConsent(): bool
    {
        return false;
    }

    public function configured(array $settings): bool
    {
        return is_string($settings['pixel_id'] ?? null) && $settings['pixel_id'] !== '';
    }

    public function hashEmail(?string $email): ?string
    {
        return PiiHash::emailPlain($email);
    }

    public function hashPhone(?string $phone): ?string
    {
        return PiiHash::phoneDigits($phone);
    }

    public function label(array $settings): string
    {
        return 'ChatGPT Ads pixel ' . (is_string($settings['pixel_id'] ?? null) ? $settings['pixel_id'] : '');
    }

    public function formSettings(array $post, array $settings): array
    {
        foreach (['pixel_id', 'event_type', 'custom_event_name', 'scope'] as $key) {
            $settings[$key] = DestinationForm::text($post, $key);
        }
        $account = DestinationForm::text($post, 'ppc_account_id');
        if ($account === '') {
            unset($settings['ppc_account_id']);
        } else {
            $settings['ppc_account_id'] = PostedId::read($account) ?? throw new DestinationInputException('settings.ppc_account_id', 'ppc_account_id names a traffic source account from the list.');
        }
        $settings['event_types'] = [];
        foreach (DestinationForm::pairs($post, 'et_campaign', 'et_type', 'settings.event_types', 'A per-campaign event type needs its campaign.') as [$campaign, $type]) {
            $id = PostedId::read($campaign) ?? throw new DestinationInputException('settings.event_types', 'A per-campaign event type names a campaign from the list.');
            if (isset($settings['event_types'][$id])) {
                throw new DestinationInputException('settings.event_types', 'Campaign ' . $id . ' has two event types; keep one.');
            }
            $settings['event_types'][$id] = $type;
        }

        return $settings;
    }

    public function validate(array $settings, ?array $credentials): array
    {
        $pixel = trim(self::text($settings, 'pixel_id', '', 'settings.'));
        if (preg_match('/\A[A-Za-z0-9_\-]{1,128}\z/D', $pixel) !== 1) {
            throw new DestinationInputException('settings.pixel_id', 'pixel_id must be the pixel\'s id from Ads Manager › Conversions (letters, digits, - and _).');
        }
        $type = self::text($settings, 'event_type', 'order_created', 'settings.');
        $type = $type === '' ? 'order_created' : $type;
        if (!isset(self::EVENT_TYPES[$type])) {
            throw new DestinationInputException('settings.event_type', 'event_type must be one of: ' . implode(', ', array_keys(self::EVENT_TYPES)) . '.');
        }
        $custom = trim(self::text($settings, 'custom_event_name', '', 'settings.'));
        if ($type === 'custom') {
            if (preg_match(self::CUSTOM_NAME, $custom) !== 1 || isset(self::EVENT_TYPES[$custom])) {
                throw new DestinationInputException('settings.custom_event_name', 'custom_event_name must be 1-64 lowercase letters, digits, - or _, starting and ending with a letter or digit, and not one of OpenAI\'s standard event types.');
            }
        } elseif ($custom !== '') {
            throw new DestinationInputException('settings.custom_event_name', 'custom_event_name is set only with event_type custom.');
        }
        $scope = self::text($settings, 'scope', 'oppref', 'settings.');
        $scope = $scope === '' ? 'oppref' : $scope;
        if (!in_array($scope, self::SCOPES, true)) {
            throw new DestinationInputException('settings.scope', 'scope must be one of: ' . implode(', ', self::SCOPES) . '.');
        }
        $out = ['pixel_id' => $pixel, 'event_type' => $type, 'scope' => $scope, 'event_types' => []];
        if ($custom !== '') {
            $out['custom_event_name'] = $custom;
        }
        $account = $settings['ppc_account_id'] ?? null;
        if ($scope === 'source') {
            if (!is_int($account) || $account <= 0) {
                throw new DestinationInputException('settings.ppc_account_id', 'scope source needs ppc_account_id, the traffic source account whose conversions are sent (p202 ppc-account list).');
            }
            $out['ppc_account_id'] = $account;
        } elseif ($account !== null) {
            throw new DestinationInputException('settings.ppc_account_id', 'ppc_account_id is set only with scope source.');
        }
        $overrides = $settings['event_types'] ?? [];
        if (!is_array($overrides) || count($overrides) > 500) {
            throw new DestinationInputException('settings.event_types', 'event_types must be an object of at most 500 campaign ids, each with an event type.');
        }
        foreach ($overrides as $campaign => $one) {
            if (!is_int($campaign) && !(is_string($campaign) && preg_match('/\A[1-9][0-9]{0,9}\z/D', $campaign) === 1)) {
                throw new DestinationInputException('settings.event_types', 'event_types is keyed by campaign id (p202 campaign list).');
            }
            if (!is_string($one) || !isset(self::EVENT_TYPES[$one]) || $one === 'custom') {
                throw new DestinationInputException('settings.event_types', 'Campaign ' . $campaign . '\'s event type must be one of OpenAI\'s standard types: ' . implode(', ', array_keys(array_diff_key(self::EVENT_TYPES, ['custom' => true]))) . '.');
            }
            $out['event_types'][(int) $campaign] = $one;
        }
        if ($credentials === null) {
            return [$out, null];
        }
        $key = trim(self::text($credentials, 'conversions_api_key', '', 'credentials.'));
        if (preg_match('/\A[\x21-\x7E]{16,512}\z/D', $key) !== 1) {
            throw new DestinationInputException('credentials.conversions_api_key', 'credentials.conversions_api_key must be a Conversions API key from Ads Manager › Conversions (16 or more characters, no spaces).');
        }

        return [$out, ['conversions_api_key' => $key]];
    }

    public function send(array $settings, array $credentials, ConversionFacts $facts, bool $test, int $now): SendResult
    {
        $key = (string) ($credentials['conversions_api_key'] ?? '');
        if (!$test) {
            $held = self::outOfScope($settings, $facts);
            if ($held !== null) {
                return new SendResult(false, false, null, $held, withheld: true);
            }
        }
        try {
            $body = self::body($settings, $facts, $test ? $now : null);
        } catch (DestinationInputException $e) {
            return new SendResult(false, false, null, $e->getMessage());
        }
        $summary = self::summary($body, $facts);
        $time = $test ? $now : $facts->convTime;
        if ($now - $time > self::MAX_AGE_SECONDS) {
            return new SendResult(false, false, null, 'not sent: the conversion is more than 7 days old, older than OpenAI accepts', $summary);
        }
        if ($time - $now > self::MAX_AHEAD_SECONDS) {
            return new SendResult(false, false, null, 'not sent: the conversion\'s time is more than 10 minutes ahead of this server\'s clock, which OpenAI refuses', $summary);
        }
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $url = 'https://' . self::HOST . '/v1/events?pid=' . rawurlencode((string) $settings['pixel_id']);
        // OpenAI's host, or the loopback stand-in P202_OPENAI_ADS_ENDPOINT names for tests and live passes.
        $this->sender ??= \Prosper202\AdPlatforms\OpenAiAds\AdvertiserApi::eventsSender();
        $sent = $this->sender->post($url, $json, ['Content-Type: application/json', 'Authorization: Bearer ' . $key, 'User-Agent: Prosper202-Destinations/1'], [443]);
        $redact = static fn (string $text): string => $key === '' ? $text : str_replace([$key, rawurlencode($key)], '…', $text);

        if ($sent['refused'] !== null) {
            return new SendResult(false, $sent['retryable'], null, $redact('not sent: ' . $sent['refused']), $summary);
        }
        if ($sent['strayed'] !== null) {
            return new SendResult(false, false, null, 'not sent: the connection went to ' . $sent['strayed'] . ' instead of the checked address ' . $sent['pinned'], $summary);
        }

        return self::answer((int) $sent['status'], (string) $sent['body'], $sent['error'] ?? null, $test, $summary, $redact);
    }

    /**
     * What an answer means: 2xx sent (validated, on a test fire); 400
     * refused with OpenAI's words; 401 and 403 the key; 408, 429, 5xx and
     * no answer tried again by the outbox's rules; anything else failed.
     *
     * @param array<string, mixed> $summary
     * @param \Closure(string): string $redact
     */
    public static function answer(int $status, string $body, ?string $error, bool $test, array $summary, \Closure $redact): SendResult
    {
        if ($status === 0) {
            return new SendResult(false, true, null, $redact('no answer: ' . ($error ?? 'the request failed')), $summary);
        }
        if ($status >= 200 && $status < 300) {
            return new SendResult(true, false, $status, '', $summary);
        }
        $words = self::message($body);
        if ($status === 401 || $status === 403) {
            return new SendResult(false, false, $status, $redact('the Conversions API key was refused (' . $status . ($words !== '' ? ': ' . $words : '') . '); set the key again'), $summary, $test && $words !== '' ? [$redact($words)] : []);
        }
        $retry = $status >= 500 || $status === 408 || $status === 429;

        return new SendResult(
            false,
            $retry,
            $status,
            $redact(($status === 400 ? 'OpenAI refused the event' : 'OpenAI answered ' . $status) . ($words !== '' ? ': ' . $words : '')),
            $summary,
            $test && $words !== '' ? [$redact($words)] : []
        );
    }

    /**
     * Why a conversion is not one the destination sends, or null when it is.
     *
     * @param array<string, mixed> $settings
     */
    public static function outOfScope(array $settings, ConversionFacts $facts): ?string
    {
        return match ($settings['scope'] ?? 'oppref') {
            'all' => null,
            'source' => is_int($settings['ppc_account_id'] ?? null) && $facts->ppcAccountId === $settings['ppc_account_id']
                ? null
                : 'withheld: the click is not from the traffic source account this destination sends (scope source)',
            'oppref' => self::oppref($facts) !== ''
                ? null
                : 'withheld: the click carries no ChatGPT Ads click id (oppref), and this destination sends only those (scope oppref; scope all sends every conversion)',
            default => 'not sent: this destination\'s scope cannot be read; save it again',
        };
    }

    /**
     * The request body: one event. $testTime replaces the conversion's time
     * on a test fire, which also asks OpenAI to validate without saving.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     * @throws DestinationInputException when the event cannot be built (no page, an unknown currency)
     */
    public static function body(array $settings, ConversionFacts $facts, ?int $testTime = null): array
    {
        $type = (string) ($settings['event_types'][$facts->campaignId] ?? $settings['event_type'] ?? 'order_created');
        if (!isset(self::EVENT_TYPES[$type])) {
            throw new DestinationInputException('settings.event_type', 'not sent: the event type "' . $type . '" is not one OpenAI takes; save the destination again');
        }
        $page = $facts->pageUrl;
        if ($page === null || $page === '') {
            throw new DestinationInputException('page_url', 'not sent: no landing page or tracking link is stored for the click, and OpenAI needs the page of a web event');
        }
        $event = [
            'id' => $facts->transactionId,
            'type' => $type,
        ];
        if ($type === 'custom') {
            $event['custom_event_name'] = (string) ($settings['custom_event_name'] ?? '');
        }
        $event['timestamp_ms'] = ($testTime ?? $facts->convTime) * 1000;
        $event['action_source'] = 'web';
        $event['source_url'] = $page;
        $oppref = self::oppref($facts);
        if ($oppref !== '') {
            $event['oppref'] = $oppref;
        }
        $user = array_filter([
            'emails_sha256' => $facts->emailHashes,
            'phone_numbers_sha256' => $facts->phoneHashes,
            // A pseudonymous id, as GA4's user_id: the customer's own ref can be an email address.
            'external_ids_sha256' => $facts->customerId !== null && $facts->customerId > 0 ? [hash('sha256', 'p202c.' . $facts->customerId)] : [],
            'ip_address' => $facts->ipAddress ?? '',
            'user_agent' => $facts->userAgent ?? '',
        ], static fn ($v): bool => $v !== [] && $v !== '');
        if ($user !== []) {
            $event['user'] = $user;
        }
        $data = ['type' => self::EVENT_TYPES[$type]];
        $units = MinorUnits::of($facts->value, $facts->currency);
        if ($units > 0) {
            $data['amount'] = $units;
            $data['currency'] = $facts->currency;
        }
        $event['data'] = $data;
        $body = ['integration_source' => self::INTEGRATION_SOURCE];
        if ($testTime !== null) {
            $body['validate_only'] = true;
        }
        $body['events'] = [$event];

        return $body;
    }

    /**
     * What the log keeps of a body: no identifier's value, and no page (its
     * query can carry what the link put there).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function summary(array $body, ConversionFacts $facts): array
    {
        $event = $body['events'][0] ?? [];
        $summary = [
            'event' => $event['type'] ?? null,
            'id' => $event['id'] ?? null,
            'amount' => $event['data']['amount'] ?? null,
            'currency' => $event['data']['currency'] ?? null,
            'oppref' => isset($event['oppref']),
            'user' => array_keys($event['user'] ?? []),
        ];
        if ($facts->restates) {
            $summary['note'] = 'restates an earlier conversion and goes under its id: when OpenAI already has that event it keeps it, and this amount is not taken';
        }

        return $summary;
    }

    /** OpenAI's error words from an answer, '' when it gave none. */
    private static function message(string $body): string
    {
        try {
            $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }
        $text = is_array($decoded) ? ($decoded['error']['message'] ?? $decoded['message'] ?? null) : null;
        if (!is_string($text)) {
            $text = $body;
        }

        return trim(mb_substr((string) preg_replace('/\s+/', ' ', $text), 0, 200));
    }

    /** A text field, refused when it is not text: a cast would make ["x"] read "Array". */
    private static function text(array $from, string $key, string $default, string $prefix): string
    {
        $value = $from[$key] ?? $default;
        if (!is_string($value)) {
            throw new DestinationInputException($prefix . $key, $key . ' must be text.');
        }

        return $value;
    }
}
