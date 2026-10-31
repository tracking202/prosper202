<?php

declare(strict_types=1);

namespace Prosper202\Click\Filter;

use InvalidArgumentException;

/**
 * One entry's addresses: a single IPv4 or IPv6 address, or a CIDR block.
 *
 * Read strictly, so what is stored is what was meant: a block whose address
 * is not where the block starts (203.0.113.7/24) is refused naming the
 * block that holds it, and an IPv4 address written as IPv6
 * (::ffff:203.0.113.7) is refused naming the IPv4 spelling, since a click
 * from it is compared as IPv4 (packedForMatch()). A block wider than /8
 * (IPv4) or /16 (IPv6) is refused: it is a typo far more often than a wish
 * to count or filter a sixteenth of the internet.
 */
final class AddressRange
{
    public const IPV4_WIDEST = 8;
    public const IPV6_WIDEST = 16;

    private function __construct(
        public readonly int $family,
        public readonly string $start,
        public readonly string $end,
        public readonly int $prefix,
    ) {
    }

    /** @throws InvalidArgumentException with the sentence to show, naming the value */
    public static function parse(mixed $value): self
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('must be an IP address or a CIDR block, as text');
        }
        $text = trim($value);
        if ($text === '') {
            throw new InvalidArgumentException('must be an IP address (203.0.113.7) or a CIDR block (203.0.113.0/24)');
        }
        $parts = explode('/', $text);
        if (count($parts) > 2) {
            throw new InvalidArgumentException("\"$text\" is not an IP address or a CIDR block");
        }
        $address = $parts[0];
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException("\"$address\" is not an IPv4 or IPv6 address");
        }
        $packed = inet_pton($address);
        if ($packed === false) {
            throw new InvalidArgumentException("\"$address\" is not an IPv4 or IPv6 address");
        }
        $mapped = self::unmapped($packed);
        if ($mapped !== $packed) {
            throw new InvalidArgumentException(
                "\"$address\" is an IPv4 address written as IPv6: write it as " . (string) inet_ntop($mapped)
            );
        }
        $family = strlen($packed) === 4 ? 4 : 6;
        $bits = $family === 4 ? 32 : 128;
        $prefix = $bits;
        if (count($parts) === 2) {
            $digits = $parts[1];
            if (preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/', $digits) !== 1 || (int) $digits > $bits) {
                throw new InvalidArgumentException("\"/$digits\" is not a prefix length for IPv$family (0 to $bits)");
            }
            $prefix = (int) $digits;
        }
        $widest = $family === 4 ? self::IPV4_WIDEST : self::IPV6_WIDEST;
        if ($prefix < $widest) {
            throw new InvalidArgumentException("a /$prefix block is too wide: the widest an entry takes is /$widest for IPv$family");
        }
        $start = self::masked($packed, $prefix, false);
        if ($start !== $packed) {
            $block = (string) inet_ntop($start) . '/' . $prefix;
            throw new InvalidArgumentException(
                "\"$text\" is not where a /$prefix block starts: the /$prefix holding " . (string) inet_ntop($packed)
                . " is $block"
            );
        }

        return new self($family, $start, self::masked($packed, $prefix, true), $prefix);
    }

    /** A range as stored (MysqlClickFilterStore). */
    public static function fromStored(int $family, string $start, int $prefix): self
    {
        $bits = $family === 4 ? 32 : ($family === 6 ? 128 : 0);
        if ($bits === 0 || strlen($start) !== intdiv($bits, 8) || $prefix < 0 || $prefix > $bits) {
            throw new \UnexpectedValueException('A stored click filter entry is not a range (family ' . $family . ', prefix ' . $prefix . ')');
        }

        return new self($family, $start, self::masked($start, $prefix, true), $prefix);
    }

    /**
     * The address of a click as entries are compared with it: packed, and an
     * IPv4 address that arrived as IPv4-mapped IPv6 as IPv4. Null when it is
     * no address (a request with none).
     */
    public static function packedForMatch(string $address): ?string
    {
        if ($address === '' || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($address);

        return $packed === false ? null : self::unmapped($packed);
    }

    /** "203.0.113.7" for one address, "203.0.113.0/24" for a block. */
    public function text(): string
    {
        $address = (string) inet_ntop($this->start);

        return $this->prefix === ($this->family === 4 ? 32 : 128) ? $address : $address . '/' . $this->prefix;
    }

    public function contains(string $packed): bool
    {
        return strlen($packed) === strlen($this->start)
            && strcmp($this->start, $packed) <= 0
            && strcmp($packed, $this->end) <= 0;
    }

    private static function unmapped(string $packed): string
    {
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return substr($packed, 12);
        }

        return $packed;
    }

    /** The first ($fill false) or last ($fill true) address of the /$prefix holding $packed. */
    private static function masked(string $packed, int $prefix, bool $fill): string
    {
        $out = '';
        $length = strlen($packed);
        for ($i = 0; $i < $length; $i++) {
            $keep = max(0, min(8, $prefix - $i * 8));
            $mask = $keep === 0 ? 0 : (0xFF << (8 - $keep)) & 0xFF;
            $byte = ord($packed[$i]) & $mask;
            if ($fill) {
                $byte |= ~$mask & 0xFF;
            }
            $out .= chr($byte);
        }

        return $out;
    }
}
