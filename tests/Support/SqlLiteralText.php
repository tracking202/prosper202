<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A PHP file's SQL, rebuilt from its string literals in token order, for the
 * structural tests that ask "who writes this table".
 *
 * Every non-string token between two literals is read as a placeholder
 * (` ? `), so a statement split across concatenation or across `.=`
 * statements reads whole, and a table or column built at runtime shows up as
 * `?` — which the callers refuse or name rather than pass (CLAUDE.md #20: a
 * scanner that cannot see a construct must not answer "no such construct").
 * Comments are dropped; a `;` ends a statement unless the next statement
 * continues it with `$sql .=`.
 */
final class SqlLiteralText
{
    public static function of(string $source): string
    {
        $text = '';
        $tokens = token_get_all($source);
        $continuing = false;
        foreach ($tokens as $i => $token) {
            if ($continuing && is_array($token) && in_array($token[0], [T_VARIABLE, T_CONCAT_EQUAL], true)) {
                // The `$sql .=` that continues the statement is not SQL.
                $continuing = $token[0] !== T_CONCAT_EQUAL;
                continue;
            }
            if (is_array($token)) {
                [$id, $value] = $token;
                if ($id === T_CONSTANT_ENCAPSED_STRING) {
                    $text .= stripcslashes(substr($value, 1, -1));
                } elseif ($id === T_ENCAPSED_AND_WHITESPACE) {
                    $text .= $value;
                } elseif ($id === T_COMMENT || $id === T_DOC_COMMENT || $id === T_WHITESPACE || $id === T_OPEN_TAG) {
                    continue;
                } else {
                    $text .= ' ? ';
                }
            } elseif ($token === ';') {
                $continuing = self::continuesWithConcatAssign($tokens, $i);
                $text .= $continuing ? ' ' : ' ; ';
            } elseif ($token !== '.' && $token !== '"') {
                $text .= ' ? ';
            }
        }

        return $text;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function continuesWithConcatAssign(array $tokens, int $at): bool
    {
        $seen = [];
        for ($j = $at + 1, $n = count($tokens); $j < $n && count($seen) < 2; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $seen[] = $t;
        }

        return count($seen) === 2 && is_array($seen[0]) && $seen[0][0] === T_VARIABLE
            && is_array($seen[1]) && $seen[1][0] === T_CONCAT_EQUAL;
    }

    /**
     * Every PHP file of the application (not vendor, tests, docs, SDKs).
     *
     * @param list<string> $skipTopLevel
     * @return iterable<string>
     */
    public static function phpFiles(string $root, array $skipTopLevel = ['vendor', 'tests', '.git', '.claude', 'node_modules', 'go-cli', 'sdk', 'documentation', 'docs']): iterable
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file) use ($root, $skipTopLevel): bool {
                    if ($file->isDir()) {
                        return !in_array($file->getFilename(), $skipTopLevel, true)
                            || dirname($file->getPathname()) !== $root;
                    }
                    return $file->getExtension() === 'php';
                }
            )
        );
        foreach ($iterator as $file) {
            yield $file->getPathname();
        }
    }
}
