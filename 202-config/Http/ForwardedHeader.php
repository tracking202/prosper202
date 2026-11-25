<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * The RFC 7239 Forwarded header's value, parsed: a list of elements
 * (`for=192.0.2.60;proto=https, for="[2001:db8::17]:4711"`), each a set of
 * name=value pairs whose value is a token or a quoted string. A value that
 * does not follow the grammar is no list at all (null), never a partial one:
 * what follows a syntax error cannot be told from what a client wrote.
 *
 * Takes the header's text; reading it from the request is VisitorIp's
 * (ForwardedIpHeaderReadTest).
 */
final class ForwardedHeader
{
    /** Longer than any chain of MAX_ELEMENTS proxies writes; a longer one is not read. */
    public const MAX_BYTES = 4096;

    public const MAX_ELEMENTS = 20;

    private const TOKEN = '!#$%&\'*+.^_`|~-';

    private function __construct()
    {
    }

    /**
     * @return list<array<string, string>>|null each element's pairs, names in
     *         lower case; null when the value is not a Forwarded list
     */
    public static function elements(string $value): ?array
    {
        if (strlen($value) > self::MAX_BYTES) {
            return null;
        }
        $elements = [];
        $pairs = [];
        $len = strlen($value);
        $i = 0;
        $skipSpace = static function () use ($value, $len, &$i): void {
            while ($i < $len && ($value[$i] === ' ' || $value[$i] === "\t")) {
                $i++;
            }
        };
        while (true) {
            $skipSpace();
            if ($i < $len && $value[$i] !== ',' && $value[$i] !== ';') {
                $name = self::token($value, $i);
                if ($name === '' || $i >= $len || $value[$i] !== '=') {
                    return null;
                }
                $i++;
                if ($i < $len && $value[$i] === '"') {
                    $pairValue = self::quoted($value, $i);
                    if ($pairValue === null) {
                        return null;
                    }
                } else {
                    $pairValue = self::token($value, $i);
                    if ($pairValue === '') {
                        return null;
                    }
                }
                $name = strtolower($name);
                if (isset($pairs[$name])) {
                    return null;
                }
                $pairs[$name] = $pairValue;
                $skipSpace();
            }
            if ($i >= $len || $value[$i] === ',') {
                if ($pairs !== []) {
                    $elements[] = $pairs;
                    if (count($elements) > self::MAX_ELEMENTS) {
                        return null;
                    }
                }
                $pairs = [];
                if ($i >= $len) {
                    break;
                }
                $i++;
                continue;
            }
            if ($value[$i] !== ';') {
                return null;
            }
            $i++;
        }

        return $elements === [] ? null : $elements;
    }

    /**
     * A node (`for=`, `by=`) as an address in canonical form, its port
     * dropped; '' for `unknown`, an obfuscated identifier (`_hidden`), or
     * anything else that names no address.
     */
    public static function nodeAddress(string $node): string
    {
        $port = '(?::(?:[0-9]{1,5}|_[A-Za-z0-9._-]+))?';
        if (preg_match('/^\[([0-9A-Fa-f:.]+)\]' . $port . '$/D', $node, $m) === 1) {
            $address = filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        } elseif (preg_match('/^([0-9.]+)' . $port . '$/D', $node, $m) === 1) {
            $address = filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        } else {
            return '';
        }
        if ($address === false) {
            return '';
        }
        $packed = inet_pton($address);
        $canonical = $packed === false ? false : inet_ntop($packed);

        return $canonical === false ? '' : $canonical;
    }

    private static function token(string $value, int &$i): string
    {
        $start = $i;
        $len = strlen($value);
        while ($i < $len && (ctype_alnum($value[$i]) || str_contains(self::TOKEN, $value[$i]))) {
            $i++;
        }

        return substr($value, $start, $i - $start);
    }

    /** A quoted string from the opening quote at $i, unescaped; null when it never closes. */
    private static function quoted(string $value, int &$i): ?string
    {
        $len = strlen($value);
        $out = '';
        for ($i++; $i < $len; $i++) {
            $c = $value[$i];
            if ($c === '\\') {
                if (++$i >= $len) {
                    return null;
                }
                $out .= $value[$i];
                continue;
            }
            if ($c === '"') {
                $i++;

                return $out;
            }
            $out .= $c;
        }

        return null;
    }
}
