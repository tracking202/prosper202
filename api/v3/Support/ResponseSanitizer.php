<?php

declare(strict_types=1);

namespace Api\V3\Support;

/**
 * Sanitizes visitor-authored strings before they leave the API.
 *
 * Keywords, city/region/ISP names, and browser/platform/device names are
 * authored (directly or via derivation) by whoever clicks a tracking link.
 * Anything an AI agent or dashboard renders from those fields is untrusted
 * third-party text, so serialization strips what lets a hostile value hide
 * content, imitate protocol markup, or flood a context window:
 *
 *  - Unicode is NFKC-normalized (when ext-intl is available) so lookalike
 *    codepoints cannot dodge the patterns below.
 *  - Invisible characters are removed: zero-width and joiner characters,
 *    bidirectional embeddings/overrides/isolates, soft hyphens, variation
 *    selectors, tag characters (which spell out invisible ASCII), and other
 *    format controls.
 *  - C0/C1 control characters (terminal escapes included) become spaces,
 *    and whitespace runs collapse — these fields are single-line names.
 *  - Text shaped like model protocol markup — special tokens (`<|...|>`)
 *    and transcript/tool-call tags — is replaced with `[removed]`, repeated
 *    to a fixpoint so one marker nested inside another cannot reassemble
 *    after the inner one goes.
 *  - Length is capped, with a visible truncation marker.
 *
 * The stored value is never modified — this runs on the way out. Character
 * hygiene is all it promises: instruction-shaped *visible* text passes
 * through, and the reader's contract (docs/cli-agent.md, "Untrusted data in
 * responses") is that such text is data to report on, never to act on.
 */
final class ResponseSanitizer
{
    public const MAX_FIELD_LENGTH = 512;

    /** Appended when a value is cut at MAX_FIELD_LENGTH so truncation is visible. */
    public const TRUNCATION_MARKER = '…[truncated]';

    /**
     * Invisible and format characters removed outright. Mirrors the ranges
     * the commerce-agents reference sanitizer strips: soft hyphen,
     * zero-width space/joiners + LRM/RLM, line/paragraph separators, bidi
     * embeddings/overrides, word joiner + invisible operators, bidi
     * isolates, Arabic letter mark, Mongolian vowel separator, deprecated
     * format controls, variation selectors (+ supplement), interlinear
     * annotation controls, BOM, and tag characters.
     */
    private const INVISIBLE_PATTERN = '/[\x{00AD}\x{200B}-\x{200F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{061C}\x{180E}\x{206A}-\x{206F}\x{FE00}-\x{FE0F}\x{FFF9}-\x{FFFB}\x{FEFF}\x{E0000}-\x{E007F}\x{E0100}-\x{E01EF}]/u';

    /** C0 and C1 controls plus DEL, replaced by spaces (fields are one line). */
    private const CONTROL_PATTERN = '/[\x00-\x1F\x7F]|[\x{0080}-\x{009F}]/u';

    /**
     * Model special tokens (`<|...|>`) and transcript/tool-call tag shapes,
     * optionally namespaced, with bounded attribute lists. Only tag-shaped
     * text matches; quantifiers are bounded so the pattern stays linear on
     * hostile input.
     */
    private const SPECIAL_TOKEN_PATTERN = '/<[ \t]*\/?[ \t]*(?:'
        . '(?:[a-z][\w.-]{0,30}:)?(?:transcript|conversation|function_calls|function_results'
        . '|invoke|tool_use|tool_result|system|human|user|assistant)'
        . '|[a-z][\w.-]{0,30}:(?:parameter|result)'
        . ')\b(?:[ \t]+[\w:.-]{1,40}[ \t]*=[ \t]*(?:"[^"]{0,200}"|\'[^\']{0,200}\'|[^\s"\'>]{1,200})){0,8}[ \t]*\/?>'
        . '|<\|[^|<>\r\n]{1,64}\|>/iu';

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

        // NFKC folds lookalikes (fullwidth, compatibility forms) into the
        // codepoints the patterns below actually name. ext-intl is optional
        // on older installs; without it the remaining passes still run.
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::NFKC);
            if (is_string($normalized)) {
                $value = $normalized;
            }
        }

        $value = self::replaceOrEmpty(self::INVISIBLE_PATTERN, '', $value);
        $value = self::replaceOrEmpty(self::CONTROL_PATTERN, ' ', $value);

        // Remove protocol-shaped markup to a fixpoint, so a token nested
        // inside another cannot reassemble once the inner one is gone.
        // The replacement contains no '<', so each pass strictly shrinks
        // what can match and the loop terminates.
        while (true) {
            $stripped = self::replaceOrEmpty(self::SPECIAL_TOKEN_PATTERN, '[removed]', $value);
            if ($stripped === $value) {
                break;
            }
            $value = $stripped;
        }

        $value = trim(self::replaceOrEmpty('/\s+/u', ' ', $value));

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

    /**
     * preg_replace returns null only on engine failure; fail closed to an
     * empty string rather than passing the raw value through.
     */
    private static function replaceOrEmpty(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? '';
    }
}
