<?php

declare(strict_types=1);

namespace Prosper202\Conversion;

/**
 * The Meta (Facebook) Pixel ID a "Bot202 Facebook Pixel Assistant" traffic
 * source pixel (202_pixel_types 6) holds in its pixel_code: the bare ID, as
 * paid 1.9.56's setup page stored it, or found in the Meta base code or
 * <noscript> image pasted whole.
 */
final class FacebookPixelId
{
    /** 202_pixel_types.pixel_type_id of 'Bot202 Facebook Pixel Assistant'. */
    public const PIXEL_TYPE = 6;

    private function __construct()
    {
    }

    public static function fromCode(mixed $code): ?string
    {
        if (!is_string($code)) {
            return null;
        }
        $code = trim($code);
        if (preg_match('/^[0-9]{5,20}$/D', $code) === 1) {
            return $code;
        }
        if (preg_match('/fbq\(\s*[\'"]init[\'"]\s*,\s*[\'"]?([0-9]{5,20})[\'"]?\s*[,)]/', $code, $m) === 1) {
            return $m[1];
        }
        if (preg_match('#facebook\.com/tr/?\?(?:[^"\'\s<>]*?[&;])?id=([0-9]{5,20})(?![0-9])#i', $code, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
