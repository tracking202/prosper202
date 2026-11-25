<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

/**
 * What a delivery's log says of its last attempt (last_response_body): the
 * receiver's answer, or "curl: …", "blocked: …", "error: …", or a reason
 * the dispatcher wrote. A receiver's answer is any bytes, and one that
 * echoes what it was sent, or reports its own sign-in failing, can carry a
 * credential: the webhook's signing secret, an Authorization or Cookie
 * header, a bearer token. redact() takes those out before the answer is
 * stored, and again where it is read, for rows stored before it did.
 */
final class DeliveryReason
{
    public const REMOVED = '[removed]';

    /** How much of an answer the Log shows on its row. */
    public const LINE_CHARS = 140;

    /**
     * $text with the webhook's $secret (when it is known and long enough to
     * be one) and every credential-shaped value taken out. Byte-safe: it
     * matches ASCII only, so an answer that is not UTF-8 is left for the
     * writer to scrub.
     */
    public static function redact(string $text, string $secret = ''): string
    {
        if (strlen($secret) >= 8) {
            $text = str_replace($secret, self::REMOVED, $text);
        }
        // A name ending in one of these words (a token only as x_token or
        // x-token, so "invalid token: expired" keeps its reason), then ":"
        // or "=" (a header, a query string, a JSON key), then its value; a
        // value already removed is left, so a stored answer read again reads
        // the same.
        $text = preg_replace_callback(
            '/(?<![A-Za-z0-9_-])'
                . '([A-Za-z0-9_-]*(?:authorization|cookie|api[_-]?key|secret|password)'
                . '|[A-Za-z0-9_-]*[_-]token|x-p202-signature)'
                . '(["\']?[ \t]*[:=][ \t]*)(\[removed\]|"[^"]*"?|\'[^\']*\'?|[^\r\n"\',;&}\]]*)/i',
            static function (array $m): string {
                $quote = ($m[3] !== '' && ($m[3][0] === '"' || $m[3][0] === "'")) ? $m[3][0] : '';
                return $m[1] . $m[2] . $quote . self::REMOVED . $quote;
            },
            $text
        ) ?? throw new \RuntimeException('The delivery answer could not be redacted.');

        return preg_replace('/\b(Bearer|Basic)[ \t]+[A-Za-z0-9._~+\/=-]+/i', '$1 ' . self::REMOVED, $text)
            ?? throw new \RuntimeException('The delivery answer could not be redacted.');
    }

    /** What is stored in place of an answer that could not be redacted. */
    public const WITHHELD = 'answer withheld: could not be redacted';

    /** How much of an answer is looked at for credentials; the rest is dropped. */
    public const SCAN_BYTES = 8192;

    /**
     * redact() for an answer of any size, never throwing: the webhook's
     * secret is taken out of the whole answer, the rest is cut to
     * SCAN_BYTES, and an answer the patterns cannot handle is replaced by
     * WITHHELD, never kept raw.
     */
    public static function redactSafely(string $text, string $secret = ''): string
    {
        try {
            if (strlen($secret) >= 8) {
                $text = str_replace($secret, self::REMOVED, $text);
            }

            return self::redact(mb_strcut(mb_scrub($text, 'UTF-8'), 0, self::SCAN_BYTES, 'UTF-8'));
        } catch (\Throwable) {
            return self::WITHHELD;
        }
    }

    /**
     * The answer as the Log's one line: valid UTF-8, every run of
     * whitespace and control characters one space, cut to $max characters
     * with an ellipsis. Not escaped: the page escapes it.
     */
    public static function line(?string $text, int $max = self::LINE_CHARS): string
    {
        if ($text === null) {
            return '';
        }
        $line = preg_replace('/[\s\x{0}-\x{1F}\x{7F}\x{85}\x{2028}\x{2029}]+/u', ' ', mb_scrub($text, 'UTF-8'))
            ?? throw new \RuntimeException('The delivery answer could not be put on one line.');
        $line = trim($line);
        if (mb_strlen($line, 'UTF-8') <= $max) {
            return $line;
        }

        return rtrim(mb_substr($line, 0, max(1, $max - 1), 'UTF-8')) . '…';
    }
}
