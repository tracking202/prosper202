<?php

declare(strict_types=1);

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;

/**
 * Every page that takes a POST before there is a login checks the session
 * token, and the check decides whether the work runs.
 *
 * Three pages answer a POST with nobody logged in: the installer, the
 * upgrader and the login form. For them the token connect.php mints on every
 * request is the only thing between a cross-site form and the work the page
 * does, because there is no user session to require yet. install.php and
 * 202-login.php made the check; upgrade.php did not, and the repair
 * RELEASING.md gives for a stranded branch deployment — wind 202_version back
 * and open that page — is exactly when the gap was open.
 * tests/live/upgrade-csrf.sh proves the upgrader over HTTP against a running
 * instance, locally and in the Agent Evals job; this is the part that runs
 * on every push.
 *
 * Two claims, each held to the code rather than to a name. The first version
 * asserted that the guard call came before the work in the source, and that
 * the helpers' bodies contained `hash_equals(`: `$error = false;` after the
 * guard passed the first, and a helper that evaluated hash_equals() and then
 * returned true passed the second. Now the work has to sit inside a branch
 * on the guard's result with nothing between the guard and that branch able
 * to weaken it, and the helpers are executed as well as read.
 *
 * The form is held to the same standard. `name="token"` anywhere in the
 * file said nothing about which form carried it, so the token input now has
 * to sit inside every form that posts to the page — hidden, enabled, owned
 * by no other form, outside any HTML comment, in the same PHP block as its
 * form tag — with the escaped session token as its value.
 *
 * Scoped to the pre-login pages on purpose: of the 74 files in the tree that
 * read $_POST, 27 check a token, so the tree-wide invariant cannot land as one
 * change. Those are a sweep of their own.
 */
final class PreLoginPostRequiresTokenTest extends TestCase
{
    /** The guard spellings the tree uses: the call as a page writes it => [defining file, function]. */
    private const GUARDS = [
        'install_csrf_ok(' => ['202-config/functions-install-helpers.php', 'install_csrf_ok'],
        'AUTH::check_csrf_token(' => ['202-config/functions-auth.php', 'check_csrf_token'],
    ];

    /** Assignment operators other than `=`; each rewrites the variable on its left. */
    private const COMPOUND_ASSIGNMENTS = [
        T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_MOD_EQUAL,
        T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL,
    ];

    /**
     * Each pre-login page, the call its guard protects, and the shape the
     * page gates that call in. The shape is declared rather than inferred: a
     * page written another way fails here and says how it was read, instead
     * of being reasoned about.
     *
     *   result   the variable the guard's return value is assigned to, once,
     *            as `$result = guard(…);` — or `$result = !guard(…);` when
     *            `negated`, so that true means the check FAILED
     *   gate     the variable the work's branch tests: the result itself, or
     *            an error flag that is true whenever the check failed
     *   seed     how the flag takes the result when it is not the result: the
     *            statement `$flag = $result;`, or the failure branch
     *            `if (!$result) {` writing an element of the flag
     *
     * @return array<string, array{0: array{file: string, work: string, result: string, negated: bool,
     *     gate: string, seed: ?string}}>
     */
    public static function pages(): array
    {
        return [
            'installer' => [[
                'file' => '202-config/install.php',
                'work' => 'new INSTALL(',
                'result' => '$csrf_ok',
                'negated' => false,
                'gate' => '$csrf_ok',
                'seed' => null,
            ]],
            'upgrader' => [[
                'file' => '202-config/upgrade.php',
                'work' => 'UPGRADE::upgrade_databases(',
                'result' => '$csrf_error',
                'negated' => true,
                'gate' => '$error',
                'seed' => '$error = $csrf_error;',
            ]],
            'login' => [[
                'file' => '202-login.php',
                'work' => 'AUTH::authenticate(',
                'result' => '$csrf_ok',
                'negated' => false,
                'gate' => '$error',
                'seed' => 'if (!$csrf_ok) {',
            ]],
        ];
    }

    /**
     * The guard's result decides whether the work runs.
     *
     * Read from the tokens, in this order: the guard is called once, its
     * result assigned on its own directly inside the POST block as a
     * statement of its own; the flag, when there is one, takes that result
     * the declared way, just as directly; every call of the work sits inside
     * the POST block, inside an `if` whose condition has the result — or the
     * negated flag — as a whole conjunct (a conjunct can only narrow); and
     * between the guard and that `if` neither variable is written in a way
     * that could weaken it. `$error = true;` narrows and is allowed; a
     * `$error = false;`, an `unset`, a compound assignment, a reference or a
     * call taking the variable is refused by line, because this check does
     * not tell a read from a write and says so. Directly, because a guard or
     * a seed inside a further condition can be skipped, and the branch then
     * tests a flag nothing set; inside the POST block, because outside it a
     * GET runs the work with no token asked for at all. The scan for writes
     * runs up to the branch's opening brace rather than its `if`, because
     * the condition is code: `if (($error = false) === false && !$error)`
     * carries the accepted conjunct and resets the flag before testing it.
     * And no `goto` in the file at all: PHP lets one enter an `if` block
     * from anywhere in the same scope, so a goto could land inside the POST
     * block below its guard, or past the seed, and nothing here reads a
     * jump target. Nor, anywhere in the file, a write path into the guarded
     * variables that the scan for writes could not follow — a reference to
     * one, a `global` declaration of one, `$GLOBALS`, a variable variable,
     * `extract()` or `eval()` — because an alias made above the guard
     * carries a write below it that never names the variable.
     *
     * @dataProvider pages
     * @param array{file: string, work: string, result: string, negated: bool, gate: string, seed: ?string} $page
     */
    public function testTheGuardResultControlsTheWork(array $page): void
    {
        $file = $page['file'];
        $tokens = $this->tokensOf($file);
        $pairs = $this->pairs($tokens, $file);
        $code = implode('', array_column($tokens, 'text'));

        $gotos = [];
        foreach ($tokens as $i => $token) {
            if ($token['id'] === T_GOTO) {
                $gotos[] = $this->lineOf($tokens, $i);
            }
        }
        $this->assertSame(
            [],
            $gotos,
            "$file uses goto at line(s) " . implode(', ', $gotos) . ': a goto can enter the POST'
            . ' block below its guard, or skip the guard, from anywhere in the file, and this check'
            . ' reads no jump targets. Teach it before writing one.'
        );
        $this->assertNoWritePathTheScanCannotFollow($tokens, array_unique([$page['result'], $page['gate']]), $file);

        $post = strpos($code, "\$_SERVER['REQUEST_METHOD'] == 'POST'");
        $this->assertNotFalse($post, "$file no longer branches on a POST; this test's subject has moved");
        $postOpen = $this->enclosingOpener($tokens, $this->tokenAt($tokens, (int) $post));
        $postBrace = $postOpen === null || !isset($pairs[$postOpen])
            ? null
            : $this->nextSignificant($tokens, $pairs[$postOpen] + 1);
        $this->assertTrue(
            $postBrace !== null && $tokens[$postBrace]['text'] === '{' && isset($pairs[$postBrace]),
            "$file's POST branch is not `if (…) { … }`; this check reads that shape only"
        );
        $postBrace = (int) $postBrace;
        $postClose = $pairs[$postBrace];

        // 1. One guard call after the POST branch, assigned on its own, so
        //    the variable holds that result and only that result.
        $calls = [];
        foreach (array_keys(self::GUARDS) as $marker) {
            for ($at = strpos($code, $marker, $post); $at !== false; $at = strpos($code, $marker, $at + 1)) {
                $calls[] = [$marker, $at];
            }
        }
        $this->assertCount(
            1,
            $calls,
            "$file must call the token guard exactly once after its POST branch ("
            . implode(' or ', array_keys(self::GUARDS)) . '); found ' . count($calls)
        );
        [$marker, $at] = $calls[0];
        $first = $this->tokenAt($tokens, $at);
        $open = $this->tokenAt($tokens, $at + strlen($marker) - 1);
        $close = $pairs[$open] ?? null;
        $this->assertNotNull($close, "$file: unbalanced guard call at line " . $this->lineOf($tokens, $first));
        $end = $this->nextSignificant($tokens, (int) $close + 1);

        $operator = $this->previousSignificant($tokens, $first - 1);
        if ($page['negated']) {
            // The polarity is part of the shape: without the `!`, the flag
            // is true when the token MATCHED and the work runs when it did not.
            $this->assertTrue(
                $operator !== null && $tokens[$operator]['text'] === '!',
                "$file must store the guard's failure as `{$page['result']} = !guard(…);` (line "
                . $this->lineOf($tokens, $first) . '); without the negation the flag means the opposite'
            );
            $operator = $this->previousSignificant($tokens, (int) $operator - 1);
        }
        $variable = $operator === null ? null : $this->previousSignificant($tokens, $operator - 1);
        $this->assertTrue(
            $operator !== null && $tokens[$operator]['text'] === '='
            && $variable !== null && $tokens[$variable]['id'] === T_VARIABLE
            && $tokens[$variable]['text'] === $page['result']
            && $end !== null && $tokens[$end]['text'] === ';',
            "$file must assign the guard's result on its own, as `{$page['result']} = "
            . ($page['negated'] ? '!' : '') . 'guard(…);` (line ' . $this->lineOf($tokens, $first)
            . '); a different shape is refused here rather than read'
        );
        $resultEnd = (int) $end;
        $this->assertTrue(
            $this->runsWheneverTheBlockRuns($tokens, $pairs, $postBrace, (int) $variable),
            "$file must assign the guard's result directly inside its POST block, as a statement of its own"
            . ' (line ' . $this->lineOf($tokens, (int) $variable) . '); inside a further condition, a request'
            . ' that skips it reaches the work with nothing checked'
        );

        // 2. Every call of the work, each inside the POST block: a second
        //    one, unguarded, is the same hole as the first, and one outside
        //    the block runs on a GET.
        $works = [];
        for ($w = strpos($code, $page['work']); $w !== false; $w = strpos($code, $page['work'], $w + 1)) {
            $works[] = $this->tokenAt($tokens, $w);
        }
        $this->assertNotSame(
            [],
            $works,
            "$file no longer calls {$page['work']}; the work this guard protects has moved"
        );
        foreach ($works as $work) {
            $this->assertTrue(
                $work > $postBrace && $work < $postClose,
                "$file calls {$page['work']} at line " . $this->lineOf($tokens, $work)
                . ' outside its POST block, so a GET runs it with no token asked for at all'
            );
        }

        // 3. Where the flag takes the result.
        $seedEnd = $page['gate'] === $page['result']
            ? $resultEnd
            : $this->seedEnd($tokens, $pairs, $page, $resultEnd, $postBrace);

        foreach ($works as $work) {
            $this->assertGreaterThan(
                $seedEnd,
                $work,
                "$file calls {$page['work']} at line " . $this->lineOf($tokens, $work)
                . ' before the token guard has run'
            );
            // 4. The branch the work sits under, and 5. nothing in between
            //    that could weaken what it tests.
            $gate = $this->gateOf($tokens, $pairs, $page, $work, $seedEnd);
            // Up to the branch's opening brace, so the condition itself is
            // scanned: `if (($error = false) === false && !$error)` has the
            // accepted conjunct and resets the flag before testing it.
            $gateOpen = (int) $this->nextSignificant($tokens, $gate + 1);
            $gateBrace = (int) $this->nextSignificant($tokens, $pairs[$gateOpen] + 1);
            $this->assertUntouched(
                $tokens,
                $pairs,
                $page['result'],
                $resultEnd,
                $gateBrace,
                [],
                $file,
                'the guard result'
            );
            if ($page['gate'] !== $page['result']) {
                $allowed = ['true'];
                if ($page['negated']) {
                    $allowed[] = $page['result'];
                }
                $this->assertUntouched(
                    $tokens,
                    $pairs,
                    $page['gate'],
                    $seedEnd,
                    $gateBrace,
                    $allowed,
                    $file,
                    'the error flag'
                );
            }
        }
    }

    /**
     * Every form that posts to the page carries the token, inside it.
     *
     * The first version asserted that `name="token"` appeared somewhere in
     * the file, which said nothing about which form carried it: the input
     * moved below `</form>`, into a second form, disabled, or inside an HTML
     * comment all kept it green while the page's own submissions failed the
     * guard. Now every `method="post"` form is found, its `</form>` located
     * with no form nested between, and inside that span there has to be an
     * `<input>` named token that is hidden, not disabled, owned by no other
     * form (`form="…"`), outside any `<!-- -->`, in the same PHP block as
     * its form tag — a `<?php if (…) { ?>` around it can render the form
     * without it, and this check reads no conditions — and whose value is
     * one echo of the session token through htmlentities() or
     * htmlspecialchars() with ENT_QUOTES. At least one such form has to post
     * to the page itself (no action, or an empty one), or nothing reaches
     * the handler this suite guards.
     *
     * Read from the source, so a closing tag echoed from PHP, or a form
     * assembled in a string, is not seen; the three pages write their forms
     * as markup. tests/live/upgrade-csrf.sh submits the upgrader's rendered
     * form with the fields a browser would send.
     *
     * @dataProvider pages
     * @param array{file: string} $page
     */
    public function testTheFormCarriesTheToken(array $page): void
    {
        $file = $page['file'];
        $tokens = $this->tokensOf($file);
        $pairs = $this->pairs($tokens, $file);
        $code = implode('', array_column($tokens, 'text'));

        $forms = [];
        $selfPosting = 0;
        foreach ($this->tagsNamed($tokens, $code, 'form') as $form) {
            $attributes = $this->attributesOf($form['tag']);
            if (strtolower($attributes['method'] ?? '') !== 'post') {
                continue;
            }
            $forms[] = $form;
            if (trim($attributes['action'] ?? '') === '') {
                $selfPosting++;
            }
        }
        $this->assertGreaterThan(
            0,
            $selfPosting,
            "$file has no `method=\"post\"` form posting to itself (no action, or an empty one), so"
            . ' nothing reaches the POST handler this suite guards; this check reads that shape only'
        );

        foreach ($forms as $form) {
            $line = $this->lineOf($tokens, $form['token']);
            $close = $this->closingTagOf($tokens, $code, 'form', $form['end']);
            $this->assertNotNull(
                $close,
                "$file: the form at line $line is never closed, or another form opens inside it"
            );

            $comments = $this->commentsBetween($tokens, $code, $form['end'], (int) $close);
            $candidates = [];
            $commented = [];
            foreach ($this->tagsNamed($tokens, $code, 'input', $form['end'], (int) $close) as $input) {
                $attributes = $this->attributesOf($input['tag']);
                if (($attributes['name'] ?? null) !== 'token') {
                    continue;
                }
                foreach ($comments as [$from, $to]) {
                    if ($input['start'] > $from && $input['start'] < $to) {
                        $commented[] = $this->lineOf($tokens, $input['token']);
                        continue 2;
                    }
                }
                $candidates[] = $input + ['attributes' => $attributes];
            }
            $this->assertNotSame(
                [],
                $candidates,
                "$file: the form at line $line carries no token input"
                . ($commented === [] ? '' : ' (one sits inside an HTML comment at line '
                    . implode(', ', $commented) . ')')
                . ', so its own submissions fail the check the POST handler makes'
            );

            foreach ($candidates as $input) {
                $at = $this->lineOf($tokens, $input['token']);
                $attributes = $input['attributes'];
                $this->assertSame(
                    'hidden',
                    strtolower($attributes['type'] ?? ''),
                    "$file: the token input at line $at is not type=\"hidden\"; a visible field, a"
                    . ' checkbox or a button named token is submitted only sometimes, or shown'
                );
                $this->assertArrayNotHasKey(
                    'disabled',
                    $attributes,
                    "$file: the token input at line $at is disabled, and a disabled field is not submitted"
                );
                $this->assertArrayNotHasKey(
                    'form',
                    $attributes,
                    "$file: the token input at line $at names another form as its owner, so this form"
                    . ' does not submit it'
                );
                $why = $this->echoesEscapedSessionToken($attributes['value'] ?? '');
                $this->assertNull(
                    $why,
                    "$file: the value of the token input at line $at $why; this check reads one echo of"
                    . " \$_SESSION['token'] through htmlentities() or htmlspecialchars() with ENT_QUOTES"
                );
                $depth = $this->phpDepthBetween($tokens, $pairs, $form['token'], $input['token']);
                $this->assertSame(
                    0,
                    $depth,
                    "$file: the token input at line $at sits in a different PHP block from its form tag"
                    . " at line $line (depth $depth): a condition around it can render the form without"
                    . ' its token, and this check does not read conditions'
                );
            }
        }
    }

    /**
     * Executed, not read: each helper with both tokens empty (hash_equals('',
     * '') is true, which is why the empty check exists), one empty, a
     * mismatch, a prefix, and a match — and the AUTH one with a session that
     * was never seeded and a POST that carries no token at all.
     */
    public function testEveryGuardFailsClosedWhenExecuted(): void
    {
        foreach (self::GUARDS as [$file]) {
            require_once dirname(__DIR__, 2) . '/' . $file;
        }

        $cases = [
            ['', '', false],
            ['abc', '', false],
            ['', 'abc', false],
            ['abc', 'abd', false],
            ['abc', 'ab', false],
            ['abc', 'abc', true],
        ];

        $session = $_SESSION ?? [];
        $post = $_POST;
        try {
            foreach ($cases as [$expected, $submitted, $want]) {
                $this->assertSame(
                    $want,
                    \install_csrf_ok($expected, $submitted),
                    "install_csrf_ok('$expected', '$submitted')"
                );
                $_SESSION = ['token' => $expected];
                $_POST = ['token' => $submitted];
                $this->assertSame(
                    $want,
                    \AUTH::check_csrf_token(),
                    "AUTH::check_csrf_token() with session token '$expected' and posted token '$submitted'"
                );
            }
            $_SESSION = [];
            $_POST = ['token' => 'abc'];
            $this->assertFalse(\AUTH::check_csrf_token(), 'AUTH::check_csrf_token() with no session token at all');
            $_SESSION = ['token' => 'abc'];
            $_POST = [];
            $this->assertFalse(\AUTH::check_csrf_token(), 'AUTH::check_csrf_token() with no posted token at all');
        } finally {
            $_SESSION = $session;
            $_POST = $post;
        }
    }

    /**
     * Read as well as run: what each helper returns IS the hash_equals()
     * comparison, so the constant-time compare is what decides and not
     * something beside it. Every `return` is `return false;` or a
     * conjunction one of whose conjuncts is exactly a hash_equals(…) call — a
     * conjunct can only narrow, so `$expected !== '' && hash_equals(…)` is the
     * shape and `hash_equals(…); return true;` is not. Anything else, a
     * disjunction, a negation, a ternary, a variable, is refused by line.
     */
    public function testEveryGuardReturnsTheHashEqualsComparison(): void
    {
        foreach (self::GUARDS as [$file, $name]) {
            $tokens = $this->tokensOf($file);
            $pairs = $this->pairs($tokens, $file);
            [$from, $to] = $this->functionRange($tokens, $pairs, $file, $name);

            $compared = 0;
            for ($i = $from; $i <= $to; $i++) {
                if ($tokens[$i]['id'] !== T_RETURN) {
                    continue;
                }
                $expression = [];
                for ($j = $i + 1; $j <= $to && $tokens[$j]['text'] !== ';'; $j++) {
                    if ($tokens[$j]['id'] !== T_WHITESPACE) {
                        $expression[] = $j;
                    }
                }
                if (count($expression) === 1 && strtolower($tokens[$expression[0]]['text']) === 'false') {
                    continue;
                }

                $decides = false;
                foreach ($this->splitTopLevelAnd($tokens, $expression) as $conjunct) {
                    $conjunct = $this->stripParentheses($tokens, $pairs, $conjunct);
                    $decides = $decides || (
                        count($conjunct) >= 3
                        && $this->namesFunction($tokens[$conjunct[0]], 'hash_equals')
                        && $tokens[$conjunct[1]]['text'] === '('
                        && ($pairs[$conjunct[1]] ?? null) === $conjunct[count($conjunct) - 1]
                    );
                }
                $this->assertTrue(
                    $decides,
                    "$name() in $file returns something other than false or the hash_equals() comparison at line "
                    . $this->lineOf($tokens, $i) . ': `return false;` and `return … && hash_equals(…);` are the'
                    . ' shapes this check reads, because only those make the constant-time compare decide'
                );
                $compared++;
            }
            $this->assertGreaterThan(0, $compared, "$name() in $file never returns the hash_equals() comparison");
        }
    }

    /**
     * The index of the `;` (statement seed) or `}` (branch seed) that ends
     * the seeding of the flag from the result — a seed that runs whenever
     * the POST block runs, directly inside it as a statement of its own,
     * because one inside a further condition can be skipped, and the work's
     * branch then tests a flag nothing set. The first version of this found
     * the seed anywhere after the guard, and counted any whole assignment
     * in the failure branch as one: `$error = [];` there leaves the flag
     * empty and the work reachable. The second stopped at the first good
     * write, so a reset after it in the same branch went unread; the whole
     * branch is scanned now.
     *
     * @param array{file: string, result: string, gate: string, seed: ?string} $page
     */
    private function seedEnd(array $tokens, array $pairs, array $page, int $resultEnd, int $postBrace): int
    {
        $file = $page['file'];
        $seed = (string) $page['seed'];
        $count = count($tokens);
        $conditional = [];

        if (str_starts_with($seed, $page['gate'])) {
            // `$flag = $result;`
            for ($i = $resultEnd + 1; $i < $count; $i++) {
                if ($tokens[$i]['id'] !== T_VARIABLE || $tokens[$i]['text'] !== $page['gate']) {
                    continue;
                }
                $equals = $this->nextSignificant($tokens, $i + 1);
                $value = $equals === null ? null : $this->nextSignificant($tokens, $equals + 1);
                $end = $value === null ? null : $this->nextSignificant($tokens, $value + 1);
                $matches = $equals !== null && $tokens[$equals]['text'] === '='
                    && $value !== null && $tokens[$value]['text'] === $page['result']
                    && $end !== null && $tokens[$end]['text'] === ';';
                if (!$matches) {
                    continue;
                }
                if (!$this->runsWheneverTheBlockRuns($tokens, $pairs, $postBrace, $i)) {
                    $conditional[] = $this->lineOf($tokens, $i);
                    continue;
                }

                return (int) $end;
            }
            $this->fail(
                "$file no longer seeds {$page['gate']} from {$page['result']} as `$seed` directly inside its"
                . ' POST block as a statement of its own'
                . ($conditional === [] ? '' : ' (found inside a further condition at line '
                    . implode(', ', $conditional) . ')')
                . '; a request that skips the seed reaches the work with the flag unset'
            );
        }

        // `if (!$result) {` setting the flag to something the work's branch
        // refuses — an element write, or `= true` — directly inside it.
        $weak = [];
        for ($i = $resultEnd + 1; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_IF) {
                continue;
            }
            $open = $this->nextSignificant($tokens, $i + 1);
            if ($open === null || $tokens[$open]['text'] !== '(' || !isset($pairs[$open])) {
                continue;
            }
            $inside = $this->significantBetween($tokens, $open, $pairs[$open]);
            $condition = $this->stripParentheses($tokens, $pairs, $inside);
            if (
                count($condition) !== 2
                || $tokens[$condition[0]]['text'] !== '!'
                || $tokens[$condition[1]]['text'] !== $page['result']
            ) {
                continue;
            }
            $brace = $this->nextSignificant($tokens, $pairs[$open] + 1);
            if ($brace === null || $tokens[$brace]['text'] !== '{' || !isset($pairs[$brace])) {
                continue;
            }
            if (!$this->runsWheneverTheBlockRuns($tokens, $pairs, $postBrace, $i)) {
                $conditional[] = $this->lineOf($tokens, $i);
                continue;
            }
            $depth = 0;
            for ($j = $brace + 1; $j < $pairs[$brace]; $j++) {
                $text = $tokens[$j]['text'];
                if ($text === '{' || $text === '${') {
                    $depth++;
                } elseif ($text === '}') {
                    $depth--;
                } elseif ($depth === 0 && $tokens[$j]['id'] === T_VARIABLE && $tokens[$j]['text'] === $page['gate']) {
                    $use = $this->useOf($tokens, $pairs, $j);
                    if ($use === 'element write' || ($use === 'assignment' && $this->rhsOf($tokens, $j) === ['true'])) {
                        // The rest of the branch as well: a reset after the
                        // seed (`$error['user'] = …; $error = [];`) leaves
                        // the flag empty on the way out.
                        $this->assertUntouched(
                            $tokens,
                            $pairs,
                            $page['gate'],
                            $brace,
                            $pairs[$brace],
                            ['true'],
                            $file,
                            'the error flag inside its failure branch'
                        );

                        return $pairs[$brace];
                    }
                    if ($use === 'assignment') {
                        $weak[] = $this->lineOf($tokens, $j);
                    }
                }
            }
        }
        $this->fail(
            "$file no longer seeds {$page['gate']} inside `$seed` directly inside its POST block: the failure"
            . " branch must set {$page['gate']} to something the work's branch refuses — an element write, or"
            . ' `= true` — directly inside it, not inside a further condition'
            . ($weak === [] ? '' : '; the assignment at line ' . implode(', ', $weak)
                . ' assigns something else, which can leave the flag empty')
            . ($conditional === [] ? '' : '; the branch at line ' . implode(', ', $conditional)
                . ' is itself inside a further condition')
        );
    }

    /**
     * The index of the `if`/`elseif` the work at $work sits under, whose
     * condition has the result (or the negated flag) as a whole conjunct and
     * which comes after the seed.
     *
     * @param array{file: string, work: string, result: string, negated: bool, gate: string} $page
     */
    private function gateOf(array $tokens, array $pairs, array $page, int $work, int $seedEnd): int
    {
        $file = $page['file'];
        $accepted = [$page['negated'] ? ['!', $page['result']] : [$page['result']]];
        if ($page['gate'] !== $page['result']) {
            $accepted[] = ['!', $page['gate']];
        }
        $spellings = implode(' or ', array_map(static fn(array $a): string => '`' . implode('', $a) . '`', $accepted));

        $early = [];
        foreach ($pairs as $open => $close) {
            if ($tokens[$open]['text'] !== '{' || $open >= $work || $close <= $work) {
                continue;
            }
            $closeParen = $this->previousSignificant($tokens, $open - 1);
            if ($closeParen === null || $tokens[$closeParen]['text'] !== ')' || !isset($pairs[$closeParen])) {
                continue;
            }
            $openParen = $pairs[$closeParen];
            $keyword = $this->previousSignificant($tokens, $openParen - 1);
            if ($keyword === null || ($tokens[$keyword]['id'] !== T_IF && $tokens[$keyword]['id'] !== T_ELSEIF)) {
                continue;
            }
            $condition = $this->significantBetween($tokens, $openParen, $closeParen);
            foreach ($this->splitTopLevelAnd($tokens, $condition) as $conjunct) {
                $conjunct = $this->stripParentheses($tokens, $pairs, $conjunct);
                $texts = array_map(static fn(int $k): string => $tokens[$k]['text'], $conjunct);
                if (!in_array($texts, $accepted, true)) {
                    continue;
                }
                if ($keyword <= $seedEnd) {
                    $early[] = $this->lineOf($tokens, $keyword);
                    continue;
                }

                return $keyword;
            }
        }

        $this->fail(
            "$file calls {$page['work']} at line " . $this->lineOf($tokens, $work) . ' outside any `if` whose condition'
            . " has the guard's result as a whole conjunct ($spellings)"
            . ($early === [] ? '' : '; the branch at line ' . implode(', ', $early) . ' tests it before it is seeded')
            . ', so the work runs whatever the token check said'
        );
    }

    /**
     * Every appearance of $name in ($from, $to) is a read, an element write,
     * or an assignment of one of $allowed — nothing that could make the
     * variable falsy when the guard said no.
     *
     * @param list<string> $allowed right-hand sides a whole assignment may have
     */
    private function assertUntouched(
        array $tokens,
        array $pairs,
        string $name,
        int $from,
        int $to,
        array $allowed,
        string $file,
        string $what
    ): void {
        $touched = [];
        for ($i = $from + 1; $i < $to; $i++) {
            if ($tokens[$i]['id'] === T_UNSET) {
                $open = $this->nextSignificant($tokens, $i + 1);
                if ($open !== null && isset($pairs[$open])) {
                    foreach ($this->significantBetween($tokens, $open, $pairs[$open]) as $k) {
                        if ($tokens[$k]['id'] === T_VARIABLE && $tokens[$k]['text'] === $name) {
                            $touched[] = 'unset at line ' . $this->lineOf($tokens, $k);
                        }
                    }
                }
                continue;
            }
            $blind = $this->writesNoScanCanSee($tokens, $i);
            if ($blind !== null) {
                $touched[] = "$blind at line " . $this->lineOf($tokens, $i);
                continue;
            }
            if ($tokens[$i]['id'] !== T_VARIABLE || $tokens[$i]['text'] !== $name) {
                continue;
            }
            $use = $this->useOf($tokens, $pairs, $i);
            if ($use === 'read' || ($use === 'element write' && $allowed !== [])) {
                continue;
            }
            if ($use === 'assignment') {
                $value = $this->rhsOf($tokens, $i);
                if (count($value) === 1 && in_array($value[0], array_map('strtolower', $allowed), true)) {
                    continue;
                }
            }
            $touched[] = "$use at line " . $this->lineOf($tokens, $i);
        }

        $this->assertSame(
            [],
            $touched,
            "$file changes $what ($name) between the guard and the branch that tests it: " . implode(', ', $touched)
            . '. Only a read, an element write'
            . ($allowed === [] ? '' : ', or assigning ' . implode(' or ', $allowed))
            . ' is read as safe; anything else could let the work run when the token check failed'
        );
    }

    /**
     * Nothing anywhere in the file can write one of $names without naming
     * it inside the ranges this test scans. The scan for writes looks for
     * the variable's own token between the guard and the branch, and a
     * write path made elsewhere carries a write in unnamed: an alias
     * (`$alias =& $error;` above the guard, then `$alias = false;` below
     * it — the shape a reviewer planted and watched pass), a closure's
     * `use (&$error)`, a `foreach` by reference, a function's `global
     * $error` (called in the range as a bare call, which names nothing), or
     * `$GLOBALS`, a variable variable, `extract()` and `eval()` anywhere.
     * Each is refused where it is made, by line; a page that needs one
     * teaches this check first. An `include` before the range is not read:
     * the pages load their configuration that way.
     *
     * @param list<string> $names
     */
    private function assertNoWritePathTheScanCannotFollow(array $tokens, array $names, string $file): void
    {
        $found = [];
        foreach ($tokens as $i => $token) {
            $line = $this->lineOf($tokens, $i);
            if ($token['id'] === T_VARIABLE && in_array($token['text'], $names, true)) {
                $prev = $this->previousSignificant($tokens, $i - 1);
                if ($prev !== null && $tokens[$prev]['text'] === '&') {
                    $found[] = "a reference to {$token['text']} at line $line";
                }
                if (in_array($this->statementKeyword($tokens, $i), [T_GLOBAL, T_STATIC], true)) {
                    $found[] = "a global or static declaration of {$token['text']} at line $line";
                }
                continue;
            }
            $blind = $this->writesNoScanCanSee($tokens, $i);
            if ($blind !== null && $blind !== 'an include') {
                $found[] = "$blind at line $line";
            }
        }

        $this->assertSame(
            [],
            $found,
            "$file makes " . implode(', ', $found) . ', which can write ' . implode(' or ', $names)
            . ' inside the ranges this test scans without naming it there — through an alias, a'
            . ' global, or a construct that names no variable at all. Refuse it, or teach this'
            . ' check the new shape.'
        );
    }

    /**
     * A construct at $at that can write a variable without naming it — a
     * variable variable (`$$name`, `${'name'}`), `$GLOBALS['name']` (the
     * pages run at file scope, where that is the same variable),
     * `extract()`, `eval()`, or an `include`/`require` — or null. The scan
     * for writes looks for the variable's own token, and none of these
     * carries it, so each is refused in the range rather than read past.
     */
    private function writesNoScanCanSee(array $tokens, int $at): ?string
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
            $next = $this->nextSignificant($tokens, $at + 1);
            if ($next !== null && $tokens[$next]['text'] === '(') {
                return 'an extract()';
            }
        }

        return null;
    }

    /**
     * How the variable at $at is used: 'read', 'element write', 'assignment',
     * or a kind this refuses — a compound assignment, an increment, a
     * reference, a destructuring, or an argument to a call that may take it
     * by reference.
     *
     * The write shapes are the ones PHP's grammar has, not the ones that
     * came to mind: assignment, compound assignment, increment, element
     * write, destructuring (`[…] =`, `list(…) =`, keyed or not), a
     * reference, an argument to a call (positional or named — `f(name:
     * $x)` has a `:` before the variable, which the first version read as
     * harmless), a `foreach` target (`as $x`, `=> $x`), a `catch` target,
     * and a `global` or `static` declaration. Writes that never name the
     * variable — `$$name`, `$GLOBALS['name']`, `extract()`, `eval()`, an
     * `include` — are refused in the scanned range by writesNoScanCanSee().
     */
    private function useOf(array $tokens, array $pairs, int $at): string
    {
        $prev = $this->previousSignificant($tokens, $at - 1);
        $next = $this->nextSignificant($tokens, $at + 1);

        $stepped = $prev !== null && in_array($tokens[$prev]['id'], [T_INC, T_DEC], true);
        if ($prev !== null && ($tokens[$prev]['text'] === '&' || $stepped)) {
            return 'reference or increment';
        }
        if (in_array($this->statementKeyword($tokens, $at), [T_GLOBAL, T_STATIC], true)) {
            return 'global or static declaration';
        }
        if ($next !== null) {
            if ($tokens[$next]['text'] === '=') {
                return 'assignment';
            }
            if (in_array($tokens[$next]['id'], self::COMPOUND_ASSIGNMENTS, true)) {
                return 'compound assignment';
            }
            if (in_array($tokens[$next]['id'], [T_INC, T_DEC], true)) {
                return 'increment';
            }
            if ($tokens[$next]['text'] === '[' && isset($pairs[$next])) {
                $after = $this->nextSignificant($tokens, $pairs[$next] + 1);
                $written = $after !== null && (
                    $tokens[$after]['text'] === '='
                    || in_array($tokens[$after]['id'], self::COMPOUND_ASSIGNMENTS, true)
                );
                if ($written) {
                    return 'element write';
                }
                return 'read';
            }
        }

        // Inside brackets, what the variable is to them is told by the
        // opener and the token before it. `f(name: $x)` puts a `:` before
        // the variable, so the call's `(` or `,` is two tokens further
        // back — the first version read that shape as a read.
        $named = false;
        if ($prev !== null && $tokens[$prev]['text'] === ':') {
            $label = $this->previousSignificant($tokens, $prev - 1);
            $beforeLabel = $label === null ? null : $this->previousSignificant($tokens, $label - 1);
            $named = $label !== null && $tokens[$label]['id'] === T_STRING
                && $beforeLabel !== null && in_array($tokens[$beforeLabel]['text'], ['(', ','], true);
        }
        $listed = $prev !== null && (
            in_array($tokens[$prev]['text'], ['(', ',', '['], true)
            || in_array($tokens[$prev]['id'], [T_AS, T_DOUBLE_ARROW], true)
            || $named
        );
        $opener = $this->enclosingOpener($tokens, $at);
        if ($opener === null) {
            return 'read';
        }
        $after = isset($pairs[$opener]) ? $this->nextSignificant($tokens, $pairs[$opener] + 1) : null;
        $before = $this->previousSignificant($tokens, $opener - 1);
        if ($tokens[$opener]['text'] === '[') {
            // An index (`$x[$v]`) follows a variable, a call or another
            // index; a list literal follows anything else, and is a
            // destructuring when `=` follows its `]`.
            $indexes = $before !== null && (
                in_array($tokens[$before]['id'], [T_VARIABLE, T_STRING], true)
                || in_array($tokens[$before]['text'], [')', ']'], true)
            );

            $assigned = $after !== null && $tokens[$after]['text'] === '=';

            return !$indexes && $listed && $assigned ? 'destructuring' : 'read';
        }
        if ($before === null) {
            return 'read';
        }
        if ($tokens[$before]['id'] === T_CATCH) {
            return 'catch target';
        }
        if ($tokens[$before]['id'] === T_FOREACH) {
            return $prev !== null && in_array($tokens[$prev]['id'], [T_AS, T_DOUBLE_ARROW], true)
                ? 'foreach target'
                : 'read';
        }
        if (!$listed) {
            return 'read';
        }
        if ($tokens[$before]['id'] === T_LIST) {
            return 'destructuring';
        }
        $callee = [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE, T_VARIABLE];
        $isCall = in_array($tokens[$before]['id'], $callee, true)
            || in_array($tokens[$before]['text'], [')', ']'], true);

        return $isCall ? 'argument to a call' : 'read';
    }

    /**
     * The id of the first significant token of the statement the token at
     * $at sits in — what follows the nearest `;`, `{`, `}` or `:` before
     * it — so `global $a, $error;` is read as the declaration it is for
     * every variable it names, not only the first.
     */
    private function statementKeyword(array $tokens, int $at): ?int
    {
        for ($j = $at - 1; $j >= 0; $j--) {
            if (in_array($tokens[$j]['text'], [';', '{', '}', ':'], true)) {
                break;
            }
        }
        $first = $this->nextSignificant($tokens, $j + 1);

        return $first === null ? null : $tokens[$first]['id'];
    }

    /**
     * The right-hand side of the assignment whose left-hand side is the
     * variable at $at: its significant token texts, lowercased, up to the
     * `;` or to the close of the parentheses the assignment sits in,
     * whichever comes first — `($error = false) === false` assigns `false`.
     *
     * @return list<string>
     */
    private function rhsOf(array $tokens, int $at): array
    {
        $equals = $this->nextSignificant($tokens, $at + 1);
        $value = [];
        $depth = 0;
        for ($j = ($equals ?? $at) + 1, $n = count($tokens); $j < $n && $tokens[$j]['text'] !== ';'; $j++) {
            $text = $tokens[$j]['text'];
            if ($text === '(' || $text === '[') {
                $depth++;
            } elseif (($text === ')' || $text === ']') && --$depth < 0) {
                break;
            }
            if ($tokens[$j]['id'] !== T_WHITESPACE) {
                $value[] = strtolower($text);
            }
        }

        return $value;
    }

    /**
     * Does the token at $at start a statement that runs whenever the block
     * opened at $open runs — inside its braces at no deeper level, and first
     * in its statement, so not the body of a braceless `if` or of an `else`?
     *
     * A return, exit or throw above the statement is not read here, unlike
     * in AttributionUpgradeStepTest's twin of this: there the invariant is
     * that the statement runs, and a jump above it is a path on which it
     * does not; here it is that the work never runs unguarded, and a jump
     * above the guard leaves the block without the work — the safe
     * direction. The one jump that can skip a statement and still reach a
     * later one, `goto`, is refused for the whole file by
     * testTheGuardResultControlsTheWork().
     */
    private function runsWheneverTheBlockRuns(array $tokens, array $pairs, int $open, int $at): bool
    {
        if ($at <= $open || $at >= ($pairs[$open] ?? -1)) {
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
        $prev = $this->previousSignificant($tokens, $at - 1);

        return $prev !== null && in_array($tokens[$prev]['text'], [';', '{', '}'], true);
    }

    /** The innermost unmatched `(` or `[` before $at, or null. */
    private function enclosingOpener(array $tokens, int $at): ?int
    {
        $depth = 0;
        for ($i = $at - 1; $i >= 0; $i--) {
            $text = $tokens[$i]['text'];
            if ($text === ')' || $text === ']') {
                $depth++;
            } elseif ($text === '(' || $text === '[') {
                if ($depth === 0) {
                    return $i;
                }
                $depth--;
            }
        }

        return null;
    }

    /**
     * The conjuncts of $run split at top-level `&&` / `and`, each a list of
     * significant token indices.
     *
     * @param  list<int> $run
     * @return list<list<int>>
     */
    private function splitTopLevelAnd(array $tokens, array $run): array
    {
        $parts = [[]];
        $depth = 0;
        foreach ($run as $i) {
            $text = $tokens[$i]['text'];
            if ($text === '(' || $text === '[' || $text === '{' || $text === '${') {
                $depth++;
            } elseif ($text === ')' || $text === ']' || $text === '}') {
                $depth--;
            } elseif ($depth === 0 && in_array($tokens[$i]['id'], [T_BOOLEAN_AND, T_LOGICAL_AND], true)) {
                $parts[] = [];
                continue;
            }
            $parts[count($parts) - 1][] = $i;
        }

        return $parts;
    }

    /**
     * Drop every pair of parentheses that encloses the whole run.
     *
     * @param  list<int> $run significant token indices
     * @return list<int>
     */
    private function stripParentheses(array $tokens, array $pairs, array $run): array
    {
        $run = array_values($run);
        while (
            count($run) >= 2
            && $tokens[$run[0]]['text'] === '('
            && ($pairs[$run[0]] ?? null) === $run[count($run) - 1]
        ) {
            $run = array_slice($run, 1, -1);
        }

        return $run;
    }

    /**
     * @return list<int> significant token indices strictly between $open and $close
     */
    private function significantBetween(array $tokens, int $open, int $close): array
    {
        $inside = [];
        for ($i = $open + 1; $i < $close; $i++) {
            if ($tokens[$i]['id'] !== T_WHITESPACE) {
                $inside[] = $i;
            }
        }

        return $inside;
    }

    /** Does this token name the global function $name, qualified or not? */
    private function namesFunction(array $token, string $name): bool
    {
        if (!in_array($token['id'], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE], true)) {
            return false;
        }
        $segments = explode('\\', $token['text']);

        return strtolower((string) end($segments)) === $name;
    }

    /**
     * The `{` and `}` bounding the body of `function $name`.
     *
     * @return array{0: int, 1: int}
     */
    private function functionRange(array $tokens, array $pairs, string $file, string $name): array
    {
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_FUNCTION) {
                continue;
            }
            $j = $this->nextSignificant($tokens, $i + 1);
            if ($j === null || $tokens[$j]['id'] !== T_STRING || $tokens[$j]['text'] !== $name) {
                continue;
            }
            for ($k = $j; $k < $count; $k++) {
                if ($tokens[$k]['text'] === '{') {
                    $this->assertArrayHasKey($k, $pairs, "$name() in $file has no closing brace");

                    return [$k, $pairs[$k]];
                }
            }
        }

        $this->fail("$name() is not defined in $file");
    }

    /**
     * Every `(`, `[`, `{`/`${` mapped to its closer and back.
     *
     * @return array<int, int>
     */
    private function pairs(array $tokens, string $file): array
    {
        $pairs = [];
        $stack = [];
        foreach ($tokens as $i => $token) {
            $text = $token['text'];
            if ($text === '(' || $text === '[' || $text === '{' || $text === '${') {
                $stack[] = $i;
            } elseif ($text === ')' || $text === ']' || $text === '}') {
                $open = array_pop($stack);
                $this->assertNotNull($open, "$file: unbalanced `$text` at line " . $this->lineOf($tokens, $i));
                $pairs[(int) $open] = $i;
                $pairs[$i] = (int) $open;
            }
        }
        $this->assertSame([], $stack, "$file: an opener is never closed");

        return $pairs;
    }

    /**
     * Every `<$name` tag in the inline HTML between the offsets $from and
     * $to: where it starts, where its `>` ends (PHP blocks and quoted values
     * skipped), its text, and the token it starts in. A `<$name` inside a
     * PHP string or comment is not a tag and is not returned.
     *
     * @return list<array{start: int, end: int, tag: string, token: int}>
     */
    private function tagsNamed(array $tokens, string $code, string $name, int $from = 0, ?int $to = null): array
    {
        $to ??= strlen($code);
        $needle = '<' . $name;
        $found = [];
        for ($p = stripos($code, $needle, $from); $p !== false && $p < $to; $p = stripos($code, $needle, $p + 1)) {
            $after = $code[$p + strlen($needle)] ?? '';
            if (!in_array($after, [' ', "\t", "\n", "\r", '>', '/'], true)) {
                continue;
            }
            $token = $this->tokenAt($tokens, $p);
            if ($tokens[$token]['id'] !== T_INLINE_HTML) {
                continue;
            }
            $end = $this->tagEnd($code, $p);
            $this->assertNotNull($end, "an unclosed <$name tag at line " . $this->lineOf($tokens, $token));
            $found[] = [
                'start' => $p,
                'end' => (int) $end,
                'tag' => substr($code, $p, (int) $end - $p),
                'token' => $token,
            ];
        }

        return $found;
    }

    /**
     * The offset just past the `>` that closes the tag opening at $start —
     * a `>` inside a quoted value, or inside a `<?php … ?>` block, is not it
     * — or null when the tag never closes.
     */
    private function tagEnd(string $code, int $start): ?int
    {
        $length = strlen($code);
        $quote = null;
        for ($i = $start; $i < $length; $i++) {
            $char = $code[$i];
            if ($char === '<' && ($code[$i + 1] ?? '') === '?') {
                $close = strpos($code, '?>', $i + 2);
                if ($close === false) {
                    return null;
                }
                $i = $close + 1;
                continue;
            }
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '>') {
                return $i + 1;
            }
        }

        return null;
    }

    /**
     * The offset of the `</$name` closing the tag whose `>` ended at $from,
     * or null when there is none in the inline HTML, or another `<$name`
     * opens before it (a form inside a form is not a form).
     */
    private function closingTagOf(array $tokens, string $code, string $name, int $from): ?int
    {
        $close = stripos($code, '</' . $name, $from);
        while ($close !== false && $tokens[$this->tokenAt($tokens, $close)]['id'] !== T_INLINE_HTML) {
            $close = stripos($code, '</' . $name, $close + 1);
        }
        if ($close === false) {
            return null;
        }

        return $this->tagsNamed($tokens, $code, $name, $from, $close) === [] ? $close : null;
    }

    /**
     * The `<!-- … -->` regions of the inline HTML between $from and $to, as
     * [start, end) offset pairs; a comment left open runs to $to.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function commentsBetween(array $tokens, string $code, int $from, int $to): array
    {
        $regions = [];
        for ($p = strpos($code, '<!--', $from); $p !== false && $p < $to; $p = strpos($code, '<!--', $p + 4)) {
            if ($tokens[$this->tokenAt($tokens, $p)]['id'] !== T_INLINE_HTML) {
                continue;
            }
            $end = strpos($code, '-->', $p + 4);
            $regions[] = [$p, $end === false ? $to : $end + 3];
        }

        return $regions;
    }

    /**
     * The attributes of one tag, names lowercased, values unquoted, a
     * valueless attribute (`disabled`, `checked`) mapped to ''. The first of
     * a repeated name wins, as in a browser. A `<?php … ?>` block inside the
     * tag is kept whole in the value it sits in; one standing as an
     * attribute of its own is not read.
     *
     * @return array<string, string>
     */
    private function attributesOf(string $tag): array
    {
        $php = [];
        $tag = (string) preg_replace_callback('/<\?.*?\?>/s', static function (array $m) use (&$php): string {
            $php[] = $m[0];

            return "\0" . (count($php) - 1) . "\0";
        }, $tag);
        $body = (string) preg_replace('/^<[a-zA-Z][a-zA-Z0-9-]*\s*|\/?>$/', '', $tag);

        $attributes = [];
        preg_match_all(
            '/([^\s=\/>"\']+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/',
            $body,
            $matches,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL
        );
        foreach ($matches as $m) {
            $value = $m[2] ?? $m[3] ?? $m[4] ?? '';
            $value = (string) preg_replace_callback(
                '/\0(\d+)\0/',
                static fn (array $m): string => $php[(int) $m[1]],
                $value
            );
            $attributes[strtolower($m[1])] ??= $value;
        }

        return $attributes;
    }

    /**
     * Why $value, the token input's value attribute, is not one echo of the
     * session token through an HTML escaper with ENT_QUOTES —
     * `<?php echo htmlentities((string) ($_SESSION['token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>`,
     * its htmlspecialchars() spelling, or either after `<?=` — or null when
     * it is. Read from the value's own tokens, argument by argument, and
     * the first argument parsed as the session-token expression rather than
     * searched for it: "contains $_SESSION['token']" accepted
     * `'prefix' . $_SESSION['token']`, a value the server's hash_equals()
     * refuses on every submission, so the page could never log anyone in
     * and this test stayed green. The flags have to be ENT_QUOTES, alone or
     * joined by `|` with other ENT_ constants; a charset, when given, a
     * string literal; a double_encode, when given, true or false.
     */
    private function echoesEscapedSessionToken(string $value): ?string
    {
        $raw = [];
        foreach (token_get_all($value) as $token) {
            $id = is_array($token) ? $token[0] : null;
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }
            $raw[] = ['id' => $id, 'text' => is_array($token) ? $token[1] : $token];
        }
        $count = count($raw);
        if ($count === 0 || !in_array($raw[0]['id'], [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO], true)) {
            return 'is not a PHP echo';
        }
        $k = 1;
        if ($raw[0]['id'] === T_OPEN_TAG) {
            if (($raw[$k]['id'] ?? null) !== T_ECHO) {
                return 'does not echo';
            }
            $k++;
        }
        $escapers = ['htmlentities', 'htmlspecialchars'];
        if (($raw[$k]['id'] ?? null) !== T_STRING || !in_array(strtolower($raw[$k]['text']), $escapers, true)) {
            return 'does not pass through htmlentities() or htmlspecialchars()';
        }
        $k++;
        if (($raw[$k]['text'] ?? null) !== '(') {
            return 'does not call the escaper';
        }

        // The escaper's arguments, split at the commas between them.
        $depth = 0;
        $arguments = [[]];
        for ($k++; $k < $count; $k++) {
            $text = $raw[$k]['text'];
            if ($text === '(' || $text === '[') {
                $depth++;
            } elseif ($text === ')' || $text === ']') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($text === ',' && $depth === 0) {
                $arguments[] = [];
                continue;
            }
            $arguments[count($arguments) - 1][] = $raw[$k];
        }
        if (($raw[$k]['text'] ?? null) !== ')') {
            return 'has an unbalanced escaper call';
        }
        $k++;
        if (($raw[$k]['text'] ?? null) === ';') {
            $k++;
        }
        if (($raw[$k]['id'] ?? null) !== T_CLOSE_TAG || $k !== $count - 1) {
            return 'has more in it than the one escaper call';
        }

        if (!$this->isTheSessionToken($arguments[0])) {
            return "does not hand the escaper \$_SESSION['token'] itself as its first argument";
        }
        if (count($arguments) < 2 || count($arguments) > 4) {
            return 'does not call the escaper with two to four arguments';
        }
        $quotes = false;
        foreach ($arguments[1] as $token) {
            if ($token['id'] === T_STRING && str_starts_with($token['text'], 'ENT_')) {
                $quotes = $quotes || $token['text'] === 'ENT_QUOTES';
            } elseif ($token['text'] !== '|') {
                return 'has escaper flags this check does not read (ENT_ constants joined by | only)';
            }
        }
        if (!$quotes) {
            return 'does not escape with ENT_QUOTES';
        }
        $charset = $arguments[2] ?? null;
        if ($charset !== null && (count($charset) !== 1 || $charset[0]['id'] !== T_CONSTANT_ENCAPSED_STRING)) {
            return 'has a charset argument that is not a string literal';
        }
        $doubleEncode = $arguments[3] ?? null;
        if (
            $doubleEncode !== null
            && (count($doubleEncode) !== 1 || !in_array(strtolower($doubleEncode[0]['text']), ['true', 'false'], true))
        ) {
            return 'has a double_encode argument that is not true or false';
        }

        return null;
    }

    /**
     * Is this run of tokens the session token itself — `$_SESSION['token']`
     * or `$_SESSION['token'] ?? ''`, either under a `(string)` cast, any of
     * them in redundant parentheses — and nothing more? A prefix, a suffix,
     * another default or a call around it is refused: the server compares
     * the submitted value with hash_equals(), so anything but the token
     * itself is a form that never passes.
     *
     * @param list<array{id: int|null, text: string}> $run
     */
    private function isTheSessionToken(array $run): bool
    {
        $run = $this->unwrapped($run);
        if (($run[0]['id'] ?? null) === T_STRING_CAST) {
            $run = $this->unwrapped(array_slice($run, 1));
        }
        $reads = count($run) >= 4
            && $run[0]['id'] === T_VARIABLE && $run[0]['text'] === '$_SESSION'
            && $run[1]['text'] === '['
            && $run[2]['id'] === T_CONSTANT_ENCAPSED_STRING && in_array($run[2]['text'], ["'token'", '"token"'], true)
            && $run[3]['text'] === ']';
        if (!$reads) {
            return false;
        }
        $rest = array_slice($run, 4);
        if ($rest === []) {
            return true;
        }

        return count($rest) === 2
            && $rest[0]['id'] === T_COALESCE
            && $rest[1]['id'] === T_CONSTANT_ENCAPSED_STRING && in_array($rest[1]['text'], ["''", '""'], true);
    }

    /**
     * The run with every pair of parentheses enclosing the whole of it
     * removed — only a pair whose `(` closes at the run's last `)`, so
     * `(a) . (b)` keeps both of its.
     *
     * @param list<array{id: int|null, text: string}> $run
     * @return list<array{id: int|null, text: string}>
     */
    private function unwrapped(array $run): array
    {
        while (count($run) >= 2 && $run[0]['text'] === '(' && $run[count($run) - 1]['text'] === ')') {
            $depth = 0;
            foreach ($run as $k => $token) {
                if ($token['text'] === '(') {
                    $depth++;
                } elseif ($token['text'] === ')') {
                    $depth--;
                    if ($depth === 0 && $k !== count($run) - 1) {
                        return $run;
                    }
                }
            }
            $run = array_slice($run, 1, -1);
        }

        return $run;
    }

    /**
     * The PHP block depth at $to relative to $from: braces opened and closed
     * between the two tokens, and the alternative syntax's `if (…):` …
     * `endif;` (foreach, for, while and switch alike). Zero means the two
     * sit in the same block. Anything else, or a dip below zero on the way
     * (a block opened above $from closing between them), means a condition
     * or a loop between the two can render one without the other; the dip
     * is returned so the message can say which.
     */
    private function phpDepthBetween(array $tokens, array $pairs, int $from, int $to): int
    {
        $openers = [T_IF, T_ELSEIF, T_FOREACH, T_FOR, T_WHILE, T_SWITCH];
        $closers = [T_ENDIF, T_ENDFOREACH, T_ENDFOR, T_ENDWHILE, T_ENDSWITCH];
        $depth = 0;
        $lowest = 0;
        for ($i = $from + 1; $i < $to; $i++) {
            $id = $tokens[$i]['id'];
            $text = $tokens[$i]['text'];
            if ($text === '{' || $text === '${') {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
            } elseif (in_array($id, $openers, true)) {
                $open = $this->nextSignificant($tokens, $i + 1);
                $after = $open !== null && isset($pairs[$open])
                    ? $this->nextSignificant($tokens, $pairs[$open] + 1)
                    : null;
                if ($after !== null && $tokens[$after]['text'] === ':' && $id !== T_ELSEIF) {
                    $depth++;
                }
            } elseif (in_array($id, $closers, true)) {
                $depth--;
            }
            $lowest = min($lowest, $depth);
        }

        return $lowest < 0 ? $lowest : $depth;
    }

    /** The token whose text spans character $offset of the joined code. */
    private function tokenAt(array $tokens, int $offset): int
    {
        $start = 0;
        foreach ($tokens as $i => $token) {
            $start += strlen($token['text']);
            if ($start > $offset) {
                return $i;
            }
        }

        $this->fail("offset $offset is past the end of the code");
    }

    /** The 1-based source line the token at $at starts on. */
    private function lineOf(array $tokens, int $at): int
    {
        $line = 1;
        for ($i = 0; $i < $at; $i++) {
            $line += substr_count($tokens[$i]['text'], "\n");
        }

        return $line;
    }

    /** The next non-whitespace token index at or after $from, or null. */
    private function nextSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from, $n = count($tokens); $i < $n; $i++) {
            if ($tokens[$i]['id'] !== T_WHITESPACE) {
                return $i;
            }
        }

        return null;
    }

    /** The previous non-whitespace token index at or before $from, or null. */
    private function previousSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from; $i >= 0; $i--) {
            if ($tokens[$i]['id'] !== T_WHITESPACE) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The file's tokens with comments reduced to the newlines they spanned,
     * so prose cannot satisfy a scan and lines in messages still match.
     *
     * @return list<array{id: int|null, text: string}>
     */
    private function tokensOf(string $file): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        $this->assertNotSame('', $source, "$file is empty or missing");

        $out = [];
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $out[] = ['id' => null, 'text' => $token];
                continue;
            }
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                $out[] = ['id' => T_WHITESPACE, 'text' => str_repeat("\n", substr_count($token[1], "\n"))];
                continue;
            }
            $out[] = ['id' => $token[0], 'text' => $token[1]];
        }

        return $out;
    }
}
