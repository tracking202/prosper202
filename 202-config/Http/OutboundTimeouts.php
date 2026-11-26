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

    /** @throws CurlOptionRefused */
    public static function apply(\CurlHandle $ch, int $total = self::TOTAL, int $connect = self::CONNECT): void
    {
        CurlOptions::set($ch, self::options($total, $connect));
    }

    /** @return array<int, int> both limits, for an option list FixedHostRoute::exec() sets */
    public static function options(int $total = self::TOTAL, int $connect = self::CONNECT): array
    {
        $total = max(1, $total);

        return [
            CURLOPT_CONNECTTIMEOUT => max(1, min($connect, $total)),
            CURLOPT_TIMEOUT => $total,
        ];
    }
}
