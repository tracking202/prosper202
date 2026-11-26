<?php

declare(strict_types=1);

namespace Prosper202\Http;

use Prosper202\Update\ReleaseSignature;

/**
 * getData() and getUrl(): a request to the fixed vendor host,
 * https://my.tracking202.com, and nowhere else. They followed every
 * redirect curl was handed, to any host and any address, with nothing
 * checking where; a caller that one day passed a URL from a feed would
 * have had this server fetch it. Now a URL off that host is refused, and a
 * redirect is followed by hand, at most MAX_REDIRECTS, only to that host
 * over https (ReleaseSignature::allowedUrl(), the update download's rule),
 * the whole chain under one deadline, at most MAX_BYTES read. A POST's
 * redirect is not followed.
 *
 * Each hop is sent through FixedHostRoute::exec(), as every fixed-host
 * call is: through the egress proxy when one is set.
 */
final class VendorFetch
{
    public const MAX_REDIRECTS = 3;
    public const MAX_BYTES = 8 * 1024 * 1024;

    /**
     * The body of the last answer (whatever its status, as curl returned
     * it), or null when the URL or a redirect was refused or nothing came
     * back; the reason is logged.
     *
     * @param array<string, mixed>|null $post form fields to POST, null for a GET
     * @param list<string> $headers
     * @param (callable(array<int, mixed>): array{status: int, body: string|null, redirect: string|null, error: string})|null $hop one request without redirects
     */
    public static function fetch(
        string $url,
        int $timeout,
        int $connectTimeout,
        ?array $post = null,
        array $headers = [],
        string $userAgent = '',
        ?callable $hop = null
    ): ?string {
        $hop ??= self::curlHop(...);
        $deadline = microtime(true) + max(1, $timeout);
        for ($redirects = 0;; $redirects++) {
            if (ReleaseSignature::allowedUrl($url) === null) {
                error_log('VendorFetch: refused ' . ($redirects === 0 ? 'a request to ' : 'a redirect to ')
                    . self::shown($url) . ': only https://' . ReleaseSignature::HOST . ' is fetched this way.');

                return null;
            }
            $left = (int) ceil($deadline - microtime(true));
            if ($left < 1) {
                error_log('VendorFetch: ' . self::shown($url) . ' took more than ' . $timeout . ' seconds.');

                return null;
            }
            $options = [
                CURLOPT_URL => $url,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => max(1, min($connectTimeout, $left)),
                CURLOPT_TIMEOUT => $left,
                CURLOPT_NOSIGNAL => true,
            ];
            if ($post !== null) {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = http_build_query($post);
            } else {
                $options[CURLOPT_HTTPGET] = true;
            }
            if ($headers !== []) {
                $options[CURLOPT_HTTPHEADER] = $headers;
            }
            if ($userAgent !== '') {
                $options[CURLOPT_USERAGENT] = $userAgent;
            }
            $answer = $hop($options);
            if ($answer['body'] === null) {
                error_log('VendorFetch: no answer from ' . self::shown($url) . ': ' . $answer['error']);

                return null;
            }
            if (in_array($answer['status'], [301, 302, 303, 307, 308], true) && $answer['redirect'] !== null) {
                if ($post !== null) {
                    error_log('VendorFetch: ' . self::shown($url) . ' answered a POST with a redirect, which is not followed.');

                    return null;
                }
                if ($redirects >= self::MAX_REDIRECTS) {
                    error_log('VendorFetch: ' . self::shown($url) . ' redirected more than ' . self::MAX_REDIRECTS . ' times.');

                    return null;
                }
                $url = $answer['redirect'];
                continue;
            }

            return $answer['body'];
        }
    }

    /**
     * @param array<int, mixed> $options
     * @return array{status: int, body: string|null, redirect: string|null, error: string}
     */
    public static function curlHop(array $options): array
    {
        $ch = curl_init();
        if ($ch === false) {
            return ['status' => 0, 'body' => null, 'redirect' => null, 'error' => 'curl could not be initialised'];
        }
        $body = '';
        $tooBig = false;
        $options[CURLOPT_RETURNTRANSFER] = false;
        $options[CURLOPT_WRITEFUNCTION] = static function ($handle, string $chunk) use (&$body, &$tooBig): int {
            if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                $tooBig = true;

                return 0;
            }
            $body .= $chunk;

            return strlen($chunk);
        };
        try {
            CurlOptions::set($ch, $options);
        } catch (CurlOptionRefused $e) {
            curl_close($ch);

            return ['status' => 0, 'body' => null, 'redirect' => null, 'error' => $e->getMessage()];
        }
        $ok = FixedHostRoute::exec($ch, [], self::MAX_BYTES);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $redirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $error = curl_error($ch);
        curl_close($ch);
        if ($tooBig) {
            return ['status' => $status, 'body' => null, 'redirect' => null, 'error' => 'the answer is larger than ' . self::MAX_BYTES . ' bytes'];
        }
        if ($ok === false) {
            return ['status' => $status, 'body' => null, 'redirect' => null, 'error' => $error !== '' ? $error : 'no answer'];
        }

        return [
            'status' => $status,
            'body' => $body,
            'redirect' => is_string($redirect) && $redirect !== '' ? $redirect : null,
            'error' => '',
        ];
    }

    /** Scheme, host and path: never the query, which can carry a key. */
    private static function shown(string $url): string
    {
        $cut = substr($url, 0, strcspn($url, '?#'));

        return (string) preg_replace('/[^\x21-\x7E]/', '?', mb_substr($cut, 0, 200));
    }
}
