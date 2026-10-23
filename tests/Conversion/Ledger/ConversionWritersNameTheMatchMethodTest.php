<?php

declare(strict_types=1);

namespace Tests\Conversion\Ledger;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\Ledger\MatchMethod;
use Tests\Support\SourceScan;

/**
 * Every conversion row says how its click was found (MatchMethod), so a
 * path cannot record a same-IP guess as though the request had named the
 * click, and a new path cannot forget to say.
 *
 * 1. Every entry point into the conversion writer — the repository's
 *    record(), recordInTransaction() and create(), the goal engine's
 *    ingest(), and the static endpoints' p202RecordConversion(),
 *    p202RecordLegacyConversion() and p202RecordWebEvent() — takes the
 *    method as a required, non-nullable MatchMethod parameter. PHP then
 *    refuses a call without one (ArgumentCountError) or with anything
 *    else (TypeError, under strict_types); no default can stand in for
 *    "this path did not say".
 * 2. Every call of them in the served tree passes, in that position, a
 *    MatchMethod case or the caller's own `$matchedBy` (the name every
 *    entry point gives the parameter). The phpstan job runs no arity rule
 *    (customRulesetUsed), so a call that forgot the method would be found
 *    by the first request through it — and an old `record($u, $data,
 *    function …)` would hand the click-side callback over as the method;
 *    this finds both in CI. A call is found by its name, so another
 *    class's record() is listed below by file, with how many calls there
 *    are of it; a conversion repository's create() has no caller in the
 *    served tree and is held by (1) alone.
 * 3. The rows nothing above writes say so too: the inserts into
 *    202_conversion_logs and 202_goal_events (GoalWritersTest holds who
 *    makes them) name match_method, but for the fallback each hands
 *    MatchMethod::prepareInsert() for a table the 1.9.79 upgrade has not
 *    reached. The writer's own INSERT is built from its column list and
 *    is held by MysqlConversionRepositoryExpandedTest, which reads the
 *    bound method.
 *
 * Whether each path passes the method that describes how it found its
 * click is the behaviour tests' (MatchMethodEndpointsInstanceTest, MatchMethodIntegrationTest,
 * GoalRowsCarryTheirEventsMatchMethodTest).
 */
final class ConversionWritersNameTheMatchMethodTest extends TestCase
{
    /** Function name (lower case) => the position of its MatchMethod argument. */
    private const FUNCTIONS = [
        'p202recordconversion' => 2,
        'p202recordlegacyconversion' => 3,
        'p202recordwebevent' => 3,
    ];

    /** Method name (lower case) => the position of its MatchMethod argument. */
    private const METHODS = [
        'record' => 2,
        'recordintransaction' => 2,
        'ingest' => 3,
    ];

    /** file => how many of its record() calls reach another class's record(), and whose. */
    private const OTHER_RECORDS = [
        'api/v3/Apps/Android/InstallIntake.php' => [1, 'InstallIntake::record(), the intake\'s own write of the install'],
        '202-login.php' => [1, 'SignInRecord::record(), the sign-in log'],
    ];

    /** The files that insert into the two tables, and the inserts each names match_method in. */
    private const INSERTERS = [
        '202-config/Conversion/Ledger/MysqlConversionLedger.php' => '202_conversion_logs',
        '202-config/Goals/GoalEngine.php' => '202_goal_events',
    ];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/202-config/static-endpoint-helpers.php';
    }

    public function testEveryEntryPointRequiresTheMethod(): void
    {
        $entryPoints = [
            new \ReflectionMethod(\Prosper202\Conversion\MysqlConversionRepository::class, 'record'),
            new \ReflectionMethod(\Prosper202\Conversion\MysqlConversionRepository::class, 'recordInTransaction'),
            new \ReflectionMethod(\Prosper202\Conversion\MysqlConversionRepository::class, 'create'),
            new \ReflectionMethod(\Prosper202\Conversion\ConversionRepositoryInterface::class, 'create'),
            new \ReflectionMethod(\Prosper202\Conversion\InMemoryConversionRepository::class, 'create'),
            new \ReflectionMethod(\Prosper202\Goals\GoalEngine::class, 'ingest'),
            new \ReflectionFunction('p202RecordConversion'),
            new \ReflectionFunction('p202RecordLegacyConversion'),
            new \ReflectionFunction('p202RecordWebEvent'),
        ];
        foreach ($entryPoints as $fn) {
            $name = ($fn instanceof \ReflectionMethod ? $fn->getDeclaringClass()->getShortName() . '::' : '') . $fn->getName() . '()';
            $found = null;
            foreach ($fn->getParameters() as $param) {
                $type = $param->getType();
                if ($type instanceof \ReflectionNamedType && $type->getName() === MatchMethod::class) {
                    $found = $param;
                }
            }
            self::assertNotNull($found, "$name takes a MatchMethod");
            self::assertFalse($found->isOptional(), "$name's MatchMethod has no default: a path that does not say must not record");
            self::assertFalse($found->allowsNull(), "$name's MatchMethod is not nullable");
            self::assertSame('matchedBy', $found->getName(), "$name names it \$matchedBy, which the call scan reads its callers' forwarding by");
            $expected = self::FUNCTIONS[strtolower($fn->getName())] ?? self::METHODS[strtolower($fn->getName())] ?? ($fn->getName() === 'create' ? 2 : null);
            self::assertSame($expected, $found->getPosition(), "$name's MatchMethod is where the call scan looks for it");
        }
    }

    public function testEveryCallPassesTheMethod(): void
    {
        $missing = [];
        $otherRecords = [];
        $calls = 0;
        foreach (SourceScan::phpFiles() as $file => $source) {
            foreach (self::calls($source) as [$name, $isMethod, $line, $args]) {
                $position = $isMethod ? self::METHODS[$name] : self::FUNCTIONS[$name];
                if (count($args) > $position && self::isMatchMethod($args[$position])) {
                    $calls++;
                    continue;
                }
                if ($isMethod && $name === 'record' && isset(self::OTHER_RECORDS[$file])) {
                    $otherRecords[$file] = ($otherRecords[$file] ?? 0) + 1;
                    continue;
                }
                $missing[] = "$file:$line $name() passes no MatchMethod case or \$matchedBy in argument " . ($position + 1);
            }
        }

        self::assertSame([], $missing, "A call into the conversion writer does not say how it found the click:\n  "
            . implode("\n  ", $missing) . "\nPass a MatchMethod in that position: the way the request named the click,"
            . ' or the way it was inferred.');
        $listed = array_map(static fn (array $entry): int => $entry[0], self::OTHER_RECORDS);
        ksort($listed);
        ksort($otherRecords);
        self::assertSame(
            $listed,
            $otherRecords,
            'the other classes\' record() calls are the ones listed, no more and no fewer'
        );
        // Every path the item enumerated: the API, the uploads, the subid
        // batch, the goal engine, the three helpers and their callers.
        self::assertGreaterThanOrEqual(20, $calls, 'the scan found the writer\'s callers');
    }

    public function testEveryInsertOfAConversionOrAnEventNamesTheMethod(): void
    {
        $files = SourceScan::phpFiles();
        foreach (self::INSERTERS as $file => $table) {
            self::assertArrayHasKey($file, $files);
            $tokens = array_values(array_filter(
                token_get_all($files[$file]),
                static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ));
            $inserts = 0;
            foreach ($tokens as $i => $t) {
                if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING
                    || preg_match('/\bINSERT\s+INTO\s+`?' . $table . '`?/i', $t[1]) !== 1) {
                    continue;
                }
                $inserts++;
                if (str_contains($t[1], 'match_method')) {
                    continue;
                }
                // The fallback: prepareInsert()'s third argument, after one naming it.
                self::assertSame(',', $tokens[$i - 1] ?? null, "$file:{$t[2]}: an insert into $table without match_method");
                self::assertTrue(
                    is_array($tokens[$i - 2] ?? null) && str_contains($tokens[$i - 2][1], 'match_method')
                        && ($tokens[$i + 1] ?? null) === ')',
                    "$file:{$t[2]}: an insert into $table without match_method is only MatchMethod::prepareInsert()'s"
                        . ' fallback, its last argument, after the insert that names it'
                );
            }
            self::assertSame(2, $inserts, "$file inserts into $table once, with its fallback");
        }
    }

    /**
     * Every call of a FUNCTIONS name (global) or a METHODS name (after -> or
     * ?->) in $source, with its top-level arguments as token lists.
     *
     * @return list<array{0: string, 1: bool, 2: int, 3: list<list<mixed>>}>
     */
    private static function calls(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $out = [];
        foreach ($tokens as $i => $t) {
            $name = is_array($t) ? SourceScan::callableName($t[0], $t[1]) : null;
            if ($name === null || ($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }
            $prev = $tokens[$i - 1] ?? null;
            $prevId = is_array($prev) ? $prev[0] : null;
            $isMethod = in_array($prevId, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
            if ($isMethod ? !isset(self::METHODS[$name]) || $t[0] !== T_STRING : !isset(self::FUNCTIONS[$name])) {
                continue;
            }
            if (!$isMethod && in_array($prevId, [T_FUNCTION, T_DOUBLE_COLON, T_NEW, T_CONST], true)) {
                continue;
            }
            $args = [];
            $current = [];
            $depth = 0;
            for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
                $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if (in_array($text, ['(', '[', '{'], true) || (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                    if ($depth === 1) {
                        continue;
                    }
                } elseif (in_array($text, [')', ']', '}'], true)) {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($depth === 1 && $text === ',') {
                    $args[] = $current;
                    $current = [];
                    continue;
                }
                $current[] = $tokens[$j];
            }
            if ($current !== []) {
                $args[] = $current;
            }
            $out[] = [$name, $isMethod, $t[2], $args];
        }

        return $out;
    }

    /**
     * `MatchMethod::CASE` (imported, qualified or fully qualified) or the
     * variable `$matchedBy`, and nothing else in the argument.
     *
     * @param list<mixed> $arg
     */
    private static function isMatchMethod(array $arg): bool
    {
        if (count($arg) === 1) {
            return is_array($arg[0]) && $arg[0][0] === T_VARIABLE && $arg[0][1] === '$matchedBy';
        }
        if (count($arg) !== 3 || !is_array($arg[0]) || !is_array($arg[1]) || !is_array($arg[2])) {
            return false;
        }
        $class = ltrim($arg[0][1], '\\');
        $cases = array_map(static fn (MatchMethod $m): string => $m->name, MatchMethod::cases());

        return in_array($arg[0][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && in_array($class, ['MatchMethod', 'Prosper202\\Conversion\\Ledger\\MatchMethod'], true)
            && $arg[1][0] === T_DOUBLE_COLON
            && $arg[2][0] === T_STRING && in_array($arg[2][1], $cases, true);
    }
}
