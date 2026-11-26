<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * docs/openapi.yaml read as nested blocks by indentation, enough to follow an
 * operation to the properties of the schema it sends or answers.
 *
 * The test suite has no YAML parser, so this reads the block layout the spec
 * is written in: a key on its own line at some indentation, its children on
 * the lines below at one deeper indentation, `$ref` to a component schema. A
 * schema written with allOf, oneOf or anyOf, or a property it cannot read, is
 * refused by name rather than read as having no properties (CLAUDE.md #20).
 * scripts/check-openapi-yaml.py holds the file to being YAML at all.
 */
final class OpenApiBlocks
{
    /** @var list<string> */
    private array $lines;

    public function __construct(?string $path = null)
    {
        $lines = file($path ?? dirname(__DIR__, 2) . '/docs/openapi.yaml', FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('cannot read docs/openapi.yaml');
        }
        $this->lines = $lines;
    }

    /**
     * The properties of the schema an operation's JSON request body sends,
     * or null when the operation takes no JSON body.
     *
     * @return array<string, array{line: int, text: string}>|null
     */
    public function requestProperties(string $method, string $path): ?array
    {
        $op = $this->operation($method, $path);
        $body = $this->child($op, 'requestBody');
        if ($body === null) {
            return null;
        }
        $schema = $this->at($body, ['content', 'application/json', 'schema'], "$method $path requestBody");

        return $this->properties($schema, "$method $path requestBody");
    }

    /**
     * The names an operation's JSON request body schema lists as `required`
     * (a flow list `[a, b]` or a block list of `- a` lines), sorted; an
     * empty list when the schema lists none, null when there is no body.
     *
     * @return list<string>|null
     */
    public function requestRequired(string $method, string $path): ?array
    {
        $op = $this->operation($method, $path);
        $body = $this->child($op, 'requestBody');
        if ($body === null) {
            return null;
        }
        $where = "$method $path requestBody";
        $schema = $this->resolve($this->at($body, ['content', 'application/json', 'schema'], $where), $where);
        $line = $this->child($schema, 'required');
        if ($line === null) {
            return [];
        }
        $own = $this->valueText($line);
        if ($own !== '') {
            if (preg_match('/^\[([^\[\]]*)\]$/', $own, $m) !== 1) {
                throw new RuntimeException(
                    'line ' . ($line + 1) . ": required '$own' is not a list this reader follows"
                );
            }
            $names = $m[1] === ''
                ? []
                : array_map(static fn (string $n): string => self::unquote(trim($n)), explode(',', $m[1]));
        } else {
            $names = [];
            $indent = self::indent($this->lines[$line]);
            for ($i = $line + 1, $count = count($this->lines); $i < $count; $i++) {
                $text = $this->lines[$i];
                if (trim($text) === '' || str_starts_with(ltrim($text), '#')) {
                    continue;
                }
                $at = self::indent($text);
                if ($at < $indent || ($at === $indent && !str_starts_with(ltrim($text), '- '))) {
                    break;
                }
                if (preg_match('/^\s*- (\S+)\s*$/', $text, $m) !== 1) {
                    throw new RuntimeException('line ' . ($i + 1) . ': a required entry this reader does not follow');
                }
                $names[] = self::unquote($m[1]);
            }
            if ($names === []) {
                throw new RuntimeException('line ' . ($line + 1) . ': required with no list');
            }
        }
        sort($names);

        return $names;
    }

    /**
     * The properties of the object an operation's JSON answer carries under
     * `data`, for the given status.
     *
     * @return array<string, array{line: int, text: string}>
     */
    public function responseDataProperties(string $method, string $path, string $status): array
    {
        $op = $this->operation($method, $path);
        $where = "$method $path $status";
        $schema = $this->at($op, ['responses', $status, 'content', 'application/json', 'schema'], $where);
        $envelope = $this->properties($schema, $where);
        if (!isset($envelope['data'])) {
            throw new RuntimeException("$where: the answer has no data property");
        }

        return $this->properties($envelope['data']['line'], "$where data");
    }

    /**
     * A property's maxLength, or null when it declares none.
     *
     * @param array{line: int, text: string} $property
     */
    public function maxLength(array $property): ?int
    {
        $value = $this->scalar($property['line'], 'maxLength');
        if ($value === null) {
            return null;
        }
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new RuntimeException(
                'line ' . ($property['line'] + 1) . ": maxLength '$value' is not a whole number"
            );
        }

        return (int) $value;
    }

    /**
     * Whether a property lists its values (`enum`), which bounds its length
     * without a maxLength.
     *
     * @param array{line: int, text: string} $property
     */
    public function hasEnum(array $property): bool
    {
        $own = $this->valueText($property['line']);
        if ($own !== '' && str_starts_with($own, '{')) {
            return preg_match('/[{,]\s*enum:/', $own) === 1;
        }

        return $this->child($property['line'], 'enum') !== null;
    }

    /** The line number (1-based) of a component schema's head. */
    public function componentLine(string $name): int
    {
        return $this->component($name) + 1;
    }

    private function operation(string $method, string $path): int
    {
        $paths = $this->topLevel('paths');
        $pathLine = $this->child($paths, $path);
        if ($pathLine === null) {
            throw new RuntimeException("docs/openapi.yaml has no path $path");
        }
        $op = $this->child($pathLine, strtolower($method));
        if ($op === null) {
            throw new RuntimeException("docs/openapi.yaml has no $method $path");
        }

        return $op;
    }

    /** @param list<string> $keys */
    private function at(int $line, array $keys, string $where): int
    {
        foreach ($keys as $key) {
            $next = $this->child($line, $key);
            if ($next === null) {
                throw new RuntimeException("$where: no '$key' under line " . ($line + 1));
            }
            $line = $next;
        }

        return $line;
    }

    /** @return array<string, array{line: int, text: string}> */
    private function properties(int $schemaLine, string $where): array
    {
        $schemaLine = $this->resolve($schemaLine, $where);
        foreach (['allOf', 'oneOf', 'anyOf'] as $composed) {
            if ($this->child($schemaLine, $composed) !== null) {
                throw new RuntimeException(
                    "$where: the schema at line " . ($schemaLine + 1)
                    . " is written with $composed, which this reader does not follow"
                );
            }
        }
        $props = $this->child($schemaLine, 'properties');
        if ($props === null) {
            throw new RuntimeException("$where: the schema at line " . ($schemaLine + 1) . ' has no properties');
        }
        $out = [];
        foreach ($this->children($props) as $line) {
            $out[$this->keyOf($line)] = ['line' => $line, 'text' => $this->lines[$line]];
        }

        return $out;
    }

    /** Follows a `$ref` to a component schema; a schema written in place is itself. */
    private function resolve(int $schemaLine, string $where): int
    {
        for ($hops = 0; $hops < 10; $hops++) {
            $ref = $this->scalar($schemaLine, '$ref');
            if ($ref === null) {
                return $schemaLine;
            }
            if (preg_match('~^#/components/schemas/([A-Za-z0-9_.-]+)$~', $ref, $m) !== 1) {
                throw new RuntimeException("$where: \$ref '$ref' is not a component schema");
            }
            $schemaLine = $this->component($m[1]);
        }
        throw new RuntimeException("$where: \$ref chain too long");
    }

    private function component(string $name): int
    {
        $components = $this->topLevel('components');
        $schemas = $this->child($components, 'schemas');
        $line = $schemas === null ? null : $this->child($schemas, $name);
        if ($line === null) {
            throw new RuntimeException("docs/openapi.yaml has no component schema $name");
        }

        return $line;
    }

    /**
     * A child's scalar value: the text after `key:` on a child line, or the
     * same key inside a flow mapping on the line itself (`{type: string,
     * maxLength: 50}`), quotes removed. Null when absent.
     */
    private function scalar(int $line, string $key): ?string
    {
        $own = $this->valueText($line);
        if ($own !== '' && str_starts_with($own, '{')) {
            if (!str_ends_with($own, '}')) {
                throw new RuntimeException(
                    'line ' . ($line + 1) . ': a flow mapping over several lines, which this reader does not follow'
                );
            }
            if (preg_match('/[{,]\s*' . preg_quote($key, '/') . ':\s*([^,}]+)/', $own, $m) === 1) {
                return self::unquote(trim($m[1]));
            }
            return null;
        }
        $child = $this->child($line, $key);
        if ($child === null) {
            return null;
        }

        return self::unquote($this->valueText($child));
    }

    private function topLevel(string $key): int
    {
        foreach ($this->lines as $i => $text) {
            if ($text === "$key:") {
                return $i;
            }
        }
        throw new RuntimeException("docs/openapi.yaml has no top-level $key");
    }

    private function child(int $line, string $key): ?int
    {
        foreach ($this->children($line) as $c) {
            if ($this->keyOf($c) === $key) {
                return $c;
            }
        }

        return null;
    }

    /** @return list<int> the lines of a block's direct children */
    private function children(int $line): array
    {
        $indent = self::indent($this->lines[$line]);
        $childIndent = null;
        $out = [];
        $count = count($this->lines);
        for ($i = $line + 1; $i < $count; $i++) {
            $text = $this->lines[$i];
            if (trim($text) === '' || str_starts_with(ltrim($text), '#')) {
                continue;
            }
            $at = self::indent($text);
            if ($at <= $indent) {
                break;
            }
            $childIndent ??= $at;
            if ($at === $childIndent && !str_starts_with(ltrim($text), '- ')) {
                $out[] = $i;
            }
        }

        return $out;
    }

    private function keyOf(int $line): string
    {
        $text = ltrim($this->lines[$line]);
        if (preg_match('/^(\'[^\']*\'|"[^"]*"|[^\s:][^:]*?):(\s|$)/', $text, $m) !== 1) {
            throw new RuntimeException('line ' . ($line + 1) . ' is not a key');
        }

        return self::unquote($m[1]);
    }

    private function valueText(int $line): string
    {
        $text = ltrim($this->lines[$line]);
        if (preg_match('/^(\'[^\']*\'|"[^"]*"|[^\s:][^:]*?):(?:\s+(.*))?$/', $text, $m) !== 1) {
            throw new RuntimeException('line ' . ($line + 1) . ' is not a key');
        }

        return trim($m[2] ?? '');
    }

    private static function indent(string $text): int
    {
        return strlen($text) - strlen(ltrim($text, ' '));
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
