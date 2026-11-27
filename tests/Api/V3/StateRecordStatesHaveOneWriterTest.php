<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Support\StagedChangeStatus;
use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * A staged change's status and an Idempotency-Key record's state are written
 * in one place each, ServerStateStore, which asks the transition table under
 * the lock that re-read the record (StagedApplyOutlivesItsClaimTest and
 * IdempotencyRecordMovesTest execute that). This holds the rest of the tree
 * to going through it: nobody else opens the files, nobody but the listed
 * callers asks for a write, and the owners type no state by hand.
 *
 * Read from every token of every production file, so there is no call shape
 * to miss. Does not see: a method called by a name built at run time, a
 * path assembled from pieces ('staged' . '_changes'), a state used as an
 * array key, or anything that is not PHP.
 */
final class StateRecordStatesHaveOneWriterTest extends TestCase
{
    private const STORE = 'api/v3/Support/ServerStateStore.php';
    private const STAGED_CONTROLLER = 'api/v3/Controllers/StagedChangesController.php';
    private const RECORD_STATE = 'api/v3/Support/IdempotencyRecordState.php';

    /** Who may ask the store for each write. */
    private const CALLERS = [
        'stageWriteChange' => [self::STAGED_CONTROLLER],
        'moveStagedChange' => [self::STAGED_CONTROLLER],
        'updateStagedChange' => [self::STAGED_CONTROLLER],
        'claimIdempotent' => ['api/v3/Controller.php', 'api/v3/Support/IdempotentCreate.php'],
        'reserveIdempotent' => [],
        'releaseIdempotent' => ['api/v3/Controller.php', 'api/v3/Support/IdempotentCreate.php'],
        'markIdempotentIndeterminate' => ['api/v3/Controller.php', 'api/v3/Support/IdempotentCreate.php'],
        // SyncController records after a read, with no claim: the move the
        // table lists as free -> committed.
        'putIdempotent' => [
            'api/v3/Controller.php', 'api/v3/Support/IdempotentCreate.php', 'api/v3/Controllers/SyncController.php',
        ],
        'moveIdempotentRecord' => [],
    ];

    /** The store's functions that open each kind of file for writing. */
    private const FILE_WRITERS = [
        'stagedChangesPath' => ['stageWriteChange', 'updateStagedChange'],
        'idempotencyPath' => ['claimIdempotent', 'moveIdempotentRecord'],
    ];

    public function testOnlyTheStoreNamesTheFilesTheRecordsLiveIn(): void
    {
        $named = [];
        foreach (SourceScan::phpFiles() as $file => $source) {
            foreach (self::strings($source) as [$text, $line]) {
                if (str_contains($text, 'staged_changes') || $text === 'idempotency' || str_contains($text, 'idempotency/')) {
                    $named[$file] = true;
                }
            }
        }
        self::assertSame([
            // The restore notice's wording for each kind of record: it opens no file.
            '202-config/functions-restore-notice-ui.php',
            self::STORE,
        ], array_keys($named));
    }

    public function testEveryWriteIsAskedForByAListedCaller(): void
    {
        $unlisted = [];
        $seen = [];
        foreach (SourceScan::phpFiles() as $file => $source) {
            foreach (\PhpToken::tokenize($source) as $token) {
                if (!$token->is(T_STRING) || !isset(self::CALLERS[$token->text])) {
                    continue;
                }
                $seen[$token->text] = true;
                if ($file !== self::STORE && !in_array($file, self::CALLERS[$token->text], true)) {
                    $unlisted[] = "$file:{$token->line} names {$token->text}()";
                }
            }
        }
        self::assertSame([], $unlisted, implode("\n", $unlisted));
        self::assertCount(count(self::CALLERS), $seen, 'a writer the list names is gone: the scan reads names, so the list is wrong');
    }

    public function testTheStoreOpensEachFileForWritingInItsListedFunctionsOnly(): void
    {
        $source = SourceScan::phpFiles()[self::STORE];
        foreach (self::FILE_WRITERS as $path => $expected) {
            $writers = [];
            foreach (self::tokensByFunction($source) as $function => $names) {
                if (in_array($path, $names, true) && in_array('mutateJsonFile', $names, true) && $function !== $path) {
                    $writers[] = $function;
                }
            }
            sort($writers);
            sort($expected);
            self::assertSame($expected, $writers, "$path() is passed to mutateJsonFile() here");
        }
    }

    public function testTheOwnersTypeNoStagedStatusByHand(): void
    {
        $states = StagedChangeStatus::values();
        $typed = [];
        foreach ([self::STORE, self::STAGED_CONTROLLER] as $file) {
            $tokens = self::significant(SourceScan::phpFiles()[$file]);
            foreach ($tokens as $i => $token) {
                if (!$token->is(T_CONSTANT_ENCAPSED_STRING)) {
                    continue;
                }
                $text = substr($token->text, 1, -1);
                $next = $tokens[$i + 1] ?? null;
                // An array key is a field's name (the `staged` request flag), not a status.
                if (in_array($text, $states, true) && !($next !== null && $next->is(T_DOUBLE_ARROW))) {
                    $typed[] = "$file:{$token->line} types {$token->text}";
                }
                if ($file === self::STAGED_CONTROLLER && $text === 'status'
                    && $next?->text === ']' && ($tokens[$i + 2] ?? null)?->text === '=') {
                    $typed[] = "$file:{$token->line} assigns ['status'] itself";
                }
            }
        }
        self::assertSame([], $typed, implode("\n", $typed));
    }

    public function testOnlyTheRecordStateReadsAndWritesTheIdempotencyFields(): void
    {
        $elsewhere = [];
        foreach (SourceScan::phpFiles() as $file => $source) {
            if ($file === self::RECORD_STATE || $file === 'api/v3/Support/IdempotencyAnswer.php') {
                continue;
            }
            foreach (self::strings($source) as [$text, $line]) {
                if ($text === 'claimed_at' || $text === 'indeterminate') {
                    $elsewhere[] = "$file:$line types '$text'";
                }
            }
        }
        self::assertSame([], $elsewhere, implode("\n", $elsewhere));

        // `response` and `fingerprint` are common words, so they are held
        // where the records are handled: the store reads them through the enum.
        $inStore = [];
        foreach (self::tokensByFunction(SourceScan::phpFiles()[self::STORE], true) as $function => $texts) {
            if (!str_contains($function, 'Idempoten')) {
                continue;
            }
            foreach (['response', 'fingerprint', 'stored_at'] as $field) {
                if (in_array($field, $texts, true) && $function !== 'pruneIdempotencyItems') {
                    $inStore[] = "$function() types '$field'";
                }
            }
        }
        self::assertSame([], $inStore, implode("\n", $inStore));
    }

    /**
     * Every string the source holds, as [text, line]: literals without their
     * quotes, and the literal parts of interpolated strings and heredocs.
     *
     * @return list<array{string, int}>
     */
    private static function strings(string $source): array
    {
        $strings = [];
        foreach (\PhpToken::tokenize($source) as $token) {
            if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                $strings[] = [substr($token->text, 1, -1), $token->line];
            } elseif ($token->is(T_ENCAPSED_AND_WHITESPACE)) {
                $strings[] = [$token->text, $token->line];
            }
        }

        return $strings;
    }

    /** @return list<\PhpToken> */
    private static function significant(string $source): array
    {
        return array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])
        ));
    }

    /**
     * The names (or, with $literals, the string literals) each named function
     * of a class file holds. Methods do not nest, so a token belongs to the
     * last `function name` before it; closures are part of their method.
     *
     * @return array<string, list<string>>
     */
    private static function tokensByFunction(string $source, bool $literals = false): array
    {
        $tokens = self::significant($source);
        $current = '';
        $byFunction = [];
        foreach ($tokens as $i => $token) {
            if ($token->is(T_FUNCTION) && ($tokens[$i + 1] ?? null)?->is(T_STRING)) {
                $current = $tokens[$i + 1]->text;
            }
            if ($current !== '' && $token->is($literals ? T_CONSTANT_ENCAPSED_STRING : T_STRING)) {
                $byFunction[$current][] = $literals ? substr($token->text, 1, -1) : $token->text;
            }
        }
        self::assertNotSame([], $byFunction, 'the scan read no function');

        return $byFunction;
    }
}
