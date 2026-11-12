<?php

declare(strict_types=1);

namespace Prosper202\AdPlatforms\OpenAiAds;

use Prosper202\AdPlatforms\AdConnectionInputException;
use Prosper202\AdPlatforms\AdConnectionStore;
use Prosper202\Destinations\DestinationInputException;
use Prosper202\Destinations\DestinationStore;

/**
 * A ChatGPT Ads (OpenAI Ads) connection: one Advertiser API key, verified
 * with GET /ad_account, and the setup that key can do for the operator,
 * each step shown and skippable:
 *
 * 1. pixel: find the account's web pixel (GET /conversions/pixels) or
 *    create one;
 * 2. conversions_key: create a Conversions API key;
 * 3. destination: an `openai_ads` destination with both, when this version
 *    of Prosper202 has that destination type;
 * 4. event_settings: one event setting per event type, 30-day click window,
 *    tied to the pixel's source.
 *
 * Pixel and key creation can be off for an account: OpenAI then answers 404
 * there, and the step asks for the pixel id or key from Ads Manager and says
 * why. A step that ran before is kept, so setup can be run again.
 *
 * Nothing here holds a database lock across a request to OpenAI.
 */
final class OpenAiAdsConnections
{
    public const PLATFORM = 'openai_ads';
    /** The destination type this setup creates; registered by the ChatGPT Ads destination. */
    public const DESTINATION_TYPE = 'openai_ads';
    public const STEPS = ['pixel', 'conversions_key', 'destination', 'event_settings'];
    /** OpenAI's standard event types an event setting can be made for here (custom ones are made in Ads Manager). */
    public const EVENT_TYPES = [
        'order_created', 'lead_created', 'registration_completed', 'subscription_created', 'trial_started',
        'appointment_scheduled', 'checkout_started', 'items_added', 'contents_viewed', 'page_viewed',
    ];
    public const DEFAULT_EVENT_TYPE = 'order_created';
    public const ATTRIBUTION_WINDOW_DAYS = 30;
    public const PIXEL_NAME = 'Prosper202';
    public const KEY_NAME = 'Prosper202 server events';
    private const MAX_PAGES = 10;
    private const NOT_ENABLED = 'OpenAI answered 404 Not found: %s is not switched on for this ad account. Ask your OpenAI partner representative to enable it, or %s';

    public function __construct(
        private readonly AdConnectionStore $store,
        private readonly AdvertiserApi $api,
        private readonly DestinationStore $destinations,
        private readonly string $installId,
    ) {
    }

    /**
     * Verify the key with GET /ad_account and store the connection. Nothing is stored for a key OpenAI refuses or
     * could not be asked about.
     *
     * @return array<string, mixed> the connection as AdConnectionStore::get() shapes it
     */
    public function connect(int $userId, #[\SensitiveParameter] string $key, ?int $ppcAccountId, int $now): array
    {
        $key = self::advertiserKey($key);
        $account = $this->account($key);
        if (is_string($account)) {
            throw new AdConnectionInputException('api_key', $account);
        }

        return $this->store->create($userId, self::PLATFORM, $ppcAccountId, $account, ['advertiser_api_key' => $key], $now);
    }

    /**
     * Read the account again: name, currency and time zone are refreshed; a refused key marks the connection, and a
     * failed request leaves its status as it was and says why. Null when the account has no such connection.
     *
     * @return array<string, mixed>|null
     */
    public function check(int $userId, int $connectionId, int $now): ?array
    {
        $connection = $this->store->forCalling($userId, $connectionId);
        if ($connection === null) {
            return null;
        }
        $key = $connection['credentials']['advertiser_api_key'] ?? '';
        $answer = $this->api->get($key, 'ad_account');
        if (!$answer->isOk()) {
            // Only a refusal says the key is bad; an outage says nothing about it (CLAUDE.md #11).
            $status = $answer->kind === AdvertiserAnswer::KEY_REFUSED ? AdConnectionStore::STATUS_KEY_REFUSED : null;
            $this->store->recordCheck($userId, $connectionId, null, $status ?? $this->statusOf($userId, $connectionId), (string) $answer->error, $now);

            return $this->store->get($userId, $connectionId);
        }
        $account = self::accountOf($answer);
        if (is_string($account)) {
            $this->store->recordCheck($userId, $connectionId, null, $this->statusOf($userId, $connectionId), $account, $now);
        } elseif ($account['ref'] !== $connection['account_ref']) {
            $this->store->recordCheck($userId, $connectionId, null, AdConnectionStore::STATUS_KEY_REFUSED, 'The stored key now reaches ad account ' . $account['ref'] . ', not ' . $connection['account_ref'] . '; replace it with this account\'s key.', $now);
        } else {
            $this->store->recordCheck($userId, $connectionId, $account, AdConnectionStore::STATUS_CONNECTED, null, $now);
        }

        return $this->store->get($userId, $connectionId);
    }

    /**
     * Replace the Advertiser API key with one that reaches the same ad account. Null when the account has no such
     * connection.
     *
     * @return array<string, mixed>|null
     */
    public function replaceKey(int $userId, int $connectionId, #[\SensitiveParameter] string $key, int $now): ?array
    {
        $connection = $this->store->get($userId, $connectionId);
        if ($connection === null) {
            return null;
        }
        $key = self::advertiserKey($key);
        $account = $this->account($key);
        if (is_string($account)) {
            throw new AdConnectionInputException('api_key', $account);
        }
        if ($account['ref'] !== $connection['account']['id']) {
            throw new AdConnectionInputException('api_key', 'That key reaches ad account ' . $account['ref'] . ', not ' . $connection['account']['id'] . ', which this connection is for; connect it as a new connection instead.');
        }
        $this->store->putCredential($userId, $connectionId, 'advertiser_api_key', $key, $now);
        $this->store->recordCheck($userId, $connectionId, $account, AdConnectionStore::STATUS_CONNECTED, null, $now);

        return $this->store->get($userId, $connectionId);
    }

    /**
     * Run the setup steps. $options: skip (step names), pixel_source_id (which existing pixel), pixel_id with
     * source_id (one from Ads Manager), conversions_api_key (one from Ads Manager), event_types. Null when the
     * account has no such connection.
     *
     * @param array<string, mixed> $options
     * @return array{connection: array<string, mixed>, steps: list<array<string, mixed>>}|null
     */
    public function setup(int $userId, int $connectionId, #[\SensitiveParameter] array $options, int $now): ?array
    {
        $options = self::options($options);
        $connection = $this->store->forCalling($userId, $connectionId);
        if ($connection === null) {
            return null;
        }
        $key = $connection['credentials']['advertiser_api_key'] ?? '';
        $steps = [];
        $skip = static fn (string $step): bool => in_array($step, $options['skip'], true);

        $reread = fn (): array => $this->store->forCalling($userId, $connectionId) ?? throw new \RuntimeException('ad connection ' . $connectionId . ' was deleted during its setup');

        $steps[] = $skip('pixel') ? self::step('pixel', 'skipped', 'Skipped: no pixel was looked for or made.') : $this->pixelStep($userId, $connection, $key, $options, $now);
        $setup = $reread()['setup'];
        $pixel = is_array($setup['pixel'] ?? null) ? $setup['pixel'] : [];

        $steps[] = $skip('conversions_key') ? self::step('conversions_key', 'skipped', 'Skipped: no Conversions API key was made.') : $this->keyStep($userId, $connection, $key, $options, $now);
        $conversionsKey = $reread()['credentials']['conversions_api_key'] ?? null;

        $steps[] = $skip('destination') ? self::step('destination', 'skipped', 'Skipped: no destination was made.') : $this->destinationStep($userId, $connection, $setup, $pixel, $conversionsKey, $options, $now);

        $steps[] = $skip('event_settings') ? self::step('event_settings', 'skipped', 'Skipped: no event settings were made.') : $this->eventSettingsStep($userId, $connection, $key, $setup, $pixel, $options, $now);

        return ['connection' => $this->store->get($userId, $connectionId) ?? throw new \RuntimeException('ad connection ' . $connectionId . ' was deleted during its setup'), 'steps' => $steps];
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function pixelStep(int $userId, array $connection, #[\SensitiveParameter] string $key, array $options, int $now): array
    {
        $id = (int) $connection['connection_id'];
        $stored = is_array($connection['setup']['pixel'] ?? null) ? $connection['setup']['pixel'] : null;
        if ($options['pixel_id'] !== null) {
            $pixel = ['source_id' => $options['source_id'], 'pixel_id' => $options['pixel_id'], 'name' => null, 'how' => 'given'];
            $this->store->mergeSetup($userId, $id, ['pixel' => $pixel], $now);

            return self::step('pixel', 'given', 'Using pixel ' . $options['pixel_id'] . ' from Ads Manager.' . ($options['source_id'] === null ? ' Without its source id (clidsrc_…) no event setting can be made here.' : ''), ['pixel' => $pixel]);
        }
        if ($stored !== null && $options['pixel_source_id'] === null) {
            return self::step('pixel', 'kept', 'Using pixel ' . (string) ($stored['pixel_id'] ?? '') . ', set up before.', ['pixel' => $stored]);
        }
        $listed = $this->pages($key, 'conversions/pixels');
        if (is_string($listed)) {
            return self::step('pixel', 'failed', 'The pixels were not read: ' . $listed);
        }
        if ($listed === null) {
            return self::step('pixel', 'needs_input', sprintf(self::NOT_ENABLED, 'pixel management', 'create the pixel in Ads Manager and give its pixel id and source id (clidsrc_…) here.'));
        }
        $web = [];
        foreach ($listed as $row) {
            $pixel = self::pixelOf($row);
            if ($pixel !== null && ($row['client_type'] ?? null) === 'web') {
                $web[$pixel['source_id']] = $pixel;
            }
        }
        if ($options['pixel_source_id'] !== null) {
            $chosen = $web[$options['pixel_source_id']] ?? null;
            if ($chosen === null) {
                return self::step('pixel', 'needs_input', 'This ad account has no web pixel ' . $options['pixel_source_id'] . '. Its web pixels: ' . self::pixelList($web) . '.', ['pixels' => array_values($web)]);
            }
            $web = [$chosen['source_id'] => $chosen];
        }
        if (count($web) > 1) {
            $ours = array_values(array_filter($web, static fn (array $p): bool => $p['name'] === self::PIXEL_NAME));
            if (count($ours) !== 1) {
                return self::step('pixel', 'needs_input', 'This ad account has ' . count($web) . ' web pixels; say which one: ' . self::pixelList($web) . '.', ['pixels' => array_values($web)]);
            }
            $web = [$ours[0]['source_id'] => $ours[0]];
        }
        if ($web !== []) {
            $pixel = reset($web) + ['how' => 'found'];
            $this->store->mergeSetup($userId, $id, ['pixel' => $pixel], $now);

            return self::step('pixel', 'found', 'Using the account\'s web pixel ' . $pixel['name'] . ' (' . $pixel['pixel_id'] . ').', ['pixel' => $pixel]);
        }
        $created = $this->api->post($key, 'conversions/pixels', ['name' => self::PIXEL_NAME, 'client_type' => 'web'], $this->idempotencyKey($connection, 'pixel'));
        if ($created->kind === AdvertiserAnswer::NOT_FOUND) {
            return self::step('pixel', 'needs_input', sprintf(self::NOT_ENABLED, 'pixel creation', 'create the pixel in Ads Manager and give its pixel id and source id (clidsrc_…) here.'));
        }
        $pixel = $created->isOk() ? self::pixelOf($created->json) : null;
        if ($pixel === null) {
            return self::step('pixel', 'failed', 'The pixel was not created: ' . ($created->error ?? 'OpenAI\'s answer names no pixel id'));
        }
        $pixel += ['how' => 'created'];
        $this->store->mergeSetup($userId, $id, ['pixel' => $pixel], $now);

        return self::step('pixel', 'created', 'Created the web pixel ' . $pixel['name'] . ' (' . $pixel['pixel_id'] . ').', ['pixel' => $pixel]);
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function keyStep(int $userId, array $connection, #[\SensitiveParameter] string $key, array $options, int $now): array
    {
        $id = (int) $connection['connection_id'];
        if ($options['conversions_api_key'] !== null) {
            $this->store->putCredential($userId, $id, 'conversions_api_key', $options['conversions_api_key'], $now);

            return self::step('conversions_key', 'given', 'Stored the Conversions API key from Ads Manager (encrypted, never shown again).');
        }
        if (($connection['credentials']['conversions_api_key'] ?? '') !== '') {
            return self::step('conversions_key', 'kept', 'Using the Conversions API key made before.');
        }
        $created = $this->api->post($key, 'conversions/api_keys', ['name' => self::KEY_NAME], $this->idempotencyKey($connection, 'conversions_key'));
        if ($created->kind === AdvertiserAnswer::NOT_FOUND) {
            return self::step('conversions_key', 'needs_input', sprintf(self::NOT_ENABLED, 'Conversions API key creation', 'make a Conversions API key in Ads Manager and give it here.'));
        }
        $made = $created->json['api_key'] ?? null;
        if (!$created->isOk() || !is_string($made) || $made === '' || preg_match('/[\x00-\x20\x7f]/', $made) === 1) {
            return self::step('conversions_key', 'failed', 'No Conversions API key was made: ' . ($created->error ?? 'OpenAI\'s answer holds no key'));
        }
        $this->store->putCredential($userId, $id, 'conversions_api_key', $made, $now);

        return self::step('conversions_key', 'created', 'Created a Conversions API key named ' . self::KEY_NAME . ' and stored it (encrypted, never shown).');
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $setup
     * @param array<string, mixed> $pixel
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function destinationStep(int $userId, array $connection, array $setup, array $pixel, #[\SensitiveParameter] ?string $conversionsKey, array $options, int $now): array
    {
        $id = (int) $connection['connection_id'];
        $existing = is_int($setup['destination_id'] ?? null) ? $this->destinations->get($userId, $setup['destination_id']) : null;
        if ($existing !== null) {
            return self::step('destination', 'kept', 'Conversions go to destination ' . $existing['destination_id'] . ' (' . $existing['name'] . '), set up before.', ['destination_id' => $existing['destination_id']]);
        }
        if (!isset($this->destinations->types()[self::DESTINATION_TYPE])) {
            return self::step('destination', 'unavailable', 'This version of Prosper202 has no ChatGPT Ads destination yet, so none was made. The pixel and Conversions API key are kept on this connection for it.');
        }
        $pixelId = is_string($pixel['pixel_id'] ?? null) ? $pixel['pixel_id'] : null;
        if ($pixelId === null || $conversionsKey === null || $conversionsKey === '') {
            return self::step('destination', 'needs_input', 'A destination needs the pixel id and a Conversions API key; ' . ($pixelId === null ? 'there is no pixel yet' : 'there is no Conversions API key yet') . '. Run the step above, or give them from Ads Manager.');
        }
        try {
            $made = $this->destinations->create($userId, self::DESTINATION_TYPE, mb_substr('ChatGPT Ads ' . (string) $connection['name'], 0, 100), [
                'pixel_id' => $pixelId,
                'event_type' => $options['event_types'][0] ?? self::DEFAULT_EVENT_TYPE,
            ], ['conversions_api_key' => $conversionsKey], $now);
        } catch (DestinationInputException $e) {
            return self::step('destination', 'failed', 'No destination was made: ' . $e->getMessage());
        }
        $this->store->mergeSetup($userId, $id, ['destination_id' => (int) $made['destination_id']], $now);

        return self::step('destination', 'created', 'Created destination ' . $made['destination_id'] . ' (' . $made['name'] . '): new conversions are sent to ChatGPT Ads.', ['destination_id' => (int) $made['destination_id']]);
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $setup
     * @param array<string, mixed> $pixel
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function eventSettingsStep(int $userId, array $connection, #[\SensitiveParameter] string $key, array $setup, array $pixel, array $options, int $now): array
    {
        $sourceId = is_string($pixel['source_id'] ?? null) ? $pixel['source_id'] : null;
        if ($sourceId === null) {
            return self::step('event_settings', 'needs_input', 'An event setting is tied to the pixel\'s source id (clidsrc_…), and there is none: run the pixel step, give the source id with the pixel id, or make the event settings in Ads Manager.');
        }
        $types = $options['event_types'] !== [] ? $options['event_types'] : $this->mappedEventTypes($userId, $setup);
        $listed = $this->pages($key, 'conversions/event_settings');
        if (is_string($listed)) {
            return self::step('event_settings', 'failed', 'The event settings were not read: ' . $listed);
        }
        $found = [];
        foreach ($listed ?? [] as $row) {
            $type = $row['event_type'] ?? null;
            $id = $row['id'] ?? null;
            if (is_string($type) && is_string($id) && ($row['archived'] ?? false) !== true && in_array($sourceId, (array) ($row['source_ids'] ?? []), true)) {
                $found[$type] ??= ['id' => $id, 'name' => is_string($row['name'] ?? null) ? $row['name'] : ''];
            }
        }
        $made = [];
        $said = [];
        $failed = false;
        foreach ($types as $type) {
            if (isset($found[$type])) {
                $made[$type] = $found[$type] + ['how' => 'found'];
                $said[] = $type . ': found ' . $found[$type]['id'];
                continue;
            }
            $created = $this->api->post($key, 'conversions/event_settings', [
                'name' => 'Prosper202 ' . str_replace('_', ' ', $type),
                'event_type' => $type,
                'attribution_window_days' => self::ATTRIBUTION_WINDOW_DAYS,
                'source_ids' => [$sourceId],
            ], $this->idempotencyKey($connection, 'event_setting|' . $sourceId . '|' . $type));
            $id = $created->json['id'] ?? null;
            if (!$created->isOk() || !is_string($id) || $id === '') {
                $failed = true;
                $said[] = $type . ': not made (' . ($created->error ?? 'OpenAI\'s answer names no event setting') . ')';
                continue;
            }
            $made[$type] = ['id' => $id, 'name' => is_string($created->json['name'] ?? null) ? $created->json['name'] : '', 'how' => 'created'];
            $said[] = $type . ': created ' . $id;
        }
        if ($made !== []) {
            $this->store->mergeSetup($userId, (int) $connection['connection_id'], ['event_settings' => $made], $now);
        }
        $outcome = $failed ? 'failed' : (array_filter($made, static fn (array $m): bool => $m['how'] === 'created') !== [] ? 'created' : 'found');

        return self::step('event_settings', $outcome, implode('; ', $said) . '. Each counts clicks for ' . self::ATTRIBUTION_WINDOW_DAYS . ' days.', ['event_settings' => $made]);
    }

    /**
     * The event types the connection's destination sends (its event_type and per-campaign event_types), else the
     * default.
     *
     * @param array<string, mixed> $setup
     * @return list<string>
     */
    private function mappedEventTypes(int $userId, array $setup): array
    {
        $destination = is_int($setup['destination_id'] ?? null) ? $this->destinations->get($userId, $setup['destination_id']) : null;
        $settings = is_array($destination['settings'] ?? null) ? $destination['settings'] : [];
        $types = [];
        foreach ([$settings['event_type'] ?? null, ...array_values(is_array($settings['event_types'] ?? null) ? $settings['event_types'] : [])] as $type) {
            if (is_string($type) && in_array($type, self::EVENT_TYPES, true)) {
                $types[$type] = $type;
            }
        }

        return $types === [] ? [self::DEFAULT_EVENT_TYPE] : array_values($types);
    }

    /**
     * Every row of a list endpoint, page by page; null when OpenAI answers 404 (the feature is off), a string when it
     * could not be read.
     *
     * @return list<array<string, mixed>>|string|null
     */
    private function pages(#[\SensitiveParameter] string $key, string $path): array|string|null
    {
        $rows = [];
        $after = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $answer = $this->api->get($key, $path, ['limit' => 500] + ($after === null ? [] : ['after' => $after]));
            if ($answer->kind === AdvertiserAnswer::NOT_FOUND) {
                return null;
            }
            if (!$answer->isOk()) {
                return (string) $answer->error;
            }
            $data = $answer->json['data'] ?? null;
            if (!is_array($data) || !array_is_list($data)) {
                return 'OpenAI\'s answer has no list of data';
            }
            foreach ($data as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
            $last = $answer->json['last_id'] ?? null;
            if (($answer->json['has_more'] ?? false) !== true || !is_string($last) || $last === '' || $last === $after) {
                return $rows;
            }
            $after = $last;
        }

        return 'there are more than ' . (self::MAX_PAGES * 500) . ' of them; make this one in Ads Manager';
    }

    /**
     * The account the key reaches, or why it could not be read.
     *
     * @return array{ref: string, name: string, currency: string, timezone: string}|string
     */
    private function account(#[\SensitiveParameter] string $key): array|string
    {
        $answer = $this->api->get($key, 'ad_account');
        if ($answer->kind === AdvertiserAnswer::KEY_REFUSED) {
            return 'OpenAI refused that key (' . $answer->error . '). Use an Advertiser API key from Ads Manager › Settings for the ad account you mean.';
        }
        if (!$answer->isOk()) {
            return 'The key could not be checked, so nothing was saved: ' . $answer->error;
        }

        return self::accountOf($answer);
    }

    /** @return array{ref: string, name: string, currency: string, timezone: string}|string */
    private static function accountOf(AdvertiserAnswer $answer): array|string
    {
        $json = $answer->json;
        foreach (['id', 'name', 'currency_code', 'timezone'] as $field) {
            if (!is_string($json[$field] ?? null) || ($field !== 'name' && $json[$field] === '')) {
                return 'OpenAI\'s description of the ad account has no ' . $field . ', so nothing was saved.';
            }
        }
        $readable = preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $json['id']) === 1 && preg_match('/\A[A-Z]{3}\z/D', $json['currency_code']) === 1
            && preg_match('#\A[A-Za-z0-9_+/-]{1,64}\z#D', $json['timezone']) === 1;
        if (!$readable) {
            return 'OpenAI\'s description of the ad account has an id, currency or time zone this cannot read, so nothing was saved.';
        }

        return ['ref' => $json['id'], 'name' => $json['name'], 'currency' => $json['currency_code'], 'timezone' => $json['timezone']];
    }

    private function statusOf(int $userId, int $connectionId): string
    {
        $status = $this->store->get($userId, $connectionId)['status'] ?? AdConnectionStore::STATUS_CONNECTED;

        return $status === AdConnectionStore::STATUS_UNREADABLE ? AdConnectionStore::STATUS_CONNECTED : (string) $status;
    }

    /**
     * Same install, connection, account and thing made: same key, so a retry after a lost answer returns what the
     * first request made. Fields are length-prefixed, so no two inputs give one key (CLAUDE.md #17).
     *
     * @param array<string, mixed> $connection
     */
    private function idempotencyKey(array $connection, string $what): string
    {
        $parts = [$this->installId, (string) $connection['connection_id'], (string) $connection['account_ref'], $what];

        return 'p202-' . substr(hash('sha256', implode('', array_map(static fn (string $p): string => strlen($p) . ':' . $p, $parts))), 0, 40);
    }

    /** @return array{source_id: string, pixel_id: string, name: string}|null */
    private static function pixelOf(mixed $row): ?array
    {
        if (!is_array($row) || !is_string($row['id'] ?? null) || !is_string($row['pixel_id'] ?? null) || $row['id'] === '' || $row['pixel_id'] === '') {
            return null;
        }

        return ['source_id' => $row['id'], 'pixel_id' => $row['pixel_id'], 'name' => is_string($row['name'] ?? null) ? $row['name'] : ''];
    }

    /** @param array<string, array{source_id: string, pixel_id: string, name: string}> $pixels */
    private static function pixelList(array $pixels): string
    {
        return $pixels === [] ? 'none' : implode(', ', array_map(static fn (array $p): string => $p['name'] . ' (' . $p['source_id'] . ')', $pixels));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function step(string $step, string $outcome, string $detail, array $extra = []): array
    {
        return ['step' => $step, 'outcome' => $outcome, 'detail' => $detail] + $extra;
    }

    public static function advertiserKey(#[\SensitiveParameter] mixed $key): string
    {
        if (!is_string($key) || preg_match('/\A[\x21-\x7e]{8,512}\z/D', trim($key)) !== 1) {
            throw new AdConnectionInputException('api_key', 'api_key is required: the Advertiser API key from Ads Manager › Settings, 8 to 512 characters with no spaces.');
        }

        return trim($key);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{skip: list<string>, pixel_source_id: ?string, pixel_id: ?string, source_id: ?string, conversions_api_key: ?string, event_types: list<string>}
     */
    public static function options(#[\SensitiveParameter] array $options): array
    {
        $known = ['skip', 'pixel_source_id', 'pixel_id', 'source_id', 'conversions_api_key', 'event_types'];
        foreach (array_keys($options) as $name) {
            if (!in_array($name, $known, true)) {
                throw new AdConnectionInputException((string) $name, $name . ' is not a setup option; the options are ' . implode(', ', $known) . '.');
            }
        }
        $skip = $options['skip'] ?? [];
        if (!is_array($skip) || !array_is_list($skip) || array_diff($skip, self::STEPS) !== [] || count(array_filter($skip, 'is_string')) !== count($skip)) {
            throw new AdConnectionInputException('skip', 'skip must be a list of steps, each one of: ' . implode(', ', self::STEPS) . '.');
        }
        $id = static function (string $name, string $pattern, string $what) use ($options): ?string {
            if (!array_key_exists($name, $options) || $options[$name] === null) {
                return null;
            }
            if (!is_string($options[$name]) || preg_match($pattern, trim($options[$name])) !== 1) {
                throw new AdConnectionInputException($name, $name . ' must be ' . $what . '.');
            }

            return trim($options[$name]);
        };
        $sourcePattern = '/\A[A-Za-z0-9_-]{1,64}\z/D';
        $parsed = [
            'skip' => array_values(array_unique($skip)),
            'pixel_source_id' => $id('pixel_source_id', $sourcePattern, 'a pixel\'s source id (clidsrc_…) from this ad account'),
            'pixel_id' => $id('pixel_id', $sourcePattern, 'the pixel id Ads Manager shows, up to 64 letters, digits, _ or -'),
            'source_id' => $id('source_id', $sourcePattern, 'the pixel\'s source id (clidsrc_…)'),
            'conversions_api_key' => $id('conversions_api_key', '/\A[\x21-\x7e]{8,512}\z/D', 'a Conversions API key, 8 to 512 characters with no spaces'),
            'event_types' => [],
        ];
        if ($parsed['source_id'] !== null && $parsed['pixel_id'] === null) {
            throw new AdConnectionInputException('source_id', 'source_id goes with pixel_id: give both from Ads Manager, or leave both out to find or make the pixel here.');
        }
        if ($parsed['pixel_id'] !== null && $parsed['pixel_source_id'] !== null) {
            throw new AdConnectionInputException('pixel_source_id', 'Give pixel_source_id (a pixel this account lists) or pixel_id (one from Ads Manager), not both.');
        }
        $types = $options['event_types'] ?? [];
        if (!is_array($types) || !array_is_list($types) || count($types) > count(self::EVENT_TYPES)) {
            throw new AdConnectionInputException('event_types', 'event_types must be a list of event types, each one of: ' . implode(', ', self::EVENT_TYPES) . '.');
        }
        foreach ($types as $type) {
            if (!is_string($type) || !in_array($type, self::EVENT_TYPES, true)) {
                throw new AdConnectionInputException('event_types', 'event_types must hold event types, each one of: ' . implode(', ', self::EVENT_TYPES) . ' (a custom event setting is made in Ads Manager).');
            }
            $parsed['event_types'][] = $type;
        }
        $parsed['event_types'] = array_values(array_unique($parsed['event_types']));

        return $parsed;
    }
}
