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
        curl_close($ch);

        if ($failed) {
            return null;
        }

        return self::interpret($status, (string)$response);
    }

    /**
     * Turn the endpoint's HTTP answer into a verdict.
     *
     * Only a well-formed, authoritative answer decides: 200 + JSON msg
     * (valid only when msg is 'Key valid'), or 404 + JSON (the endpoint's
     * "invalid key" answer). Anything else (5xx, a redirect, an HTML error
     * page, malformed JSON) is an outage and returns null, so callers fail
     * open instead of caching a denial.
     *
     * @return array{valid: bool, paid: ?bool}|null
     */
    public static function interpret(int $status, string $body): ?array
    {
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['msg']) || !is_string($data['msg'])) {
            return null;
        }
        if ($status === 200) {
            $valid = $data['msg'] === 'Key valid';
        } elseif ($status === 404) {
            $valid = false;
        } else {
            return null;
        }
        $paid = array_key_exists('paid', $data) ? ($data['paid'] === true) : null;
        return ['valid' => $valid, 'paid' => $valid ? $paid : false];
    }
}
