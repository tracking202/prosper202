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

    /** Hosts the test-only override may point at. */
    private const array LOOPBACK_HOSTS = ['127.0.0.1', '::1', 'localhost'];

    /**
     * The licence endpoint every key check calls.
     *
     * P202_LICENSE_VALIDATE_URL is a TEST SEAM, not a setting: it lets CI
     * point an install at a local stub so the evals never depend on
     * production's licence rules. It is honoured only for http(s) URLs on a
     * loopback host; anything else is ignored and the real endpoint (with
     * full TLS verification) is used. Not a security boundary: this is
     * open-source code an operator can edit anyway.
     */
    public static function url(): string
    {
        $override = getenv('P202_LICENSE_VALIDATE_URL');
        if (is_string($override) && $override !== '') {
            $scheme = strtolower((string)parse_url($override, PHP_URL_SCHEME));
            $host = strtolower(trim((string)parse_url($override, PHP_URL_HOST), '[]'));
            if (in_array($scheme, ['http', 'https'], true) && in_array($host, self::LOOPBACK_HOSTS, true)) {
                return $override;
            }
        }
        return self::VALIDATE_URL;
    }

    /**
     * @return bool|null true = valid, false = invalid, null = network failure
     *                   (callers decide whether to fail open or closed)
     */
    public static function validate(string $key, int $connectTimeoutSeconds = 5, int $timeoutSeconds = 10): ?bool
    {
        $ch = curl_init();
        if ($ch === false) {
            return null;
        }

        curl_setopt($ch, CURLOPT_URL, self::url());
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
        curl_close($ch);

        if ($failed) {
            return null;
        }

        $data = json_decode((string)$response, true);
        return is_array($data) && ($data['msg'] ?? '') === 'Key valid';
    }
}
