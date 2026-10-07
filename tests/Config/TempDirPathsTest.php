<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * Every path the served tree builds in the system temp dir is listed here
 * with what makes it safe there.
 *
 * The temp dir is anyone's, and a name in it that the code can work out is a
 * name anyone can work out and take first: a directory another local user
 * made at the API state store's path decided what the install read -- an
 * idempotency record there replayed its planted response, and a staged
 * change there was listed to apply (ServerStateStore now uses such a
 * directory only when this process's user owns it alone). A name in the temp
 * dir is a claim about who made it, and only the owner and the mode are the
 * proof (CLAUDE.md #16).
 *
 * A use is a `sys_get_temp_dir()` call. The one argument of `tempnam()`
 * needs no listing: tempnam() makes a new file of its own. Anything else is
 * listed, file => how many and why.
 */
final class TempDirPathsTest extends TestCase
{
    /** @var array<string, array{int, string}> */
    private const LISTED = [
        'api/v3/Support/ServerStateStore.php' => [
            1,
            'the API state directory: privateTempDirectory() uses it only when it is a real directory this'
                . " process's user owns and its group and others cannot write, else a numbered alternative"
                . ' that is, or refuses',
        ],
        '202-config/License/ShellAccessCache.php' => [
            1,
            'the shell license cache: dir() skips caching unless the directory is not a link and is owned by'
                . " this process's user",
        ],
        '202-config/Messaging/mock-server.php' => [
            1,
            'a development mock of the messaging service, run by hand, never by an install',
        ],
        '202-config/connect2.php' => [
            1,
            'the opt-in click tracer (p202LogRedirectHit(), on only while 202-config/.click-debug exists):'
                . ' a fixed file name it appends to without checking who made it -- a known gap, its owner'
                . " is the click path's",
        ],
    ];

    public function testEveryTempDirPathIsListed(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            $count = count(self::uses($source));
            if ($count > 0) {
                $found[$path] = $count;
            }
        }
        $expected = array_map(static fn (array $entry): int => $entry[0], self::LISTED);
        ksort($expected);
        ksort($found);

        self::assertSame($expected, $found, "A path in the system temp dir is a name anyone can take first.\n"
            . 'Make a file with tempnam(), or check who owns what is there before using it, and list the use'
            . ' here with why it is safe.');
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function shapes(): iterable
    {
        yield 'a fixed name' => ['<?php $f = sys_get_temp_dir() . "/x.log";', 1];
        yield 'fully qualified' => ['<?php $f = \sys_get_temp_dir() . "/x";', 1];
        yield 'tempnam() makes its own file' => ['<?php $f = tempnam(sys_get_temp_dir(), "p");', 0];
        yield 'tempnam() of a subdirectory still names one' => [
            '<?php $f = tempnam(sys_get_temp_dir() . "/d", "p");',
            1,
        ];
        yield 'a method of that name is not it' => ['<?php $o->sys_get_temp_dir(); X::sys_get_temp_dir();', 0];
        yield 'a comment is not a use' => ["<?php // sys_get_temp_dir()\n", 0];
    }

    /** @dataProvider shapes */
    public function testTheShapesAUseTakes(string $source, int $expected): void
    {
        self::assertCount($expected, self::uses($source));
    }

    /**
     * Lines of the sys_get_temp_dir() calls that are not tempnam()'s whole
     * first argument.
     *
     * @return list<int>
     */
    private static function uses(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $lines = [];
        foreach ($tokens as $i => $token) {
            $name = is_array($token) && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                ? ltrim($token[1], '\\')
                : null;
            if ($name === null || strtolower($name) !== 'sys_get_temp_dir') {
                continue;
            }
            $before = $tokens[$i - 1] ?? null;
            $notTheFunction = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW];
            if (is_array($before) && in_array($before[0], $notTheFunction, true)) {
                continue;
            }
            if (($tokens[$i + 1] ?? null) !== '(' || ($tokens[$i + 2] ?? null) !== ')') {
                continue;
            }
            // tempnam(sys_get_temp_dir(), ...): the whole first argument.
            $callee = $tokens[$i - 2] ?? null;
            $tempnam = $before === '(' && is_array($callee) && strtolower(ltrim($callee[1], '\\')) === 'tempnam';
            if ($tempnam && ($tokens[$i + 3] ?? null) === ',') {
                continue;
            }
            $lines[] = $token[2];
        }

        return $lines;
    }
}
