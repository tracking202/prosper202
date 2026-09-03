<?php

declare(strict_types=1);

namespace Api\V3\Support;

/**
 * Sanitizes visitor-authored strings before they leave the API.
 *
 * Keywords, city/region/ISP names, and browser/platform/device names are
 * authored (directly or via derivation) by whoever clicks a tracking link.
 * Anything an AI agent or dashboard renders from those fields is untrusted
 * third-party text, so serialization strips the characters that let a
 * hostile value imitate terminal output, reorder rendered text, or smuggle
 * invisible content, and caps the length so one row cannot flood a context
 * window. The stored value is never modified — this runs on the way out.
 */
final class ResponseSanitizer
{
    public const MAX_FIELD_LENGTH = 512;

    /** Appended when a value is cut at MAX_FIELD_LENGTH so truncation is visible. */
    public const TRUNCATION_MARKER = '…[truncated]';

    /**
     * Characters removed outright:
     *  - C0 controls (incl. NUL/ESC; \t \n \r are replaced by a space first
     *    so words don't fuse) and DEL
     *  - C1 controls U+0080–U+009F
     *  - zero-width and joiner characters U+200B–U+200F, U+FEFF
     *  - bidirectional embedding/override/isolate controls U+202A–U+202E,
     *    U+2066–U+2069
     */
    private const STRIP_PATTERN = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|[\x{0080}-\x{009F}\x{200B}-\x{200F}\x{FEFF}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    public static function cleanVisitorString(?string $value, int $maxLength = self::MAX_FIELD_LENGTH): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // Invalid UTF-8 makes /u patterns fail; drop the invalid sequences
        // first so sanitization cannot be bypassed with malformed bytes.
        if (!mb_check_encoding($value, 'UTF-8')) {
            $converted = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            $value = is_string($converted) ? $converted : '';
        }

        $value = str_replace(["\t", "\n", "\r"], ' ', $value);
        $stripped = preg_replace(self::STRIP_PATTERN, '', $value);
        // preg_replace returns null only on engine failure; fail closed to
        // an empty string rather than passing the raw value through.
        $value = $stripped ?? '';

        if (mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength) . self::TRUNCATION_MARKER;
        }

        return $value;
    }

    /**
     * Sanitize the named keys of one row in place, leaving absent or
     * non-string values untouched.
     *
     * @param string[] $keys
     */
    public static function cleanRowFields(array $row, array $keys, int $maxLength = self::MAX_FIELD_LENGTH): array
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_string($row[$key])) {
                $row[$key] = self::cleanVisitorString($row[$key], $maxLength);
            }
        }
        return $row;
    }
}
