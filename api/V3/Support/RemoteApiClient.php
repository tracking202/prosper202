<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\DatabaseException;

class RemoteApiClient
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct(string $url, string $apiKey)
    {
        $url = trim($url);
        if ($url === '') {
            throw new DatabaseException('Remote URL is required');
        }

        $url = self::translateUrl($url);

        $url = rtrim($url, '/');
        if (!str_contains($url, '/api/v3')) {
            $url .= '/api/v3';
        }

        $this->baseUrl = $url;
        $this->apiKey = $apiKey;
    }

    /**
     * Translate external URLs to internal Docker service names using P202_URL_MAP.
     * Format: P202_URL_MAP=localhost:8000=web:80,localhost:8001=web2:80
     */
    private static function translateUrl(string $url): string
    {
        $map = getenv('P202_URL_MAP');
        if ($map === false || $map === '') {
            return $url;
        }
        foreach (explode(',', $map) as $entry) {
            $parts = explode('=', trim($entry), 2);
            if (count($parts) !== 2) {
                continue;
            }
            $url = str_replace(trim($parts[0]), trim($parts[1]), $url);
        }
        return $url;
    }

    /** @return array<string, mixed> */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query, null, []);
    }

    /** @return array<string, mixed> */
    public function post(string $path, array $body = [], array $extraHeaders = []): array
    {
        return $this->request('POST', $path, [], $body, $extraHeaders);
    }

    /** @return array<string, mixed> */
    public function put(string $path, array $body = [], array $extraHeaders = []): array
    {
        return $this->request('PUT', $path, [], $body, $extraHeaders);
    }

    /** @return array<string, mixed> */
    public function delete(string $path, array $extraHeaders = []): array
    {
        return $this->request('DELETE', $path, [], null, $extraHeaders);
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchAllRows(string $endpoint, array $extraQuery = []): array
    {
        $offset = 0;
        $limit = 500;
        $rows = [];

        while (true) {
            $query = array_merge($extraQuery, [
                'limit' => (string)$limit,
                'offset' => (string)$offset,
            ]);

            $resp = $this->get($endpoint, $query);
            $page = $resp['data'] ?? null;
            if (!is_array($page)) {
                break;
            }

            foreach ($page as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            $pagination = $resp['pagination'] ?? [];
            $total = (int)($pagination['total'] ?? count($rows));
            $offset += $limit;
            if ($offset >= $total || count($page) === 0) {
                break;
            }
        }

        return $rows;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, array $query, ?array $body, array $extraHeaders): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new DatabaseException('Failed to initialize remote request');
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: prosper202-server-sync/1.0',
        ];
        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }
        foreach ($extraHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                curl_close($ch);
                throw new DatabaseException('Failed to encode remote request body');
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new DatabaseException('Remote request failed: ' . $err);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        if ($status >= 400) {
            $message = (string)($decoded['message'] ?? ('Remote API error ' . $status));
            throw new DatabaseException($message);
        }

        return $decoded;
    }
}
