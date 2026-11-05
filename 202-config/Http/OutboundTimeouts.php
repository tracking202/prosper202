<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * The connect and total time limits for a request this install makes to an
 * outside service that sets none of its own (OutboundCallsHaveTimeoutsTest).
 */
final class OutboundTimeouts
{
    public const CONNECT = 5;
    public const TOTAL = 15;

    public static function apply(\CurlHandle $ch, int $total = self::TOTAL, int $connect = self::CONNECT): void
    {
        $total = max(1, $total);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, max(1, min($connect, $total)));
        curl_setopt($ch, CURLOPT_TIMEOUT, $total);
    }
}
