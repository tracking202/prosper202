<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * An instance suite clicks from the runner itself (127.0.0.1), an address
 * GeoIP cannot place, which privacy `eu` or `all` holds back: the address
 * stored masked and no click cookie. A fresh install's account starts at
 * `eu` (1.9.79), so a suite whose subject is not privacy tracks every visitor
 * in full for its run: the install's setting (user 1's, which the key the
 * installer mints acts as) is set to `disabled` through the API, and put
 * back after.
 */
final class InstancePrivacy
{
    private const PATH = '/api/v3/users/1/preferences';

    /**
     * @return string the setting to put back
     * @throws \RuntimeException when it cannot be read or set
     */
    public static function trackInFull(string $base, string $key): string
    {
        [$status, $body] = self::call($base, $key, 'GET', null);
        $before = $body['data']['user_pref_privacy'] ?? null;
        if ($status !== 200 || !is_string($before)) {
            throw new \RuntimeException("GET /users/1/preferences answered $status: " . json_encode($body));
        }
        if ($before !== 'disabled') {
            self::set($base, $key, 'disabled');
        }

        return $before;
    }

    public static function restore(string $base, string $key, string $before): void
    {
        self::set($base, $key, $before);
    }

    private static function set(string $base, string $key, string $setting): void
    {
        [$status, $body] = self::call($base, $key, 'PUT', ['user_pref_privacy' => $setting]);
        if ($status !== 200 || ($body['data']['user_pref_privacy'] ?? null) !== $setting) {
            throw new \RuntimeException("PUT /users/1/preferences ($setting) answered $status: " . json_encode($body));
        }
    }

    /** @return array{int, array<mixed>} */
    private static function call(string $base, string $key, string $method, ?array $body): array
    {
        $ch = curl_init($base . self::PATH);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_TIMEOUT => 60,
        ] + ($body === null ? [] : [CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR)]));
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = is_string($response) && $response !== '' ? json_decode($response, true) : null;

        return [$status, is_array($decoded) ? $decoded : ['raw' => $response]];
    }
}
