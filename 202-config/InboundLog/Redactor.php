<?php

declare(strict_types=1);

namespace Prosper202\InboundLog;

/**
 * What the log keeps of a request's fields: every name, and each value
 * unless it is a secret (signature, key, token) or about a person (address,
 * email, name, phone). Redacted by name and, whatever the name, by value.
 */
final class Redactor
{
    public const REDACTED = '[redacted]';
    public const MAX_FIELDS = 40;
    public const MAX_VALUE = 120;
    public const MAX_BYTES = 4000;

    private const SECRET_NAME = '/sig|secret|key|token|hash|verif|pass|auth|cred|nonce|cipher|^iv$|^notification$/i';
    private const VISITOR_NAME = '/mail|name|phone|addr|street|city|zip|postal|card|birth|ssn|agent|refer|(^|[^a-z])ip($|[^a-z])/i';
    private const EMAIL_VALUE = '/[^@\s]+@[^@\s]+\.[a-z]{2,}/i';
    private const IPV4_VALUE = '/(^|[^0-9.])(\d{1,3}\.){3}\d{1,3}($|[^0-9.])/';

    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $fields
     * @return array<string, mixed>
     */
    public static function fields(array $fields): array
    {
        $out = [];
        $kept = 0;
        foreach ($fields as $name => $value) {
            if ($kept === self::MAX_FIELDS) {
                $out['_more_fields'] = count($fields) - $kept;
                break;
            }
            $out[self::text((string) $name, 64)] = self::value((string) $name, $value, 0);
            $kept++;
        }

        return $out;
    }

    /** The fields as the log stores them: JSON, at most MAX_BYTES; null for none. */
    public static function encode(array $redacted): ?string
    {
        if ($redacted === []) {
            return null;
        }
        while (true) {
            $json = json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false) {
                return null;
            }
            if (strlen($json) <= self::MAX_BYTES || count($redacted) <= 1) {
                return strlen($json) <= self::MAX_BYTES ? $json : null;
            }
            $more = (int) ($redacted['_more_fields'] ?? 0);
            unset($redacted['_more_fields']);
            array_pop($redacted);
            $redacted['_more_fields'] = $more + 1;
        }
    }

    private static function value(string $name, mixed $value, int $depth): mixed
    {
        if (preg_match(self::SECRET_NAME, $name) === 1 || preg_match(self::VISITOR_NAME, $name) === 1) {
            return self::REDACTED;
        }
        if (is_array($value)) {
            if ($depth >= 2) {
                return self::REDACTED;
            }
            $out = [];
            foreach (array_slice($value, 0, self::MAX_FIELDS, true) as $k => $v) {
                $out[self::text((string) $k, 64)] = self::value((string) $k, $v, $depth + 1);
            }

            return $out;
        }
        if (!is_scalar($value) && $value !== null) {
            return self::REDACTED;
        }
        $text = (string) $value;
        if (preg_match(self::EMAIL_VALUE, $text) === 1 || preg_match(self::IPV4_VALUE, $text) === 1
            || (str_contains($text, ':') && filter_var(trim($text, '[]'), FILTER_VALIDATE_IP) !== false)) {
            return self::REDACTED;
        }

        return self::text($text, self::MAX_VALUE);
    }

    private static function text(string $text, int $max): string
    {
        return mb_strlen($text, 'UTF-8') > $max ? mb_substr($text, 0, $max, 'UTF-8') . '…' : $text;
    }
}
