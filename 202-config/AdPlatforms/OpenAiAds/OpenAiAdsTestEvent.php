<?php

declare(strict_types=1);

namespace Prosper202\AdPlatforms\OpenAiAds;

use Prosper202\AdPlatforms\AdConnectionInputException;
use Prosper202\AdPlatforms\AdPlatformUnavailable;
use Prosper202\AdPlatforms\AdConnectionStore;
use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;
use Prosper202\Destinations\ConversionFacts;
use Prosper202\Destinations\DestinationQueue;
use Prosper202\Destinations\DestinationStore;
use Prosper202\Destinations\OpenAiAdsDestination;
use Prosper202\Destinations\SendResult;

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
 * reason to send another: every send is one more counted conversion. Each
 * send is logged in the destination's delivery log (a test row marked
 * real), and check() reads the list again for an event that log shows was
 * sent at that second, and sends nothing.
 *
 * OpenAI's list (GET /conversions/events) is a sample of the pixel's recent
 * events, at most 50 (`limit`), with no paging and no event id; an event is
 * matched by its channel, type and the second it was stamped with, and the
 * answer says so. Not finding it is never proof it was lost.
 */
final class OpenAiAdsTestEvent
{
    public const SERVER_CHANNEL = 'server_to_server';
    /** Reads of the event stream, a few seconds apart: OpenAI lists an event shortly after it arrives. */
    public const READS = 3;
    /** The most events OpenAI's list answers (its `limit` maximum); it does not page. */
    public const LIST_LIMIT = 50;
    // How far back OpenAI's recent events reach, in seconds.
    public const RECENT_SECONDS = 900;
    public const ARRIVED = 'arrived';
    /** The list was read and does not show the event (yet). */
    public const NOT_LISTED = 'not_listed';
    /** OpenAI took the event, and its list could not be read: nothing is known about the event. */
    public const UNREAD = 'unread';
    /** OpenAI answered 404 to the list: the event stream is off for the ad account, so no check can read it. */
    public const STREAM_OFF = 'stream_off';
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
            self::eventId($destinationId, $now),
            $sample->click,
            pageUrl: $sample->pageUrl
        );
        $result = $this->destination->send(['scope' => 'all'] + $settings, $destination['credentials'], $facts, false, $now);
        $logged = $this->log($userId, $destinationId, $result, $now);
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
        $answer = $this->readBack($key, $pixelId, $type, $now, true, $base);
        if (!$logged && in_array($answer['state'], [self::NOT_LISTED, self::UNREAD], true)) {
            $answer['message'] .= ' It could not be written to the delivery log, so Check again cannot read for'
                . ' it here: look in Ads Manager › Conversions instead.';
        }

        return $answer;
    }

    /**
     * Read OpenAI's recent events again for the real test event this
     * destination sent at $sentAt (send()'s sent_at, which its delivery log
     * holds), and say whether it is listed. Sends nothing.
     *
     * @return array<string, mixed>|null as send() answers
     * @throws AdConnectionInputException when the destination is not one a check can be made for, it logged no real
     *     test event sent at $sentAt, or OpenAI refused the connection's key
     * @throws AdPlatformUnavailable when OpenAI's list could not be read: nothing is known about the event
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
        $queue = new DestinationQueue($this->conn, $this->destinations);
        if ($queue->realTestSent($userId, $destinationId, $sentAt, self::eventId($destinationId, $sentAt)) === null) {
            throw new AdConnectionInputException(
                'check_sent_at',
                'This destination\'s delivery log shows no real test event sent at ' . $sentAt
                    . ' (' . self::when($sentAt) . '): give the sent_at of the answer that sent it (the test rows of'
                    . ' p202 destination deliveries '
                    . $destinationId . ' --kind test). Nothing was read or sent.'
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

    /** The id a real test event is sent under: its destination and second, never a real conversion's. */
    private static function eventId(int $destinationId, int $sentAt): string
    {
        return 'p202-real-test-' . $destinationId . '-' . $sentAt;
    }

    private static function when(int $at): string
    {
        return gmdate('Y-m-d H:i:s', $at) . ' UTC';
    }

    /**
     * The send in the destination's delivery log, which a check reads to know it was sent. False when it could not
     * be written: the event's answer still stands (OpenAI may have counted it), so it is not thrown.
     */
    private function log(int $userId, int $destinationId, SendResult $result, int $now): bool
    {
        try {
            $queue = new DestinationQueue($this->conn, $this->destinations);
            $queue->recordRealTest($userId, $destinationId, $result, $now);

            return true;
        } catch (\Throwable $e) {
            error_log('Prosper202: a real test event of destination ' . $destinationId . ' was not logged: '
                . $e::class . ': ' . $e->getMessage());

            return false;
        }
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
     * OpenAI's recent events for the pixel, up to READS times, for the event sent at $sentAt. A list that was never
     * read says nothing about the event: after a send that is UNREAD; in a check, where nothing was sent, it is thrown.
     *
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     * @throws AdConnectionInputException|AdPlatformUnavailable in a check, when no read of the list succeeded
     */
    private function readBack(string $key, string $pixelId, string $type, int $sentAt, bool $justSent, array $base): array
    {
        $looked = 'a server event (' . self::SERVER_CHANNEL . ') of type ' . $type . ' stamped ' . self::when($sentAt);
        $failed = null;
        $listed = false;
        for ($read = 1; $read <= self::READS; $read++) {
            $answer = $this->api->get($key, 'conversions/events', ['pid' => $pixelId, 'limit' => self::LIST_LIMIT]);
            if ($answer->kind === AdvertiserAnswer::NOT_FOUND) {
                $off = ($justSent ? 'OpenAI took the test event, but answered' : 'OpenAI answered')
                    . ' 404 Not found to reading recent events: the event stream is not switched on for this ad'
                    . ' account, so it cannot be read here. Look for it in Ads Manager › Conversions.';

                return $this->answer(true, false, self::STREAM_OFF, $off, $base);
            }
            if ($answer->isOk()) {
                $listed = true;
                if (self::arrived($answer->json, $type, $sentAt)) {
                    $got = 'OpenAI lists ' . $looked . ' for pixel ' . $pixelId . ': the test event from this server,'
                        . ' known by its type and the second it was sent at (OpenAI\'s list names no event id). It is'
                        . ' saved, and counts as a conversion of the pixel.';

                    return $this->answer(true, true, self::ARRIVED, $got, $base);
                }
            } else {
                $failed = $answer;
                if (in_array($answer->kind, [AdvertiserAnswer::KEY_REFUSED, AdvertiserAnswer::REFUSED], true)) {
                    // Refused, not failed: asking again gets the same answer.
                    break;
                }
            }
            if ($read < self::READS) {
                ($this->sleep)(2);
            }
        }
        $again = ' Check again in a few minutes (that only reads, and sends nothing), or look in Ads Manager ›'
            . ' Conversions. Do not send another test event for this: each one is one more conversion OpenAI counts.';
        if (!$listed && $failed !== null) {
            $why = 'OpenAI\'s answer to reading recent events was: ' . (string) $failed->error;
            if ($justSent) {
                return $this->answer(true, false, self::UNREAD, 'OpenAI took the test event, but its recent events'
                    . ' could not be read, so whether it is listed is not known. ' . $why . '.' . $again, $base);
            }
            if ($failed->kind === AdvertiserAnswer::KEY_REFUSED) {
                throw new AdConnectionInputException('connection', 'OpenAI refused the Advertiser API key of ad'
                    . ' connection ' . $base['connection_id'] . ' to reading recent events, so nothing about the event'
                    . ' is known. '
                    . $why . '. Replace the key (p202 ad-connection update ' . $base['connection_id']
                    . ' --api-key-file <file>). Nothing was sent.');
            }
            $reason = match ($failed->kind) {
                AdvertiserAnswer::RATE_LIMITED => AdPlatformUnavailable::RATE_LIMITED,
                AdvertiserAnswer::UNAVAILABLE => AdPlatformUnavailable::UNREACHABLE,
                default => AdPlatformUnavailable::UNREADABLE,
            };

            throw new AdPlatformUnavailable($reason, 'OpenAI\'s recent events could not be read, so nothing about the'
                . ' event is known: ' . (string) $failed->error . '. Nothing was sent; check again in a minute.');
        }
        $missing = 'OpenAI\'s recent events for pixel ' . $pixelId . ' do not list ' . $looked . ' yet. That does not'
            . ' mean it was lost: the list is a sample of at most ' . self::LIST_LIMIT . ' recent events, and it can'
            . ' lag.' . $again;

        $missing = ($justSent ? 'OpenAI took the test event. ' : '') . $missing;

        return $this->answer(true, false, self::NOT_LISTED, $missing, $base);
    }

    /** @param array<string, mixed> $json */
    private static function arrived(array $json, string $type, int $sentAt): bool
    {
        foreach ((array) ($json['data'] ?? []) as $event) {
            if (!is_array($event)) {
                continue;
            }
            $at = $event['event_timestamp_ms'] ?? null;
            if (($event['api_channel'] ?? null) === self::SERVER_CHANNEL && ($event['event_type'] ?? null) === $type
                && is_int($at) && intdiv($at, 1000) === $sentAt) {
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
