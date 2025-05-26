<?php
declare(strict_types=1);

namespace Tracking202\Redirect;

class RedirectHelper
{
    public static function getIntParam(string $name): ?int
    {
        $value = $_GET[$name] ?? null;
        if ($value === null) {
            return null;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_INT);
        return $filtered === false ? null : $filtered;
    }

    public static function getStringParam(string $name): ?string
    {
        $value = $_GET[$name] ?? null;
        if ($value === null) {
            return null;
        }

        $filtered = filter_var($value, FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH);
        return is_string($filtered) ? trim($filtered) : null;
    }

    public static function redirect(string $url): void
    {
        header('Location: ' . $url);
        header('Connection: close');
        exit;
    }
}

