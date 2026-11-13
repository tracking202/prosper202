<?php

declare(strict_types=1);

namespace Prosper202\AdPlatforms\OpenAiAds;

use Prosper202\AdPlatforms\AdConnectionInputException;
use Prosper202\AdPlatforms\AdPlatformUnavailable;
use Prosper202\AdPlatforms\AdConnectionStore;
use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;
use Prosper202\Destinations\ConversionFacts;
use Prosper202\Destinations\DestinationStore;
use Prosper202\Destinations\OpenAiAdsDestination;

/**
 * "Send a real test event" for a ChatGPT Ads destination that a connection
 * reaches (spec 5 C, Test fire): one made-up conversion sent for real (not
 * validate_only), then OpenAI's recent conversion events read back through
 * the connection (GET /conversions/events?pid=) to say whether it arrived
 * as a server event (api_channel server_to_server).
 *
 * A real event is saved by OpenAI and counts as a conversion of the pixel,
 * so the page asks first. It is sent whatever the destination's scope
 * (the test click carries no oppref), and with its own id, so it can never
 * take the id of a real conversion.
 */
final class OpenAiAdsTestEvent
{
    public const SERVER_CHANNEL = 'server_to_server';
    /** Reads of the event stream, a few seconds apart: OpenAI lists an event shortly after it arrives. */
    public const READS = 3;

    /** @var callable(int): void */
    private $sleep;

    /** @param (callable(int): void)|null $sleep seconds */
    public function __construct(
        private readonly Connection $conn,
        private readonly AdConnectionStore $connections,
        private readonly DestinationStore $destinations,
        private readonly AdvertiserApi $api,
        private readonly OpenAiAdsDestination $destination,
        ?callable $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * Null when the account has no such destination.
     *
     * @return array{sent: bool, arrived: bool, event_type: string, pixel_id: string, connection_id: int, message: string, status_code: int|null}|null
     * @throws AdPlatformUnavailable when OpenAI was not reached or is limiting requests: nothing was sent
     */
    public function send(int $userId, int $destinationId, int $now): ?array
    {
        $destination = $this->destinations->forSending($userId, $destinationId);
        if ($destination === null) {
            return null;
        }
        if ($destination['type'] !== OpenAiAdsDestination::TYPE) {
            throw new AdConnectionInputException('destination', 'A real test event is a ChatGPT Ads destination\'s; this one is ' . $destination['type'] . '. Send it a test instead.');
        }
        $settings = $destination['settings'];
        $pixelId = (string) ($settings['pixel_id'] ?? '');
        $connection = $this->connectionFor($userId, $destinationId, $pixelId);
        if ($connection === null) {
            throw new AdConnectionInputException('destination', 'Reading back the event needs a ChatGPT Ads connection to the ad account of pixel ' . $pixelId . ': connect it under Setup › Destinations › ChatGPT Ads (p202 ad-connection create), or send a test, which OpenAI checks without saving.');
        }
        $type = (string) ($settings['event_type'] ?? 'order_created');
        $sample = ConversionFacts::sample($now, $this->currency($userId));
        $facts = new ConversionFacts(
            null,
            0,
            0,
            $now,
            $sample->value,
            $sample->currency,
            'p202-real-test-' . $destinationId . '-' . $now,
            $sample->click,
            pageUrl: $sample->pageUrl
        );
        $result = $this->destination->send(['scope' => 'all'] + $settings, $destination['credentials'], $facts, false, $now);
        $base = ['event_type' => $type, 'pixel_id' => $pixelId, 'connection_id' => $connection['connection_id'], 'status_code' => $result->status];
        if (!$result->delivered && $result->retry) {
            // No verdict on the event: OpenAI was not reached, failed itself or is limiting requests. Nothing was sent.
            $limited = $result->status === 429;
            $why = $result->reason !== '' ? $result->reason : implode(' ', $result->messages);

            throw new AdPlatformUnavailable(
                $limited ? AdPlatformUnavailable::RATE_LIMITED : AdPlatformUnavailable::UNREACHABLE,
                ($limited ? 'OpenAI is limiting requests' : 'OpenAI could not be reached')
                    . ', so no test event was sent: ' . $why . '. Try again in a minute.'
            );
        }
        if (!$result->delivered) {
            return ['sent' => false, 'arrived' => false, 'message' => 'OpenAI did not take the test event: ' . ($result->reason !== '' ? $result->reason : implode(' ', $result->messages))] + $base;
        }
        $key = $connection['credentials']['advertiser_api_key'] ?? '';
        $last = '';
        for ($read = 1; $read <= self::READS; $read++) {
            $answer = $this->api->get($key, 'conversions/events', ['pid' => $pixelId]);
            if ($answer->kind === AdvertiserAnswer::NOT_FOUND) {
                return ['sent' => true, 'arrived' => false, 'message' => 'OpenAI took the test event, but answered 404 Not found to reading recent events: the event stream is not switched on for this ad account. Look for it in Ads Manager › Conversions.'] + $base;
            }
            if (!$answer->isOk()) {
                $last = (string) $answer->error;
            } elseif (self::arrived($answer->json, $type, $now)) {
                return ['sent' => true, 'arrived' => true, 'message' => 'OpenAI received the test event from this server (' . self::SERVER_CHANNEL . ', ' . $type . ', pixel ' . $pixelId . '). It is saved, and counts as a conversion of the pixel.'] + $base;
            } else {
                $last = 'it is not among the recent events yet';
            }
            if ($read < self::READS) {
                ($this->sleep)(2);
            }
        }

        return ['sent' => true, 'arrived' => false, 'message' => 'OpenAI took the test event, but ' . $last . '. Recent events cover the last 15 minutes; try again in a minute, or look in Ads Manager › Conversions.'] + $base;
    }

    /** @param array<string, mixed> $json */
    private static function arrived(array $json, string $type, int $now): bool
    {
        foreach ((array) ($json['data'] ?? []) as $event) {
            if (!is_array($event)) {
                continue;
            }
            $at = $event['event_timestamp_ms'] ?? null;
            if (($event['api_channel'] ?? null) === self::SERVER_CHANNEL && ($event['event_type'] ?? null) === $type
                && is_int($at) && intdiv($at, 1000) === $now) {
                return true;
            }
        }

        return false;
    }

    /**
     * The connection that made this destination, else one whose setup found or was given its pixel.
     *
     * @return array{connection_id: int, credentials: array<string, string>}|null
     */
    private function connectionFor(int $userId, int $destinationId, string $pixelId): ?array
    {
        $byPixel = null;
        foreach ($this->connections->list($userId) as $listed) {
            $setup = (array) $listed['setup'];
            $made = ($setup['destination_id'] ?? null) === $destinationId;
            $pixel = is_array($setup['pixel'] ?? null) && ($setup['pixel']['pixel_id'] ?? null) === $pixelId;
            if (!$made && !$pixel) {
                continue;
            }
            $calling = $this->connections->forCalling($userId, (int) $listed['connection_id']);
            if ($calling === null) {
                continue;
            }
            $found = ['connection_id' => $calling['connection_id'], 'credentials' => $calling['credentials']];
            if ($made) {
                return $found;
            }
            $byPixel ??= $found;
        }

        return $byPixel;
    }

    private function currency(int $userId): string
    {
        $stmt = $this->conn->prepareRead('SELECT user_account_currency FROM `' . TableRegistry::USERS_PREF . '` WHERE user_id = ? LIMIT 1');
        $this->conn->bind($stmt, 'i', [$userId]);
        $currency = $this->conn->fetchOne($stmt)['user_account_currency'] ?? null;

        return is_string($currency) && $currency !== '' ? strtoupper($currency) : 'USD';
    }
}
