<?php

declare(strict_types=1);

namespace P202Cli;

class ApiClient
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;

    public function __construct(string $baseUrl, string $apiKey, int $timeout = 30)
    {
        $this->baseUrl = rtrim($baseUrl, '/') . '/api/v3';
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
    }

    public static function fromConfig(Config $config): self
    {
        $url = $config->getUrl();
        $key = $config->getApiKey();

        if ($url === '') {
            throw new \RuntimeException("No URL configured. Run: p202 config:set-url <url>");
        }
        if ($key === '') {
            throw new \RuntimeException("No API key configured. Run: p202 config:set-key <key>");
        }

        return new self($url, $key, (int)$config->get('timeout', 30));
    }

    public function get(string $path, array $params = []): array
    {
        return $this->request('GET', $path, $params);
    }

    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $path, [], $body);
    }

    public function put(string $path, array $body = []): array
    {
        return $this->request('PUT', $path, [], $body);
    }

    public function patch(string $path, array $body = []): array
    {
        return $this->request('PATCH', $path, [], $body);
    }

    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    private function request(string $method, string $path, array $params = [], array $body = []): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        if ($params) {
            $url .= '?' . http_build_query($params);
        }

        $encodedBody = null;
        if ($body && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $encodedBody = json_encode($body);
            if ($encodedBody === false) {
                throw new \RuntimeException(
                    sprintf('Failed to encode request body as JSON: %s', json_last_error_msg())
                );
            }
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: p202-cli/1.0',
            ],
            CURLOPT_CUSTOMREQUEST => $method,
        ]);

        if ($encodedBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedBody);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno) {
            throw new \RuntimeException("HTTP request failed: $error (errno: $errno)");
        }
        if ($response === false) {
            throw new \RuntimeException('HTTP request failed without cURL error details.');
        }

        $decoded = null;
        if ($response !== '') {
            $decoded = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("Invalid JSON response from server: " . substr($response, 0, 200));
            }
        }
        $data = is_array($decoded) ? $decoded : [];

        if ($httpCode >= 400) {
            $msg = $data['message'] ?? $data['error'] ?? "HTTP $httpCode";
            throw new ApiException($msg, $httpCode, $data);
        }

        return $data;
    }
}
