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
    private const string VALIDATE_HOST = 'my.tracking202.com';
    /**
     * Redirect hops followed before the answer is "unknown". One: an edge
     * redirect is a single hop, and every hop costs a connect and a timeout
     * on the request path (checkKey gives 2 s + 4 s each).
     */
    private const int MAX_REDIRECTS = 1;

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
        $url = self::VALIDATE_URL;
        for ($hop = 0;; $hop++) {
            $answer = self::post($url, $key, $connectTimeoutSeconds, $timeoutSeconds);
            if ($answer === null) {
                return null;
            }
            [$status, $body, $redirect] = $answer;
            if (!in_array($status, [301, 302, 307, 308], true)) {
                $verdict = self::interpret($status, $body);
                if ($verdict === null) {
                    // The gate and the web check fail open on this, so without
                    // a line here nobody would know they had stopped deciding.
                    error_log(sprintf(
                        'ClickServerKeyValidator: unusable answer from %s: HTTP %d, body %s',
                        $url,
                        $status,
                        json_encode(substr($body, 0, 200)) ?: '(unencodable)'
                    ));
                }
                return $verdict;
            }
            // A redirect is followed by hand, one hop at a time, and only to the
            // service's own host over HTTPS (redirectTarget): curl's own
            // FOLLOWLOCATION would re-POST the key to whatever host the
            // Location names, over whatever scheme. The two older callers of
            // this endpoint (functions-tracking202.php) follow blindly; here a
            // redirect elsewhere is "unknown", and said so.
            $next = $hop < self::MAX_REDIRECTS ? self::redirectTarget($redirect) : null;
            if ($next === null) {
                error_log(sprintf(
                    'ClickServerKeyValidator: HTTP %d from %s to %s not followed (hop %d)',
                    $status,
                    $url,
                    json_encode($redirect) ?: '(unencodable)',
                    $hop + 1
                ));
                return null;
            }
            $url = $next;
        }
    }

    /**
     * Where a redirect may be followed to: the same URL only if it is HTTPS,
     * on the service's own host, at the default port, with no credentials in
     * it. Anything else, a relative or malformed Location included (curl
     * resolves a relative one before handing it over), is null.
     */
    public static function redirectTarget(?string $location): ?string
    {
        if ($location === null || $location === '') {
            return null;
        }
        $parts = parse_url($location);
        if (
            !is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($parts['host'] ?? '')) !== self::VALIDATE_HOST
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
        ) {
            return null;
        }
        return $location;
    }

    /**
     * One POST of the key to $url, redirects not followed.
     *
     * @return array{int, string, ?string}|null [status, body, redirect URL
     *         curl resolved from the Location header, or null]; null when no
     *         answer came back at all (logged).
     */
    private static function post(string $url, string $key, int $connectTimeoutSeconds, int $timeoutSeconds): ?array
    {
        $ch = curl_init();
        if ($ch === false) {
            error_log('ClickServerKeyValidator: curl_init() failed');
            return null;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['key' => $key]));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeoutSeconds);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        // Verify the TLS certificate so the validation response cannot be forged
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        $response = curl_exec($ch);
        $failed = curl_errno($ch) || $response === false;
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $redirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $error = curl_error($ch);
        // No curl_close(): a no-op since PHP 8.0 and deprecated in 8.5, where
        // its notice lands in whatever response this runs inside of.

        if ($failed) {
            error_log('ClickServerKeyValidator: no answer from ' . $url . ': ' . $error);
            return null;
        }
        return [$status, (string)$response, is_string($redirect) && $redirect !== '' ? $redirect : null];
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
     *   404 {"code":403,"msg":"API key requires an active CPM* Newsletter subscription."}
     *
     * Only those decide, message included: a 404 is a refusal only with the
     * service's own words, since {"code":404,"msg":"Not Found"} is what a
     * gateway or WAF in front of it answers for a route it does not know.
     * Everything else is an outage or somebody else's answer and returns
     * null: a 5xx, a redirect, an HTML error page, malformed JSON, JSON
     * without the envelope, a 200 or a 404 whose message the service never
     * sends. A 'paid' that is not a
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
        if ($status === 404 && self::isRefusal($data['code'], $data['msg'])) {
            return ['valid' => false, 'paid' => false];
        }
        return null;
    }

    /** The service's two refusals, by code and its own words. */
    public static function isRefusal(int $code, string $msg): bool
    {
        return ($code === 404 && $msg === 'Key not found')
            || ($code === 403 && $msg === 'API key requires an active CPM* Newsletter subscription.');
    }
}
