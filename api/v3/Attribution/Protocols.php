<?php

declare(strict_types=1);

namespace Api\V3\Attribution;

/**
 * The postback protocols this server receives, in one place. The
 * capabilities document advertises this list, the API filters validate
 * against it, and each /.well-known/ entry point's protocol must be in it —
 * so adding a protocol means adding it here, or the rest of the surface
 * does not know it exists.
 */
final class Protocols
{
    /** Canonical names, as stored in the `protocol` column. */
    public const NAMES = [SkadnetworkProtocol::NAME, AdAttributionKitProtocol::NAME];

    /** Shorthands people type; accepted wherever a protocol is a filter. */
    public const ALIASES = [
        'skan' => SkadnetworkProtocol::NAME,
        'aak' => AdAttributionKitProtocol::NAME,
    ];

    /** The canonical name for a name or alias (case-insensitive), or null. */
    public static function normalize(string $value): ?string
    {
        $value = strtolower(trim($value));
        if (in_array($value, self::NAMES, true)) {
            return $value;
        }
        return self::ALIASES[$value] ?? null;
    }
}
