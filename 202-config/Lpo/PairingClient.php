<?php

declare(strict_types=1);

namespace Prosper202\Lpo;

use Prosper202\Bridge\EventBridge;
use Prosper202\Ltv\MysqlWebhookRepository;
use RuntimeException;

/**
 * Thin server-to-server client for the Landing Page Optimizer (bandit)
 * pairing endpoints on the SaaS control plane. Deliberately no bandit or
 * decision logic here — this client only pairs the install and pulls the
 * signed remote bridge config; every feature screen is hosted.
 *
 * TLS/timeout hygiene matches the LTV webhook dispatcher
 * (202-cronjobs/ltv_webhooks.php): https only, redirects never followed,
 * strict certificate verification, short timeouts.
 */
final class PairingClient
{
    public const DEFAULT_BASE_URL = 'https://my.tracking202.com';

    /**
     * Capabilities announced at pairing. The SaaS stores these per install
     * and degrades gracefully — it never assumes newer bridge behaviour.
     */
    public const CAPABILITIES = [
        'events' => ['conversion.recorded', 'engagement.recorded'],
        'wildcard_subscribe' => true,
        'remote_config' => true,
        'v3_api' => true,
        'ctx_token' => 'v1',
        'dimensions_sync' => 'v1',
    ];

    private string $baseUrl;

    /** @var callable(string, string, ?string, list<string>): array{0: int, 1: string} */
    private $transport;

    /**
     * @param ?string $baseUrl null/'' = the LPO_SAAS_BASE_URL constant
     *        when defined, otherwise DEFAULT_BASE_URL
     * @param ?callable $transport test seam:
     *        fn(method, url, jsonBody, extraHeaders) => [statusCode, responseBody]
     *        — extraHeaders is a list<string> of raw header lines (always
     *        passed; empty for unsigned requests); null = the curl transport
     */
    public function __construct(?string $baseUrl = null, ?callable $transport = null)
    {
        $this->baseUrl = rtrim($baseUrl !== null && $baseUrl !== '' ? $baseUrl : self::saasBaseUrl(), '/');
        $this->transport = $transport ?? $this->curlTransport(...);
    }

    /**
     * The configured SaaS origin (no trailing slash) — also used to build
     * hosted-dashboard deeplinks.
     */
    public static function saasBaseUrl(): string
    {
        return defined('LPO_SAAS_BASE_URL')
            ? rtrim((string) LPO_SAAS_BASE_URL, '/')
            : self::DEFAULT_BASE_URL;
    }

    /**
     * Start pairing: announces the envelope version, install versions and
     * bridge capabilities; the SaaS responds {site_key, hook_url, status}.
     *
     * @return array<string, mixed>
     */
    public function pairInit(string $customersApiKey, string $installHash, string $installUrl): array
    {
        return $this->request('POST', '/api/v2/lpo/pair/init', [
            'customers_api_key' => $customersApiKey,
            'install_hash' => $installHash,
            'install_url' => $installUrl,
            'bridge_version' => EventBridge::BRIDGE_VERSION,
            'p202_version' => defined('PROSPER202_VERSION') ? (string) PROSPER202_VERSION : '',
            'capabilities' => self::CAPABILITIES,
        ]);
    }

    /**
     * Finish pairing: hands the SaaS the locally registered webhook id and
     * its signing secret (transported exactly once, over TLS).
     *
     * @return array<string, mixed>
     */
    public function pairComplete(string $customersApiKey, string $installHash, int $p202WebhookId, string $webhookSecret): array
    {
        return $this->request('POST', '/api/v2/lpo/pair/complete', [
            'customers_api_key' => $customersApiKey,
            'install_hash' => $installHash,
            'p202_webhook_id' => $p202WebhookId,
            'webhook_secret' => $webhookSecret,
        ]);
    }

    /**
     * Revoke the pairing SaaS-side.
     *
     * @return array<string, mixed>
     */
    public function pairDisconnect(string $customersApiKey, string $installHash): array
    {
        return $this->request('POST', '/api/v2/lpo/pair/disconnect', [
            'customers_api_key' => $customersApiKey,
            'install_hash' => $installHash,
        ]);
    }

    /**
     * Pull the signed remote bridge config: {config: {...}, sig: "sha256=..."}.
     * Returned UNVERIFIED — the caller must verify sig against the pairing
     * webhook secret (see 202-cronjobs/bridge_config.php) and fail closed.
     *
     * @return array<string, mixed>
     */
    public function fetchBridgeConfig(string $installHash): array
    {
        return $this->request('GET', '/api/v2/lpo/bridge/config?' . http_build_query([
            'install_hash' => $installHash,
            'bridge_version' => EventBridge::BRIDGE_VERSION,
        ]), null);
    }

    /**
     * Push the dimension-map snapshot (p202-edge-sync §4.2): the install's
     * traffic-source / campaign / landing-page dictionaries, signed over the
     * exact raw body with the pairing webhook secret — byte-identical to the
     * webhook dispatcher's signing (MysqlWebhookRepository::signature).
     *
     * @param array{networks: list<array<string, mixed>>, accounts: list<array<string, mixed>>, campaigns: list<array<string, mixed>>, landing_pages: list<array<string, mixed>>} $snapshot
     * @return array<string, mixed>
     */
    public function pushDimensions(string $installHash, array $snapshot, string $webhookSecret): array
    {
        $encodedBody = json_encode([
            'install_hash' => $installHash,
            'bridge_version' => EventBridge::BRIDGE_VERSION,
            'snapshot' => $snapshot,
        ]);
        if ($encodedBody === false) {
            throw new RuntimeException('Failed to encode dimensions snapshot for /api/v2/lpo/dimensions');
        }

        return $this->requestEncoded('POST', '/api/v2/lpo/dimensions', $encodedBody, [
            'X-P202-Signature: ' . MysqlWebhookRepository::signature($encodedBody, $webhookSecret),
        ]);
    }

    /**
     * @param array<string, mixed>|null $body null = no request body (GET)
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body): array
    {
        $encodedBody = null;
        if ($body !== null) {
            $encodedBody = json_encode($body);
            if ($encodedBody === false) {
                throw new RuntimeException('Failed to encode pairing request body for ' . $path);
            }
        }

        return $this->requestEncoded($method, $path, $encodedBody);
    }

    /**
     * @param list<string> $extraHeaders extra "Name: value" request headers
     * @return array<string, mixed>
     * @throws PairingRequestException on transport failure, non-2xx status,
     *         or a non-JSON response — malformed responses are errors, never
     *         silently empty
     */
    private function requestEncoded(string $method, string $path, ?string $encodedBody, array $extraHeaders = []): array
    {
        $url = $this->baseUrl . $path;

        [$status, $responseBody] = ($this->transport)($method, $url, $encodedBody, $extraHeaders);

        if ($status < 200 || $status >= 300) {
            throw new PairingRequestException(
                'Pairing request ' . $path . ' failed with HTTP ' . $status . ': ' . substr($responseBody, 0, 200),
                PairingRequestException::KIND_HTTP,
                $url,
                $status,
                self::serverErrorMessage($responseBody)
            );
        }
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new PairingRequestException(
                'Pairing request ' . $path . ' returned invalid JSON',
                PairingRequestException::KIND_BAD_RESPONSE,
                $url,
                $status
            );
        }

        return $decoded;
    }

    /**
     * A human-readable error the SaaS included in a JSON error body
     * ({"error": "..."} or {"message": "..."}), or null. HTML and anything
     * non-JSON is never surfaced.
     */
    private static function serverErrorMessage(string $responseBody): ?string
    {
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            return null;
        }
        foreach (['error', 'message'] as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key]) && trim($decoded[$key]) !== '') {
                return trim($decoded[$key]);
            }
        }
        return null;
    }

    /**
     * @param list<string> $extraHeaders extra "Name: value" request headers
     * @return array{0: int, 1: string} [status code, response body]
     */
    private function curlTransport(string $method, string $url, ?string $jsonBody, array $extraHeaders = []): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new RuntimeException('Pairing requests must use https:// (got ' . $url . ')');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl init failed for ' . $url);
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, // never follow redirects
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => array_merge([
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: Prosper202-Lpo-Bridge/' . EventBridge::BRIDGE_VERSION,
            ], $extraHeaders),
        ];
        if ($jsonBody !== null) {
            $options[CURLOPT_POSTFIELDS] = $jsonBody;
        }
        curl_setopt_array($ch, $options);

        $responseBody = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            throw new PairingRequestException(
                'Pairing request to ' . $url . ' failed: ' . $curlError,
                PairingRequestException::KIND_TRANSPORT,
                $url
            );
        }

        return [$statusCode, (string) $responseBody];
    }
}
