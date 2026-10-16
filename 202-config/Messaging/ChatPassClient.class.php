<?php

declare(strict_types=1);

include_once(__DIR__ . '/MessagingClient.class.php');
require_once __DIR__ . '/../version.php';

/**
 * Asks the portal for a chat pass (POST CHAT_PASS_URL): the
 * signed `cht1.` token the browser presents to the chat service, which the
 * install never reads or verifies. The install's identity — the customer API
 * key and the install hash among it — goes to the portal and nowhere else;
 * 202-account/ajax/chat-pass.php hands the browser only the pass, the chat
 * service's URL and the expiry.
 *
 * request() answers the three values, checked, or throws a RuntimeException
 * whose message says what went wrong, for the log. One attempt: the browser
 * is waiting, and the chat box retries on its own schedule.
 */
final class ChatPassClient
{
    private const PASS_PATTERN = '/^cht1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/';
    private const MAX_PASS_LENGTH = 4096;
    private const MAX_LIFETIME_S = 7 * 86400;

    private readonly string $url;
    /** @var array<int, mixed> */
    private readonly array $transport;

    public function __construct(?string $url = null)
    {
        $url = trim($url ?? (defined('CHAT_PASS_URL') ? (string) CHAT_PASS_URL : 'https://my.tracking202.com/api/v3/chat/pass'));
        $transport = MessagingClient::transportOptions($url);
        if ($transport === null) {
            throw new \RuntimeException('CHAT_PASS_URL must be an https:// URL (http:// is allowed only for loopback); refusing to send credentials in cleartext.');
        }
        $this->url = $url;
        $this->transport = $transport;
    }

    /**
     * What a pass is kept in the session for: everything request() sends,
     * the version included, so an upgrade asks for a pass that reports the
     * new version rather than reusing one that reports the old.
     *
     * @param array<string, mixed> $identity
     * @throws \JsonException
     */
    public static function fingerprint(
        array $identity,
        string $installUrl,
        string $passUrl,
        string $version = PROSPER202_VERSION
    ): string {
        return hash('sha256', json_encode(
            [self::body($identity, $installUrl, $version), $passUrl],
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        ));
    }

    /**
     * What request() sends: fingerprint() hashes the same, so a field added
     * to the request is part of what a kept pass is for.
     *
     * @param array<string, mixed> $identity
     * @return array<string, mixed>
     */
    private static function body(array $identity, string $installUrl, string $version): array
    {
        return ['identity' => ['p202_version' => $version] + $identity, 'install_url' => $installUrl];
    }

    /**
     * The identity goes with this install's version (p202_version), which
     * the portal keeps beside the install.
     *
     * @param array{api_key: string, install_hash: string, user_id: int, user_email: string, user_name: string} $identity
     * @param string $installUrl this install's base URL, or '' when unknown
     * @return array{pass: string, chat_url: string, expires_at_s: int}
     * @throws \RuntimeException when the portal cannot be reached or answers anything but a usable pass
     */
    public function request(array $identity, string $installUrl): array
    {
        $body = json_encode(
            self::body($identity, $installUrl, PROSPER202_VERSION),
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($body === false) {
            throw new \RuntimeException('the pass request could not be encoded: ' . json_last_error_msg());
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        // The transport options on the left: in `+` the left side wins, so
        // nothing listed here can replace them (CLAUDE.md #24).
        $options = $this->transport + [
            CURLOPT_URL            => $this->url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT      => 'Prosper202-Chat/1.0',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ];
        if (!curl_setopt_array($ch, $options)) {
            curl_close($ch);
            throw new \RuntimeException('curl rejected the request options');
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($response) || $error !== '') {
            throw new \RuntimeException('the portal could not be reached: ' . ($error !== '' ? $error : 'no response'));
        }

        return self::parse($status, $response);
    }

    /**
     * The portal's answer, checked: HTTP 200, a JSON object with ok true, a
     * pass in the cht1 shape, a chat URL the browser can be sent to, and an
     * expiry in the future.
     *
     * @return array{pass: string, chat_url: string, expires_at_s: int}
     * @throws \RuntimeException naming what is wrong with it
     */
    public static function parse(int $status, string $response): array
    {
        $decoded = json_decode($response, true);
        $portalError = is_array($decoded) && isset($decoded['error']) && is_scalar($decoded['error'])
            ? ': ' . mb_substr((string) $decoded['error'], 0, 200)
            : '';
        if ($status !== 200) {
            throw new \RuntimeException('the portal answered HTTP ' . $status . $portalError);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException('the portal answered something that is not a JSON object');
        }
        if (($decoded['ok'] ?? null) !== true) {
            throw new \RuntimeException('the portal did not answer ok' . $portalError);
        }

        $pass = $decoded['pass'] ?? null;
        if (!is_string($pass) || strlen($pass) > self::MAX_PASS_LENGTH || preg_match(self::PASS_PATTERN, $pass) !== 1) {
            throw new \RuntimeException('the portal answered a pass that is not a cht1 pass');
        }
        // Seconds, in the future, and no further out than a pass can live
        // (24 h; the bound is generous): milliseconds read as seconds would
        // keep a pass cached for good.
        $expires = $decoded['expires_at_s'] ?? null;
        if (!is_int($expires) || $expires <= time() || $expires > time() + self::MAX_LIFETIME_S) {
            throw new \RuntimeException('the portal answered an expiry that is not a time in the next ' . (self::MAX_LIFETIME_S / 86400) . ' days, in seconds');
        }
        $chatUrl = self::chatUrl($decoded['chat_url'] ?? null);
        if ($chatUrl === null) {
            throw new \RuntimeException('the portal answered a chat_url that is not an https origin');
        }

        return ['pass' => $pass, 'chat_url' => $chatUrl, 'expires_at_s' => $expires];
    }

    /**
     * The chat service's origin, `scheme://host[:port]`, from what the portal
     * named, or null when it is not one the browser may be sent to: https,
     * or http on loopback for local development (MessagingClient's rule), no
     * credentials, query, fragment or path beyond '/'. The chat box appends
     * /v1/… to it and derives the WebSocket URL from its scheme.
     */
    public static function chatUrl(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || preg_match('/[\s\x00-\x1f\x7f\\\\]/', $value) === 1 || MessagingClient::transportOptions($value) === null) {
            return null;
        }
        $parts = parse_url($value);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        if (isset($parts['path']) && $parts['path'] !== '/') {
            return null;
        }
        if (preg_match('/^(?:[a-z0-9-]+\.)*[a-z0-9-]+$|^\[[0-9a-f:.]+\]$/i', $parts['host']) !== 1) {
            return null;
        }

        return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }
}
