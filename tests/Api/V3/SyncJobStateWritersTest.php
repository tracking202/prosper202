<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Support\SyncJobState;
use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * A sync job's record is written by ServerStateStore::createJob() and
 * changeJob() and by nothing else, and its status is written and compared as
 * a SyncJobState, never as text.
 *
 * The record had a whole-copy save (saveJob()) that any caller could hand a
 * copy read earlier: a cancel that read the job queued wrote 'cancelled' over
 * the 'succeeded' its run had saved in between. changeJob() reads the record
 * under its lock and moves it only as the lifecycle allows; this holds that
 * nothing goes round it.
 *
 * Read by tokens. Does not see: a path to the jobs directory built by hand
 * (without jobPath() or dir('jobs')); a status held in a variable or constant
 * or built by concatenation; an index that is a variable (`$job[$key] =`); a
 * file that changes a job without naming changeJob. That a move the lifecycle
 * does not hold is refused is SyncJobStateTest's.
 */
final class SyncJobStateWritersTest extends TestCase
{
    private const STORE = 'api/v3/Support/ServerStateStore.php';
    private const CONTROLLER = 'api/v3/Controllers/SyncController.php';

    /** The store's functions that may name a job's file: the two writers and the reader. */
    private const FILE_USERS = ['createJob', 'changeJob', 'getJob'];

    /** The store's functions that may name the jobs directory. */
    private const DIRECTORY_USERS = ['jobPath', 'jobEventsPath', 'listJobs'];

    /** The store's functions that read or write a job's record: held as the controller is. */
    private const JOB_FUNCTIONS = ['createJob', 'changeJob', 'getJob', 'listJobs'];

    public function testAJobsFileIsNamedOnlyByTheStoresJobFunctions(): void
    {
        $files = SourceScan::phpFiles();
        self::assertArrayHasKey(self::STORE, $files);
        $wrong = [];
        $seen = [];
        foreach ($files as $file => $source) {
            foreach (self::fileUses($source) as [$function, $what]) {
                $seen[$file][$function] = true;
                $allowed = $what === 'jobPath' ? self::FILE_USERS : self::DIRECTORY_USERS;
                if ($file !== self::STORE || !in_array($function, $allowed, true)) {
                    $wrong[] = $file . ': ' . ($function === '' ? '(no function)' : $function . '()') . ' names ' . $what;
                }
            }
        }
        self::assertSame([], $wrong, "A sync job's file is read and written by ServerStateStore's job functions only:\n"
            . implode("\n", $wrong));
        foreach (self::FILE_USERS as $function) {
            self::assertArrayHasKey($function, $seen[self::STORE] ?? [], $function . '() no longer names a job file: the list is stale');
        }
    }

    public function testNothingSavesAWholeJobRecord(): void
    {
        $wrong = [];
        foreach (SourceScan::phpFiles() as $file => $source) {
            foreach (\PhpToken::tokenize($source) as $token) {
                if ($token->is(T_STRING) && strcasecmp($token->text, 'saveJob') === 0) {
                    $wrong[] = $file . ':' . $token->line;
                }
            }
        }
        self::assertSame([], $wrong, 'saveJob() wrote the caller\'s copy over the record: a job changes through changeJob()');
    }

    public function testAJobsStatusIsWrittenAndComparedAsAState(): void
    {
        $files = SourceScan::phpFiles();
        $scanned = [];
        $wrong = [];
        foreach ($files as $file => $source) {
            if ($file === self::STORE) {
                continue;
            }
            foreach (self::functions($source) as $function => $tokens) {
                foreach (self::textInListJobsCalls($tokens) as $found) {
                    $wrong[] = $file . ' ' . $function . '(): ' . $found;
                }
            }
            // A file that changes jobs is held whole; elsewhere 'status' and
            // 'failed' are other things' words.
            if (!self::names($source, ['changeJob'])) {
                continue;
            }
            $scanned[] = $file;
            foreach (self::functions($source) as $function => $tokens) {
                foreach (self::statusAsText($tokens, true) as $found) {
                    $wrong[] = $file . ' ' . $function . '(): ' . $found;
                }
            }
        }
        self::assertContains(self::CONTROLLER, $scanned, 'the scan reads the controller, so it reads something');

        $store = self::functions($files[self::STORE]);
        foreach (self::JOB_FUNCTIONS as $function) {
            self::assertArrayHasKey($function, $store, 'ServerStateStore::' . $function . '() is gone: the list is stale');
            // changeJob() is where the status is assigned.
            foreach (self::statusAsText($store[$function], $function !== 'changeJob') as $found) {
                $wrong[] = self::STORE . ' ' . $function . '(): ' . $found;
            }
        }
        self::assertSame([], $wrong, "A sync job's status is a SyncJobState (SyncJobState::X, ->value where it is stored):\n"
            . implode("\n", $wrong));
    }

    /** @dataProvider textStatuses */
    public function testTheScanFindsEachWayAStatusIsWrittenAsText(string $code, string $expected): void
    {
        $found = [];
        foreach (self::functions('<?php class C { function f() { ' . $code . ' } }') as $tokens) {
            array_push($found, ...self::statusAsText($tokens, true));
        }
        self::assertNotSame([], $found, $code);
        self::assertStringContainsString($expected, implode(' ', $found));
    }

    /** @return iterable<string, array{string, string}> */
    public static function textStatuses(): iterable
    {
        yield 'assigned' => ['$job[\'status\'] = SyncJobState::Failed->value;', 'assigns'];
        yield 'assigned, double quotes' => ['$job["status"] = $x;', 'assigns'];
        yield 'assigned in a nested record' => ['$r[\'job\'][\'status\'] ??= $x;', 'assigns'];
        yield 'set to a state as text' => ['return [\'status\' => \'cancelled\'];', 'as text'];
        yield 'set to text no state names' => ['return [\'status\' => \'done\'];', 'as text'];
        yield 'set to interpolated text' => ['return [\'status\' => "can$x"];', 'as text'];
        yield 'compared' => ['if ($s === \'queued\') {}', '\'queued\''];
        yield 'compared, the other way round' => ['if ("running" == $s) {}', '"running"'];
        yield 'listed' => ['in_array($s, [\'succeeded\', \'partial\'], true);', '\'succeeded\''];
        yield 'listed alone' => ['in_array($s, [\'queued\'], true);', '\'queued\''];
        yield 'passed' => ['f(\'failed\');', '\'failed\''];
        yield 'matched' => ['match ($s) { \'cancelled\' => 1 };', '\'cancelled\''];
    }

    public function testTheScanFindsJobsListedByAStateGivenAsText(): void
    {
        $in = static fn (string $code): array => array_merge(...array_values(array_map(
            self::textInListJobsCalls(...),
            self::functions('<?php class C { function f() { ' . $code . ' } }')
        )));
        self::assertCount(1, $in('$store->listJobs([\'queued\'], 5000);'));
        self::assertCount(2, $in('$this->store->listJobs([\'queued\', \'paused\']);'));
        self::assertSame([], $in('$store->listJobs([SyncJobState::Queued], 5000); $x = [\'queued\'];'));
    }

    public function testTheScanLeavesAloneWhatIsNotAStatus(): void
    {
        $code = '$summary[\'failed\'] += (int)($r[\'failed\'] ?? 0); $a = [\'failed\' => $failed, \'partial\' => 1];'
            . ' $e = [\'status\' => $job[\'status\']]; $v = [\'status\' => \'Valid values: \' . $list]; $f = [\'status\' => SyncJobState::Queued]; $s = $set[\'status\'];'
            . ' $m = \'jobs_failed\'; // \'queued\'';
        foreach (self::functions('<?php class C { function f() { ' . $code . ' } }') as $tokens) {
            self::assertSame([], self::statusAsText($tokens, true));
        }
    }

    public function testTheScanFindsAJobFileNamedOutsideTheOwners(): void
    {
        $source = '<?php class S { function saveJob($j) { $this->mutateJsonFile($this->jobPath($j), [], $f); }'
            . ' function sweep() { return glob($this->dir(\'jobs\') . "/*"); } function other() { return $this->dir("locks"); } }';
        self::assertSame([['saveJob', 'jobPath'], ['sweep', 'dir(\'jobs\')']], self::fileUses($source));
    }

    /**
     * Where a source names a job's file or the jobs directory.
     *
     * @return list<array{string, string}> [function, what]
     */
    private static function fileUses(string $source): array
    {
        $uses = [];
        foreach (self::functions($source) as $function => $tokens) {
            foreach ($tokens as $i => $token) {
                // The declaration `function jobPath(` is the function itself.
                if ($token->is(T_STRING) && strcasecmp($token->text, 'jobPath') === 0 && !($tokens[$i - 1] ?? null)?->is(T_FUNCTION)) {
                    $uses[] = [$function, 'jobPath'];
                }
                if (
                    $token->is(T_STRING) && strcasecmp($token->text, 'dir') === 0
                    && ($tokens[$i + 1] ?? null)?->text === '('
                    && ($tokens[$i + 2] ?? null)?->is(T_CONSTANT_ENCAPSED_STRING)
                    && trim($tokens[$i + 2]->text, '\'"') === 'jobs'
                ) {
                    $uses[] = [$function, 'dir(\'jobs\')'];
                }
            }
        }

        return $uses;
    }

    /**
     * What in $tokens writes or compares a job's status as text.
     *
     * @param list<\PhpToken> $tokens one function's, without whitespace and comments
     * @return list<string>
     */
    private static function statusAsText(array $tokens, bool $refuseAssignment): array
    {
        $states = array_map(static fn (SyncJobState $state): string => $state->value, SyncJobState::cases());
        $text = static fn (?\PhpToken $token): ?string => $token !== null && $token->is(T_CONSTANT_ENCAPSED_STRING)
            ? substr($token->text, 1, -1)
            : null;
        $found = [];
        foreach ($tokens as $i => $token) {
            $prev = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;
            if ($text($token) === 'status') {
                $indexed = $prev?->text === '[' && $next?->text === ']';
                $after = $tokens[$i + 2] ?? null;
                if ($refuseAssignment && $indexed && $after !== null && in_array($after->text, ['=', '.=', '??='], true)) {
                    $found[] = 'line ' . $token->line . ' assigns [\'status\']: a status changes in ServerStateStore::changeJob()';
                }
                // A literal that is the whole value; one that begins a
                // sentence (a field error keyed 'status') is not a status.
                $whole = $after !== null && ($after->text === '"' || ($after->is(T_CONSTANT_ENCAPSED_STRING)
                    && in_array(($tokens[$i + 3] ?? null)?->text, [',', ']', ')', ';'], true)));
                if ($next?->is(T_DOUBLE_ARROW) && $whole) {
                    $found[] = 'line ' . $token->line . ' sets \'status\' as text';
                }
                continue;
            }
            if (!in_array($text($token), $states, true)) {
                continue;
            }
            // An array key, or an index into something: a name that happens
            // to be a state's (a results count, a response field).
            if ($next?->is(T_DOUBLE_ARROW) && !self::isMatchArm($tokens, $i)) {
                continue;
            }
            $before = $tokens[$i - 2] ?? null;
            $index = $prev?->text === '[' && $next?->text === ']' && $before !== null
                && ($before->is([T_VARIABLE, T_STRING]) || in_array($before->text, [']', ')'], true));
            if ($index) {
                continue;
            }
            $found[] = 'line ' . $token->line . ' names the state ' . $token->text . ' as text';
        }

        return $found;
    }

    /**
     * The text literals among the arguments of each listJobs() call: the
     * states to list are SyncJobState cases.
     *
     * @param list<\PhpToken> $tokens
     * @return list<string>
     */
    private static function textInListJobsCalls(array $tokens): array
    {
        $found = [];
        foreach ($tokens as $i => $token) {
            if (!$token->is(T_STRING) || strcasecmp($token->text, 'listJobs') !== 0 || ($tokens[$i + 1] ?? null)?->text !== '(') {
                continue;
            }
            if (($tokens[$i - 1] ?? null)?->is(T_FUNCTION)) {
                continue;
            }
            $depth = 0;
            for ($j = $i + 1; isset($tokens[$j]); $j++) {
                $depth += match ($tokens[$j]->text) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };
                if ($depth === 0) {
                    break;
                }
                if ($tokens[$j]->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) || $tokens[$j]->text === '"') {
                    $found[] = 'line ' . $tokens[$j]->line . ' lists jobs by a state given as text';
                }
            }
        }

        return $found;
    }

    /**
     * Whether the `x =>` at $i is an arm of a match, not an array's key: the
     * nearest bracket left open before it is a match's brace.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function isMatchArm(array $tokens, int $i): bool
    {
        $depth = 0;
        for ($j = $i - 1; $j >= 0; $j--) {
            $t = $tokens[$j]->text;
            if ($t === ']' || $t === ')' || $t === '}') {
                $depth++;
            } elseif ($t === '[' || $t === '(' || $t === '{') {
                if ($depth === 0) {
                    if ($t !== '{') {
                        return false;
                    }
                    // match (...) {
                    $k = $j - 1;
                    $parens = 0;
                    for (; $k >= 0; $k--) {
                        if ($tokens[$k]->text === ')') {
                            $parens++;
                        } elseif ($tokens[$k]->text === '(') {
                            $parens--;
                            if ($parens === 0) {
                                break;
                            }
                        }
                    }

                    return $k > 0 && $tokens[$k - 1]->is(T_MATCH);
                }
                $depth--;
            }
        }

        return false;
    }

    /** @param list<string> $names */
    private static function names(string $source, array $names): bool
    {
        foreach (\PhpToken::tokenize($source) as $token) {
            if ($token->is(T_STRING) && in_array($token->text, $names, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A source's tokens by the named function they are in ('' outside any),
     * without whitespace and comments. A closure belongs to its function.
     *
     * @return array<string, list<\PhpToken>>
     */
    private static function functions(string $source): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $token): bool => !$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])
        ));
        $functions = [];
        $name = '';
        $depth = 0;
        $endsAt = null;
        foreach ($tokens as $i => $token) {
            if ($name === '' && $token->is(T_FUNCTION) && ($tokens[$i + 1] ?? null)?->is(T_STRING)) {
                $name = $tokens[$i + 1]->text;
                $endsAt = null;
            }
            $functions[$name][] = $token;
            if ($name === '') {
                continue;
            }
            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $endsAt ??= $depth;
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
                if ($depth === $endsAt) {
                    $name = '';
                }
            } elseif ($token->text === ';' && $endsAt === null) {
                $name = ''; // an abstract or interface method: no body
            }
        }

        return $functions;
    }
}
