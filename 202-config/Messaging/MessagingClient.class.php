<?php

declare(strict_types=1);

/**
 * MessagingClient
 *
 * HTTP transport between a self-hosted Prosper202 install and the central
 * messaging API at my.tracking202.com. All communication is client-initiated
 * (the install can only make outbound requests), so this class only ever POSTs
 * and reads the JSON response.
 *
 * The contract implemented here is documented in 202-config/Messaging/CENTRAL-API.md.
 *
 * Every method returns a decoded associative array on success, or null on any
 * transport/parse/HTTP failure. Callers must treat null as "could not reach the
 * server" and keep their local cache — they must NOT treat it as "no data".
 */
class MessagingClient
{
    private readonly string $baseUrl;
    private readonly int $timeout;
    private readonly int $maxRetries;

    public function __construct()
    {
        // MESSAGING_API_URL is defined in 202-config/connect.php.
        $this->baseUrl    = defined('MESSAGING_API_URL') ? MESSAGING_API_URL : 'https://my.tracking202.com/api/v3/messaging';
        $this->timeout    = 10;
        // Kept low so the synchronous widget-poll path stays responsive when the
        // central server is slow/unreachable; a healthy server answers on the first
        // try. The cron path tolerates the occasional miss and catches up next run.
        $this->maxRetries = 2;
    }

    /**
     * Pull all conversations/messages visible to the identified user.
     *
     * @param array       $identity Identity payload (see buildPayload()).
     * @param string|null $cursor   Opaque cursor from the previous pull, or null.
     * @return array|null Decoded response, or null on failure.
     */
    public function pull(array $identity, ?string $cursor): ?array
    {
        return $this->postJson('pull', [
            'identity' => $identity,
            'cursor'   => $cursor,
        ]);
    }

    /**
     * Send a user-composed message.
     *
     * @param array       $identity               Identity payload.
     * @param string|null $conversationExternalId  Existing thread, or null to start one.
     * @param string      $body                    Plain-text message body.
     * @param string      $clientToken             Idempotency token generated client-side.
     * @return array|null Decoded response, or null on failure.
     */
    public function send(array $identity, ?string $conversationExternalId, string $body, string $clientToken): ?array
    {
        return $this->postJson('send', [
            'identity'                 => $identity,
            'conversation_external_id' => $conversationExternalId,
            'body'                     => $body,
            'client_token'             => $clientToken,
        ]);
    }

    /**
     * Report inbound messages the user has read.
     *
     * @param array         $identity     Identity payload.
     * @param array<string> $externalIds  Message external IDs that were read.
     * @return array|null Decoded response, or null on failure.
     */
    public function markRead(array $identity, array $externalIds): ?array
    {
        return $this->postJson('read', [
            'identity'             => $identity,
            'message_external_ids' => array_values($externalIds),
        ]);
    }

    /**
     * Deliver custom attributes and behavioural events for segmentation.
     *
     * @param array      $identity   Identity payload.
     * @param array      $attributes Latest custom-attribute snapshot.
     * @param array<int,array> $events Queued events (name/metadata/occurred_at/client_token).
     * @return array|null Decoded response, or null on failure.
     */
    public function track(array $identity, array $attributes, array $events): ?array
    {
        return $this->postJson('track', [
            'identity'   => $identity,
            'attributes' => (object) $attributes,
            'events'     => array_values($events),
        ]);
    }

    /**
     * POST a JSON payload to an endpoint and decode the JSON response.
     *
     * @return array|null Decoded array on HTTP 200 with valid JSON, else null.
     */
    private function postJson(string $endpoint, array $payload): ?array
    {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        $body = json_encode($payload);
        if ($body === false) {
            // Encoding our own payload should never fail; if it does, surface it
            // rather than sending an empty/garbage request (CLAUDE.md #1, #4).
            error_log("MessagingClient: failed to encode payload for {$endpoint}: " . json_last_error_msg());
            return null;
        }

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $result = $this->request($url, $body);
            if ($result !== null) {
                return $result;
            }

            if ($attempt < $this->maxRetries) {
                sleep(1); // brief pause before a single retry
            }
        }

        error_log("MessagingClient: failed to reach {$endpoint} after {$this->maxRetries} attempts");
        return null;
    }

    /**
     * Execute a single HTTP POST and validate/decode the response.
     */
    private function request(string $url, string $body): ?array
    {
        $ch = curl_init();
        if ($ch === false) {
            error_log('MessagingClient: failed to initialize cURL');
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => 'Prosper202-Messaging/1.0',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error !== '') {
            error_log("MessagingClient: cURL error for {$url}: {$error}");
            return null;
        }

        if ($httpCode !== 200) {
            error_log("MessagingClient: HTTP {$httpCode} for {$url}");
            return null;
        }

        if (!is_string($response) || $response === '') {
            error_log("MessagingClient: empty response from {$url}");
            return null;
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("MessagingClient: JSON decode error for {$url}: " . json_last_error_msg());
            return null;
        }

        if (!is_array($decoded)) {
            error_log("MessagingClient: non-object response from {$url}");
            return null;
        }

        // The server signals application-level failure with ok:false; treat that
        // as a non-result so the caller keeps its cache.
        if (array_key_exists('ok', $decoded) && $decoded['ok'] === false) {
            $serverError = isset($decoded['error']) ? (string) $decoded['error'] : 'unknown';
            error_log("MessagingClient: server returned ok:false for {$url}: {$serverError}");
            return null;
        }

        return $decoded;
    }
}
