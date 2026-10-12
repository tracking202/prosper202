<?php

declare(strict_types=1);

namespace Prosper202\License;

/**
 * Validates a ClickServer customer API key against my.tracking202.com.
 * Shared by web auth (AUTH::is_valid_api_key) and the v3 capabilities
 * endpoint so the validation contract lives in exactly one place.
 */
class ClickServerKeyValidator
{
    private const string VALIDATE_URL = 'https://my.tracking202.com/api/v2/validate-customers-key';

    /**
     * @return bool|null true = valid, false = invalid, null = network failure
     *                   (callers decide whether to fail open or closed)
     */
    public static function validate(string $key, int $connectTimeoutSeconds = 5, int $timeoutSeconds = 10): ?bool
    {
        $result = self::check($key, $connectTimeoutSeconds, $timeoutSeconds);
        return $result === null ? null : $result['valid'];
    }

    /**
     * Full licence answer for a key.
     *
     * 'paid' is true when the key's account has an active Prosper202
     * ClickServer subscription (trials count). It is null when the answer
     * carried no 'paid' field (older backend, or the edge fallback that
     * answers while the backend is unreachable); callers must treat null as
     * "unknown", not as "not paid".
     *
     * @return array{valid: bool, paid: ?bool}|null null = network failure
     */
    public static function check(string $key, int $connectTimeoutSeconds = 5, int $timeoutSeconds = 10): ?array
    {
        $ch = curl_init();
        if ($ch === false) {
            return null;
        }

        curl_setopt($ch, CURLOPT_URL, self::VALIDATE_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['key' => $key]));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeoutSeconds);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        // Verify the TLS certificate so the validation response cannot be forged
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);
        $failed = curl_errno($ch) || $response === false;
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // No curl_close(): a no-op since PHP 8.0 and deprecated in 8.5, where
        // its notice lands in whatever response this runs inside of.

        if ($failed) {
            return null;
        }

        return self::interpret($status, (string)$response);
    }

    /**
     * Turn the endpoint's HTTP answer into a verdict.
     *
     * The service answers in one envelope, {"code": int, "msg": string}
     * plus an optional boolean "paid", and only these four answers exist:
     *
     *   200 {"code":200,"msg":"Key valid","paid":true|false}  the key and its licence
     *   200 {"code":200,"msg":"Key valid"}                    the key; licence unknown
     *       (the service's own answer while its database is down, and the
     *       edge fallback's while the service is unreachable)
     *   404 {"code":404,"msg":"Key not found"}                no such key
     *   404 {"code":403,"msg":"API key requires ..."}         the key is refused
     *
     * Only those decide. Everything else is an outage or somebody else's
     * answer and returns null: a 5xx, a redirect, an HTML error page,
     * malformed JSON, JSON without the envelope (a gateway's own 404 body),
     * a 200 whose message the service never sends. A 'paid' that is not a
     * boolean is unknown too ('paid' => null) rather than "not paid": a
     * malformed value must never resolve to the answer that happens to be a
     * denial (CLAUDE.md #11). Callers then fall back or fail open, and
     * nothing is cached from it.
     *
     * @return array{valid: bool, paid: ?bool}|null
     */
    public static function interpret(int $status, string $body): ?array
    {
        $data = json_decode($body, true);
        if (
            !is_array($data)
            || !isset($data['msg']) || !is_string($data['msg'])
            || !isset($data['code']) || !is_int($data['code'])
        ) {
            return null;
        }
        if ($status === 200) {
            if ($data['code'] !== 200 || $data['msg'] !== 'Key valid') {
                return null;
            }
            $paid = $data['paid'] ?? null;
            return ['valid' => true, 'paid' => is_bool($paid) ? $paid : null];
        }
        if ($status === 404 && in_array($data['code'], [403, 404], true)) {
            return ['valid' => false, 'paid' => false];
        }
        return null;
    }
}
