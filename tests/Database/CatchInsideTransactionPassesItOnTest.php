<?php

declare(strict_types=1);

namespace Tests\Database;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * A catch inside a transaction's callback passes on what it caught.
 *
 * A deadlock rolls the whole transaction back on the server, and the
 * statements after it on the handle then commit one by one, outside any.
 * Bulk-upsert wrote its rows in one transaction() whose callback caught each
 * row's throwable and went on to the next row: a deadlock was answered as one
 * row's error, the rows before it as created with nothing of them stored, and
 * the rows after it were stored outside the transaction. So a catch written
 * in the callback of transaction() or joinTransaction() holds a `throw`, ends
 * the request (die, exit, record_mysql_error(), _die()), or catches only types
 * a database failure never is. A row that may fail on its own goes through
 * withSavepoint(), and the catch around it rethrows lock errors.
 *
 * Read lexically, in the call's own parentheses: a catch in a function the
 * callback calls is not seen, nor is one that rethrows only part of what it
 * caught. Each listed catch says why it may swallow; the list only shrinks.
 */
final class CatchInsideTransactionPassesItOnTest extends TestCase
{
    /** Types a database failure (mysqli_sql_exception, QueryException) never is. */
    private const NOT_DATABASE = [
        'error', 'typeerror', 'valueerror', 'argumentcounterror', 'jsonexception',
        'ltvinputexception', 'validationexception', 'notfoundexception', 'nothingtoupdateexception',
    ];

    private const NAMES = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

    /** file => the caught types of each catch that may swallow, for the reason given */
    private const ALLOWED = [
        // The update's own WriteCommittedException: committed with the
        // transaction and thrown after it. parent::update()'s steps after its
        // write are a read and the change feed's file, so no lock error is
        // inside it.
        'api/v3/Controllers/AppRegistrationsController.php' => ['WriteCommittedException'],
    ];

    public function testACatchInATransactionCallbackPassesOnWhatItCaught(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $file => $source) {
            foreach (self::swallowingCatches($source) as $types) {
                $found[$file][] = $types;
            }
        }

        self::assertSame(self::ALLOWED, $found, 'A catch in a transaction() or joinTransaction() callback'
            . ' swallows what it caught: a deadlock there has already rolled the transaction back, and what runs next'
            . ' commits on its own. Rethrow it, or undo the step alone with Connection::withSavepoint() and'
            . ' rethrow lock errors (Connection::isRetryableLockError()).');
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function shapes(): iterable
    {
        $call = static fn (string $body): string => '<?php $conn->transaction(function () use ($rows) {'
            . ' foreach ($rows as $row) { try { write($row); } ' . $body . ' } });';
        yield 'a catch that goes on' => [
            $call('catch (\Throwable $e) { $errors[] = $e; continue; }'),
            ['Throwable'],
        ];
        yield 'a union' => [
            $call('catch (QueryException | \mysqli_sql_exception $e) { }'),
            ['QueryException|mysqli_sql_exception'],
        ];
        yield 'joinTransaction' => [
            '<?php $this->conn?->joinTransaction(fn () => (function () { try { f(); } catch (\Exception) { } })());',
            ['Exception'],
        ];
        yield 'one of two' => [
            $call('catch (\RuntimeException $e) { log($e); } catch (\Error $e) { throw $e; }'),
            ['RuntimeException'],
        ];
        yield 'a string piece that reads as a bracket' => [
            '<?php $conn->transaction(function () { $s = "a ) b {$x} }"; try { f(); } catch (\Throwable) { } });',
            ['Throwable'],
        ];
        yield 'a rethrow' => [$call('catch (\Throwable $e) { if (!ok($e)) { throw $e; } }'), []];
        yield 'the request ends' => [$call('catch (\Throwable $e) { record_mysql_error($db, $sql); }'), []];
        yield 'die' => [$call('catch (\Throwable $e) { die("x"); }'), []];
        yield 'not a database failure' => [$call('catch (\Error | LtvInputException $e) { $n = 0; }'), []];
        yield 'outside the call' => [
            '<?php $conn->transaction(fn () => 1); try { f(); } catch (\Throwable) { }',
            [],
        ];
        yield 'a declaration' => ['<?php function transaction($f) { try { $f(); } catch (\Throwable) { } }', []];
    }

    /**
     * @dataProvider shapes
     * @param list<string> $expected
     */
    public function testTheScanReadsEachShape(string $source, array $expected): void
    {
        self::assertSame($expected, self::swallowingCatches($source));
    }

    /**
     * The caught types (`A|B`, as written less any leading `\`) of each catch
     * in a transaction() or joinTransaction() call's parentheses that holds no
     * throw and does not end the request.
     *
     * @return list<string>
     */
    private static function swallowingCatches(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $found = [];
        $count = count($tokens);
        for ($i = 1; $i < $count - 1; $i++) {
            if (!self::isTransactionCall($tokens, $i)) {
                continue;
            }
            $end = self::closing($tokens, $i + 1, '(', ')');
            for ($c = $i + 2; $c < $end; $c++) {
                if (!is_array($tokens[$c]) || $tokens[$c][0] !== T_CATCH) {
                    continue;
                }
                $typesEnd = self::closing($tokens, $c + 1, '(', ')');
                $types = [];
                for ($t = $c + 2; $t < $typesEnd; $t++) {
                    $name = $tokens[$t];
                    if (is_array($name) && in_array($name[0], self::NAMES, true)) {
                        $types[] = ltrim($name[1], '\\');
                    }
                }
                $bodyEnd = self::closing($tokens, $typesEnd + 1, '{', '}');
                if (self::passesItOn($tokens, $typesEnd + 1, $bodyEnd) || self::notDatabase($types)) {
                    continue;
                }
                $found[] = implode('|', $types);
            }
        }

        return $found;
    }

    /**
     * Whether the token at $i names a transaction() or joinTransaction()
     * method call.
     *
     * @param list<mixed> $tokens
     */
    private static function isTransactionCall(array $tokens, int $i): bool
    {
        $name = $tokens[$i];
        $before = $tokens[$i - 1];

        return is_array($name) && $name[0] === T_STRING
            && in_array(strtolower($name[1]), ['transaction', 'jointransaction'], true)
            && is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            && $tokens[$i + 1] === '(';
    }

    /** @param list<mixed> $tokens */
    private static function passesItOn(array $tokens, int $from, int $to): bool
    {
        for ($k = $from; $k < $to; $k++) {
            $token = $tokens[$k];
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_THROW || $token[0] === T_EXIT) {
                return true;
            }
            $endsTheRequest = $token[0] === T_STRING
                && in_array(strtolower($token[1]), ['record_mysql_error', '_die'], true);
            if ($endsTheRequest && ($tokens[$k + 1] ?? null) === '(') {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $types */
    private static function notDatabase(array $types): bool
    {
        foreach ($types as $type) {
            $short = strtolower(substr($type, (int) strrpos('\\' . $type, '\\')));
            if (!in_array($short, self::NOT_DATABASE, true)) {
                return false;
            }
        }

        return $types !== [];
    }

    /** @param list<mixed> $tokens */
    private static function closing(array $tokens, int $open, string $opening, string $closing): int
    {
        $depth = 0;
        $count = count($tokens);
        for ($k = $open; $k < $count; $k++) {
            $token = $tokens[$k];
            // A piece of a string is text, whatever it reads: only `{$` and
            // `${` open a brace inside one.
            if (is_array($token)) {
                if ($opening === '{' && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;
                }
                continue;
            }
            if ($token === $opening) {
                $depth++;
            } elseif ($token === $closing && --$depth === 0) {
                return $k;
            }
        }

        throw new \RuntimeException('Unbalanced ' . $opening . ' at token ' . $open);
    }
}
