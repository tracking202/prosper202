<?php

declare(strict_types=1);

namespace P202Cli;

/**
 * Options are spelled in kebab-case, while the API fields many of them carry
 * are snake_case. A command keeps the API field name, sends that, and asks
 * this for the option that carries it.
 */
final class OptionName
{
    private function __construct()
    {
    }

    /** The option for an API field: aff_campaign_id is aff-campaign-id. An option name comes back unchanged. */
    public static function of(string $field): string
    {
        return str_replace('_', '-', $field);
    }

    /** The option as a message names it: --aff-campaign-id. */
    public static function flag(string $field): string
    {
        return '--' . self::of($field);
    }
}
