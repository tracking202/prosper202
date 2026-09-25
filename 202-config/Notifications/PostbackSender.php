<?php

declare(strict_types=1);

namespace Prosper202\Notifications;

/**
 * The one sender of server-to-server traffic-source postbacks: gpb's and
 * upx's p202FireTrafficSourcePixels() for their type-4 pixels, and the
 * notification outbox's worker (plan §5.2 step 6).
 *
 * A GET that follows redirects, with a bounded time, and answers whether the
 * network heard it: a transport failure and a non-2xx/3xx status are both
 * false. getUrl() answered '' for a failure and for an empty body alike, so
 * it could not say that.
 */
final class PostbackSender
{
    public const USER_AGENT = 'Mozilla/5.0 Postback202-Bot v1.8';

    private function __construct()
    {
    }

    public static function fetch(string $url): bool
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            return false;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $body !== false && $status >= 200 && $status < 400;
    }
}
