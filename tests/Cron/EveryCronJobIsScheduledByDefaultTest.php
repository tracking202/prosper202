<?php

declare(strict_types=1);

namespace Tests\Cron;

use PHPUnit\Framework\TestCase;

/**
 * Every job in 202-cronjobs/ runs on a default deployment, or says why not.
 *
 * The measurement rewrite added jobs as standalone files and scheduled them
 * only in docker-compose.coolify.yaml. Every default scheduler — the
 * docker-compose.yaml cron service, the installer's printed cron line and
 * install.sh's — fetches 202-cronjobs/index.php and nothing else, so the
 * Android intake's Play Integrity decode and pending_click settle
 * (app-installs.php) never ran there: an `integrity_mode=require` install sat
 * at pending_integrity for good. The same was true of app-retention.php.
 *
 * What this holds:
 *
 * - every default scheduler names 202-cronjobs/index.php;
 * - every job file is one of: index.php itself; a job whose work index.php
 *   also runs, by calling the same entry point the job calls (SCHEDULED
 *   below); or a job listed in NOT_SCHEDULED with the reason nothing
 *   schedules it. A new file in none of these fails, naming the two ways
 *   to fix it;
 * - "calls" is read as a call site, not a substring (CLAUDE.md #21): a
 *   static call `Class::method(` or `(new Class(...))->method(`, with the
 *   class name resolved through the file's `use` imports, in a file that
 *   declares no namespace; and in index.php the call has to sit at top level
 *   or in a function reachable from top level by bare calls — a helper
 *   defined and never called schedules nothing.
 *
 * What it does not hold: that the call's arguments or surrounding guards
 * let it do real work. The live passes do that end to end with only
 * index.php driving the settle (tests/live/play-integrity.sh,
 * tests/live/android-intake.sh with P202_SETTLE_VIA_INDEX=1).
 */
final class EveryCronJobIsScheduledByDefaultTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    /**
     * The files a default deployment schedules from. docker-compose.coolify.yaml
     * is deliberately absent: it runs the standalone workers itself.
     */
    private const DEFAULT_SCHEDULERS = [
        'docker-compose.yaml',
        '202-config/functions-install-helpers.php',
        'install.sh',
    ];

    /**
     * Job => the entry points it calls, each of which index.php must call too.
     * 'static' is Class::method(; 'new' is (new Class(...))->method(.
     *
     * @var array<string, list<array{0: 'static'|'new', 1: string, 2: string}>>
     */
    private const SCHEDULED = [
        'app-installs.php' => [
            ['static', 'Api\V3\Apps\Android\AndroidIntakeJob', 'runExclusive'],
            ['new', 'Prosper202\Notifications\NotificationOutbox', 'sendDue'],
        ],
        'app-retention.php' => [
            ['static', 'Api\V3\Apps\AppRetention', 'forRegisteredSources'],
        ],
        'attribution-worker.php' => [
            ['static', 'Prosper202\Attribution\AttributionWorker', 'runExclusive'],
        ],
        'attribution-exports.php' => [
            ['new', 'Prosper202\Attribution\ExportRunner', 'run'],
        ],
    ];

    /**
     * Jobs no default scheduler runs, each predating the measurement rewrite.
     * Adding to this list is a decision to ship a job a default install never
     * runs; say why here.
     */
    private const NOT_SCHEDULED = [
        'bridge_config.php' => 'LPO remote config; opt-in for paired installs (docs schedule it every 6 hours)',
        'lpo_dimensions.php' => 'LPO nightly full sync; index.php pushes dirty users hourly, this is the backstop',
        'ltv_maintenance.php' => 'LTV rollup reconciliation; opt-in, predates the measurement rewrite',
        'ltv_webhooks.php' => 'LTV webhook dispatcher; opt-in, predates the measurement rewrite',
        'sync-messaging.php' => 'messenger sync booster; the widget syncs on every poll',
        'sync-worker.php' => 'sync job worker; predates the measurement rewrite',
        'daily-email.php' => 'fetched by the hosted mail service with the install hash, not by cron',
        'dni.php' => 'DNI callback fetched by the hosted service with the install hash',
        'dej.php' => 'data-engine summary fetched on demand',
        'process_dataengine_job.php' => 'included by DataEngine (class-dataengine.php), not scheduled',
        'health.php' => 'an authenticated status endpoint, not a job',
    ];

    public function testEveryDefaultSchedulerRunsTheMinutelyCron(): void
    {
        foreach (self::DEFAULT_SCHEDULERS as $scheduler) {
            $src = file_get_contents(self::ROOT . $scheduler);
            self::assertIsString($src, $scheduler . ' is readable');
            self::assertStringContainsString(
                '202-cronjobs/index.php',
                $src,
                $scheduler . ' is a default scheduler and must run 202-cronjobs/index.php'
            );
        }
    }

    public function testEveryCronJobIsScheduledOrSaysWhyNot(): void
    {
        $files = glob(self::ROOT . '202-cronjobs/*.php') ?: [];
        self::assertGreaterThan(10, count($files), 'the cron directory was found');
        $names = array_map('basename', $files);
        sort($names);

        foreach ($names as $name) {
            if ($name === 'index.php') {
                continue;
            }
            self::assertTrue(
                isset(self::SCHEDULED[$name]) || isset(self::NOT_SCHEDULED[$name]),
                "202-cronjobs/{$name} is run by no default scheduler. Call its entry point from "
                . "202-cronjobs/index.php (and list it in SCHEDULED here), or add it to every default "
                . 'scheduler (' . implode(', ', self::DEFAULT_SCHEDULERS) . ') and document it.'
            );
            self::assertFalse(
                isset(self::SCHEDULED[$name]) && isset(self::NOT_SCHEDULED[$name]),
                "{$name} is listed as both scheduled and not scheduled"
            );
        }
        foreach (array_merge(array_keys(self::SCHEDULED), array_keys(self::NOT_SCHEDULED)) as $listed) {
            self::assertContains($listed, $names, "{$listed} is listed here but no longer exists; drop it");
        }
    }

    public function testIndexPhpRunsTheEntryPointOfEveryScheduledJob(): void
    {
        $index = self::parse(self::ROOT . '202-cronjobs/index.php');
        $reachable = self::reachableFunctions($index);

        foreach (self::SCHEDULED as $job => $entries) {
            $jobFile = self::parse(self::ROOT . '202-cronjobs/' . $job);
            foreach ($entries as [$form, $class, $method]) {
                $label = ($form === 'static' ? $class . '::' : '(new ' . $class . ')->') . $method . '()';
                self::assertNotSame(
                    [],
                    self::callSites($jobFile, $form, $class, $method),
                    "{$job} was expected to call {$label}; update SCHEDULED if its entry point moved"
                );
                $sites = self::callSites($index, $form, $class, $method);
                self::assertNotSame(
                    [],
                    self::live($sites, $reachable),
                    "202-cronjobs/index.php never runs {$label}, so {$job}'s work does not happen on a default "
                    . 'deployment'
                    . ($sites !== [] ? ' (it is called only from a function nothing reachable calls)' : '')
                );
            }
        }
    }

    /**
     * Planted shapes the call-site reader must refuse, and the ones it must
     * accept (CLAUDE.md #20: a checker is only as good as the syntax it reads).
     */
    public function testTheCallSiteReaderReadsCallsNotNames(): void
    {
        $job = 'Api\V3\Apps\Android\AndroidIntakeJob';
        $call = '\\' . $job . '::runExclusive($db);';
        $cases = [
            // [source, expect a live static AndroidIntakeJob::runExclusive call]
            ["<?php {$call}", true],
            ["<?php use {$job};\nAndroidIntakeJob::runExclusive(\$db);", true],
            ["<?php use {$job} as J;\nJ::runExclusive(\$db);", true],
            ["<?php function A() { {$call} }\nfunction B() { A(); }\nB();", true],
            // named, not called
            ["<?php // {$call}", false],
            ["<?php \$x = '\\{$job}::runExclusive(';", false],
            ["<?php \\{$job}::runExclusiveLater(\$db);", false],
            // another class with the same short name
            ['<?php \Other\AndroidIntakeJob::runExclusive($db);', false],
            ['<?php \MyAndroidIntakeJob::runExclusive($db);', false],
            ["<?php use Other\\AndroidIntakeJob;\nAndroidIntakeJob::runExclusive(\$db);", false],
            // unqualified with no import is the global class, not ours
            ['<?php AndroidIntakeJob::runExclusive($db);', false],
            // defined in a function nothing calls
            ["<?php function Unused() { {$call} }", false],
            // called only from another unreachable function
            ["<?php function A() { {$call} }\nfunction B() { A(); }", false],
            // a method or static call of the same name is not a call of the function
            ["<?php function A() { {$call} }\n\$o->A();\nX::A();", false],
        ];
        foreach ($cases as $i => [$src, $expect]) {
            $parsed = self::parseSource($src);
            $sites = self::callSites($parsed, 'static', $job, 'runExclusive');
            $live = self::live($sites, self::reachableFunctions($parsed));
            self::assertSame($expect, $live !== [], "case {$i}: {$src}");
        }

        $runner = 'Prosper202\Attribution\ExportRunner';
        $newCases = [
            ["<?php (new \\{$runner}(new \\Prosper202\\Database\\Connection(\$db)))->run(15);", true],
            ["<?php use {$runner};\n\$r = (new ExportRunner(\$c))->run();", true],
            ["<?php \$r = new \\{$runner}(\$c); \$r->other();", false],
            ["<?php (new \\{$runner}(\$c))->runner();", false],
            ['<?php (new \Other\ExportRunner($c))->run();', false],
        ];
        foreach ($newCases as $i => [$src, $expect]) {
            $sites = self::callSites(self::parseSource($src), 'new', $runner, 'run');
            self::assertSame($expect, $sites !== [], "new case {$i}: {$src}");
        }
    }

    /**
     * The sites that run: at top level, or in a function reachable from it.
     *
     * @param list<?string> $sites
     * @param array<string, true> $reachable
     * @return list<?string>
     */
    private static function live(array $sites, array $reachable): array
    {
        return array_values(array_filter(
            $sites,
            static fn (?string $fn): bool => $fn === null || isset($reachable[strtolower($fn)])
        ));
    }

    /**
     * @return array{
     *     tokens: list<array{0: int, 1: string}>,
     *     imports: array<string, string>,
     *     functions: list<array{name: string, start: int, end: int}>
     * }
     */
    private static function parse(string $file): array
    {
        $src = file_get_contents($file);
        self::assertIsString($src, $file . ' is readable');

        return self::parseSource($src, basename($file));
    }

    /**
     * @return array{
     *     tokens: list<array{0: int, 1: string}>,
     *     imports: array<string, string>,
     *     functions: list<array{name: string, start: int, end: int}>
     * }
     */
    private static function parseSource(string $src, string $label = 'source'): array
    {
        $tokens = [];
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $tokens[] = [$t[0], $t[1]];
            } else {
                $tokens[] = [0, $t];
            }
        }

        $imports = [];
        $functions = [];
        $depth = 0;
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            [$id, $text] = $tokens[$i];
            // Name resolution below assumes the global namespace.
            self::assertNotSame(
                T_NAMESPACE,
                $id,
                $label . ' declares a namespace; teach this test to resolve names in it'
            );
            if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
            }
            if ($id === T_USE && $depth === 0) {
                // use A\B; | use A\B as C;  (use function / const are not class imports)
                $j = $i + 1;
                if (!in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    continue; // use function/const, or a closure's use (…)
                }
                $name = ltrim($tokens[$j][1], '\\');
                $alias = substr($name, (int) strrpos('\\' . $name, '\\'));
                if ($tokens[$j + 1][0] === T_AS) {
                    $alias = $tokens[$j + 2][1];
                }
                $imports[strtolower($alias)] = $name;
                continue;
            }
            $insideFunction = $functions !== [] && $i < $functions[count($functions) - 1]['end'];
            if ($id === T_FUNCTION && !$insideFunction && ($tokens[$i + 1][0] ?? null) === T_STRING) {
                // A named function not inside another (index.php declares one
                // inside its try block): find its body's extent.
                $j = $i;
                while ($j < $n && $tokens[$j][1] !== '{') {
                    $j++;
                }
                $level = 0;
                $k = $j;
                for (; $k < $n; $k++) {
                    $t = $tokens[$k];
                    if ($t[1] === '{' || $t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                        $level++;
                    } elseif ($t[1] === '}') {
                        $level--;
                        if ($level === 0) {
                            break;
                        }
                    }
                }
                $functions[] = ['name' => $tokens[$i + 1][1], 'start' => $j, 'end' => $k];
            }
        }

        return ['tokens' => $tokens, 'imports' => $imports, 'functions' => $functions];
    }

    /** The class a name token refers to, lower-cased, in a file with no namespace. */
    private static function resolve(array $parsed, array $token): ?string
    {
        [$id, $text] = $token;
        if ($id === T_NAME_FULLY_QUALIFIED) {
            return strtolower(ltrim($text, '\\'));
        }
        if ($id === T_NAME_QUALIFIED) {
            $first = strtolower(strstr($text, '\\', true));
            $rest = substr($text, strlen($first));

            return strtolower(isset($parsed['imports'][$first]) ? $parsed['imports'][$first] . $rest : $text);
        }
        if ($id === T_STRING) {
            return strtolower($parsed['imports'][strtolower($text)] ?? $text);
        }

        return null;
    }

    /** The top-level function whose body holds token $i, or null at top level. */
    private static function enclosing(array $parsed, int $i): ?string
    {
        foreach ($parsed['functions'] as $fn) {
            if ($i > $fn['start'] && $i < $fn['end']) {
                return $fn['name'];
            }
        }

        return null;
    }

    /**
     * Where the file calls the entry point: the enclosing function of each
     * site (null at top level).
     *
     * @return list<?string>
     */
    private static function callSites(array $parsed, string $form, string $class, string $method): array
    {
        $t = $parsed['tokens'];
        $n = count($t);
        $want = strtolower($class);
        $sites = [];
        for ($i = 0; $i < $n; $i++) {
            if ($form === 'static') {
                // Class :: method (   — and nothing before the class that makes it
                // part of another expression's member access.
                $isCall = ($t[$i + 1][0] ?? null) === T_DOUBLE_COLON
                    && self::isName($t, $i + 2, $method)
                    && ($t[$i + 3][1] ?? null) === '(';
                if (!$isCall || in_array($t[$i - 1][0] ?? null, self::NOT_FREE, true)) {
                    continue;
                }
                if (self::resolve($parsed, $t[$i]) === $want) {
                    $sites[] = self::enclosing($parsed, $i);
                }
                continue;
            }
            // ( new Class ( … ) ) -> method (
            $opensNew = $t[$i][1] === '(' && ($t[$i + 1][0] ?? null) === T_NEW
                && ($t[$i + 3][1] ?? null) === '('
                && self::resolve($parsed, $t[$i + 2] ?? [0, '']) === $want;
            if (!$opensNew) {
                continue;
            }
            $level = 0;
            $k = $i + 3;
            for (; $k < $n; $k++) {
                if ($t[$k][1] === '(') {
                    $level++;
                } elseif ($t[$k][1] === ')') {
                    $level--;
                    if ($level === 0) {
                        break;
                    }
                }
            }
            $callsMethod = ($t[$k + 1][1] ?? null) === ')'
                && in_array($t[$k + 2][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && self::isName($t, $k + 3, $method)
                && ($t[$k + 4][1] ?? null) === '(';
            if ($callsMethod) {
                $sites[] = self::enclosing($parsed, $i);
            }
        }

        return $sites;
    }

    /**
     * Tokens that, before a name, make it a member, a declaration or a
     * constructor rather than a call of that name.
     */
    private const NOT_FREE = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_FUNCTION];

    /** @param list<array{0: int, 1: string}> $t */
    private static function isName(array $t, int $i, string $name): bool
    {
        return ($t[$i][0] ?? null) === T_STRING && strtolower($t[$i][1]) === strtolower($name);
    }

    /**
     * Functions reachable from top-level code through bare calls `name(`
     * (not `->name(`, `::name(`, `new name(` or `function name(`).
     *
     * @return array<string, true> lower-cased names
     */
    private static function reachableFunctions(array $parsed): array
    {
        $t = $parsed['tokens'];
        $defined = [];
        foreach ($parsed['functions'] as $fn) {
            $defined[strtolower($fn['name'])] = true;
        }
        $edges = []; // caller (null = top level) => callees
        foreach ($t as $i => [$id, $text]) {
            if ($id !== T_STRING && $id !== T_NAME_FULLY_QUALIFIED) {
                continue;
            }
            if (($t[$i + 1][1] ?? null) !== '(') {
                continue;
            }
            if (in_array($t[$i - 1][0] ?? null, self::NOT_FREE, true)) {
                continue;
            }
            $callee = strtolower(ltrim($text, '\\'));
            if (!isset($defined[$callee])) {
                continue;
            }
            $caller = self::enclosing($parsed, $i);
            $edges[$caller === null ? '' : strtolower($caller)][$callee] = true;
        }
        $reachable = [];
        $queue = array_keys($edges[''] ?? []);
        while ($queue !== []) {
            $fn = array_shift($queue);
            if (isset($reachable[$fn])) {
                continue;
            }
            $reachable[$fn] = true;
            foreach (array_keys($edges[$fn] ?? []) as $next) {
                $queue[] = $next;
            }
        }

        return $reachable;
    }
}
