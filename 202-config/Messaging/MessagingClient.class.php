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
        // Every request body below carries the install's customer API key and the
        // user's email, so refuse to speak cleartext even if MESSAGING_API_URL is
        // misconfigured (mirrors Lpo\PairingClient's guard).
        $configuredUrl = defined('MESSAGING_API_URL') ? MESSAGING_API_URL : 'https://my.tracking202.com/api/v3/messaging';
        if (!self::isSafeTransport((string) $configuredUrl)) {
            throw new \RuntimeException('MESSAGING_API_URL must be an https:// URL (http:// is allowed only for loopback); refusing to send credentials in cleartext.');
        }
        $this->baseUrl    = $configuredUrl;
        $this->timeout    = 10;
        // Kept low so the synchronous widget-poll path stays responsive when the
        // central server is slow/unreachable; a healthy server answers on the first
        // try. The cron path tolerates the occasional miss and catches up next run.
        $this->maxRetries = 2;
    }

    /**
     * Credentials must not cross a network in cleartext, so https is required —
     * except against loopback, which never leaves the host. The carve-out exists
     * because 202-config/Messaging/mock-server.php and the comment at
     * connect.php:86 both document MESSAGING_API_URL=http://127.0.0.1:8787/messaging
     * for local development; rejecting it made the repo's own documented setup
     * throw out of the constructor, which the messaging AJAX endpoints surface as
     * a bare 500 instead of degrading gracefully.
     */
    private static function isSafeTransport(string $url): bool
    {
        $url = strtolower(trim($url));
        if (str_starts_with($url, 'https://')) {
            return true;
        }
        if (!str_starts_with($url, 'http://')) {
            return false;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return false;
        }
        if ($host === 'localhost' || $host === '::1' || $host === '[::1]') {
            return true;
        }

        // 127.0.0.0/8 only — not every RFC1918 address, which does traverse a network.
        return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && str_starts_with($host, '127.');
    }

    /**
     * The curl protocol allowlist, derived from the same predicate the
     * constructor enforces: HTTPS always, plus HTTP only for a URL
     * isSafeTransport() would accept as cleartext — i.e. loopback.
     *
     * The two must agree in both directions. Narrower than the transport rule
     * makes the loopback carve-out dead code: the constructor accepts the
     * documented mock-server URL and then every request fails with
     * CURLE_UNSUPPORTED_PROTOCOL. Wider lets a misconfigured MESSAGING_API_URL
     * carry the install's customer API key over cleartext.
     *
     * isSafeTransport() is re-run here rather than assumed. Keying only on the
     * http:// prefix would be correct today purely because the constructor
     * throws first — a fail-open that any future caller reaching this method by
     * another path (or any relaxation of that constructor check) inherits
     * silently, which is exactly how the mismatch above got in.
     */
    private static function allowedCurlProtocols(string $url): int
    {
        if (str_starts_with(strtolower(trim($url)), 'http://') && self::isSafeTransport($url)) {
            return CURLPROTO_HTTPS | CURLPROTO_HTTP;
        }

        return CURLPROTO_HTTPS;
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
            // Not a bare CURLPROTO_HTTPS: that disagreed with isSafeTransport()
            // and broke the documented loopback mock-server setup. See
            // allowedCurlProtocols().
            CURLOPT_PROTOCOLS      => self::allowedCurlProtocols($this->baseUrl),
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
