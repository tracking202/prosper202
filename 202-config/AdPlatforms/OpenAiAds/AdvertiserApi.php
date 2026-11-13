<?php

declare(strict_types=1);

namespace Prosper202\AdPlatforms\OpenAiAds;

use Api\V3\Support\QuotaStore;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;
use Prosper202\Destinations\GoogleAdsOAuth;

/**
 * OpenAI's Advertiser API (https://api.ads.openai.com/v1), one ad account
 * per key, reached only through WebhookSender::read(), so every request is
 * resolved, pinned and bounded as every other outbound call.
 *
 * Limits: OpenAI allows 600 requests a minute per endpoint and 1,200 in all,
 * per ad account and per address. This object holds its own requests under
 * both (a request past them is not sent and answers RATE_LIMITED), and a 429
 * is never retried here: the caller says "try again in a minute".
 *
 * Creates carry an Idempotency-Key the caller derives from what is being
 * created. OpenAI documents the header for campaign, ad group and ad
 * creates; for pixels, Conversions API keys and event settings it is sent
 * but nothing promises it is honoured, so those callers look for what
 * exists before they create, and a key whose answer was lost may be made
 * twice.
 *
 * The key is write-only everywhere: it is sent in the Authorization header
 * and taken out of every text this returns.
 */
final class AdvertiserApi
{
    public const HOST = 'api.ads.openai.com';
    public const BASE = 'https://' . self::HOST . '/v1/';
    public const PER_ENDPOINT_PER_MINUTE = 600;
    public const OVERALL_PER_MINUTE = 1200;
    private const MESSAGE_CHARS = 300;

    public const ENV_ENDPOINT = 'P202_OPENAI_ADS_ENDPOINT';
    public const ENV_CA_FILE = 'P202_OPENAI_ADS_CA_FILE';
    private const LOOPBACK = ['127.0.0.1', '[::1]', 'localhost'];

    /** @var array<string, list<float>> request times in the last minute, by key and endpoint ('' is overall) */
    private array $sent = [];
    /** @var (callable(): float) */
    private $clock;
    private string $base = self::BASE;
    /** @var list<int> */
    private array $ports = [443];

    /**
     * @param (callable(): float)|null $clock seconds, for the in-process count used without $quota
     * @param QuotaStore|null $quota where every process counts the per-minute limits (ServerStateStore::forInstall());
     *                               without one, this object counts its own requests only
     */
    public function __construct(
        private ?WebhookSender $sender = null,
        ?callable $clock = null,
        private ?QuotaStore $quota = null,
        private readonly int $perEndpointLimit = self::PER_ENDPOINT_PER_MINUTE,
        private readonly int $overallLimit = self::OVERALL_PER_MINUTE,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** The same API, its limits counted in $quota, which every process of the install shares. */
    public function countingIn(QuotaStore $quota): self
    {
        $api = clone $this;
        $api->quota = $quota;

        return $api;
    }

    /**
     * The API the install uses: OpenAI's, or, for tests and the live pass, the loopback server
     * P202_OPENAI_ADS_ENDPOINT names (https://127.0.0.1:<port> only, so a stray setting in production
     * cannot send the key off this machine), trusted through P202_OPENAI_ADS_CA_FILE with
     * verification on. The request is still checked, pinned and bounded by WebhookSender.
     *
     * @throws \InvalidArgumentException for an endpoint that is not a loopback https origin
     */
    public static function fromEnvironment(?QuotaStore $quota = null): self
    {
        $endpoint = getenv(self::ENV_ENDPOINT);
        if ($endpoint === false || $endpoint === '') {
            return new self(null, null, $quota);
        }
        $parts = parse_url($endpoint);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !in_array($host, self::LOOPBACK, true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new \InvalidArgumentException(self::ENV_ENDPOINT . ' must be https://127.0.0.1:<port> (or [::1], localhost): it exists for tests, and an Advertiser API off this machine is refused');
        }
        $ca = getenv(self::ENV_CA_FILE);
        $ca = $ca === false || $ca === '' ? null : $ca;
        $transport = static function (array $options) use ($ca): array {
            if ($ca !== null) {
                $options[CURLOPT_CAINFO] = $ca;
            }

            return WebhookSender::curlTransport($options, WebhookSender::MAX_READ_BYTES, WebhookSender::MAX_READ_BYTES);
        };
        $api = new self(new WebhookSender(new WebhookGuard(null, '127.0.0.0/8,::1/128'), $transport), null, $quota);
        $api->base = 'https://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . '/v1/';
        $api->ports = [isset($parts['port']) ? (int) $parts['port'] : 443];

        return $api;
    }

    /**
     * The sender for OpenAI's Conversions API host (bzr.openai.com): the plain WebhookSender, or, when
     * P202_OPENAI_ADS_ENDPOINT names the loopback stand-in, one that checks and pins the host as usual (to
     * the loopback address, which is allowed) and connects to the stand-in's port, TLS verified as
     * bzr.openai.com against P202_OPENAI_ADS_CA_FILE. Never reaches OpenAI when the endpoint is set.
     */
    public static function eventsSender(): WebhookSender
    {
        $endpoint = getenv(self::ENV_ENDPOINT);
        if ($endpoint === false || $endpoint === '') {
            return new WebhookSender();
        }
        $port = self::fromEnvironment()->ports[0];
        $ca = getenv(self::ENV_CA_FILE);
        $transport = static function (array $options) use ($port, $ca): array {
            // Every option the sender set stays; the connection goes to the stand-in's port, verified as bzr.openai.com.
            $options[CURLOPT_CONNECT_TO] = [\Prosper202\Destinations\OpenAiAdsDestination::HOST . ':443:127.0.0.1:' . $port];
            if (is_string($ca) && $ca !== '') {
                $options[CURLOPT_CAINFO] = $ca;
            }

            return WebhookSender::curlTransport($options);
        };

        return new WebhookSender(new WebhookGuard(static fn (string $host): array => ['127.0.0.1'], '127.0.0.0/8'), $transport);
    }

    /** @param array<string, scalar|list<scalar>> $query a list is sent as name[]=a&name[]=b, as OpenAI reads it */
    public function get(#[\SensitiveParameter] string $key, string $path, array $query = []): AdvertiserAnswer
    {
        $url = $this->base . ltrim($path, '/') . ($query === [] ? '' : '?' . self::query($query));

        return $this->call($key, 'GET', $path, $url, null, null);
    }

    /** @param array<string, scalar|list<scalar>> $query */
    public static function query(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $pairs[] = rawurlencode($name) . (is_array($value) ? '[]' : '') . '=' . rawurlencode(is_bool($item) ? ($item ? 'true' : 'false') : (string) $item);
            }
        }

        return implode('&', $pairs);
    }

    /**
     * An update or an action (OpenAI's updates are POSTs), or a create with $idempotencyKey.
     *
     * @param array<string, mixed> $body
     */
    public function post(#[\SensitiveParameter] string $key, string $path, array $body, ?string $idempotencyKey = null): AdvertiserAnswer
    {
        try {
            $json = json_encode($body === [] ? new \stdClass() : $body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return AdvertiserAnswer::failed(AdvertiserAnswer::REFUSED, 0, 'not sent: the request could not be written as JSON (' . $e->getMessage() . ')');
        }

        return $this->call($key, 'POST', $path, $this->base . ltrim($path, '/'), $json, $idempotencyKey);
    }

    /** The endpoint a path is counted under: its ids (cmpn_101, adgrp_…: a prefix, _, and a value with a digit) read as one. */
    public static function endpoint(string $method, string $path): string
    {
        return $method . ' ' . (string) preg_replace('#/[a-z]+_(?=[A-Za-z0-9-]*[0-9])[A-Za-z0-9-]+(?=/|$)#', '/{id}', '/' . trim($path, '/'));
    }

    /** OpenAI's own words from an error body, cut short; '' when it has none. */
    public static function failureText(string $body): string
    {
        try {
            $json = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }
        if (!is_array($json)) {
            return '';
        }
        $error = $json['error'] ?? null;
        $text = match (true) {
            is_array($error) && is_string($error['message'] ?? null) => $error['message'],
            is_string($error) => $error,
            is_string($json['message'] ?? null) => $json['message'],
            is_string($json['detail'] ?? null) => $json['detail'],
            default => '',
        };
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > self::MESSAGE_CHARS ? mb_substr($text, 0, self::MESSAGE_CHARS) . '…' : $text;
    }

    private function call(#[\SensitiveParameter] string $key, string $method, string $path, string $url, ?string $body, ?string $idempotencyKey): AdvertiserAnswer
    {
        $failed = static fn (string $kind, int $status, string $why): AdvertiserAnswer => AdvertiserAnswer::failed($kind, $status, GoogleAdsOAuth::redact($why, [$key]));
        if ($key === '' || preg_match('/[\x00-\x20\x7f]/', $key) === 1) {
            return $failed(AdvertiserAnswer::KEY_REFUSED, 0, 'not sent: the Advertiser API key is empty or holds spaces or control characters');
        }
        $held = $this->hold($key, self::endpoint($method, $path));
        if ($held !== null) {
            return $failed(AdvertiserAnswer::RATE_LIMITED, 0, $held);
        }
        $headers = ['Authorization: Bearer ' . $key, 'Accept: application/json', 'User-Agent: Prosper202-AdConnections/1'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        $this->sender ??= new WebhookSender();
        $sent = $this->sender->read($url, $body, $headers, $this->ports);
        if ($sent['refused'] !== null) {
            return $failed(AdvertiserAnswer::UNAVAILABLE, 0, 'not sent: ' . $sent['refused']);
        }
        if ($sent['strayed'] !== null) {
            return $failed(AdvertiserAnswer::UNAVAILABLE, 0, 'not sent: the connection went to ' . $sent['strayed'] . ' instead of the checked address ' . $sent['pinned']);
        }
        $status = $sent['status'];
        if ($status === 0) {
            return $failed(AdvertiserAnswer::UNAVAILABLE, 0, 'no answer from OpenAI: ' . ($sent['error'] ?? 'the request failed') . '. Try again in a minute');
        }
        if ($status < 200 || $status >= 300) {
            $text = self::failureText($sent['body']);
            $said = 'OpenAI answered ' . $status . ($text !== '' ? ': ' . $text : '');
            $kind = match (true) {
                $status === 401 || $status === 403 => AdvertiserAnswer::KEY_REFUSED,
                $status === 404 => AdvertiserAnswer::NOT_FOUND,
                $status === 429 => AdvertiserAnswer::RATE_LIMITED,
                $status >= 500 => AdvertiserAnswer::UNAVAILABLE,
                default => AdvertiserAnswer::REFUSED,
            };

            return $failed($kind, $status, $said . ($kind === AdvertiserAnswer::RATE_LIMITED || $kind === AdvertiserAnswer::UNAVAILABLE ? '. Try again in a minute' : ''));
        }
        if ($sent['error'] !== null) {
            return $failed(AdvertiserAnswer::UNAVAILABLE, $status, 'OpenAI\'s answer was cut off (' . $sent['error'] . ')');
        }
        try {
            $json = json_decode($sent['body'], true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $json = null;
        }
        // Nothing is read from an answer that is not OpenAI's JSON object (CLAUDE.md #4).
        if (!is_array($json) || ($json !== [] && array_is_list($json))) {
            return $failed(AdvertiserAnswer::UNAVAILABLE, $status, 'OpenAI answered ' . $status . ' with something that is not its JSON');
        }

        return AdvertiserAnswer::ok($status, $json);
    }

    /**
     * Why this request is held back, or null when it may go: OpenAI's two per-minute limits, per key (one key is
     * one ad account), counted in the install's shared store when there is one, so every process sees every
     * request. A counter that cannot be read holds the request back (CLAUDE.md #11).
     */
    private function hold(#[\SensitiveParameter] string $key, string $endpoint): ?string
    {
        $account = substr(hash('sha256', $key), 0, 32);
        $held = static fn (int $limit, string $what): string => 'not sent: OpenAI allows ' . $limit . ' requests a minute '
            . ($what === '' ? 'in all' : 'to ' . $what) . ', and that many were made. Try again in a minute';
        if ($this->quota !== null) {
            try {
                $overall = $this->quota->reserveQuotaWindow('openai-ads-' . $account . '-all', $this->overallLimit, 60);
                if ($overall['retry_after'] !== null) {
                    return $held($this->overallLimit, '');
                }
                $one = $this->quota->reserveQuotaWindow('openai-ads-' . $account . '-' . substr(hash('sha256', $endpoint), 0, 16), $this->perEndpointLimit, 60);
                if ($one['retry_after'] !== null) {
                    $this->quota->refundQuota('openai-ads-' . $account . '-all', 60, 1, $overall['window_start']);

                    return $held($this->perEndpointLimit, $endpoint);
                }
            } catch (\RuntimeException $e) {
                return 'not sent: the count of requests to OpenAI could not be read (' . $e->getMessage() . '). Try again in a minute';
            }

            return null;
        }
        $now = ($this->clock)();
        foreach (['' => $this->overallLimit, $endpoint => $this->perEndpointLimit] as $what => $limit) {
            $slot = $account . '|' . $what;
            $this->sent[$slot] = array_values(array_filter($this->sent[$slot] ?? [], static fn (float $t): bool => $t > $now - 60.0));
            if (count($this->sent[$slot]) >= $limit) {
                return $held($limit, (string) $what);
            }
        }
        foreach (['', $endpoint] as $what) {
            $this->sent[$account . '|' . $what][] = $now;
        }

        return null;
    }
}
