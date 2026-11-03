<?php

declare(strict_types=1);

namespace Prosper202\Fraud;

/**
 * IPQualityScore's IP reputation lookup, for the cron job's scoring
 * (IpqsScoring), never on a click's request. Only the address and the
 * lookup's fixed flags are sent: no user agent, referer, click id or keyword.
 * https only, no redirects, verified TLS, a connect and a total timeout, and
 * an answer read up to MAX_BYTES. The key is part of the URL IPQualityScore
 * documents, so neither the URL nor anything quoting the key is logged or
 * thrown.
 */
final class IpqsClient
{
    public const ORIGIN = 'https://www.ipqualityscore.com';
    public const CONNECT_TIMEOUT = 3;
    public const TIMEOUT = 5;
    public const MAX_BYTES = 65536;

    /** Tests' stand-in, a loopback origin only (P202_IPQS_ORIGIN); anything else is ignored. */
    private ?string $override;

    public function __construct(?string $overrideOrigin = null)
    {
        $this->override = self::loopbackOrigin($overrideOrigin ?? (getenv('P202_IPQS_ORIGIN') ?: null));
    }

    /**
     * The address's fraud score, 0 to 100.
     *
     * @throws IpqsLookupFailed when no score came back: no answer, a status
     *     other than 200, a body that is not IPQualityScore's JSON, or its
     *     success = false (the key refused, the credits spent)
     */
    public function fraudScore(string $key, string $address): int
    {
        if ($key === '' || filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('An IPQualityScore lookup needs a key and an IP address');
        }
        $url = ($this->override ?? self::ORIGIN) . '/api/json/ip/' . rawurlencode($key) . '/' . rawurlencode($address)
            . '?' . http_build_query(
                ['strictness' => 0, 'allow_public_access_points' => 'true', 'fast' => 'true'],
                '',
                '&',
                PHP_QUERY_RFC3986
            );
        [$status, $body] = $this->get($url, $key);
        if ($status !== 200) {
            throw new IpqsLookupFailed('IPQualityScore answered HTTP ' . $status);
        }
        try {
            $answer = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new IpqsLookupFailed('IPQualityScore answered something other than JSON');
        }
        if (!is_array($answer)) {
            throw new IpqsLookupFailed('IPQualityScore answered something other than a JSON object');
        }
        if (($answer['success'] ?? null) !== true) {
            $message = is_string($answer['message'] ?? null) ? $answer['message'] : 'no reason given';
            throw new IpqsLookupFailed('IPQualityScore refused the lookup: ' . self::withoutKey($message, $key));
        }
        $score = $answer['fraud_score'] ?? null;
        if (!is_int($score) || $score < 0 || $score > 100) {
            throw new IpqsLookupFailed('IPQualityScore answered no fraud score from 0 to 100');
        }

        return $score;
    }

    /** @return array{int, string} the status and the body */
    private function get(string $url, string $key): array
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new IpqsLookupFailed('curl could not start');
        }
        $body = '';
        $tooLong = false;
        $protocols = str_starts_with($url, 'http://') ? CURLPROTO_HTTP : CURLPROTO_HTTPS;
        $set = curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_PROTOCOLS => $protocols,
            CURLOPT_REDIR_PROTOCOLS => $protocols,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_USERAGENT => 'Prosper202',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            // A short count aborts the transfer, which curl_exec() then reports.
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLong): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                    $tooLong = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        if ($set !== true) {
            curl_close($ch);
            throw new IpqsLookupFailed('curl refused an option of the lookup');
        }
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($tooLong) {
            throw new IpqsLookupFailed('IPQualityScore answered more than ' . self::MAX_BYTES . ' bytes');
        }
        if ($ok === false) {
            throw new IpqsLookupFailed('IPQualityScore could not be reached (curl ' . $errno . ': '
                . self::withoutKey($error, $key) . ')');
        }

        return [$status, $body];
    }

    /** A message as it may be logged and shown: the key taken out, at most 200 characters. */
    private static function withoutKey(string $message, string $key): string
    {
        $clean = str_replace([$key, rawurlencode($key)], '…', $message);
        $clean = (string) preg_replace('/[^\P{C}]+/u', ' ', $clean);

        return mb_substr(trim($clean), 0, 200);
    }

    private static function loopbackOrigin(?string $origin): ?string
    {
        if ($origin === null || $origin === '') {
            return null;
        }
        $parts = parse_url($origin);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $loopback = in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true);
        $plain = is_array($parts) && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && !isset($parts['path']) && !isset($parts['query']) && !isset($parts['user']);
        if (!$loopback || !$plain) {
            error_log('IPQualityScore: ignoring P202_IPQS_ORIGIN; only a loopback origin is accepted');

            return null;
        }

        return rtrim($origin, '/');
    }
}
