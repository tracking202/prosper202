<?php

declare(strict_types=1);

namespace P202Cli;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputDefinition;

/**
 * argv with every long option name read in kebab-case, so the snake_case
 * spelling the options had before still works. Only the name is rewritten,
 * never a value, and nothing after a bare "--". A separate value token is
 * left alone: ArgvInput never takes a token that starts with "-" as a value.
 */
final class KebabCaseArgvInput extends ArgvInput
{
    /** @param list<string>|null $argv the application name first, as in $_SERVER['argv'] */
    public function __construct(?array $argv = null, ?InputDefinition $definition = null)
    {
        parent::__construct(self::kebabCaseOptionNames(array_values($argv ?? $_SERVER['argv'] ?? [])), $definition);
    }

    /**
     * @param list<string> $argv the application name first
     * @return list<string>
     */
    public static function kebabCaseOptionNames(array $argv): array
    {
        foreach ($argv as $i => $token) {
            if ($i === 0) {
                continue;
            }
            if ($token === '--') {
                break;
            }
            if (!str_starts_with($token, '--')) {
                continue;
            }
            $equals = strpos($token, '=');
            $name = $equals === false ? substr($token, 2) : substr($token, 2, $equals - 2);
            $argv[$i] = OptionName::flag($name) . ($equals === false ? '' : substr($token, $equals));
        }

        return $argv;
    }
}
