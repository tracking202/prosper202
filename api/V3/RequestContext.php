<?php

declare(strict_types=1);

namespace Api\V3;

/**
 * Per-request metadata accessible from controllers/services without threading
 * headers through every constructor.
 */
final class RequestContext
{
    /** @var array<string, string> */
    private static array $headers = [];
    private static int $actorUserId = 0;
    private static string $resolvedApiVersion = 'v3';

    private function __construct()
    {
    }

    public static function setHeaders(array $headers): void
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $name = strtolower((string)$key);
            if (is_array($value)) {
                $value = (string)($value[0] ?? '');
            }
            $normalized[$name] = trim((string)$value);
        }
        self::$headers = $normalized;
    }

    public static function header(string $name, ?string $default = null): ?string
    {
        $key = strtolower($name);
        if (!array_key_exists($key, self::$headers)) {
            return $default;
        }
        $value = self::$headers[$key];
        return $value === '' ? $default : $value;
    }

    /** @return array<string, string> */
    public static function headers(): array
    {
        return self::$headers;
    }

    public static function setActorUserId(int $userId): void
    {
        self::$actorUserId = $userId;
    }

    public static function actorUserId(): int
    {
        return self::$actorUserId;
    }

    public static function setResolvedApiVersion(string $version): void
    {
        self::$resolvedApiVersion = $version;
    }

    public static function resolvedApiVersion(): string
    {
        return self::$resolvedApiVersion;
    }

    /** @internal */
    public static function reset(): void
    {
        self::$headers = [];
        self::$actorUserId = 0;
        self::$resolvedApiVersion = 'v3';
    }
}
