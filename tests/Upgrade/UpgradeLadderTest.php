<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use Prosper202\Database\Schema\SchemaBuilder;
use Prosper202\Database\SchemaReconciler;
use Prosper202\Database\Tables\AppTables;
use Tests\TestCase;

/**
 * The ladder, version.php's constant and the downgrade guard must agree. CI
 * never catches a disagreement because CI always installs fresh, so this
 * pins them textually.
 *
 * Two shapes have been written. F1: a version whose schema no step created.
 * Its mirror image: a block gated on 1.9.76 while the code was 1.9.76, so
 * upgrade_needed() (`stored != code`) meant upgrade.php never ran it for the
 * installs it existed to serve.
 */
final class UpgradeLadderTest extends TestCase
{
    private const CURRENT_VERSION = '1.9.76';
    private const PRIOR_VERSION = '1.9.75';

    /** The reconcile call a step must make; also how a step is recognised. */
    private const RECONCILE_CALL = '_upgrade_measurement_tables(';

    /**
     * Statements that leave: return, exit/die, throw, break, continue, goto.
     * A statement below one of these, or below a block holding one, may
     * never run — jumpsBetween() has the reasoning.
     */
    private const JUMPS = [T_RETURN, T_EXIT, T_THROW, T_BREAK, T_CONTINUE, T_GOTO];

    /**
     * A statement writing a version into 202_version, with the version captured.
     *
     * Tolerant of what SQL allows around the same statement, because
     * `UPDATE 202_version SET version='…'` is this file's convention, not a
     * rule. The exact-text pattern it replaces already missed one write the
     * ladder has always contained — `INSERT INTO 202_version SET
     * version='1.0.3'`, the row's first insertion — so a reformatted rung
     * would have dropped out of the ordering in silence.
     * testEveryVersionWriteIsInASpellingTheScanCanRead refuses a spelling
     * this cannot read rather than ignoring it.
     */
    private const PERSIST_BODY =
        '(?:UPDATE|INSERT\\s+INTO)\\s+`?202_version`?\\s+SET\\s+`?version`?\\s*=\\s*\'([^\']*)\'';
    private const PERSIST_PATTERN = '/\\b' . self::PERSIST_BODY . '/i';

    /**
     * The same as the whole statement. What a query call receives has to be
     * the write and nothing after it: a clause appended to it changes what
     * the statement does while PERSIST_PATTERN still reads the version.
     */
    private const PERSIST_STATEMENT = '/^\\s*' . self::PERSIST_BODY . '\\s*;?\\s*$/i';

    /** @var list<array{id: int|null, text: string}>|null */
    private ?array $tokenCache = null;

    /**
     * @var list<array{gates: list<string>, gatesOnly: bool, condition: string,
     *     persists: list<string>, reconciles: bool, block: string, from: int, to: int}>|null
     */
    private ?array $stepCache = null;

    private function upgradeSource(): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/202-config/functions-upgrade.php');
    }

    /**
     * The ladder with its comments removed.
     *
     * Every scan below keys on tokens the blocks also mention in prose, so
     * scanning the raw file matched comments: a planted
     * `_upgrade_measurement_tables([])` left this suite green because the
     * sentence above it still named getDefinitions(). String literals
     * survive the strip, so the UPDATE 202_version scan still works.
     */
    private function upgradeCode(): string
    {
        return implode('', array_column($this->upgradeTokens(), 'text'));
    }

    /**
     * The same, as tokens, so blocks can be bounded by brace depth.
     *
     * @return list<array{id: int|null, text: string}>
     */
    private function upgradeTokens(): array
    {
        if ($this->tokenCache !== null) {
            return $this->tokenCache;
        }

        $tokens = token_get_all('<?php ' . $this->upgradeSource());
        $this->assertGreaterThan(100, count($tokens), 'the ladder could not be tokenized');

        $out = [];
        foreach ($tokens as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    // Keep the newlines it spanned so failure messages still
                    // read as lines.
                    $out[] = ['id' => T_WHITESPACE, 'text' => str_repeat("\n", substr_count($token[1], "\n"))];
                    continue;
                }
                $out[] = ['id' => $token[0], 'text' => $token[1]];
                continue;
            }
            $out[] = ['id' => null, 'text' => $token];
        }

        return $this->tokenCache = $out;
    }

    /**
     * The indices of every token that is not whitespace, in source order.
     *
     * @return list<int>
     */
    private function significantTokens(array $tokens): array
    {
        $significant = [];
        foreach ($tokens as $i => $token) {
            if ($token['id'] !== T_WHITESPACE) {
                $significant[] = $i;
            }
        }

        return $significant;
    }

    public function testVersionConstantIsTheBumpedVersion(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/202-config/version.php');
        $this->assertStringContainsString("\$version_string = '" . self::CURRENT_VERSION . "'", $source);
    }

    public function testAnUpgradeStepGatedOnThePriorVersionCreatesTheAppTables(): void
    {
        // Brace-bounded, not "up to the next gate": the 1.9.75 step is now
        // the ladder's last, so a strpos window found no next gate and ran to
        // the end of the file, swallowing the downgrade guard.
        $block = $this->blockGatedOn(self::PRIOR_VERSION);

        // The block's DDL must come from the installer definitions (never a
        // hand-copied CREATE that can drift), and it must persist the bumped
        // version so a 1.9.75 install converges to 1.9.76.
        $this->assertStringContainsString('AppTables::getDefinitions()', $block);
        $this->assertStringContainsString("version='" . self::CURRENT_VERSION . "'", $block);
    }

    public function testThePriorVersionBlockReconcilesExistingTablesAndDoesNotOnlyCreateThem(): void
    {
        // CREATE TABLE IF NOT EXISTS converges nothing: against a table that
        // already exists in an older shape it is a no-op, and the block used
        // to contain nothing else. An install carrying an earlier shape of
        // these tables therefore came out of the upgrade still missing
        // columns the running code selects.
        $this->assertStringContainsString(
            self::RECONCILE_CALL,
            $this->blockGatedOn(self::PRIOR_VERSION)
        );
        $this->assertStringContainsString('SchemaReconciler', $this->upgradeCode());
    }

    /**
     * Every step that reconciles the attribution tables advances the version.
     *
     * Derived, not listed: a listed pair goes stale in silence once the
     * version moves twice. Asserting the derived pair would be circular, so
     * these are the properties a reconciling step must have whatever its
     * numbers.
     */
    public function testEveryAttributionStepUsesTheSharedDefinitionsAndAdvancesTheVersion(): void
    {
        foreach ($this->attributionSteps() as $step) {
            $gate = implode('/', $step['gates']);

            $this->assertStringContainsString(
                'AppTables::getDefinitions()',
                $step['block'],
                "the step gated on $gate must reconcile from the installer's definitions, not a copied CREATE"
            );
            $this->assertNotSame(
                [],
                $step['persists'],
                "the step gated on $gate reconciles the attribution tables but persists no version, so it"
                . ' either never advances or is an ungated block in disguise'
            );

            foreach ($step['persists'] as $to) {
                foreach ($step['gates'] as $from) {
                    $this->assertTrue(
                        version_compare($to, $from, '>'),
                        "the step gated on $gate persists $to, which does not move $from forward"
                    );
                }
                $this->assertTrue(
                    version_compare($to, self::CURRENT_VERSION, '<='),
                    "the step gated on $gate persists $to, a version this release does not know;"
                    . ' the install would be stranded above the ladder and the downgrade guard would'
                    . ' pull it back down on the next run'
                );

                // Writing it, not just spelling it. A rung whose UPDATE is
                // assigned or logged rather than run reads as persisted here
                // while the database stays where it was, so the rung
                // re-enters on every upgrade run forever.
                $this->assertArrayHasKey(
                    $to,
                    $this->persistsThatReachAQuery($step['from'], $step['to'], "the step gated on $gate"),
                    "the step gated on $gate spells an UPDATE to $to but no _upgrade_query() call"
                    . ' receives that statement whole — as a literal, or as a variable assigned it'
                    . ' and not touched again before the call — so the version is never written'
                );
            }

            $this->assertPersistsAreGuardedByReconcileSuccess($step);
        }
    }

    /**
     * A step's version persists must run only when the reconcile succeeded.
     *
     * Advancing on a failed reconcile is the ladder's worst outcome: the
     * install is recorded at the new version with incomplete tables, the rung
     * that would repair them never runs again, and no later release is
     * looking for it. The block's own comment promises the opposite —
     * "Advance the version only once every DDL statement succeeded, so a
     * partial failure re-enters this block on the next run" — and asserting
     * only that a reconcile call and a persist share a block left that
     * promise unguarded: replacing `if ($attribution_ok)` with `if (true)`
     * kept the whole suite green.
     *
     * The recognised shape is the one the ladder uses: the reconcile result
     * assigned to a variable on its own, and the `_upgrade_query()` call that
     * receives the persist inside the braces of an `if` below it whose
     * condition is that variable. A different shape —
     * `!$ok` with an early return, a ternary — fails here rather than being
     * reasoned about, because the failure direction matters: reading the
     * negated branch as the success branch would call an unguarded persist
     * guarded.
     */
    private function assertPersistsAreGuardedByReconcileSuccess(array $step): void
    {
        $tokens = $this->upgradeTokens();
        $gate = implode('/', $step['gates']);

        $ranges = $this->reconcileSuccessRanges($step['from'], $step['to'], $gate);
        $this->assertNotSame(
            [],
            $ranges,
            "the step gated on $gate assigns the result of "
            . rtrim(self::RECONCILE_CALL, '(') . '() but never tests it. Expected the version'
            . " persisted inside `if (\$that_variable) { … }` below the assignment; teach this"
            . ' check the new shape before writing one.'
        );

        // The calls, not the literals. A literal assigned inside the branch
        // and handed to _upgrade_query() after its closing brace is a write
        // outside the branch — a failed reconcile then runs a query with
        // whatever $sql holds — and a literal assigned above the branch and
        // handed to a call inside it is a write inside. The first version of
        // this located the literal, so it passed the first shape and failed
        // the second. persistsThatReachAQuery() says which calls receive
        // which persist; every call receiving one of this step's persists
        // has to sit inside a success range. Not vacuous: the caller has
        // already asserted that each persisted version reaches a call.
        //
        // And on every path through that range, not merely somewhere in it:
        // a call nested in a further condition inside the success branch, or
        // behind a short-circuit, leaves a successful reconcile without its
        // version write when that condition is false, and the rung is stuck.
        // So does a call below a branch that can leave — `if ($skip) {
        // return false; }` above it — which reads as a statement after a
        // closing brace unless the jump is read too.
        $reach = $this->persistsThatReachAQuery($step['from'], $step['to'], "the step gated on $gate");
        $unguarded = [];
        $conditional = [];
        $leaves = [];
        foreach ($step['persists'] as $version) {
            foreach ($reach[$version] ?? [] as $call) {
                $inside = null;
                foreach ($ranges as [$start, $end]) {
                    if ($call >= $start && $call <= $end) {
                        $inside = $start;
                        break;
                    }
                }
                if ($inside === null) {
                    $unguarded[] = "$version at line " . $this->lineOf($tokens, $call);
                } elseif (($jumps = $this->jumpsBetween($tokens, $inside, $call)) !== []) {
                    $leaves[] = "$version at line " . $this->lineOf($tokens, $call)
                        . ' below a jump at line(s) ' . implode(', ', $jumps);
                } elseif (!$this->runsOnEveryPathOf($tokens, $inside, $call)) {
                    $conditional[] = "$version at line " . $this->lineOf($tokens, $call);
                }
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            "the step gated on $gate hands _upgrade_query() a write of a version outside the branch"
            . ' guarded by the reconcile result (' . implode(', ', $unguarded) . '), so a failed'
            . ' reconcile would record the new version anyway and the step would never re-run to'
            . ' finish the schema'
        );
        $this->assertSame(
            [],
            $conditional,
            "the step gated on $gate runs the query writing a version inside a further condition"
            . ' within the branch guarded by the reconcile result (' . implode(', ', $conditional)
            . '), so a successful reconcile may not advance the version and the rung never'
            . ' converges. The call has to run on every path through that branch: as a statement'
            . ' of its own, as `$v = _upgrade_query(…);`, or as the first operand of an `if` that'
            . ' starts a statement, directly inside the branch.'
        );
        $this->assertSame(
            [],
            $leaves,
            "the step gated on $gate runs the query writing a version below a jump inside the branch"
            . ' guarded by the reconcile result (' . implode('; ', $leaves) . '), so on the path that'
            . ' jump takes a successful reconcile never advances the version and the rung never'
            . ' converges. This check does not read where a jump lands — a return, exit, throw,'
            . ' break, continue or goto above the call is refused whatever it does; teach it the'
            . ' new shape before writing one.'
        );
    }

    /**
     * No `goto` anywhere in the ladder.
     *
     * PHP lets a goto enter an `if` block from anywhere in the same scope —
     * only loops and switches are closed to it — so a label inside a success
     * branch would run the persist with no reconcile before it, and every
     * range check here, which reads a branch's braces as its only way in,
     * would stay green. The checks read no jump targets; the file has never
     * needed one.
     */
    public function testTheLadderHasNoGoto(): void
    {
        $tokens = $this->upgradeTokens();
        $gotos = [];
        foreach ($tokens as $i => $token) {
            if ($token['id'] === T_GOTO) {
                $gotos[] = $this->lineOf($tokens, $i);
            }
        }
        $this->assertSame(
            [],
            $gotos,
            'the ladder uses goto at line(s) ' . implode(', ', $gotos) . ': a goto can enter a'
            . ' success branch from anywhere in the same scope, and the checks here read no jump'
            . ' targets. Teach them before writing one.'
        );
    }

    /**
     * The ladder declares no namespace and imports no function, so an
     * unqualified `_upgrade_query(` or `_upgrade_measurement_tables(` is
     * the global helper. namesFunction() reads a call site by its token and
     * what precedes it; under a `namespace` declaration the same token
     * would resolve to that namespace's function first, and `use function
     * Other\log as _upgrade_query;` would rebind the name outright — and
     * neither is visible at the call site. Refused here, by line, so every
     * check that reads a call by name keeps its ground.
     */
    public function testTheLadderNamesItsHelpersGlobally(): void
    {
        $tokens = $this->upgradeTokens();
        $last = count($tokens) - 1;
        $found = [];
        foreach ($tokens as $i => $token) {
            if ($token['id'] === T_NAMESPACE) {
                $found[] = 'a namespace declaration at line ' . $this->lineOf($tokens, $i);
            }
            if ($token['id'] === T_USE) {
                $next = $this->nextSignificant($tokens, $i + 1, $last);
                if ($next !== null && $tokens[$next]['id'] === T_FUNCTION) {
                    $found[] = 'a function import at line ' . $this->lineOf($tokens, $i);
                }
            }
        }
        $this->assertSame(
            [],
            $found,
            'the ladder makes ' . implode(', ', $found) . ': an unqualified `_upgrade_query(` or'
            . ' `_upgrade_measurement_tables(` is then not certainly the global helper, and every check'
            . ' here that reads a call by name assumes it is. Teach namesFunction() the new resolution'
            . ' before adding one.'
        );
    }

    /**
     * No construct anywhere in the ladder can write a variable without
     * naming it.
     *
     * Every scan here that vouches for a variable — the reconcile result
     * between its assignment and the guard, a held persist between its
     * assignment and the call — looks for the variable's own token, and
     * these carry none: a variable variable (`$$name`, `${'name'}`),
     * `$GLOBALS['name']`, `extract()`, `eval()`, and an `include` or
     * `require`, which runs the included file in the scope of the line it
     * sits on. Each can write inside a scanned range where it sits, and
     * each can make the alias assertNoWritePathTheScanCannotFollow()
     * refuses (`extract($vars, EXTR_REFS)`, `$$a = &$$b`, an included line
     * `$alias = &$attribution_ok;`) from anywhere in the same method, so
     * the refusal is file-wide rather than by range. Executed against a
     * method-local variable before they were listed: every one of them
     * writes it, except `$GLOBALS`, which reaches a method's local only
     * through a `global` declaration and is refused without reading for
     * one.
     *
     * An include outside every function body is the one exception: it runs
     * the file in whatever scope included the ladder, never in a method's.
     * The ladder's own, at its top, is that — and it sits inside an `if`,
     * so the line is "in a function body", not "at brace depth zero". The
     * check does not read which function an include sits in; one in any
     * body is refused.
     */
    public function testTheLadderMakesNoWriteAScanCannotFollow(): void
    {
        $tokens = $this->upgradeTokens();
        $last = count($tokens) - 1;
        $found = [];
        $depth = 0;
        // The brace depths at which a function body is open; `function`
        // keywords whose `{` has not come yet. `use function Foo\bar;` and
        // an abstract signature end at a `;` with no body.
        $bodies = [];
        $pending = false;
        foreach ($tokens as $i => $token) {
            $text = $token['text'];
            if ($token['id'] === T_FUNCTION) {
                $pending = true;
                continue;
            }
            if ($text === ';' && $pending) {
                $pending = false;
                continue;
            }
            if ($text === '{' || $text === '${') {
                $depth++;
                if ($pending && $text === '{') {
                    $bodies[] = $depth;
                    $pending = false;
                }
                continue;
            }
            if ($text === '}') {
                if ($bodies !== [] && end($bodies) === $depth) {
                    array_pop($bodies);
                }
                $depth--;
                continue;
            }
            $blind = $this->writesNoScanCanSee($tokens, $i, $last);
            if ($blind === null || ($blind === 'an include' && $bodies === [])) {
                continue;
            }
            $found[] = "$blind at line " . $this->lineOf($tokens, $i)
                . ($blind === 'an include' ? ', inside a function body' : '');
        }
        $this->assertSame(0, $depth, 'the ladder\'s braces are unbalanced, so this scan read the wrong scopes');

        $this->assertSame(
            [],
            $found,
            'the ladder makes ' . implode(', ', $found) . ': a construct that names no variable can'
            . ' write any variable the checks here vouch for, and make the aliases they refuse, and'
            . ' the scans read a variable\'s own token only. Refuse it, or teach this file the new'
            . ' shape.'
        );
    }

    /**
     * Nothing anywhere in the ladder makes a path by which one of $names
     * can be written without naming it: no reference to it, and no
     * `global` or `static` declaration of it.
     *
     * reconcileSuccessRanges() vouches for the reconcile result by finding
     * its own token between the assignment and the guard, and
     * persistsThatReachAQuery() vouches for a held persist the same way. An
     * alias made elsewhere carries a write in unnamed: `$alias =
     * &$attribution_ok;` above the gate, then `$alias = true;` between the
     * assignment and the guard — the shape a reviewer planted against the
     * sibling test in tests/Auth and watched pass — or a closure's `use
     * (&$attribution_ok)`, a `foreach` by reference, or a `global`
     * declaration paired with a function that declares the same name and
     * is called in the range as a bare call, which names nothing. Each is
     * refused where it is made, by line, anywhere in the file: the ladder's
     * scope is the method it runs in, and a step is a range inside it.
     * Every shape was executed against a method-local variable before it
     * was listed. The constructs that make such a path without naming the
     * variable at all are testTheLadderMakesNoWriteAScanCannotFollow()'s.
     *
     * A reference has two ends. `$alias =& $attribution_ok;` puts the `&`
     * before the name; `$attribution_ok =& $alias;` binds the same two
     * slots with the name on the left, and the first version of this read
     * only the token before the name — the reviewer planted that one next.
     * Executed: made above the gate, `$alias = true;` between the reconcile
     * and its guard persists over a failed reconcile, and `$sql =&
     * $carried;` lets `$carried = "SELECT 1";` reach the query call as the
     * held UPDATE. Both ends are read: a `&` before the name, and a `=`
     * then `&` after it.
     *
     * @param list<string> $names
     */
    private function assertNoWritePathTheScanCannotFollow(array $names, string $what): void
    {
        $tokens = $this->upgradeTokens();
        $last = count($tokens) - 1;
        $found = [];
        foreach ($tokens as $i => $token) {
            if ($token['id'] !== T_VARIABLE || !in_array($token['text'], $names, true)) {
                continue;
            }
            $prev = $this->previousSignificant($tokens, $i - 1, 0);
            if ($prev !== null && $tokens[$prev]['text'] === '&') {
                $found[] = "a reference to {$token['text']} at line " . $this->lineOf($tokens, $i);
            }
            $next = $this->nextSignificant($tokens, $i + 1, $last);
            $after = $next === null ? null : $this->nextSignificant($tokens, $next + 1, $last);
            if (
                $next !== null && $tokens[$next]['text'] === '='
                && $after !== null && $tokens[$after]['text'] === '&'
            ) {
                $found[] = "a reference taken by {$token['text']} at line " . $this->lineOf($tokens, $i);
            }
            if (in_array($this->statementKeyword($tokens, $i), [T_GLOBAL, T_STATIC], true)) {
                $found[] = "a global or static declaration of {$token['text']} at line "
                    . $this->lineOf($tokens, $i);
            }
        }

        $this->assertSame(
            [],
            $found,
            "$what relies on " . implode(' and ', $names) . ' being written only where this check'
            . ' reads it, and the ladder makes ' . implode(', ', $found) . ', through which it can'
            . ' be written inside the range this check scans without being named there. Refuse'
            . ' it, or teach this check the new shape.'
        );
    }

    /**
     * A construct at $at that can write a variable without naming it — a
     * variable variable (`$$name`, `${'name'}`), `$GLOBALS['name']`,
     * `extract()`, `eval()`, or an `include`/`require` — or null. The
     * scans for writes look for the variable's own token, and none of
     * these carries it.
     */
    private function writesNoScanCanSee(array $tokens, int $at, int $last): ?string
    {
        $id = $tokens[$at]['id'];
        if ($id === null && $tokens[$at]['text'] === '$') {
            return 'a variable variable';
        }
        if ($id === T_VARIABLE && $tokens[$at]['text'] === '$GLOBALS') {
            return 'a use of $GLOBALS';
        }
        if (in_array($id, [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
            return 'an include';
        }
        if ($id === T_EVAL) {
            return 'an eval()';
        }
        if ($id === T_STRING && strtolower($tokens[$at]['text']) === 'extract') {
            $next = $this->nextSignificant($tokens, $at + 1, $last);
            if ($next !== null && $tokens[$next]['text'] === '(') {
                return 'an extract()';
            }
        }

        return null;
    }

    /**
     * The id of the first significant token of the statement the token at
     * $at sits in — what follows the nearest `;`, `{`, `}` or `:` before
     * it — so `global $a, $attribution_ok;` is read as the declaration it
     * is for every variable it names, not only the first.
     */
    private function statementKeyword(array $tokens, int $at): ?int
    {
        for ($j = $at - 1; $j >= 0; $j--) {
            if (in_array($tokens[$j]['text'], [';', '{', '}', ':'], true)) {
                break;
            }
        }
        $first = $this->nextSignificant($tokens, $j + 1, $at);

        return $first === null ? null : $tokens[$first]['id'];
    }

    /**
     * The token ranges inside a step that run only when the reconcile call
     * reported success — the braces of each `if`/`elseif` whose condition is
     * exactly the variable holding that result, below the assignment.
     *
     * Exactly the variable, not merely mentioning it: `if (!$attribution_ok)`
     * mentions it and its braces are the FAILURE branch, so a mention-based
     * match would read the one place a persist must never be as the one place
     * it must.
     *
     * An `if` of its own, directly inside the step: nested in a further
     * condition, or an `elseif`, the success branch may never run for a
     * reconcile that succeeded, and the rung then never converges.
     *
     * Below the assignment, with the variable untouched between: the first
     * version of this found the assignment anywhere in the step and then
     * credited every `if ($ok) {` from the step's start, so a guard above the
     * assignment — reading nothing, or a stale value, so the rung never
     * advances or advances over a failed reconcile — and a guard below a
     * second write (`$ok = true;`) both read as guarded. Now the assignment
     * has to be `$ok = _upgrade_measurement_tables(…);` on its own, made once,
     * directly inside the step's braces; a guard counts only below it; and any
     * other appearance of the variable in the step fails here by line,
     * because a scan that cannot tell a read from a write must not answer
     * "unchanged".
     *
     * @return list<array{0: int, 1: int}>
     */
    private function reconcileSuccessRanges(int $from, int $to, string $gate): array
    {
        $tokens = $this->upgradeTokens();
        $name = rtrim(self::RECONCILE_CALL, '(');

        $calls = [];
        for ($i = $from; $i <= $to; $i++) {
            if ($this->namesFunction($tokens, $i, $name)) {
                $calls[] = $i;
            }
        }
        $this->assertCount(
            1,
            $calls,
            "the step gated on $gate calls $name() " . count($calls) . ' times; this check reads'
            . ' one call, whose result guards the persist. A second result nothing tests would'
            . ' let the version advance over its failure — teach this check the new shape'
            . ' before writing one.'
        );

        // `$variable = _upgrade_measurement_tables(…);` and nothing else on
        // the statement, so the variable holds that result and only that
        // result: `… || true;` holds true.
        $call = $calls[0];
        $equals = $this->previousSignificant($tokens, $call - 1, $from);
        $variable = $equals === null ? null : $this->previousSignificant($tokens, $equals - 1, $from);
        $open = $this->nextSignificant($tokens, $call + 1, $to);
        $close = $open !== null && $tokens[$open]['text'] === '(' ? $this->matchingParen($tokens, $open) : null;
        $end = $close !== null && $close <= $to ? $this->nextSignificant($tokens, $close + 1, $to) : null;
        $this->assertTrue(
            $equals !== null && $tokens[$equals]['text'] === '='
            && $variable !== null && $tokens[$variable]['id'] === T_VARIABLE
            && $end !== null && $tokens[$end]['text'] === ';',
            "the step gated on $gate does not assign the result of $name() on its own (line "
            . $this->lineOf($tokens, $call) . '). Expected `$variable = ' . $name . '(…);`, so the'
            . ' variable holds nothing but that result, and the version persisted inside'
            . ' `if ($variable) { … }` below it; teach this check the new shape before writing one.'
        );
        $variable = (int) $variable;
        $statementEnd = (int) $end;
        $result = $tokens[$variable]['text'];

        // Directly inside the step's braces, as a statement of its own, with
        // nothing above it that can leave: assigned in a nested block, as the
        // body of a braceless `if` or of an `else`, or below a jump, a guard
        // below can read a value that was never set, and the rung never
        // advances — or advances over a reconcile that never ran. Depth alone
        // read `if ($enabled) $ok = …;` as directly inside the step; the
        // question is the one the guard is asked, so it is asked the same way.
        $gateOpen = $this->nextSignificant($tokens, $from + 1, $to);
        $gateClose = $gateOpen === null ? null : $this->matchingParen($tokens, $gateOpen);
        $stepBrace = $gateClose === null ? null : $this->nextSignificant($tokens, $gateClose + 1, $to);
        $this->assertNotNull($stepBrace, "the step gated on $gate has no opening brace");
        $this->assertTrue(
            $this->runsWheneverTheBlockRuns($tokens, (int) $stepBrace, $variable),
            "the step gated on $gate does not assign the result of $name() as a statement of its own"
            . ' directly inside the step (line ' . $this->lineOf($tokens, $variable) . '): nested in a'
            . ' block, as the body of a braceless `if` or of an `else`, or below a return, exit,'
            . ' throw, break, continue or goto, a guard below it can read a value that was never'
            . ' set, and the rung never advances or advances over a reconcile that never ran.'
            . ' Assign it directly inside the step, first in its statement.'
        );

        $recognised = [$variable => true];
        $ranges = [];
        $early = [];
        $conditional = [];
        $leaves = [];
        for ($i = $from; $i <= $to; $i++) {
            if ($tokens[$i]['id'] !== T_IF && $tokens[$i]['id'] !== T_ELSEIF) {
                continue;
            }
            $open = $this->nextSignificant($tokens, $i + 1, $to);
            if ($open === null || $tokens[$open]['text'] !== '(') {
                continue;
            }
            $close = $this->matchingParen($tokens, $open);
            if ($close === null || $close > $to) {
                continue;
            }

            $condition = [];
            for ($j = $open + 1; $j < $close; $j++) {
                if ($tokens[$j]['id'] !== T_WHITESPACE) {
                    $condition[] = $j;
                }
            }
            $condition = $this->stripParentheses($tokens, $condition);
            if (count($condition) !== 1 || $tokens[$condition[0]]['text'] !== $result) {
                continue;
            }
            $recognised[$condition[0]] = true;

            $brace = $this->nextSignificant($tokens, $close + 1, $to);
            $this->assertTrue(
                $brace !== null && $tokens[$brace]['text'] === '{',
                "the step gated on $gate tests the reconcile result without braces (line "
                . $this->lineOf($tokens, $i) . "); this check reads `if ($result) { … }` only"
            );
            $end = $this->matchingBrace($tokens, (int) $brace);
            $this->assertTrue(
                $end !== null && $end <= $to,
                "the step gated on $gate has an unbalanced guard at line " . $this->lineOf($tokens, $i)
            );

            if ($i < $statementEnd) {
                $early[] = $this->lineOf($tokens, $i);
                continue;
            }
            // An `if` of its own, directly inside the step, with nothing
            // above it that can leave: nested in a further condition, or an
            // `elseif` that runs only when the branch before it did not, or
            // below `if ($skip) { return false; }`, a successful reconcile
            // may never reach its persist and the rung never converges.
            $jumps = $this->jumpsBetween($tokens, (int) $stepBrace, $i);
            if ($jumps !== []) {
                $leaves[] = 'line ' . $this->lineOf($tokens, $i)
                    . ' below a jump at line(s) ' . implode(', ', $jumps);
                continue;
            }
            if ($tokens[$i]['id'] !== T_IF || !$this->runsWheneverTheBlockRuns($tokens, (int) $stepBrace, $i)) {
                $conditional[] = $this->lineOf($tokens, $i);
                continue;
            }
            $ranges[] = [(int) $brace, (int) $end];
        }
        $this->assertSame(
            [],
            $early,
            "the step gated on $gate tests the reconcile result $result at line(s) "
            . implode(', ', $early) . ' before assigning it at line '
            . $this->lineOf($tokens, $variable) . ': that guard reads nothing, or a stale value,'
            . ' so the persist inside it never runs and the rung never advances — or runs over a'
            . ' failed reconcile. Move the guard below the assignment.'
        );
        $this->assertSame(
            [],
            $conditional,
            "the step gated on $gate tests the reconcile result $result at line(s) "
            . implode(', ', $conditional) . ' inside a further condition, or as an `elseif`, so a'
            . ' successful reconcile may never reach its persist and the rung never converges.'
            . ' The guard has to be an `if` of its own, directly inside the step.'
        );
        $this->assertSame(
            [],
            $leaves,
            "the step gated on $gate tests the reconcile result $result at " . implode('; ', $leaves)
            . ': on the path that jump takes, a successful reconcile never reaches its persist and'
            . ' the rung never converges. This check does not read where a jump lands — a return,'
            . ' exit, throw, break, continue or goto above the guard is refused whatever it does;'
            . ' teach it the new shape before writing one.'
        );

        $stray = [];
        for ($i = $from; $i <= $to; $i++) {
            if ($tokens[$i]['id'] === T_VARIABLE && $tokens[$i]['text'] === $result && !isset($recognised[$i])) {
                $stray[] = $this->lineOf($tokens, $i);
            }
        }
        $this->assertSame(
            [],
            $stray,
            "the step gated on $gate uses the reconcile result $result at line(s) "
            . implode(', ', $stray) . ' other than as the whole condition of an `if` below its'
            . ' assignment. A write there — an assignment, a compound assignment, a reference, a'
            . ' destructuring — would make the guard test something other than the reconcile'
            . ' result, and this check does not tell a read from a write. Keep the variable to'
            . ' its assignment and its guards, or teach this check the new shape.'
        );

        // And nothing outside the step that can write it without naming it
        // here: the scan above reads the step, and an alias made above the
        // gate is not in the step.
        $this->assertNoWritePathTheScanCannotFollow([$result], "the step gated on $gate");

        return $ranges;
    }

    /** The previous non-whitespace token index in [$floor, $from], or null. */
    private function previousSignificant(array $tokens, int $from, int $floor): ?int
    {
        for ($i = $from; $i >= $floor; $i--) {
            if ($tokens[$i]['id'] !== T_WHITESPACE) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Does the token at $at start a statement that runs whenever the block
     * opened at $open runs — inside its braces at no deeper level, first in
     * its statement, so not the body of a braceless `if` or of an `else`,
     * and with nothing above it in the block that can leave? A statement
     * after a closing brace runs only if the branch that brace closed cannot
     * return, throw or exit first; jumpsBetween() has the shapes.
     */
    private function runsWheneverTheBlockRuns(array $tokens, int $open, int $at): bool
    {
        $close = $this->matchingBrace($tokens, $open);
        if ($close === null || $at <= $open || $at >= $close) {
            return false;
        }
        if ($this->jumpsBetween($tokens, $open, $at) !== []) {
            return false;
        }
        $depth = 0;
        for ($i = $open + 1; $i < $at; $i++) {
            $text = $tokens[$i]['text'];
            if ($text === '{' || $text === '${') {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
            }
        }
        if ($depth !== 0) {
            return false;
        }
        $prev = $this->previousSignificant($tokens, $at - 1, $open);

        return $prev !== null && in_array($tokens[$prev]['text'], [';', '{', '}'], true);
    }

    /**
     * The lines of every jump — return, exit/die, throw, break, continue,
     * goto — between the block opened at $open and the token at $at, at any
     * depth.
     *
     * A statement after a closing brace runs whenever the block runs only if
     * the branch that brace closed cannot leave first: `if ($skip) { return
     * false; }` above the guard, or above the persist call inside it, is a
     * path on which a successful reconcile never records its version and
     * the rung is stuck. This check does not read where a jump lands — a
     * `break` in a nested loop, a `return` in a closure, a `throw` a nested
     * `try` catches would all stay inside the block — so every one in the
     * range is refused, by line, and a step that needs one teaches the check
     * first. A call to a function that never returns is not read at all.
     *
     * @return list<int>
     */
    private function jumpsBetween(array $tokens, int $open, int $at): array
    {
        $lines = [];
        for ($i = $open + 1; $i < $at; $i++) {
            if (in_array($tokens[$i]['id'], self::JUMPS, true)) {
                $lines[] = $this->lineOf($tokens, $i);
            }
        }

        return $lines;
    }

    /**
     * Does the call whose name is at $call run on every path through the
     * block opened at $open? Directly inside it, and in one of three shapes
     * that evaluate the call unconditionally: a statement of its own,
     * `$v = call(…);`, or the first operand of an `if` that starts a
     * statement — so `if ($enabled && call(…))` is refused, the short-circuit
     * skipping the call when $enabled is false.
     */
    private function runsOnEveryPathOf(array $tokens, int $open, int $call): bool
    {
        if ($this->runsWheneverTheBlockRuns($tokens, $open, $call)) {
            return true;
        }
        $close = $this->matchingBrace($tokens, $open);
        if ($close === null || $call <= $open || $call >= $close) {
            return false;
        }
        $depth = 0;
        for ($i = $open + 1; $i < $call; $i++) {
            $text = $tokens[$i]['text'];
            if ($text === '{' || $text === '${') {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
            }
        }
        if ($depth !== 0) {
            return false;
        }

        $prev = $this->previousSignificant($tokens, $call - 1, $open);
        if ($prev === null) {
            return false;
        }
        if ($tokens[$prev]['text'] === '=') {
            $variable = $this->previousSignificant($tokens, $prev - 1, $open);

            return $variable !== null && $tokens[$variable]['id'] === T_VARIABLE
                && $this->runsWheneverTheBlockRuns($tokens, $open, $variable);
        }
        if ($tokens[$prev]['text'] === '(') {
            $keyword = $this->previousSignificant($tokens, $prev - 1, $open);

            return $keyword !== null && $tokens[$keyword]['id'] === T_IF
                && $this->runsWheneverTheBlockRuns($tokens, $open, $keyword);
        }

        return false;
    }

    /** The 1-based line of the ladder source that the token at $at starts on. */
    private function lineOf(array $tokens, int $at): int
    {
        $line = 1;
        for ($i = 0; $i < $at; $i++) {
            $line += substr_count($tokens[$i]['text'], "\n");
        }

        return $line;
    }

    /**
     * Every reconcile call sits inside a version gate.
     *
     * Brace-bounding stops an escaped call being credited to the gate above
     * it, but a call added BESIDE a still-correct gated one would otherwise
     * go unseen: it runs for every stored version, which is the ungated shape
     * this file exists to refuse.
     */
    public function testEveryReconcileCallIsInsideAVersionGate(): void
    {
        $tokens = $this->upgradeTokens();
        $steps = $this->ladderSteps();
        $name = rtrim(self::RECONCILE_CALL, '(');

        $calls = 0;
        $ungated = [];
        foreach ($tokens as $i => $token) {
            if (!$this->namesFunction($tokens, $i, $name)) {
                continue;
            }

            $calls++;
            foreach ($steps as $step) {
                // gatesOnly, not merely enclosing: a condition that names a
                // version but admits another path
                // (`$prosper202_version == '1.9.75' || $force`) encloses the
                // call while letting it run at every other stored version,
                // which is the ungated shape this test exists to refuse.
                if ($step['gatesOnly'] && $i >= $step['from'] && $i <= $step['to']) {
                    continue 2;
                }
            }
            $ungated[] = $i;
        }

        $this->assertGreaterThan(0, $calls, 'no reconcile call found; this test reads them by name');
        $this->assertSame(
            [],
            $ungated,
            $name . '() is called outside every version gate, so it reconciles on every upgrade run'
            . ' whatever version the install is stored at'
        );
    }

    /**
     * A version gate admits the stored versions it names, and nothing else.
     *
     * `versionsComparedIn()` reports the versions a condition compares
     * against, which is not the same question as whether the condition is
     * those comparisons. `if ($prosper202_version == '1.9.75' || $force)`
     * answers 1.9.75 to the first question and runs at every version, so a
     * step was read as gated on 1.9.75 while reconciling on every upgrade
     * run — planted as the stronger `|| true` on the real reconcile gate, the
     * whole suite stayed green.
     *
     * The condition must therefore BE the version equalities: `||` between
     * them is the ladder's own compound gate, parentheses are free, and
     * nothing else. Not `&&` — a conjunct keeps other versions out and can
     * keep this one out too, which strands every install at the version the
     * rung was written to move. Not a cast — `(bool) $prosper202_version ==
     * '1.9.75'` coerces both sides and admits every non-empty version. Not a
     * negation. Each of those fails naming the condition rather than being
     * reasoned about.
     */
    public function testEveryVersionGateAdmitsOnlyTheStoredVersion(): void
    {
        $leaky = [];
        foreach ($this->ladderSteps() as $step) {
            if ($step['gatesOnly']) {
                continue;
            }
            $leaky[] = $step['condition'];
        }

        $this->assertSame(
            [],
            $leaky,
            'an upgrade block names a version but its condition admits another path in, so the'
            . ' block runs for stored versions it does not name. Every path into a gated block'
            . ' must go through one of its version equalities.'
        );
    }

    /**
     * An install that converges its attribution tables must go on to reach
     * CURRENT_VERSION, not stop at a rung whose successor nobody wrote.
     *
     * Walked from the step's GATE, not from what it persists. Seeded with the
     * persist, the loop exited before its first iteration whenever the newest
     * step already writes CURRENT_VERSION — which is the arrangement today,
     * so the walk ran zero times and proved nothing. From the gate it climbs
     * at least one rung, and $rungs asserts that it did.
     */
    public function testTheNewestAttributionStepChainsToTheCodeVersion(): void
    {
        $steps = $this->attributionSteps();
        $newest = $steps[count($steps) - 1];

        $ladder = [];
        foreach ($this->ladderSteps() as $step) {
            foreach ($step['persists'] as $to) {
                foreach ($step['gates'] as $from) {
                    if (version_compare($to, $from, '>')) {
                        $ladder[$from] = $to;
                    }
                }
            }
        }

        $rungs = 0;
        foreach ($newest['gates'] as $gate) {
            $at = $gate;
            $seen = [];
            while ($at !== self::CURRENT_VERSION) {
                $this->assertArrayNotHasKey($at, $seen, "the ladder loops at $at");
                $seen[$at] = true;
                $this->assertArrayHasKey(
                    $at,
                    $ladder,
                    "the ladder stops at $at, short of " . self::CURRENT_VERSION
                    . '; an install that converged its attribution tables would be stranded there'
                );
                $at = $ladder[$at];
                $rungs++;
            }
            $this->assertSame(self::CURRENT_VERSION, $at);
        }

        $this->assertGreaterThan(0, $rungs, 'the walk never ran, so it checked nothing');
    }

    /**
     * The ladder's last rung is the code's version.
     *
     * The step gated on PRIOR_VERSION persists CURRENT_VERSION, so an install
     * that reports the previous release converges through the ordinary
     * upgrade page: upgrade_needed() is true, connect.php redirects there,
     * and the block runs. That holds for every deployment mode, the ones
     * with the 1-click pages disabled included.
     */
    public function testTheStepGatedOnThePriorVersionPersistsTheCodeVersion(): void
    {
        // Asked of the parsed persists, not matched as one spelling of the
        // statement: reformatting the UPDATE must not be able to fail this.
        foreach ($this->ladderSteps() as $step) {
            if (in_array(self::PRIOR_VERSION, $step['gates'], true)) {
                $this->assertContains(self::CURRENT_VERSION, $step['persists']);

                return;
            }
        }

        $this->fail('there must be an upgrade block gated on ' . self::PRIOR_VERSION);
    }

    /**
     * Every write to 202_version is in a spelling PERSIST_PATTERN can read.
     *
     * The other half of the persist scans: they answer "the top is 1.9.76"
     * from the writes they recognise, and a write they do not recognise is
     * indistinguishable from one that is not there. A rung added as
     * ``UPDATE `202_version` SET `version` = '1.9.77'`` left the 100-plus
     * matches and their maximum untouched, so the very regression
     * testTheLadderTopIsTheCodeVersion exists to catch passed.
     */
    public function testEveryVersionWriteIsInASpellingTheScanCanRead(): void
    {
        $writes = 0;
        $unreadable = [];

        foreach ($this->upgradeTokens() as $token) {
            if (!in_array($token['id'], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }

            $text = $token['text'];
            if (stripos($text, '202_version') === false) {
                continue;
            }
            if (preg_match('/\\b(?:UPDATE|INSERT\\s+INTO)\\b/i', $text) !== 1) {
                // A read or the CREATE TABLE, not a write.
                continue;
            }

            $writes++;
            if (preg_match(self::PERSIST_PATTERN, $text) === 1) {
                continue;
            }

            // The value may be concatenated on rather than quoted inline —
            // the downgrade guard writes `version='" . $prosper202_version . "'`
            // — so a literal that ends with the value still open is read by
            // the scan over the joined code, not by this one over one token.
            $inner = preg_match('/^[\'"]/', $text) === 1 ? substr($text, 1, -1) : $text;
            if (preg_match('/`?version`?\\s*=\\s*\'$/i', $inner) === 1) {
                continue;
            }

            $unreadable[] = trim($text);
        }

        $this->assertGreaterThan(
            100,
            $writes,
            'the ladder writes fewer versions than it has steps; this scan is reading the wrong thing'
        );
        $this->assertSame(
            [],
            array_values(array_unique($unreadable)),
            'a statement writes 202_version in a spelling PERSIST_PATTERN cannot read, so the version'
            . ' it writes is invisible to every persist scan. Widen the pattern to cover it.'
        );
    }

    /**
     * No upgrade block may be gated on the version the code itself carries.
     *
     * Such a block only runs when the stored version already equals the code
     * version. upgrade.php refuses that case with "Already Upgraded" before
     * the ladder is reached (upgrade_needed() is `stored != code`), and
     * upgrade.php is where connect.php redirects and the only upgrade entry
     * point a deployment with the 1-click pages disabled has. The 1-click
     * pages do NOT check upgrade_needed() — their POST handler runs the
     * ladder once a download has unpacked — so the block is reachable by
     * re-installing the same release over itself, which is not something an
     * existing install does and not a convergence mechanism a release can
     * rely on.
     *
     * A block gated on 1.9.76 was one of these, written to converge
     * pre-release deployments holding an earlier shape of the 1.9.76 tables.
     * The answer is to fold the reshape into the step that introduces the
     * number while it is unreleased, or to take the next number once it has
     * shipped.
     *
     * Read from the whole token stream, deliberately not from ladderSteps().
     * ladderSteps() recognises `if (…) {` and `elseif (…) {` only, so
     * `if (…):`, a braceless body, and a comparison buried in a ternary were
     * spellings of this gate it would not report — and a scanner that cannot
     * see a gate answers "no such gate", the one direction in which a hole
     * here passes in silence. Asking the question without looking at
     * statement structure has no shape left to miss.
     */
    public function testNoUpgradeBlockIsGatedOnTheCodeVersion(): void
    {
        $code = $this->codeVersion();
        $this->assertSame(self::CURRENT_VERSION, $code, 'CURRENT_VERSION must track version.php');

        $tokens = $this->upgradeTokens();
        $compared = $this->versionsComparedAmong($tokens, $this->significantTokens($tokens));
        $this->assertNotSame([], $compared, 'no version comparison found at all; this scan is broken');

        $this->assertNotContains(
            $code,
            $compared,
            "an upgrade block gated on the code's own version ($code) is dead where it matters."
            . ' upgrade_needed() is `stored != code`, so upgrade.php answers "Already Upgraded"'
            . ' before the ladder runs — and that is the entry point connect.php redirects to,'
            . ' and the only one a deployment with the 1-click pages disabled has. Fold the'
            . ' change into the step that introduces the number while it is unreleased, or take'
            . ' the next number once it has shipped.'
        );
    }

    /**
     * The ladder gates on equality, never on a switch over the stored version.
     *
     * A `case` arm carries no T_IS_EQUAL, so the scan above cannot see
     * `switch ($prosper202_version) { case '1.9.76': }` — the one spelling of
     * the forbidden gate that survives dropping statement structure. Rather
     * than teach that scan to follow arms, which is the structure-following
     * whose holes it exists to close, the construct is forbidden outright:
     * the ladder is 120-odd equality gates and a switch over it would be a
     * rewrite. Teach both scans before writing one.
     *
     * The construct is read WHOLE — subject and body, `switch`/`match` to
     * its closing brace — and the version may not appear anywhere in it.
     * Every earlier draft read one position inside it instead and something
     * equivalent kept turning up there: the token after `(` missed
     * `switch ((string) $v)` and `match (($v))`, and then the token after
     * `case` missed `case ($v):`. Reading one position is what keeps being
     * wrong, so no position is read.
     *
     * Two deliberate over-approximations follow from reading it whole, and
     * the file contains no switch, match or case today, so neither costs a
     * false positive now: a construct that mentions the version for an
     * unrelated reason fails, and so does any switch in the alternative
     * syntax, whose body this cannot bound at a brace. Both say to teach the
     * scans first, which is the right way round — the alternative is a hole
     * that says nothing.
     *
     * Not covered, and named so it is not mistaken for an oversight: a
     * version reached through an intermediate (`$v = $prosper202_version;
     * switch ($v)`). The scan sees names, not dataflow.
     */
    public function testTheLadderNeverSwitchesOnTheStoredVersion(): void
    {
        $tokens = $this->upgradeTokens();
        $significant = $this->significantTokens($tokens);
        $position = array_flip($significant);
        $name = '$prosper202_version';

        $found = [];
        foreach ($significant as $k => $i) {
            if (!in_array($tokens[$i]['id'], [T_SWITCH, T_MATCH], true)) {
                continue;
            }

            $open = $significant[$k + 1] ?? null;
            $this->assertNotNull($open, 'a ' . $tokens[$i]['text'] . ' with no subject');
            $this->assertSame('(', $tokens[$open]['text'], 'a ' . $tokens[$i]['text'] . ' with no subject');

            $close = $this->matchingParen($tokens, $open);
            $this->assertNotNull($close, 'unbalanced parentheses after ' . $tokens[$i]['text']);
            $this->assertArrayHasKey($close, $position, 'the subject\'s ) is not significant');

            $brace = $significant[$position[$close] + 1] ?? null;
            $this->assertNotNull($brace, 'a ' . $tokens[$i]['text'] . ' with no body');
            $this->assertSame(
                '{',
                $tokens[$brace]['text'],
                'a switch in the alternative syntax (`switch (…): … endswitch;`). This scan reads'
                . ' `{ … }` only, so teach it that form before writing one.'
            );

            $end = $this->matchingBrace($tokens, $brace);
            $this->assertNotNull($end, 'unbalanced braces in a ' . $tokens[$i]['text']);

            for ($j = $i; $j <= $end; $j++) {
                if ($tokens[$j]['id'] === T_VARIABLE && $tokens[$j]['text'] === $name) {
                    $found[] = $tokens[$i]['text'] . ' naming ' . $name;
                    break;
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($found)),
            'a switch or match names $prosper202_version, and its arms carry no equality token for'
            . ' testNoUpgradeBlockIsGatedOnTheCodeVersion to read, so a gate on the code version'
            . ' would pass both guards. Teach both scans before writing one.'
        );
    }

    /**
     * The downgrade guard names the code version.
     *
     * The ladder ends with `if (stored > X) set X`, so an install that is
     * ahead of the code is pulled back to it. If X lags version.php, every
     * upgrade run clamps a converged install back BELOW the code version,
     * upgrade_needed() turns true again, and connect.php redirects every
     * page to the upgrade screen forever. That is a version bump that forgot
     * one line, and nothing else in the tree would notice.
     */
    public function testTheDowngradeGuardNamesTheCodeVersion(): void
    {
        $guard = $this->downgradeGuard();

        // The whole condition, not a pattern that may match part of it: a
        // greedy `/^version_compare\(.*'>'\)$/` accepted
        // `version_compare(…, '<') || version_compare(…, '>')`, which runs
        // the clamp for every version BUT the current one — so a step that
        // failed and left the version behind would be marked current and
        // never retried.
        $this->assertSame(
            [self::CURRENT_VERSION, '>'],
            $this->downgradeComparison($guard['conditionTokens']),
            'the downgrade guard must be exactly version_compare($prosper202_version, \''
            . self::CURRENT_VERSION . '\', \'>\'), which pulls an install that is AHEAD of the'
            . ' code back to it. Its condition reads: ' . $guard['condition']
        );

        $this->assertStringContainsString(
            "\$prosper202_version = '" . self::CURRENT_VERSION . "';",
            $guard['block'],
            'the downgrade guard must clamp the stored version to ' . self::CURRENT_VERSION
        );

        // In memory is not enough, and asserting only the assignment let the
        // UPDATE and its _upgrade_query() call be deleted with this green.
        // Without the write, 202_version keeps the higher number,
        // upgrade_needed() stays true, and connect.php redirects every page
        // to the upgrade screen on every request — the exact failure the
        // guard exists to prevent, and the one its clamp only appears to fix.
        //
        // Asserting the persist and the call separately was not enough
        // either: the guard could build $sql and hand _upgrade_query() a
        // different variable, and both assertions passed. The call has to
        // receive the persist.
        //
        // And the version it writes is read, not assumed: the guard builds
        // its statement from $prosper202_version, which it has just set, so
        // the fold in persistsThatReachAQuery() resolves it to the code
        // version — and a guard that writes anything else fails here.
        $this->assertArrayHasKey(
            self::CURRENT_VERSION,
            $this->persistsThatReachAQuery($guard['from'], $guard['to'], 'the downgrade guard'),
            'the downgrade guard does not run a query carrying its UPDATE of 202_version to '
            . self::CURRENT_VERSION . ', so the stored version stays above the code version and'
            . ' every request keeps entering the upgrade flow'
        );
    }

    /**
     * The version and operator of a condition that is exactly one
     * `version_compare($prosper202_version, 'X', 'OP')`, or [] for anything
     * else — another call beside it, a disjunction, a negation.
     *
     * @param  list<int> $inside significant token indices of the condition
     * @return list<string>
     */
    private function downgradeComparison(array $inside): array
    {
        $tokens = $this->upgradeTokens();
        $inside = array_values($inside);

        $connectives = [T_BOOLEAN_OR, T_LOGICAL_OR, T_BOOLEAN_AND, T_LOGICAL_AND];
        if (count($this->splitTopLevel($tokens, $inside, $connectives)) > 1) {
            return [];
        }
        if ($inside === [] || !$this->namesFunction($tokens, $inside[0], 'version_compare')) {
            return [];
        }

        $open = $inside[1] ?? null;
        if ($open === null || $tokens[$open]['text'] !== '(') {
            return [];
        }
        $close = $this->matchingParen($tokens, $open);
        if ($close === null || $close !== $inside[count($inside) - 1]) {
            // Something follows the call, so the call is not the condition.
            return [];
        }

        $arguments = $this->splitTopLevel($tokens, array_slice($inside, 2, count($inside) - 3), [], [',']);
        if (count($arguments) !== 3) {
            return [];
        }

        $text = [];
        foreach ($arguments as $argument) {
            $joined = '';
            foreach ($argument as $j) {
                $joined .= $tokens[$j]['text'];
            }
            $text[] = trim($joined);
        }

        if (!$this->isStoredVersionOperand($tokens, $arguments[0])) {
            return [];
        }

        return [trim($text[1], '\'"'), trim($text[2], '\'"')];
    }

    /**
     * Is this argument the stored version itself?
     *
     * `str_contains($argument, '$prosper202_version')` accepted
     * `(bool) $prosper202_version`, which stringifies to "1" — so
     * `version_compare("1", '1.9.76', '>')` is false for every stored
     * version, the clamp never fires, and an install ahead of the code keeps
     * entering the upgrade flow while this check stays green. Measured
     * against 1.9.77, 1.10.0 and 2.0.0.
     *
     * Parentheses are free and one `(string)` cast is allowed, because the
     * value is already a string and the guard is written with it. Every
     * other cast changes what is compared, so it is refused rather than
     * reasoned about — the same call made for gate operands.
     *
     * @param list<int> $argument significant token indices of the argument
     */
    private function isStoredVersionOperand(array $tokens, array $argument): bool
    {
        $argument = $this->stripParentheses($tokens, $argument);

        if ($argument !== [] && $tokens[$argument[0]]['id'] === T_STRING_CAST) {
            if (preg_replace('/\\s+/', '', strtolower($tokens[$argument[0]]['text'])) !== '(string)') {
                return false;
            }
            $argument = $this->stripParentheses($tokens, array_slice($argument, 1));
        }

        return count($argument) === 1
            && $tokens[$argument[0]]['id'] === T_VARIABLE
            && $tokens[$argument[0]]['text'] === '$prosper202_version';
    }

    /**
     * Drop every pair of parentheses that encloses the whole run.
     *
     * @param  list<int> $run
     * @return list<int>
     */
    private function stripParentheses(array $tokens, array $run): array
    {
        $run = array_values($run);

        for ($guard = 0; $guard < 64; $guard++) {
            $last = count($run) - 1;
            if ($last < 1 || $tokens[$run[0]]['text'] !== '(') {
                return $run;
            }
            if ($this->matchingParen($tokens, $run[0]) !== $run[$last]) {
                return $run;
            }
            $run = array_slice($run, 1, $last - 1);
        }

        return $run;
    }

    /**
     * The versions a range actually writes: those whose `UPDATE 202_version`
     * reaches an `_upgrade_query()` call.
     *
     * A literal is not a write. A rung keeping
     * `_upgrade_query("UPDATE 202_version SET version='1.9.76'")` as a bare
     * string — assigned, logged, refactored past — still satisfies a scan
     * that reads the block for the statement, so the version reads as
     * persisted while the database stays where it was and the rung re-enters
     * on every run. The downgrade guard was held to this first; every
     * attribution rung is held to it too.
     *
     * One walk in source order. A variable holds a value from a plain
     * assignment whose right-hand side this can read — string literals and
     * variables already held, joined with `.` — and holds it until the
     * variable next appears anywhere but as the sole argument of
     * `_upgrade_query()`: a compound assignment, an increment, a reference, a
     * destructuring, an argument to a call that may take it by reference.
     * This check does not tell a read from a write, so any of them ends the
     * hold, and a persist that then reaches no call is reported as never
     * written — the loud direction. What reaches the call has to be the whole
     * statement, `UPDATE 202_version SET version='X'` and nothing after it: a
     * clause appended to it changes what the statement does while the
     * spelling scan still reads the version.
     *
     * The first version of this collected every `$v = "UPDATE …"` in the
     * range with no order and credited each `_upgrade_query($v)` from that
     * map, so `$sql = "UPDATE …"; $sql = "SELECT …"; _upgrade_query($sql);`
     * read as a persist, and so did a call above its assignment. The second
     * walked in order but through braces as if they were not there, so a
     * value set inside a nested condition was credited at a call after it.
     * A hold now lives in the block that set it and leaving the block drops
     * it. PHP's scope is the function, so this is stricter than the
     * language: a shape that is only conditionally correct is refused
     * rather than reasoned about.
     *
     * @param  string $what the block, for the failure message
     * @return array<string, list<int>> the versions written, as the statements
     *     spell them, each to the `_upgrade_query()` calls (token indices of
     *     the name) that receive it
     *
     * A hold is established only by an assignment that starts a statement
     * of its own — the previous significant token a `;`, `{` or `}` — so
     * `if ($enabled) $sql = "UPDATE …";` and `else $sql = …;`, which sit at
     * their block's depth and run only sometimes, establish none, and the
     * call below them then receives a variable this walk does not vouch
     * for. The same question the guard and the reconcile assignment are
     * asked, asked here as well.
     */
    private function persistsThatReachAQuery(int $from, int $to, string $what): array
    {
        $tokens = $this->upgradeTokens();

        $holds = [];
        $held = [];
        $reach = [];
        $depth = 0;
        for ($i = $from; $i <= $to; $i++) {
            $token = $tokens[$i];

            if ($token['text'] === '{' || $token['text'] === '${') {
                $depth++;
                continue;
            }
            if ($token['text'] === '}') {
                $depth--;
                foreach ($holds as $name => $hold) {
                    if ($hold['depth'] > $depth) {
                        unset($holds[$name]);
                    }
                }
                continue;
            }

            if ($token['id'] === T_VARIABLE) {
                $next = $this->nextSignificant($tokens, $i + 1, $to);
                if ($next === null || $tokens[$next]['text'] !== '=') {
                    unset($holds[$token['text']]);
                    continue;
                }
                // Only an assignment that starts a statement of its own can
                // establish a hold: `if ($enabled) $sql = …;` sits at its
                // block's depth and runs only when $enabled is true, so a
                // call below it would receive whatever $sql held before.
                // Depth alone read it as unconditional. The right-hand side
                // is still walked, for the calls and the variables in it.
                $prev = $this->previousSignificant($tokens, $i - 1, $from);
                if ($prev === null || !in_array($tokens[$prev]['text'], [';', '{', '}'], true)) {
                    unset($holds[$token['text']]);
                    continue;
                }

                $end = $next + 1;
                while ($end <= $to && $tokens[$end]['text'] !== ';') {
                    $end++;
                }
                $value = $this->foldedString(
                    $tokens,
                    $next + 1,
                    $end - 1,
                    array_map(static fn(array $hold): string => $hold['value'], $holds)
                );
                unset($holds[$token['text']]);
                if ($value === null) {
                    // An unreadable right-hand side is walked into, so a call
                    // in it is still seen and the variables it uses lose
                    // their holds.
                    continue;
                }
                $holds[$token['text']] = ['value' => $value, 'depth' => $depth];
                $held[$token['text']] = true;
                $i = $end;
                continue;
            }

            if (!$this->namesFunction($tokens, $i, '_upgrade_query')) {
                continue;
            }
            $call = $i;
            $open = $this->nextSignificant($tokens, $i + 1, $to);
            if ($open === null || $tokens[$open]['text'] !== '(') {
                continue;
            }
            $close = $this->matchingParen($tokens, $open);
            if ($close === null || $close > $to) {
                continue;
            }

            $argument = [];
            for ($j = $open + 1; $j < $close; $j++) {
                if ($tokens[$j]['id'] !== T_WHITESPACE) {
                    $argument[] = $j;
                }
            }
            if (count($argument) !== 1) {
                // Walked into rather than skipped: a variable in it is a use
                // this cannot read.
                continue;
            }
            $statement = $this->literalString($tokens[$argument[0]]);
            if ($tokens[$argument[0]]['id'] === T_VARIABLE) {
                $statement = $holds[$tokens[$argument[0]]['text']]['value'] ?? null;
            }
            // The call's own argument is a read this has accounted for.
            $i = $close;
            if ($statement !== null && preg_match(self::PERSIST_STATEMENT, $statement, $written) === 1) {
                $reach[$written[1]][] = $call;
            }
        }

        // A hold says the variable was not touched between its assignment
        // and the call, which the walk above knows only for writes that
        // name it in the range. Nothing elsewhere may alias it.
        $this->assertNoWritePathTheScanCannotFollow(array_keys($held), $what);

        return $reach;
    }

    /**
     * The string an expression denotes when it is string literals and held
     * variables joined with `.`, or null for anything this cannot read — a
     * call, a cast, an unknown variable, an interpolated string.
     *
     * @param array<string, string> $holds variable name => the string it holds
     */
    private function foldedString(array $tokens, int $from, int $to, array $holds): ?string
    {
        $value = '';
        $operand = true;
        for ($i = $from; $i <= $to; $i++) {
            $token = $tokens[$i];
            if ($token['id'] === T_WHITESPACE) {
                continue;
            }
            if ($operand) {
                $part = $this->literalString($token);
                if ($part === null && $token['id'] === T_VARIABLE) {
                    $part = $holds[$token['text']] ?? null;
                }
                if ($part === null) {
                    return null;
                }
                $value .= $part;
            } elseif ($token['text'] !== '.') {
                return null;
            }
            $operand = !$operand;
        }

        return $operand ? null : $value;
    }

    /**
     * The string a T_CONSTANT_ENCAPSED_STRING token denotes, or null when the
     * token is not one or spells an escape this does not decode.
     */
    private function literalString(array $token): ?string
    {
        if ($token['id'] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $text = $token['text'];
        $inner = substr($text, 1, -1);
        if ($text[0] === "'") {
            return preg_replace('/\\\\([\\\\\'])/', '$1', $inner);
        }
        if ($text[0] !== '"' || str_contains($inner, '\\')) {
            return null;
        }

        return $inner;
    }

    /** The next non-whitespace token index in [$from, $to], or null. */
    private function nextSignificant(array $tokens, int $from, int $to): ?int
    {
        for ($i = $from; $i <= $to; $i++) {
            if ($tokens[$i]['id'] !== T_WHITESPACE) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The ladder's downgrade guard: its condition, and its body bounded at
     * the real closing brace.
     *
     * Found by the condition in the comment-stripped code, never by the
     * comment above it — anchored on that prose, this check passed against a
     * guard commented out in its entirety, because the prose was all that
     * survived.
     *
     * @return array{condition: string, conditionTokens: list<int>, block: string, from: int, to: int}
     */
    private function downgradeGuard(): array
    {
        $tokens = $this->upgradeTokens();
        $significant = $this->significantTokens($tokens);
        $position = array_flip($significant);

        foreach ($significant as $k => $i) {
            if ($tokens[$i]['id'] !== T_IF) {
                continue;
            }
            $open = $significant[$k + 1] ?? null;
            if ($open === null || $tokens[$open]['text'] !== '(') {
                continue;
            }
            $close = $this->matchingParen($tokens, $open);
            if ($close === null || !isset($position[$close])) {
                continue;
            }

            $from = $position[$open] + 1;
            $conditionTokens = array_slice($significant, $from, max(0, $position[$close] - $from));
            $condition = '';
            foreach ($conditionTokens as $j) {
                $condition .= $tokens[$j]['text'];
            }

            $isGuard = str_contains($condition, 'version_compare')
                && str_contains($condition, '$prosper202_version')
                && str_contains($condition, "'" . self::CURRENT_VERSION . "'");
            if (!$isGuard) {
                continue;
            }

            $brace = $significant[$position[$close] + 1] ?? null;
            if ($brace === null || $tokens[$brace]['text'] !== '{') {
                continue;
            }
            $end = $this->matchingBrace($tokens, $brace);
            $this->assertNotNull($end, 'unbalanced braces in the downgrade guard');

            return [
                'condition' => trim($condition),
                'conditionTokens' => $conditionTokens,
                'block' => implode('', array_column(array_slice($tokens, $i, $end - $i + 1), 'text')),
                'from' => $i,
                'to' => (int)$end,
            ];
        }

        $this->fail(
            'the ladder must end with a guard comparing the stored version against '
            . self::CURRENT_VERSION . ' with version_compare, which pulls an install that is'
            . ' ahead of the code back to it'
        );
    }

    /**
     * The highest version any step persists is the code version.
     *
     * The other half of the guard above: a step that persists a version the
     * code does not know strands the install ABOVE the ladder (the guard then
     * pulls it back down on the next run, and the two fight). Derived from
     * the source, so a future step cannot persist 1.9.77 while version.php
     * still says 1.9.76.
     */
    public function testTheLadderTopIsTheCodeVersion(): void
    {
        preg_match_all(self::PERSIST_PATTERN, $this->upgradeCode(), $m);

        // `\d+\.\d+\.\d+` read only 112 of the ladder's 123 persists: 1.4,
        // 1.5, 1.6, 1.7, the four-part 1.8.2.x/1.8.3.x and 1.9.30b all fell
        // out, so "the top" was the top of a subset and a two-part version
        // could have sat above it unseen.
        $literal = [];
        $dynamic = [];
        foreach ($m[1] as $version) {
            if (preg_match('/^\d+(\.\d+)*[a-z]?$/i', $version) === 1) {
                $literal[] = $version;
                continue;
            }
            $dynamic[] = $version;
        }

        $this->assertGreaterThan(
            100,
            count($literal),
            'the ladder persists far fewer versions than it has steps; this scan is reading the wrong thing'
        );

        // The one non-literal write is the downgrade guard clamping to the
        // value it has just set. Any other is a persist this scan cannot
        // rank, and must fail rather than be dropped from the ordering.
        foreach ($dynamic as $version) {
            $this->assertStringContainsString(
                '$prosper202_version',
                $version,
                "a persisted version this scan cannot rank: $version"
            );
        }

        usort($literal, 'version_compare');
        $this->assertSame(self::CURRENT_VERSION, end($literal));
    }

    /**
     * The ladder as the source declares it: one entry per gate, in source
     * order, with the block it actually encloses, the versions that block
     * persists, and whether it reconciles the attribution tables.
     *
     * Bounded by brace depth, not "up to the next gate": a byte range
     * attributes anything before the next gate to the preceding one, so a
     * reconcile moved OUT of its gate still read as gated and inherited that
     * gate's version persist — passing every assertion here while running
     * for every stored version.
     *
     * @return list<array{gates: list<string>, persists: list<string>, reconciles: bool, block: string}>
     */
    private function ladderSteps(): array
    {
        if ($this->stepCache !== null) {
            return $this->stepCache;
        }

        $tokens = $this->upgradeTokens();
        $significant = $this->significantTokens($tokens);
        $position = array_flip($significant);

        $steps = [];
        foreach ($significant as $k => $i) {
            // T_ELSEIF as well as T_IF: an `elseif` gate is a gate, and a
            // step this misses reads to testEveryReconcileCallIsInsideAVersionGate
            // as a reconcile call belonging to no gate at all. (`} else if (`
            // needs nothing — it tokenizes as T_ELSE then T_IF.)
            if ($tokens[$i]['id'] !== T_IF && $tokens[$i]['id'] !== T_ELSEIF) {
                continue;
            }
            $open = $significant[$k + 1] ?? null;
            if ($open === null || $tokens[$open]['text'] !== '(') {
                continue;
            }
            $close = $this->matchingParen($tokens, $open);
            if ($close === null) {
                continue;
            }
            $brace = $significant[($position[$close] ?? -1) + 1] ?? null;
            if ($brace === null || $tokens[$brace]['text'] !== '{') {
                continue;
            }

            $consumed = [];
            $gates = $this->versionsComparedIn($tokens, $significant, $position, $open, $close, $consumed);
            if ($gates === []) {
                continue;
            }

            // Naming a version is not the same as being gated on one:
            // `$prosper202_version == '1.9.75' || $force` names 1.9.75 and
            // runs at every other version too. conditionGatesOnVersion()
            // asks the harder question over the condition's boolean
            // structure.
            $inside = array_slice(
                $significant,
                $position[$open] + 1,
                max(0, $position[$close] - $position[$open] - 1)
            );
            $condition = '';
            foreach ($inside as $j) {
                $condition .= $tokens[$j]['text'];
            }
            $gatesOnly = $this->conditionGatesOnVersion($tokens, $inside);

            $end = $this->matchingBrace($tokens, $brace);
            $this->assertNotNull($end, 'unbalanced braces after a version gate');

            $block = implode('', array_column(array_slice($tokens, $i, $end - $i + 1), 'text'));
            preg_match_all(self::PERSIST_PATTERN, $block, $persisted);

            $steps[] = [
                'gates' => $gates,
                'gatesOnly' => $gatesOnly,
                'condition' => trim($condition),
                'persists' => array_values(array_unique($persisted[1])),
                'reconciles' => str_contains($block, self::RECONCILE_CALL),
                'block' => $block,
                'from' => $i,
                'to' => (int)$end,
            ];
        }

        // A floor: a matcher finding nothing would make every caller pass by
        // having no work to do.
        $this->assertGreaterThan(20, count($steps), 'the version gates could not be parsed');

        return $this->stepCache = $steps;
    }

    /**
     * Every version `$prosper202_version` is compared equal to inside the
     * condition spanning $open..$close.
     *
     * Sliced off $significant by rank rather than filtered by index: filtering
     * walks all ~100k significant tokens once per gate, and with 120-odd gates
     * that was 13.9M closure calls and most of this file's runtime.
     *
     * @return list<string>
     */
    private function versionsComparedIn(
        array $tokens,
        array $significant,
        array $position,
        int $open,
        int $close,
        ?array &$consumed = null
    ): array {
        // Both bounds are punctuation, so both are in $significant. Asserted
        // rather than defaulted: a miss would silently widen the slice, and a
        // scan that reads the wrong range must not report on it.
        $this->assertArrayHasKey($open, $position, 'the condition\'s ( is not a significant token');
        $this->assertArrayHasKey($close, $position, 'the condition\'s ) is not a significant token');

        $from = $position[$open] + 1;
        $to = $position[$close];

        return $this->versionsComparedAmong(
            $tokens,
            array_slice($significant, $from, max(0, $to - $from)),
            $consumed
        );
    }

    /**
     * An operand that did not reduce to one token is not skipped when the
     * stored version is inside it.
     *
     * `((string) $prosper202_version) === PROSPER202_VERSION` reduces to two
     * tokens, so neither side read as the variable and the whole comparison
     * was dropped — the code-version gate invisible once more, by the route
     * the unwrapping was added to close. What a wrapper does to the value is
     * not something this scan can know, so it says so instead of guessing.
     */
    private function refuseUnreadableOperand(array $tokens, array $inside, int $a, int $b): void
    {
        $holdsVersion = false;
        $text = '';
        for ($rank = min($a, $b), $end = max($a, $b); $rank <= $end; $rank++) {
            $token = $tokens[$inside[$rank]];
            $text .= $token['text'];
            if ($token['id'] === T_VARIABLE && $token['text'] === '$prosper202_version') {
                $holdsVersion = true;
            }
        }

        if (!$holdsVersion) {
            return;
        }

        $this->fail(
            'a comparison wraps $prosper202_version in an expression this test cannot reduce to a'
            . ' single token (' . trim($text) . '), so it cannot say which version the gate names.'
            . ' Resolve it here rather than letting the gate go unseen.'
        );
    }

    /**
     * Does every path into a block with this condition go through one of the
     * version equalities the condition names?
     *
     * A rung's gate has to admit the versions it names and no others, and
     * BOTH halves of that are load-bearing. An earlier draft of this checked
     * only the first: it allowed `&&` on the reasoning that a conjunct can
     * only narrow, which is true and beside the point —
     * `$prosper202_version == '1.9.75' && $enabled` keeps other versions out
     * and keeps 1.9.75 out too whenever the flag is false, so the install
     * sits at 1.9.75 forever and the rung never converges it. There is no
     * conjunct this scan can prove always true, so there is no `&&` in a
     * gate: a condition is a disjunction of version equalities, or it is not
     * a gate.
     *
     * A bare term gates only if it IS one version equality, with nothing left
     * over but parentheses. Casts were briefly allowed here as
     * "value-preserving"; `(bool) $prosper202_version == '1.9.75'` coerces
     * both sides and admits every non-empty stored version, measured. A
     * negation is refused for the same reason as a conjunct:
     * `!($prosper202_version == '1.9.75')` runs at every version but that one.
     *
     * @param list<int> $inside significant token indices of the condition
     */
    private function conditionGatesOnVersion(array $tokens, array $inside): bool
    {
        $inside = array_values($inside);
        if ($inside === []) {
            return false;
        }

        $alternatives = $this->splitTopLevel($tokens, $inside, [T_BOOLEAN_OR, T_LOGICAL_OR]);
        if (count($alternatives) > 1) {
            foreach ($alternatives as $alternative) {
                if (!$this->conditionGatesOnVersion($tokens, $alternative)) {
                    return false;
                }
            }

            return true;
        }

        $last = $inside[count($inside) - 1];
        if ($tokens[$inside[0]]['text'] === '(' && $this->matchingParen($tokens, $inside[0]) === $last) {
            return $this->conditionGatesOnVersion($tokens, array_slice($inside, 1, count($inside) - 2));
        }

        $consumed = [];
        if ($this->versionsComparedAmong($tokens, $inside, $consumed) === []) {
            return false;
        }

        foreach ($inside as $i) {
            if (isset($consumed[$i]) || $tokens[$i]['text'] === '(' || $tokens[$i]['text'] === ')') {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * Split a run of significant tokens at the given operators, ignoring any
     * that sit inside parentheses.
     *
     * Separators are named by token id, or by text for punctuation — `,` and
     * the rest tokenize with no id at all, so an id list containing null
     * would split on every operator in the run.
     *
     * @param  list<int> $inside
     * @param  list<int> $ids
     * @param  list<string> $texts
     * @return list<list<int>>
     */
    private function splitTopLevel(array $tokens, array $inside, array $ids, array $texts = []): array
    {
        $parts = [];
        $current = [];
        $depth = 0;

        foreach ($inside as $i) {
            $text = $tokens[$i]['text'];
            $isSeparator = ($tokens[$i]['id'] !== null && in_array($tokens[$i]['id'], $ids, true))
                || ($tokens[$i]['id'] === null && in_array($text, $texts, true));
            if ($text === '(') {
                $depth++;
            } elseif ($text === ')') {
                $depth--;
            } elseif ($depth === 0 && $isSeparator) {
                $parts[] = $current;
                $current = [];
                continue;
            }
            $current[] = $i;
        }
        $parts[] = $current;

        return $parts;
    }

    /**
     * The same question asked of an arbitrary run of significant tokens.
     *
     * Comparisons are walked rather than shape-matched: the ladder already
     * has compound gates (`== 'a' || == 'b' || == 'c'`), and a matcher that
     * insisted the comparison fill the whole condition skipped those blocks
     * entirely. Either operand order counts; `!=` and version_compare() are
     * not equality gates and are ignored.
     *
     * Taking a token run rather than a statement is what lets
     * testNoUpgradeBlockIsGatedOnTheCodeVersion ask it of the whole file,
     * where no statement form can hide a gate from it.
     *
     * @param  list<int> $inside
     * @return list<string>
     */
    private function versionsComparedAmong(array $tokens, array $inside, ?array &$consumed = null): array
    {
        $inside = array_values($inside);

        $consumed = [];
        $versions = [];
        foreach ($inside as $n => $i) {
            if (!in_array($tokens[$i]['id'], [T_IS_EQUAL, T_IS_IDENTICAL], true)) {
                continue;
            }

            $leftAt = $this->operandRank($tokens, $inside, $n - 1, -1);
            $rightAt = $this->operandRank($tokens, $inside, $n + 1, +1);
            $left = $leftAt === null ? null : $tokens[$inside[$leftAt]];
            $right = $rightAt === null ? null : $tokens[$inside[$rightAt]];

            $isVar = static fn(?array $t): bool => $t !== null
                && $t['id'] === T_VARIABLE && $t['text'] === '$prosper202_version';

            if ($isVar($left) === $isVar($right)) {
                // Neither side is the stored version (not a gate), or both
                // are (compares the value with itself, which names no
                // version). A side that did not reduce to one token is null,
                // so it is never the variable and lands here too — unless the
                // OTHER side is, which the branches below then resolve or
                // fail on.
                continue;
            }

            $otherAt = $isVar($left) ? $rightAt : $leftAt;
            if ($otherAt === null) {
                $this->fail(
                    'a gate compares $prosper202_version against an expression this test cannot'
                    . ' reduce to a single token. Resolve it here rather than letting the gate go'
                    . ' unseen.'
                );
            }

            $version = $this->versionOperand($tokens, $inside[$otherAt]);
            if ($version === '') {
                continue;
            }

            // Which tokens this comparison accounted for, so a caller can ask
            // what ELSE the condition contains. Keyed by token index.
            $consumed[$i] = true;
            $consumed[$inside[$leftAt]] = true;
            $consumed[$inside[$rightAt]] = true;

            $versions[] = $version;
        }

        return array_values(array_unique($versions));
    }

    /**
     * The rank in $inside of the single token an operand reduces to, or null
     * when it reduces to more than one.
     *
     * Balanced parentheses are unwrapped first. `($prosper202_version) ===
     * PROSPER202_VERSION` puts a `)` next to the operator, so reading the
     * adjacent token saw punctuation, matched nothing, and reported no gate —
     * and `(($prosper202_version))` did it twice. Unwrapping is iterative for
     * that reason.
     *
     * $step is the direction of travel away from the operator: -1 for the
     * left operand, +1 for the right.
     */
    private function operandRank(array $tokens, array $inside, int $rank, int $step): ?int
    {
        $count = count($inside);
        $open = $step < 0 ? ')' : '(';
        $close = $step < 0 ? '(' : ')';

        // The far edge of what the parentheses stripped so far enclose. Null
        // until the first unwrap, because an unparenthesised operand is
        // whatever token sits next to the operator.
        $limit = null;

        // A bound on nesting, so a malformed run cannot spin here.
        for ($unwraps = 0; $unwraps < 64; $unwraps++) {
            if ($rank < 0 || $rank >= $count) {
                return null;
            }

            if ($tokens[$inside[$rank]]['text'] !== $open) {
                // Anything left between here and the far edge means the
                // parentheses held an expression, not an operand.
                if ($limit === null || $rank === $limit) {
                    return $rank;
                }

                $this->refuseUnreadableOperand($tokens, $inside, $rank, $limit);

                return null;
            }

            $depth = 0;
            $far = null;
            for ($j = $rank; $j >= 0 && $j < $count; $j += $step) {
                $text = $tokens[$inside[$j]]['text'];
                if ($text === $open) {
                    $depth++;
                } elseif ($text === $close) {
                    $depth--;
                    if ($depth === 0) {
                        $far = $j;
                        break;
                    }
                }
            }
            if ($far === null) {
                return null;
            }

            // Step inside from both brackets and go round again, so `(($v))`
            // unwraps twice. Checking for a single token before stripping
            // instead of after saw three tokens inside the outer pair and
            // gave up — which is the silent skip this whole helper exists to
            // remove.
            $limit = $far - $step;
            $rank += $step;
        }

        return null;
    }

    /**
     * The version an operand compared against $prosper202_version names.
     *
     * A string literal is itself; PROSPER202_VERSION is the code version —
     * and `if ($prosper202_version === PROSPER202_VERSION)` is the most
     * natural way to write the gate this suite forbids, so leaving it
     * unrecognised would have left the guard blind to its likeliest spelling.
     *
     * Anything else FAILS rather than being skipped. A scanner that cannot
     * tell which version a gate names must not answer "no such gate" — that
     * silence is how every hole in this parser has looked.
     */
    private function versionOperand(array $tokens, int $at): string
    {
        $token = $tokens[$at];
        if ($token['id'] === T_CONSTANT_ENCAPSED_STRING) {
            return trim($token['text'], '\'"');
        }
        if ($this->namesFunction($tokens, $at, 'PROSPER202_VERSION')) {
            return $this->codeVersion();
        }

        $this->fail(
            'a version gate compares $prosper202_version against ' . $token['text']
            . ', which this test cannot resolve to a version. Resolve it here rather than'
            . ' letting the gate go unseen.'
        );
    }

    /** Index of the `)` closing the `(` at $open, or null if unbalanced. */
    private function matchingParen(array $tokens, int $open): ?int
    {
        $depth = 0;
        for ($i = $open, $n = count($tokens); $i < $n; $i++) {
            $text = $tokens[$i]['text'];
            if ($text === '(') {
                $depth++;
            } elseif ($text === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Does the token at $at name the global function or constant $name the
     * way a call site does — an unqualified `name`, a fully qualified
     * `\name` or a relative `namespace\name`, with nothing before it that
     * makes it something else? `$x->name(`, `$x?->name(` and `Foo::name(`
     * are methods of whatever `$x` and `Foo` are, `new name(` a
     * constructor, `function name(` (or `function &name(`) a declaration,
     * `const name` a constant declaration, and `Foo\name(` a function in
     * another namespace. Every one of them carries the same final token,
     * and the first version of this read the final token only, so a
     * `$logger->_upgrade_query($sql)` that logged the statement was
     * credited as the query that wrote it — the reviewer planted exactly
     * that. An unqualified name is the global one only because the ladder
     * declares no namespace and imports no function, which
     * testTheLadderNamesItsHelpersGlobally() holds.
     */
    private function namesFunction(array $tokens, int $at, string $name): bool
    {
        $token = $tokens[$at];
        if (!in_array($token['id'], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            return false;
        }
        $segments = explode('\\', $token['text']);
        if (end($segments) !== $name) {
            return false;
        }
        $prev = $this->previousSignificant($tokens, $at - 1, 0);
        if ($prev !== null && $tokens[$prev]['text'] === '&') {
            $prev = $this->previousSignificant($tokens, $prev - 1, 0);
        }
        $owners = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_FUNCTION, T_CONST];

        return $prev === null || !in_array($tokens[$prev]['id'], $owners, true);
    }

    /** Index of the `}` closing the `{` at $open, or null if unbalanced. */
    private function matchingBrace(array $tokens, int $open): ?int
    {
        $depth = 0;
        for ($i = $open, $n = count($tokens); $i < $n; $i++) {
            $text = $tokens[$i]['text'];
            // '${' and T_CURLY_OPEN's '{' both open a brace a plain '}' closes.
            if ($text === '{' || $text === '${') {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * The ladder steps that reconcile the attribution tables, oldest first.
     *
     * @return list<array{gates: list<string>, persists: list<string>, reconciles: bool, block: string}>
     */
    private function attributionSteps(): array
    {
        $steps = array_values(array_filter(
            $this->ladderSteps(),
            static fn(array $step): bool => $step['reconciles']
        ));
        $this->assertNotSame(
            [],
            $steps,
            'no upgrade step reconciles the attribution tables, so no existing install ever receives them'
        );

        return $steps;
    }

    private function codeVersion(): string
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/202-config/version.php');
        // Single-quoted: in a double-quoted pattern the $ would interpolate.
        $found = preg_match('/\\$version_string = \'([^\']+)\'/', $source, $m);
        $this->assertSame(1, $found, 'version.php lost its constant');

        return $m[1];
    }

    /**
     * The code of the block gated on $version.
     *
     * Read from ladderSteps() so it is bounded exactly as every other scan
     * bounds it — at the next gate, or the downgrade guard for the last — and
     * a window never spills into the following block and matches ITS persist.
     */
    private function blockGatedOn(string $version): string
    {
        foreach ($this->ladderSteps() as $step) {
            if (in_array($version, $step['gates'], true)) {
                return $step['block'];
            }
        }

        $this->fail('there must be an upgrade block gated on ' . $version);
    }

    /**
     * The legacy guard is deleted, not extended (plan §4.1). It halted the
     * upgrade when pre-release 202_skan_* tables held postbacks, and it
     * backfilled columns an intermediate branch shape had added NOT NULL
     * with no default. Only a branch deployment above 1.9.55 could hold
     * either, no such database exists, and RELEASING.md says databases above
     * 1.9.55 from before the change are reinstalled. What remains of the rung
     * is one job: create the tables from the installer's definitions.
     */
    public function testTheLegacyGuardIsGone(): void
    {
        $source = $this->upgradeSource();
        foreach ([
            '202_skan_',
            '_upgrade_attribution_legacy_skan_state',
            '_upgrade_attribution_backfill',
            'legacy-and-current-data',
            '_upgrade_attribution_tables',
        ] as $gone) {
            $this->assertStringNotContainsString($gone, $source, "$gone belongs to the deleted legacy guard");
        }

        $start = strpos($source, 'function _upgrade_measurement_tables');
        $this->assertNotFalse($start, 'the rung\'s table step must exist');
        $end = strpos($source, "\n}\n", $start);
        $this->assertNotFalse($end);
        $body = substr($source, $start, $end - $start);
        // The step never halts the upgrade: it answers true or false, and a
        // false leaves the version for the next run.
        $this->assertStringNotContainsString('_die(', $body);
        $this->assertStringNotContainsString('exit', $body);

        $releasing = (string)file_get_contents(dirname(__DIR__, 2) . '/RELEASING.md');
        $this->assertStringNotContainsString('202_skan_', $releasing);
        $this->assertStringContainsString('above 1.9.55', $releasing);
        $this->assertStringContainsString('reinstalled', $releasing);
    }

    /**
     * The rung creates the app tables under their new names and never the
     * pre-reshape ones (plan §4.1): 202_app_* is app measurement, and the
     * 202_attribution_* names belong to MTA.
     */
    public function testTheRungCreatesTheAppTablesUnderTheirNewNames(): void
    {
        $names = array_map(
            static fn ($definition): string => $definition->tableName,
            AppTables::getDefinitions()
        );
        $this->assertSame(['202_app_registrations', '202_app_postbacks', '202_app_skan_encodings', '202_app_skan_encoding_history'], $names);

        $everything = $this->upgradeSource()
            . (string)file_get_contents(dirname(__DIR__, 2) . '/202-config/Database/Tables/AppTables.php');
        foreach (['202_attribution_apps', '202_attribution_postbacks', '202_attribution_conversion_values'] as $old) {
            $this->assertStringNotContainsString($old, $everything, "$old is the pre-reshape name");
        }
    }

    public function testReconciliationIsAdditiveAndIdempotent(): void
    {
        foreach (AppTables::getDefinitions() as $definition) {
            // A table that already matches its definition: nothing to do.
            $live = [];
            foreach (SchemaReconciler::requiredColumns($definition) as $name => $columnSql) {
                $live[$name] = [
                    'Field' => $name,
                    'Type' => $this->typeOf($columnSql),
                    'Null' => stripos($columnSql, 'NOT NULL') === false ? 'YES' : 'NO',
                ];
            }
            $indexes = array_keys(SchemaReconciler::requiredIndexes($definition));
            $indexes[] = 'PRIMARY';

            $plan = SchemaReconciler::planChanges($definition, $live, $indexes);
            $this->assertSame([], $plan['statements'], $definition->tableName . ' should need no changes');
            $this->assertSame([], $plan['unreconciled'], $definition->tableName);
        }
    }

    public function testReconciliationNeverEmitsADestructiveStatement(): void
    {
        // Reconciliation runs unattended inside an upgrade against a live
        // table. Whatever the live shape, it may only add and widen.
        foreach (AppTables::getDefinitions() as $definition) {
            foreach ([[], $this->halfOfTheColumns($definition)] as $live) {
                $plan = SchemaReconciler::planChanges($definition, $live, []);
                foreach ($plan['statements'] as $statement) {
                    $this->assertMatchesRegularExpression(
                        '/^ALTER TABLE `[0-9a-z_]+` (ADD COLUMN|ADD UNIQUE KEY|ADD KEY|MODIFY COLUMN) /',
                        $statement
                    );
                    $this->assertDoesNotMatchRegularExpression(
                        '/\b(DROP|RENAME|TRUNCATE|DELETE|CHANGE)\b/i',
                        $statement
                    );
                }
            }
        }
    }

    public function testANarrowingDifferenceIsReportedRatherThanApplied(): void
    {
        // Definition says NOT NULL, live column is nullable: applying that
        // fails on any stored NULL, so it is reported for a human instead.
        $definition = SchemaBuilder::fromRawSql(
            '202_reconciler_probe',
            "CREATE TABLE IF NOT EXISTS `202_reconciler_probe` (
                `id` int(10) unsigned NOT NULL,
                `label` varchar(20) NOT NULL
            ) ENGINE=InnoDB"
        );
        $live = [
            'id' => ['Field' => 'id', 'Type' => 'int(10) unsigned', 'Null' => 'NO'],
            'label' => ['Field' => 'label', 'Type' => 'varchar(20)', 'Null' => 'YES'],
        ];

        $plan = SchemaReconciler::planChanges($definition, $live, []);

        $this->assertSame([], $plan['statements']);
        $this->assertCount(1, $plan['unreconciled']);
        $this->assertStringContainsString('202_reconciler_probe.label', $plan['unreconciled'][0]);
    }

    public function testATypeDifferenceWithMatchingNullabilityIsInvisibleAsDocumented(): void
    {
        // The class docblock used to say a drifted type is "reported through
        // getUnreconciled() for a human". It is not: planChanges() compares
        // names and nullability, and a column whose nullability already
        // agrees is skipped before any type is looked at. This pins what the
        // docblock now says, so the two cannot drift apart again — if this
        // starts reporting, the docblock has to change with it.
        $definition = SchemaBuilder::fromRawSql(
            '202_reconciler_probe',
            "CREATE TABLE IF NOT EXISTS `202_reconciler_probe` (
                `id` int(10) unsigned NOT NULL,
                `label` varchar(20) NOT NULL,
                `note` text NULL
            ) ENGINE=InnoDB"
        );
        $live = [
            'id' => ['Field' => 'id', 'Type' => 'int(10) unsigned', 'Null' => 'NO'],
            // Same nullability, different type, in both directions.
            'label' => ['Field' => 'label', 'Type' => 'varchar(5)', 'Null' => 'NO'],
            'note' => ['Field' => 'note', 'Type' => 'int(11)', 'Null' => 'YES'],
        ];

        $plan = SchemaReconciler::planChanges($definition, $live, []);

        $this->assertSame([], $plan['statements']);
        $this->assertSame([], $plan['unreconciled']);
    }

    public function testAnUnreadableTableFailsInsteadOfLookingEmpty(): void
    {
        // SHOW COLUMNS returning false must not read as "the table has no
        // columns", which would plan an ADD for every column in the
        // definition (error pattern #11). The runner here fails the way
        // _upgrade_query() does.
        $reconciler = new SchemaReconciler(static fn (string $sql) => false);

        $this->assertFalse($reconciler->reconcile(AppTables::appRegistrations()));
        $this->assertSame([], $reconciler->getApplied());
        // Named probe, so that a columns probe which silently returns an
        // empty set (and is then caught only by the index probe behind it)
        // cannot pass this test.
        $this->assertSame(
            ['could not read the columns of 202_app_registrations (SHOW COLUMNS failed)'],
            $reconciler->getErrors()
        );

        // The same for the existence and row-count probes: not-known is not
        // the same answer as no.
        $this->assertNull($reconciler->tableExists('202_app_registrations'));
        $this->assertNull($reconciler->tableRowCount('202_app_registrations'));
    }

    public function testAnUnparseableDefinitionFailsRatherThanReconcilingHalfOfIt(): void
    {
        $definition = SchemaBuilder::fromRawSql(
            '202_reconciler_probe',
            "CREATE TABLE IF NOT EXISTS `202_reconciler_probe` (
                `id` int(10) unsigned NOT NULL,
                CONSTRAINT `fk` FOREIGN KEY (`id`) REFERENCES `other` (`id`)
            ) ENGINE=InnoDB"
        );

        $this->expectException(\RuntimeException::class);
        SchemaReconciler::planChanges($definition, [], []);
    }

    /**
     * A live table holding every other column of the definition: the shape
     * of an older install that the reconciler has to add to.
     *
     * @return array<string, array<string, string>>
     */
    private function halfOfTheColumns(\Prosper202\Database\Schema\SchemaDefinition $definition): array
    {
        $rows = [];
        $i = 0;
        foreach (SchemaReconciler::requiredColumns($definition) as $name => $columnSql) {
            if ($i++ % 2 === 1) {
                continue;
            }
            $rows[$name] = [
                'Field' => $name,
                'Type' => $this->typeOf($columnSql),
                'Null' => stripos($columnSql, 'NOT NULL') === false ? 'YES' : 'NO',
            ];
        }

        return $rows;
    }

    /**
     * The type SHOW COLUMNS would report for a column definition.
     */
    private function typeOf(string $columnSql): string
    {
        $afterName = (string)preg_replace('/^`[^`]+`\s*/', '', $columnSql, 1);
        preg_match('/^([a-z]+(?:\s*\([^)]*\))?(?:\s+unsigned)?)/i', $afterName, $match);

        return $match[1] ?? '';
    }

    public function testTheConvergenceHackIsGone(): void
    {
        // The version bump is the proper fix for installs already at the
        // prior version; the version-independent ensure_schema_current()
        // workaround must not linger beside it (it would create the SKAN
        // tables outside the version ladder, the exact "weird flag" the
        // bump exists to avoid).
        $this->assertStringNotContainsString('ensure_schema_current', $this->upgradeSource());
        $upgradePage = (string)file_get_contents(dirname(__DIR__, 2) . '/202-config/upgrade.php');
        $this->assertStringNotContainsString('ensure_schema_current', $upgradePage);
    }
}
