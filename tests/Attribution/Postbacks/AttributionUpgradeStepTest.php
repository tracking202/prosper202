<?php

declare(strict_types=1);

namespace Tests\Attribution\Postbacks;

use Api\V3\Attribution\AdAttributionKitProtocol;
use Api\V3\Attribution\SignatureState;
use Api\V3\Attribution\SkadnetworkProtocol;
use Prosper202\Database\Schema\SchemaBuilder;
use Prosper202\Database\SchemaReconciler;
use Prosper202\Database\Tables\AttributionPostbackTables;
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
final class AttributionUpgradeStepTest extends TestCase
{
    private const CURRENT_VERSION = '1.9.76';
    private const PRIOR_VERSION = '1.9.75';

    /** The reconcile call a step must make; also how a step is recognised. */
    private const RECONCILE_CALL = '_upgrade_attribution_tables(';

    /** @var list<array{id: int|null, text: string}>|null */
    private ?array $tokenCache = null;

    /** @var list<array{gates: list<string>, persists: list<string>, reconciles: bool, block: string, from: int, to: int}>|null */
    private ?array $stepCache = null;

    private function upgradeSource(): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/202-config/functions-upgrade.php');
    }

    /**
     * The ladder with its comments removed.
     *
     * Every scan below keys on tokens the blocks also mention in prose, so
     * scanning the raw file matched comments: a planted
     * `_upgrade_attribution_tables([])` left this suite green because the
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
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/202-config/version.php');
        $this->assertStringContainsString("\$version_string = '" . self::CURRENT_VERSION . "'", $source);
    }

    public function testAnUpgradeStepGatedOnThePriorVersionCreatesTheAttributionPostbackTables(): void
    {
        // Brace-bounded, not "up to the next gate": the 1.9.75 step is now
        // the ladder's last, so a strpos window found no next gate and ran to
        // the end of the file, swallowing the downgrade guard.
        $block = $this->blockGatedOn(self::PRIOR_VERSION);

        // The block's DDL must come from the installer definitions (never a
        // hand-copied CREATE that can drift), and it must persist the bumped
        // version so a 1.9.75 install converges to 1.9.76.
        $this->assertStringContainsString('AttributionPostbackTables::getDefinitions()', $block);
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
                'AttributionPostbackTables::getDefinitions()',
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
            }
        }
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
            if (!$this->namesFunction($token, $name)) {
                continue;
            }
            // The function's own declaration is not a call.
            for ($j = $i - 1; $j >= 0 && $tokens[$j]['id'] === T_WHITESPACE; $j--);
            if ($j >= 0 && $tokens[$j]['id'] === T_FUNCTION) {
                continue;
            }

            $calls++;
            foreach ($steps as $step) {
                if ($i >= $step['from'] && $i <= $step['to']) {
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
        $block = $this->blockGatedOn(self::PRIOR_VERSION);

        $this->assertStringContainsString("UPDATE 202_version SET version='" . self::CURRENT_VERSION . "'", $block);
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
     * whose holes it exists to close, the subject is forbidden outright: the
     * ladder is 120-odd equality gates and a switch over it would be a
     * rewrite. Teach both scans before writing one.
     */
    public function testTheLadderNeverSwitchesOnTheStoredVersion(): void
    {
        $tokens = $this->upgradeTokens();
        $significant = $this->significantTokens($tokens);

        $found = [];
        foreach ($significant as $k => $i) {
            if (!in_array($tokens[$i]['id'], [T_SWITCH, T_MATCH], true)) {
                continue;
            }
            $open = $significant[$k + 1] ?? null;
            $subject = $significant[$k + 2] ?? null;
            if ($open === null || $subject === null || $tokens[$open]['text'] !== '(') {
                continue;
            }
            if ($tokens[$subject]['id'] === T_VARIABLE && $tokens[$subject]['text'] === '$prosper202_version') {
                $found[] = $tokens[$i]['text'];
            }
        }

        $this->assertSame(
            [],
            $found,
            'the ladder gates on $prosper202_version with a switch or match, whose arms'
            . ' testNoUpgradeBlockIsGatedOnTheCodeVersion cannot read. Teach that scan to read'
            . ' them before writing one, or the code-version gate it forbids becomes invisible.'
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
        // Anchored on the guard's own condition in the COMMENT-STRIPPED code.
        // Anchored on its comment in the raw file, both assertions passed
        // against a guard that had been commented out in its entirety — the
        // prose it was anchored to was all that survived.
        $code = $this->upgradeCode();
        $condition = "version_compare((string) \$prosper202_version, '" . self::CURRENT_VERSION . "', '>')";

        $at = strpos($code, $condition);
        $this->assertNotFalse(
            $at,
            'the ladder must end with ' . $condition . ', which pulls an install that is ahead of'
            . ' the code back to it'
        );

        // The clamp is asserted after the condition, not anywhere in the
        // file: the step gated on PRIOR_VERSION assigns the same literal, so
        // an unanchored search passes with the guard deleted.
        $this->assertStringContainsString(
            "\$prosper202_version = '" . self::CURRENT_VERSION . "';",
            substr($code, $at),
            'the downgrade guard must clamp the stored version to ' . self::CURRENT_VERSION
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
        preg_match_all("/UPDATE 202_version SET version='([^']*)'/", $this->upgradeCode(), $m);

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

            $gates = $this->versionsComparedIn($tokens, $significant, $position, $open, $close);
            if ($gates === []) {
                continue;
            }

            $end = $this->matchingBrace($tokens, $brace);
            $this->assertNotNull($end, 'unbalanced braces after a version gate');

            $block = implode('', array_column(array_slice($tokens, $i, $end - $i + 1), 'text'));
            preg_match_all("/UPDATE 202_version SET version='([^']+)'/", $block, $persisted);

            $steps[] = [
                'gates' => $gates,
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
        int $close
    ): array {
        // Both bounds are punctuation, so both are in $significant. Asserted
        // rather than defaulted: a miss would silently widen the slice, and a
        // scan that reads the wrong range must not report on it.
        $this->assertArrayHasKey($open, $position, 'the condition\'s ( is not a significant token');
        $this->assertArrayHasKey($close, $position, 'the condition\'s ) is not a significant token');

        $from = $position[$open] + 1;
        $to = $position[$close];

        return $this->versionsComparedAmong($tokens, array_slice($significant, $from, max(0, $to - $from)));
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
    private function versionsComparedAmong(array $tokens, array $inside): array
    {
        $inside = array_values($inside);

        $versions = [];
        foreach ($inside as $n => $i) {
            if (!in_array($tokens[$i]['id'], [T_IS_EQUAL, T_IS_IDENTICAL], true)) {
                continue;
            }
            $left = $tokens[$inside[$n - 1] ?? -1] ?? null;
            $right = $tokens[$inside[$n + 1] ?? -1] ?? null;
            if ($left === null || $right === null) {
                continue;
            }

            $isVar = static fn(?array $t): bool => $t !== null
                && $t['id'] === T_VARIABLE && $t['text'] === '$prosper202_version';

            if ($isVar($left) && !$isVar($right)) {
                $versions[] = $this->versionOperand($right);
            } elseif ($isVar($right) && !$isVar($left)) {
                $versions[] = $this->versionOperand($left);
            }
        }

        return array_values(array_unique(array_filter($versions)));
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
    private function versionOperand(array $token): string
    {
        if ($token['id'] === T_CONSTANT_ENCAPSED_STRING) {
            return trim($token['text'], '\'"');
        }
        if ($this->namesFunction($token, 'PROSPER202_VERSION')) {
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
     * Does this token name the global function $name?
     *
     * `\_upgrade_attribution_tables()` is the same call as
     * `_upgrade_attribution_tables()`, but PHP tokenizes the qualified
     * spelling as T_NAME_FULLY_QUALIFIED, so a T_STRING-only filter ignored
     * it — an ungated qualified call passed. Both token kinds count, and the
     * name is compared on its last segment.
     */
    private function namesFunction(array $token, string $name): bool
    {
        $kinds = [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE];
        if (!in_array($token['id'], $kinds, true)) {
            return false;
        }

        $segments = explode('\\', $token['text']);

        return end($segments) === $name;
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
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/202-config/version.php');
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

    public function testTheRenamedLegacyTablesAreDetectedRatherThanSilentlyReplaced(): void
    {
        // The pre-release tables were called 202_skan_*. Those are different
        // table NAMES, so CREATE TABLE IF NOT EXISTS happily builds empty
        // 202_attribution_* tables beside them and every postback already
        // received disappears from the API while the upgrade reports success.
        $source = $this->upgradeSource();

        $this->assertStringContainsString('202_skan_postbacks', $source);
        $this->assertStringContainsString('_upgrade_attribution_legacy_skan_state', $source);

        $start = strpos($source, 'function _upgrade_attribution_tables');
        $this->assertNotFalse($start);
        $body = substr($source, $start, 6000);

        // Loud stop, not a silent skip, and the message has to name the
        // manual step (error pattern #4).
        $this->assertStringContainsString("'legacy-data'", $body);
        $this->assertStringContainsString('_die(', $body);
        // An unreadable probe must not read as "no legacy data" and let the
        // CREATEs run (error pattern #11).
        $this->assertStringContainsString("'unknown'", $body);

        // Legacy rows AND current rows is not evidence that anyone copied
        // anything: postbacks received fresh under the new names produce the
        // same counts. That state used to resolve to 'legacy-migrated' and
        // continue, which strands the legacy rows behind a log line. Row
        // counts cannot answer the question, so it fails closed like the
        // branch above.
        $this->assertStringNotContainsString('legacy-migrated', $source);
        $this->assertStringContainsString("'legacy-and-current-data'", $body);

        $ambiguous = strpos($body, "if (\$state === 'legacy-and-current-data')");
        $this->assertNotFalse($ambiguous, 'the ambiguous state needs its own branch');
        $branch = substr($body, $ambiguous, 2600);
        $this->assertStringContainsString('_die(', $branch);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testEveryColumnAddedNotNullWithoutADefaultIsBackfilled(): void
    {
        // A column added NOT NULL with no DEFAULT gets the server's implicit
        // default ('') on every pre-existing row, and '' is not a value any
        // reader accepts: the API's protocol filter matches none of those
        // rows and every GROUP BY buckets them under ''. Adding the column
        // without backfilling it is the same "the postbacks vanish from the
        // API and the reports" loss the legacy halt exists to prevent, so
        // whatever the reconciler adds in that shape must be backfilled in
        // the same step. Scope: 202_attribution_postbacks, the only one of
        // the three tables this test has a pre-release column list for —
        // 202_attribution_apps' added column carries DEFAULT '0'.
        $plan = SchemaReconciler::planChanges(
            AttributionPostbackTables::attributionPostbacks(),
            $this->preReleasePostbackColumns(),
            []
        );

        $needBackfill = [];
        foreach ($plan['statements'] as $statement) {
            if (preg_match('/ADD COLUMN `([^`]+)`(.*)$/', $statement, $match) !== 1) {
                continue;
            }
            if (stripos($match[2], 'NOT NULL') === false) {
                continue;
            }
            if (preg_match('/\bDEFAULT\b/i', $match[2]) === 1) {
                continue;
            }
            $needBackfill[] = $match[1];
        }
        sort($needBackfill);

        // Not vacuous: the pre-release shape really is missing two of them.
        $this->assertSame(['protocol', 'signature_state'], $needBackfill);

        $statements = $this->backfillStatements();
        $this->assertNotSame([], $statements);

        foreach ($needBackfill as $column) {
            $targeted = array_filter(
                $statements,
                static fn (string $sql): bool => str_contains($sql, 'SET `' . $column . '` = ')
            );
            $this->assertNotSame([], $targeted, $column . ' is added NOT NULL with no default and never backfilled');

            foreach ($targeted as $sql) {
                // Only rows still holding the implicit default, so a real
                // value is never overwritten and a re-run changes nothing.
                $this->assertStringContainsString("WHERE `" . $column . "` = ''", $sql);
            }
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheBackfilledSignatureStateIsTheOneTheReceiverWouldHaveStored(): void
    {
        // The trust bit already on the row is what the state produced:
        // PostbackReceiver stores signature_valid = SignatureState::trustBit()
        // and AttributionPostbacksController's `signature` filter reads the
        // pair back the same way. Derive the expected predicate from the enum
        // so a change to trustBit() breaks this rather than the upgrade.
        $statements = implode("\n", $this->backfillStatements());

        foreach ([SignatureState::VALID, SignatureState::INVALID, SignatureState::UNVERIFIABLE] as $state) {
            $bit = $state->trustBit(false);
            $predicate = $bit === null ? '`signature_valid` IS NULL' : '`signature_valid` = ' . $bit;
            $this->assertStringContainsString(
                "SET `signature_state` = '" . $state->value . "' WHERE `signature_state` = '' AND " . $predicate,
                $statements
            );
        }

        // DEVELOPMENT shares its trust bits with the other two (1 when the app
        // opted in, NULL when it did not) and its opt-in column arrived with
        // signature_state, so no row that predates the column can be one. It
        // must not be guessed onto a row.
        $this->assertStringNotContainsString(SignatureState::DEVELOPMENT->value, $statements);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheProtocolBackfillNamesTheProtocolThatPredatesTheColumn(): void
    {
        // AdAttributionKit support arrived WITH the protocol column, so a row
        // that has no protocol can only be SKAdNetwork.
        $statements = implode("\n", $this->backfillStatements());

        $this->assertStringContainsString(
            "SET `protocol` = '" . SkadnetworkProtocol::NAME . "' WHERE `protocol` = ''",
            $statements
        );
        $this->assertStringNotContainsString(AdAttributionKitProtocol::NAME, $statements);
    }

    /**
     * The upgrade's backfill statements, from the upgrade file itself.
     *
     * @return array<int, string>
     */
    private function backfillStatements(): array
    {
        // Loading the upgrade file pulls in 202-config/class-dataengine.php
        // (functions-upgrade.php:7), and DataEngine's constructor resolves a
        // real connection. Once that class is in the process, any later suite
        // that builds a DataEngine gets the real one instead of the stub it
        // expects, and fails with "mysqli object is not fully initialized" —
        // tests/StaticEndpoint runs after tests/Attribution, so it was the
        // one that broke. The callers therefore run in their own process.
        require_once dirname(__DIR__, 3) . '/202-config/functions-upgrade.php';

        $this->assertTrue(
            function_exists('_upgrade_attribution_backfill_statements'),
            'the upgrade must backfill the columns it adds NOT NULL with no default'
        );

        return _upgrade_attribution_backfill_statements();
    }

    public function testReconcilingThePreReleaseShapeEmitsExactlyTheMissingPiecesInOrder(): void
    {
        // The columns and indexes a 1524b62-era install actually has. The
        // plan against them is what converges that install; if the
        // reconciler stops emitting any of these, that install goes back to
        // answering device postbacks with "Unknown column 'protocol'".
        $definition = AttributionPostbackTables::attributionPostbacks();
        $live = $this->preReleasePostbackColumns();
        $liveIndexes = ['PRIMARY', 'dedupe_hash', 'user_received', 'user_app', 'user_ad_network', 'transaction', 'signature_received'];

        $plan = SchemaReconciler::planChanges($definition, $live, $liveIndexes);

        $this->assertSame([
            'ALTER TABLE `202_attribution_postbacks` ADD COLUMN `protocol` varchar(24) NOT NULL AFTER `received_at`',
            // version went from NOT NULL to nullable because AdAttributionKit
            // postbacks carry no version and their INSERT omits the column;
            // left NOT NULL the INSERT dies with errno 1364 and the postback
            // — the only copy Apple sends — is dropped.
            'ALTER TABLE `202_attribution_postbacks` MODIFY COLUMN `version` varchar(8) DEFAULT NULL',
            'ALTER TABLE `202_attribution_postbacks` ADD COLUMN `conversion_type` varchar(16) DEFAULT NULL AFTER `postback_sequence_index`',
            'ALTER TABLE `202_attribution_postbacks` ADD COLUMN `ad_interaction_type` varchar(5) DEFAULT NULL AFTER `did_win`',
            'ALTER TABLE `202_attribution_postbacks` ADD COLUMN `marketplace_id` varchar(255) DEFAULT NULL AFTER `source_domain`',
            'ALTER TABLE `202_attribution_postbacks` ADD COLUMN `signature_state` varchar(16) NOT NULL AFTER `attribution_signature`',
            'ALTER TABLE `202_attribution_postbacks` ADD COLUMN `key_id` varchar(64) DEFAULT NULL AFTER `signature_valid`',
            'ALTER TABLE `202_attribution_postbacks` ADD KEY `user_protocol` (`user_id`,`protocol`)',
        ], $plan['statements']);
        $this->assertSame([], $plan['unreconciled']);
    }

    public function testReconciliationIsAdditiveAndIdempotent(): void
    {
        foreach (AttributionPostbackTables::getDefinitions() as $definition) {
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
        foreach (AttributionPostbackTables::getDefinitions() as $definition) {
            foreach ([[], $this->preReleasePostbackColumns()] as $live) {
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

        $this->assertFalse($reconciler->reconcile(AttributionPostbackTables::attributionApps()));
        $this->assertSame([], $reconciler->getApplied());
        // Named probe, so that a columns probe which silently returns an
        // empty set (and is then caught only by the index probe behind it)
        // cannot pass this test.
        $this->assertSame(
            ['could not read the columns of 202_attribution_apps (SHOW COLUMNS failed)'],
            $reconciler->getErrors()
        );

        // The same for the existence and row-count probes: not-known is not
        // the same answer as no.
        $this->assertNull($reconciler->tableExists('202_attribution_apps'));
        $this->assertNull($reconciler->tableRowCount('202_attribution_apps'));
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
     * The 202_attribution_postbacks columns as a 1524b62-era install has
     * them, in the order that install has them.
     *
     * @return array<string, array<string, string>>
     */
    private function preReleasePostbackColumns(): array
    {
        $columns = [
            'postback_id' => ['bigint(20) unsigned', 'NO'],
            'user_id' => ['mediumint(8) unsigned', 'NO'],
            'received_at' => ['int(10) unsigned', 'NO'],
            'version' => ['varchar(8)', 'NO'],
            'ad_network_id' => ['varchar(100)', 'NO'],
            'transaction_id' => ['varchar(64)', 'NO'],
            'app_id' => ['bigint(20) unsigned', 'NO'],
            'source_identifier' => ['varchar(4)', 'YES'],
            'campaign_id' => ['bigint(20) unsigned', 'YES'],
            'conversion_value' => ['tinyint(3) unsigned', 'YES'],
            'coarse_conversion_value' => ['varchar(6)', 'YES'],
            'postback_sequence_index' => ['tinyint(3) unsigned', 'YES'],
            'redownload' => ['tinyint(1) unsigned', 'YES'],
            'did_win' => ['tinyint(1) unsigned', 'YES'],
            'source_app_id' => ['bigint(20) unsigned', 'YES'],
            'source_domain' => ['varchar(255)', 'YES'],
            'fidelity_type' => ['tinyint(3) unsigned', 'YES'],
            'country_code' => ['varchar(8)', 'YES'],
            'attribution_signature' => ['text', 'NO'],
            'signature_valid' => ['tinyint(1) unsigned', 'YES'],
            'dedupe_hash' => ['char(40)', 'NO'],
            'raw_payload' => ['text', 'NO'],
            'remote_ip' => ['varchar(45)', 'NO'],
            'created_at' => ['int(10) unsigned', 'NO'],
        ];

        $rows = [];
        foreach ($columns as $name => [$type, $null]) {
            $rows[$name] = ['Field' => $name, 'Type' => $type, 'Null' => $null];
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
        $upgradePage = (string)file_get_contents(dirname(__DIR__, 3) . '/202-config/upgrade.php');
        $this->assertStringNotContainsString('ensure_schema_current', $upgradePage);
    }
}
