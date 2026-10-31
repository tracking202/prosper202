<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Ltv\MysqlWebhookRepository;
use Tests\Support\SourceScan;

/**
 * MysqlWebhookRepository::KNOWN_EVENTS is the list a webhook may subscribe
 * to, so it has to be the list of what the install sends: an event an
 * emitter sends under a name not on it reaches only '*' subscribers, and a
 * name on it that nothing sends is a subscription to nothing.
 *
 * An emitter is a call of LtvController's enqueueEvent() (the event is the
 * first argument) or of EventBridge::emit() / emitIfEnabled() (the third).
 * Each must name its event with a string literal on the list; an event
 * named any other way cannot be read, and is refused. A direct
 * MysqlWebhookRepository::enqueue() call is not seen (its receiver has no
 * name a token scan can trust); the two that exist forward the names above.
 *
 * The Go CLI checks `--events` against its own copy of the list before it
 * sends anything; the copy is held to this one, '*' included.
 */
final class WebhookEventsAreKnownTest extends TestCase
{
    /** Emitter name => index of its event argument. */
    private const EMITTERS = ['enqueueEvent' => 0, 'emit' => 2, 'emitIfEnabled' => 2];

    /**
     * Calls that hand on an event their own caller named, by file: how many,
     * and why.
     *
     * @var array<string, array{int, string}>
     */
    private const FORWARDERS = [
        '202-config/Bridge/EventBridge.php' => [1, 'emitIfEnabled() hands its own $event to emit()'],
    ];

    /**
     * The event each emitter call names, by line: the literal, or null when
     * the argument is not one string literal.
     *
     * @return list<array{int, ?string}>
     */
    private static function emitted(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $found = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || !isset(self::EMITTERS[$token[1]])) {
                continue;
            }
            if (($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }
            $before = $tokens[$i - 1] ?? null;
            if (is_array($before) && $before[0] === T_FUNCTION) {
                continue; // the definition
            }
            // emit()/emitIfEnabled() are EventBridge's: Class::name( or self::/static:: inside it.
            if ($token[1] !== 'enqueueEvent' && !(is_array($before) && $before[0] === T_DOUBLE_COLON)) {
                continue;
            }
            $args = [[]];
            $depth = 0;
            for ($j = $i + 2, $n = count($tokens); $j < $n; $j++) {
                $t = $tokens[$j];
                $opensCurly = is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true);
                if (in_array($t, ['(', '[', '{'], true) || $opensCurly) {
                    $depth++;
                } elseif (in_array($t, [')', ']', '}'], true)) {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif ($t === ',' && $depth === 0) {
                    $args[] = [];
                    continue;
                }
                $args[count($args) - 1][] = $t;
            }
            $arg = $args[self::EMITTERS[$token[1]]] ?? [];
            $name = count($arg) === 1 && is_array($arg[0]) && $arg[0][0] === T_CONSTANT_ENCAPSED_STRING
                ? stripcslashes(substr($arg[0][1], 1, -1))
                : null;
            $found[] = [$token[2], $name];
        }

        return $found;
    }

    public function testEveryEmittedEventIsOneAWebhookCanSubscribeTo(): void
    {
        $unknown = [];
        $sent = [];
        $forwarded = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            foreach (self::emitted($source) as [$line, $name]) {
                if ($name === null && ($forwarded[$path] ?? 0) < (self::FORWARDERS[$path][0] ?? 0)) {
                    $forwarded[$path] = ($forwarded[$path] ?? 0) + 1;
                } elseif ($name === null) {
                    $unknown[] = "$path:$line names its event with something other than a string literal";
                } elseif (!in_array($name, MysqlWebhookRepository::KNOWN_EVENTS, true)) {
                    $unknown[] = "$path:$line sends \"$name\", which KNOWN_EVENTS does not list";
                } else {
                    $sent[$name] = true;
                }
            }
        }
        self::assertSame([], $unknown, 'add the event to MysqlWebhookRepository::KNOWN_EVENTS (and the Go CLI\'s'
            . ' ltvWebhookEvents), or name it with a literal');
        self::assertSame(
            array_map(static fn (array $f): int => $f[0], self::FORWARDERS),
            $forwarded,
            'a listed forwarder is gone: take it off FORWARDERS'
        );
        $neverSent = array_values(array_diff(MysqlWebhookRepository::KNOWN_EVENTS, array_keys($sent)));
        self::assertSame([], $neverSent, 'KNOWN_EVENTS lists events nothing sends: a subscription to one'
            . ' receives nothing');
    }

    public function testTheGoCliChecksEventsAgainstTheSameList(): void
    {
        $go = (string) file_get_contents(SourceScan::repoRoot() . '/go-cli/cmd/ltv_admin.go');
        $found = preg_match('/^var ltvWebhookEvents = \[\]string\{([^}]*)\}/m', $go, $m);
        self::assertSame(1, $found, 'ltvWebhookEvents is gone');
        preg_match_all('/"([^"]*)"/', $m[1], $names);
        self::assertSame([...MysqlWebhookRepository::KNOWN_EVENTS, '*'], $names[1]);
    }

    /** @return iterable<string, array{string, list<array{int, ?string}>}> */
    public static function shapes(): iterable
    {
        yield 'enqueueEvent' => [
            "<?php \$this->enqueueEvent('revenue.recorded', ['a' => 1]);",
            [[1, 'revenue.recorded']],
        ];
        yield 'emit, third argument' => [
            "<?php EventBridge::emit(\$c, \$u, 'conversion.recorded', [f(1, 2)]);",
            [[1, 'conversion.recorded']],
        ];
        yield 'emitIfEnabled, qualified' => [
            "<?php \\Prosper202\\Bridge\\EventBridge::emitIfEnabled(\$c, \$u, \"x.y\", []);",
            [[1, 'x.y']],
        ];
        yield 'a nested call does not shift the argument' => [
            "<?php EventBridge::emit(f(\$a, \$b), \$u, 'a.b', []);",
            [[1, 'a.b']],
        ];
        yield 'a variable cannot be read' => ['<?php $this->enqueueEvent($name, []);', [[1, null]]];
        yield 'a concatenation cannot be read' => [
            "<?php EventBridge::emit(\$c, \$u, 'a.' . \$b, []);",
            [[1, null]],
        ];
        yield 'the definition is not a call' => ['<?php function enqueueEvent(string $e, array $p) {}', []];
        yield 'another emit is not EventBridge\'s' => ["<?php \$logger->emit(\$a, \$b, 'a.b');", []];
        yield 'a comment is not a call' => ["<?php // \$this->enqueueEvent('x.y', []);\n", []];
    }

    /**
     * @dataProvider shapes
     * @param list<array{int, ?string}> $expected
     */
    public function testTheShapesTheScanReads(string $source, array $expected): void
    {
        self::assertSame($expected, self::emitted($source));
    }
}
