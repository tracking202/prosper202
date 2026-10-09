<?php

declare(strict_types=1);

namespace Tests\Cli;

use P202Cli\Application;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

/**
 * Every flag the PHP CLI declares or prints is kebab-case (--time-from), as
 * the Go CLI's are. The snake_case spelling is read as an alias by
 * KebabCaseArgvInput and is never declared or shown. Held here:
 *
 * - a declared option: every option of every registered command (the CRUD
 *   commands' are built from API field names at runtime) and of every other
 *   command class in cli/ that constructs without arguments, and every
 *   literal in the first argument of addOption(), getOption(), hasOption(),
 *   hasParameterOption(), getParameterOption() or new InputOption() in cli/;
 * - a flag in text: a string literal, heredoc, comment or docblock in cli/
 *   or bin/p202 that spells a flag with "_" in its name (filter[...] too);
 * - a flag built at runtime: a literal ending in "--" or in part of a flag
 *   name, joined to a value with "." or by interpolation, or a "--%s"
 *   format. OptionName::flag() is the one place a value becomes a flag, and
 *   it makes the name kebab-case.
 *
 * Not seen: a flag assembled another way (implode('--', ...), chr(45)), and
 * an option whose name is computed in a command that is neither registered
 * nor constructible without arguments.
 */
final class FlagsAreKebabCaseTest extends TestCase
{
    private const KEBAB_OPTION = '/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\[[a-z0-9]+(?:-[a-z0-9]+)*\])?$/D';

    private const SNAKE_FLAG = '/--[a-z0-9][a-z0-9\[-]*_/i';

    private const OPTION_CALLS = ['addoption', 'getoption', 'hasoption', 'hasparameteroption', 'getparameteroption'];

    private const TEXT_TOKENS = [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML, T_COMMENT, T_DOC_COMMENT];

    public function testEveryDeclaredOptionIsKebabCase(): void
    {
        $offenders = [];
        $checked = 0;
        foreach ((new Application())->all() as $name => $command) {
            $command->mergeApplicationDefinition();
            $offenders = [...$offenders, ...self::nonKebabOptions($name, $command)];
            $checked++;
        }
        foreach (self::constructibleCommandClasses() as $class) {
            $offenders = [...$offenders, ...self::nonKebabOptions($class, new $class())];
            $checked++;
        }

        self::assertSame([], $offenders);
        self::assertGreaterThan(150, $checked, 'the registered commands and the command classes were found');
    }

    public function testNoOptionIsNamedWithAnUnderscoreInTheSource(): void
    {
        $offenders = [];
        foreach (self::files(false) as $file) {
            $tokens = token_get_all((string) file_get_contents($file));
            foreach ($tokens as $i => $token) {
                if (!self::isOptionCall($tokens, $i)) {
                    continue;
                }
                foreach (self::firstArgumentLiterals($tokens, $i) as [$literal, $line]) {
                    if (str_contains($literal, '_')) {
                        $offenders[] = self::relative($file) . ':' . $line . ' ' . $literal;
                    }
                }
            }
        }

        self::assertSame([], $offenders);
    }

    public function testNoTextNamesASnakeCaseFlag(): void
    {
        $offenders = [];
        $texts = 0;
        foreach (self::files(true) as $file) {
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (!is_array($token) || !in_array($token[0], self::TEXT_TOKENS, true)) {
                    continue;
                }
                $texts++;
                if (preg_match_all(self::SNAKE_FLAG, $token[1], $m) > 0) {
                    $offenders[] = self::relative($file) . ':' . $token[2] . ' ' . implode(', ', $m[0]);
                }
            }
        }

        self::assertSame([], $offenders);
        self::assertGreaterThan(1000, $texts, 'the string literals and comments of cli/ and bin/p202 were read');
    }

    public function testNoFlagIsBuiltOutsideOptionName(): void
    {
        $offenders = [];
        foreach (self::files(true) as $file) {
            if (self::relative($file) === 'cli/OptionName.php') {
                continue;
            }
            $tokens = token_get_all((string) file_get_contents($file));
            foreach ($tokens as $i => $token) {
                if (!is_array($token) || !in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }
                $text = $token[0] === T_CONSTANT_ENCAPSED_STRING ? substr($token[1], 1, -1) : $token[1];
                $format = preg_match('/--[a-z0-9\[-]*%/i', $text) === 1;
                $joined = preg_match('/--[a-z0-9\[-]*$/iD', $text) === 1 && self::isJoinedToAValue($tokens, $i);
                if ($format || $joined) {
                    $offenders[] = self::relative($file) . ':' . $token[2] . ' ' . trim($token[1]);
                }
            }
        }

        self::assertSame([], $offenders, 'build a flag from a value with OptionName::flag()');
    }

    /** @return list<string> */
    private static function nonKebabOptions(string $where, Command $command): array
    {
        $offenders = [];
        foreach ($command->getDefinition()->getOptions() as $option) {
            if (preg_match(self::KEBAB_OPTION, $option->getName()) !== 1) {
                $offenders[] = $where . ': --' . $option->getName();
            }
        }

        return $offenders;
    }

    /** @return list<class-string<Command>> */
    private static function constructibleCommandClasses(): array
    {
        $classes = [];
        foreach (self::files(false) as $file) {
            $class = 'P202Cli\\' . str_replace('/', '\\', substr(self::relative($file), strlen('cli/'), -strlen('.php')));
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            $constructor = $reflection->getConstructor();
            if ($reflection->isSubclassOf(Command::class) && !$reflection->isAbstract()
                && ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0)) {
                $classes[] = $class;
            }
        }
        self::assertNotSame([], $classes);

        return $classes;
    }

    /** @param array<int, mixed> $tokens */
    private static function isOptionCall(array $tokens, int $i): bool
    {
        $token = $tokens[$i];
        if (!is_array($token)) {
            return false;
        }
        $name = strtolower(ltrim($token[1], '\\'));
        if ($token[0] === T_STRING && in_array($name, self::OPTION_CALLS, true)) {
            return true;
        }
        if (!in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            || !str_ends_with('\\' . $name, '\\inputoption')) {
            return false;
        }
        $previous = self::skipWhitespace($tokens, $i - 1, -1);

        return $previous !== null && is_array($tokens[$previous]) && $tokens[$previous][0] === T_NEW;
    }

    /**
     * The string literals in the first argument of the call whose name is at
     * $i: up to the first "," or ")" outside brackets.
     *
     * @param array<int, mixed> $tokens
     * @return list<array{string, int}>
     */
    private static function firstArgumentLiterals(array $tokens, int $i): array
    {
        $open = self::skipWhitespace($tokens, $i + 1, 1);
        if ($open === null || $tokens[$open] !== '(') {
            return [];
        }
        $literals = [];
        $depth = 0;
        $line = 0;
        for ($j = $open + 1, $n = count($tokens); $j < $n; $j++) {
            $token = $tokens[$j];
            if (is_array($token)) {
                $line = $token[2];
                if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    $literals[] = [$token[1], $line];
                }
                continue;
            }
            if (in_array($token, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($token, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($token === ',' && $depth === 0) {
                break;
            }
        }

        return $literals;
    }

    /**
     * Whether the literal at $i is continued by a value: "." after it, or,
     * inside an interpolated string, a variable after it.
     *
     * @param array<int, mixed> $tokens
     */
    private static function isJoinedToAValue(array $tokens, int $i): bool
    {
        $next = $tokens[$i + 1] ?? null;
        if (is_array($next) && in_array($next[0], [T_VARIABLE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            return true;
        }
        $after = $i;
        if ($tokens[$i][0] === T_ENCAPSED_AND_WHITESPACE) {
            if (!($next === '"' || (is_array($next) && $next[0] === T_END_HEREDOC))) {
                return false;
            }
            $after = $i + 1;
        }
        $following = self::skipWhitespace($tokens, $after + 1, 1);

        return $following !== null && $tokens[$following] === '.';
    }

    /** @param array<int, mixed> $tokens */
    private static function skipWhitespace(array $tokens, int $j, int $step): ?int
    {
        while (isset($tokens[$j]) && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $j += $step;
        }

        return isset($tokens[$j]) ? $j : null;
    }

    /** @return list<string> every PHP file in cli/, and bin/p202 when asked */
    private static function files(bool $withBin): array
    {
        $root = self::root();
        $files = [];
        foreach (new \RecursiveIteratorIterator(\Tests\Support\SourceScan::tree($root . '/cli')) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        self::assertGreaterThan(80, count($files), 'cli/ was found');
        if ($withBin) {
            $files[] = $root . '/bin/p202';
        }

        return $files;
    }

    private static function relative(string $file): string
    {
        return substr($file, strlen(self::root()) + 1);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
