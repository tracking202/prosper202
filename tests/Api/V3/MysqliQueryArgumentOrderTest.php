<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;

/**
 * `_mysqli_query()` takes its two arguments as ($db, $sql), and a call that
 * swaps them fails only when it runs.
 *
 * The helper (202-config/functions.php) has two calling conventions — one
 * argument, the SQL, against the global connection; or the connection and
 * then the SQL — and nothing typed tells them apart. The password-reset pair,
 * 202-lost-pass.php and 202-pass-reset.php, called `_mysqli_query($user_sql,
 * $db)` four times: the SQL was taken for the connection and every request
 * died with "Call to a member function getConnection() on string", so no
 * one could reset a password. Found in U7, only because a live pass requested
 * the page; no test did.
 *
 * So this reads every two-argument call in the tree and refuses the swapped
 * shape by the names it can see: a first argument that is SQL (a string
 * literal, an interpolated string, or a variable named for SQL or a query),
 * or a second argument that is a connection variable ($db, $conn,
 * $connection, $link, $mysqli). A call whose arguments are expressions this
 * cannot name is not judged either way.
 */
final class MysqliQueryArgumentOrderTest extends TestCase
{
    private const SKIP_DIRS = ['vendor', 'node_modules', 'tests', '.git', 'go-cli'];

    /** @return list<string> */
    private static function phpFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $file): bool => !in_array($file->getFilename(), self::SKIP_DIRS, true)
        ));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($files);
        return $files;
    }

    /**
     * The arguments of each `_mysqli_query(` call in $source, as lists of
     * significant tokens, with the line the call is on.
     *
     * @return list<array{line: int, args: list<list<\PhpToken>>}>
     */
    private static function calls(string $source): array
    {
        $tokens = array_values(array_filter(\PhpToken::tokenize($source), static fn (\PhpToken $t): bool => !$t->isIgnorable()));
        $calls = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!$tokens[$i]->is(T_STRING) || $tokens[$i]->text !== '_mysqli_query' || ($tokens[$i + 1]->text ?? '') !== '(') {
                continue;
            }
            $before = $tokens[$i - 1] ?? null;
            if ($before !== null && ($before->is([T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON]))) {
                continue;
            }
            $args = [[]];
            $depth = 0;
            for ($j = $i + 2; $j < $count; $j++) {
                $text = $tokens[$j]->text;
                if (in_array($text, ['(', '[', '{'], true)) {
                    $depth++;
                } elseif (in_array($text, [')', ']', '}'], true)) {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif ($text === ',' && $depth === 0) {
                    $args[] = [];
                    continue;
                }
                $args[count($args) - 1][] = $tokens[$j];
            }
            $calls[] = ['line' => $tokens[$i]->line, 'args' => $args];
        }
        return $calls;
    }

    /** @param list<\PhpToken> $arg */
    private static function looksLikeSql(array $arg): bool
    {
        if ($arg === []) {
            return false;
        }
        if ($arg[0]->is([T_CONSTANT_ENCAPSED_STRING, T_START_HEREDOC]) || $arg[0]->text === '"') {
            return true;
        }
        return count($arg) === 1 && $arg[0]->is(T_VARIABLE) && preg_match('/sql|query/i', $arg[0]->text) === 1;
    }

    /** @param list<\PhpToken> $arg */
    private static function looksLikeConnection(array $arg): bool
    {
        return count($arg) === 1 && $arg[0]->is(T_VARIABLE)
            && in_array(strtolower($arg[0]->text), ['$db', '$conn', '$connection', '$link', '$mysqli'], true);
    }

    public function testNoCallPassesTheSqlWhereTheConnectionGoes(): void
    {
        $root = dirname(__DIR__, 3);
        $swapped = [];
        $twoArgument = 0;
        foreach (self::phpFiles($root) as $file) {
            $source = (string) file_get_contents($root . '/' . $file);
            if (!str_contains($source, '_mysqli_query')) {
                continue;
            }
            foreach (self::calls($source) as $call) {
                if (count($call['args']) !== 2) {
                    continue;
                }
                $twoArgument++;
                [$first, $second] = $call['args'];
                if (self::looksLikeSql($first) || self::looksLikeConnection($second)) {
                    $swapped[] = $file . ':' . $call['line'];
                }
            }
        }
        self::assertGreaterThan(3, $twoArgument, 'the two-argument calls were found (a scan that finds none passes vacuously)');
        self::assertSame([], $swapped, "_mysqli_query() takes (\$db, \$sql); these calls pass the SQL first:\n" . implode("\n", $swapped));
    }

    public function testTheScanSeesTheSwappedShapes(): void
    {
        $planted = "<?php\n_mysqli_query(\$user_sql, \$db);\n_mysqli_query(\"SELECT 1\", \$conn);\n_mysqli_query('SELECT 1', \$x);\n_mysqli_query(\$q, \$db);\n_mysqli_query(\$db, \$sql);\n_mysqli_query(\$this->connection, \$sql);\n\$o->_mysqli_query(\$sql, \$db);\n";
        $flagged = [];
        foreach (self::calls($planted) as $call) {
            if (count($call['args']) === 2 && (self::looksLikeSql($call['args'][0]) || self::looksLikeConnection($call['args'][1]))) {
                $flagged[] = $call['line'];
            }
        }
        self::assertSame([2, 3, 4, 5], $flagged, 'each swapped spelling is refused, the right order and a method of that name are not');
    }
}
