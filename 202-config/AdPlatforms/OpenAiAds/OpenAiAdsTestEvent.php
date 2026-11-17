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
 *
 * An event OpenAI took but does not list yet is not a failure and not a
 * reason to send another: every send is one more counted conversion.
 * check() reads the list again for the event already sent (by the second it
 * was sent at, which send() answers as sent_at) and sends nothing.
 */
final class OpenAiAdsTestEvent
{
    public const SERVER_CHANNEL = 'server_to_server';
    /** Reads of the event stream, a few seconds apart: OpenAI lists an event shortly after it arrives. */
    public const READS = 3;
    // How far back OpenAI's recent events reach, in seconds.
    public const RECENT_SECONDS = 900;
    public const ARRIVED = 'arrived';
    public const NOT_LISTED = 'not_listed';
    public const TOO_LATE = 'too_late';
    public const NOT_SENT = 'not_sent';

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
     * @return array<string, mixed>|null sent, arrived, state, sent_at, event_type, pixel_id, connection_id, message,
     *     status_code
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
        $base = [
            'sent_at' => $now,
            'event_type' => $type,
            'pixel_id' => $pixelId,
            'connection_id' => $connection['connection_id'],
            'status_code' => $result->status,
        ];
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
            $why = $result->reason !== '' ? $result->reason : implode(' ', $result->messages);

            return $this->answer(false, false, self::NOT_SENT, 'OpenAI did not take the test event: ' . $why, $base);
        }

        $key = $connection['credentials']['advertiser_api_key'] ?? '';

        return $this->readBack($key, $pixelId, $type, $now, true, $base);
    }

    /**
     * Read OpenAI's recent events again for a real test event sent at $sentAt
     * (send()'s sent_at), and say whether it is listed. Sends nothing.
     *
     * @return array<string, mixed>|null as send() answers
     * @throws AdConnectionInputException when the destination is not one a check can be made for, or $sentAt is not
     *     a time in the past
     */
    public function check(int $userId, int $destinationId, int $sentAt, int $now): ?array
    {
        $destination = $this->destinations->forSending($userId, $destinationId);
        if ($destination === null) {
            return null;
        }
        if ($destination['type'] !== OpenAiAdsDestination::TYPE) {
            throw new AdConnectionInputException(
                'destination',
                'A real test event is a ChatGPT Ads destination\'s; this one is ' . $destination['type'] . '.'
            );
        }
        if ($sentAt <= 0 || $sentAt > $now) {
            throw new AdConnectionInputException(
                'check_sent_at',
                'The time the real test event was sent at (sent_at in the answer that sent it), in Unix seconds and not'
                    . ' in the future.'
            );
        }
        $settings = $destination['settings'];
        $pixelId = (string) ($settings['pixel_id'] ?? '');
        $connection = $this->connectionFor($userId, $destinationId, $pixelId);
        if ($connection === null) {
            throw new AdConnectionInputException(
                'destination',
                'Reading the event needs a ChatGPT Ads connection to the ad account of pixel ' . $pixelId
                    . ': connect it under Setup › Destinations › ChatGPT Ads (p202 ad-connection create).'
            );
        }
        $type = (string) ($settings['event_type'] ?? 'order_created');
        $base = [
            'sent_at' => $sentAt,
            'event_type' => $type,
            'pixel_id' => $pixelId,
            'connection_id' => $connection['connection_id'],
            'status_code' => null,
        ];
        if ($now - $sentAt > self::RECENT_SECONDS) {
            $late = 'That event was sent more than 15 minutes ago, which is further back than OpenAI\'s recent events'
                . ' reach, so it cannot be read here any more. Look for it in Ads Manager › Conversions. Nothing was'
                . ' sent now.';

            return $this->answer(true, false, self::TOO_LATE, $late, $base);
        }
        $key = $connection['credentials']['advertiser_api_key'] ?? '';

        return $this->readBack($key, $pixelId, $type, $sentAt, false, $base);
    }

    /**
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function answer(bool $sent, bool $arrived, string $state, string $message, array $base): array
    {
        return ['sent' => $sent, 'arrived' => $arrived, 'state' => $state, 'message' => $message] + $base;
    }

    /**
     * OpenAI's recent events for the pixel, up to READS times, for the event sent at $sentAt.
     *
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function readBack(string $key, string $pixelId, string $type, int $sentAt, bool $justSent, array $base): array
    {
        $took = $justSent ? 'OpenAI took the test event, but ' : 'OpenAI took the event, but ';
        $last = '';
        for ($read = 1; $read <= self::READS; $read++) {
            $answer = $this->api->get($key, 'conversions/events', ['pid' => $pixelId]);
            if ($answer->kind === AdvertiserAnswer::NOT_FOUND) {
                $off = ($justSent ? 'OpenAI took the test event, but answered' : 'OpenAI answered')
                    . ' 404 Not found to reading recent events: the event stream is not switched on for this ad'
                    . ' account. Look for it in Ads Manager › Conversions.';

                return $this->answer(true, false, self::NOT_LISTED, $off, $base);
            }
            if (!$answer->isOk()) {
                $last = 'OpenAI\'s answer to reading recent events was: ' . (string) $answer->error;
            } elseif (self::arrived($answer->json, $type, $sentAt)) {
                $got = 'OpenAI received the test event from this server (' . self::SERVER_CHANNEL . ', ' . $type
                    . ', pixel ' . $pixelId . '). It is saved, and counts as a conversion of the pixel.';

                return $this->answer(true, true, self::ARRIVED, $got, $base);
            } else {
                $last = 'it is not among the recent events yet';
            }
            if ($read < self::READS) {
                ($this->sleep)(2);
            }
        }
        $lag = $took . $last . '. That does not mean it was lost: the list can lag. Check again in a few minutes'
            . ' (that only reads, and sends nothing), or look in Ads Manager › Conversions. Do not send another test'
            . ' event for this: each one is one more conversion OpenAI counts.';

        return $this->answer(true, false, self::NOT_LISTED, $lag, $base);
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
