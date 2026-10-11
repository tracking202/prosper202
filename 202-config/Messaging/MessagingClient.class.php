<?php

declare(strict_types=1);

/**
 * MessagingClient
 *
 * HTTP transport between a self-hosted Prosper202 install and the portal's
 * product-analytics endpoint (POST <MESSAGING_API_URL>/track). The install can
 * only make outbound requests, so this class only ever POSTs and reads the
 * JSON response. The contract is in 202-config/Messaging/CENTRAL-API.md.
 *
 * Chat no longer travels through the install: the browser talks to the chat
 * service with a pass that 202-account/ajax/chat-pass.php fetches
 * (ChatPassClient), which makes its transport decision here too.
 *
 * track() returns a decoded associative array on success, or null on any
 * transport/parse/HTTP failure. Callers must treat null as "could not reach
 * the server" and keep their queued rows — never as "delivered".
 */
class MessagingClient
{
    private readonly string $baseUrl;
    private readonly int $curlProtocols;
    /** @var array<int, mixed> */
    private readonly array $transport;
    private readonly int $timeout;
    private readonly int $maxRetries;

    public function __construct()
    {
        // MESSAGING_API_URL is defined in 202-config/connect.php. Every
        // request body carries the install's customer API key and the user's
        // email, so cleartext is refused even if the URL is misconfigured.
        //
        // Trimmed once, here. The transport decision below normalises its input,
        // and storing the raw value let a whitespace-padded URL pass the check
        // and then fail every request at curl with "Malformed input to a URL".
        $configuredUrl = trim((string) (defined('MESSAGING_API_URL') ? MESSAGING_API_URL : 'https://my.tracking202.com/api/v3/messaging'));
        $transport = self::transportOptions($configuredUrl);
        if ($transport === null) {
            throw new \RuntimeException('MESSAGING_API_URL must be an https:// URL (http:// is allowed only for loopback); refusing to send credentials in cleartext.');
        }
        $this->baseUrl       = $configuredUrl;
        $this->transport     = $transport;
        $this->curlProtocols = (int) $transport[CURLOPT_PROTOCOLS];
        $this->timeout       = 10;
        // A healthy server answers on the first try; the second absorbs a
        // blip. A queued event that still fails is retried by the next sync.
        $this->maxRetries    = 2;
    }

    /**
     * The one transport decision: which curl protocols may carry requests to
     * $url, or null when the URL must be refused outright.
     *
     * Credentials must not cross a network in cleartext, so https is required --
     * except against loopback, which never leaves the host. The carve-out is
     * for local development against a stub portal (MESSAGING_API_URL and
     * CHAT_PASS_URL both take an environment override in connect.php).
     *
     * Acceptance and the curl allowlist used to be two functions that had to
     * agree. They did not: the allowlist was pinned to CURLPROTO_HTTPS, so the
     * constructor accepted a loopback URL and every request then failed with
     * CURLE_UNSUPPORTED_PROTOCOL. One function returning both answers cannot
     * drift.
     *
     *   https://...              -> CURLPROTO_HTTPS
     *   http://<loopback>...     -> CURLPROTO_HTTPS | CURLPROTO_HTTP
     *   anything else            -> null (refused)
     */
    private static function transportProtocols(string $url): ?int
    {
        $url = strtolower(trim($url));
        if (str_starts_with($url, 'https://')) {
            return CURLPROTO_HTTPS;
        }
        if (!str_starts_with($url, 'http://')) {
            return null;
        }

        return self::isLoopback($url) ? CURLPROTO_HTTPS | CURLPROTO_HTTP : null;
    }

    /**
     * localhost, ::1 and 127.0.0.0/8 only -- not every RFC1918 address,
     * which does traverse a network.
     */
    private static function isLoopback(string $url): bool
    {
        $host = (string) parse_url(strtolower(trim($url)), PHP_URL_HOST);

        return $host === 'localhost'
            || $host === '::1'
            || $host === '[::1]'
            || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.'));
    }

    /**
     * The curl options every call to a portal URL is made with: the protocol
     * allowlist above, and for a loopback URL no proxy. Null when the URL is
     * refused.
     *
     * An https URL takes the environment's proxy (https_proxy), as every
     * call to a fixed first-party host here does: nothing is pinned to an
     * address (CLAUDE.md #24's proxy bypass exists where CURLOPT_RESOLVE pins
     * a checked address, which a proxy would void), TLS still runs end to end
     * through a CONNECT proxy and is verified, and an install whose network
     * only reaches out through a proxy keeps working. A loopback URL is the
     * cleartext carve-out, justified only because the request never leaves
     * the host; libcurl sends an http:// request to http_proxy whatever its
     * host, which would carry the API key in cleartext to the proxy. So
     * loopback never goes through one.
     *
     * ChatPassClient makes its calls with these options too;
     * MessagingTransportAllowlistTest holds the two to the same set.
     *
     * @return array<int, mixed>|null
     */
    public static function transportOptions(string $url): ?array
    {
        $protocols = self::transportProtocols($url);
        if ($protocols === null) {
            return null;
        }
        $options = [CURLOPT_PROTOCOLS => $protocols];
        if (self::isLoopback($url)) {
            $options[CURLOPT_PROXY] = '';
            $options[CURLOPT_NOPROXY] = '*';
        }

        return $options;
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

        // The transport options on the left: in `+` the left side wins, so
        // nothing listed here can replace them (CLAUDE.md #24).
        curl_setopt_array($ch, $this->transport + [
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
        // as a non-result so the caller keeps its queued rows.
        if (array_key_exists('ok', $decoded) && $decoded['ok'] === false) {
            $serverError = isset($decoded['error']) ? (string) $decoded['error'] : 'unknown';
            error_log("MessagingClient: server returned ok:false for {$url}: {$serverError}");
            return null;
        }

        return $decoded;
    }
}
