<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\TestCase;

/**
 * The setup wizard's config helpers (202-config/functions-setup-config.php).
 *
 * The legacy reader decides the one thing that passes the wizard's lock on
 * an installed instance — the rewrite of a pre-DB-class 202-config.php into
 * the current format (setup-config.php?step=1.1) — so it is held to error
 * pattern #11: a file it cannot be sure of is not legacy, and a current
 * configuration, or one an operator extended with code, never is. The
 * renderer and the replace are what both the wizard and the migration write
 * with; a written file must parse, carry every value back out unchanged, and
 * never leave a truncated or temporary file behind.
 *
 * tests/live/prelogin-pages.sh drives the migration and the lock over HTTP.
 */
final class SetupConfigHelpersTest extends TestCase
{
    private const LEGACY = "<?php\n// ** MySQL settings ** //\n\$dbname = 'p202'; // The name of the database\n"
        . "\$dbuser = 'p202user'; // Your MySQL username\n\$dbpass = 'it\\'s \\\\ secret'; // ...and password\n"
        . "\$dbhost = 'db.internal:3306'; // 99% chance you won't need to change this value\n\$mchost = 'cache.internal';\n";

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-setup-config.php';
    }

    private static function sample(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/202-config-sample.php');
    }

    public function testTheValuesOfALegacyFileRoundTripItsEscapes(): void
    {
        self::assertSame([
            'dbname' => 'p202', 'dbuser' => 'p202user', 'dbpass' => "it's \\ secret",
            'dbhost' => 'db.internal:3306', 'mchost' => 'cache.internal',
        ], p202_setup_config_values(self::LEGACY));
    }

    public function testALegacyFileIsLegacy(): void
    {
        self::assertTrue(p202_setup_config_is_legacy(self::LEGACY));
        self::assertTrue(p202_setup_config_is_legacy(self::LEGACY . "?>\n"), 'a closing tag and its newline are not code');
    }

    /** @return array<string, array{string}> */
    public static function notLegacy(): array
    {
        $legacy = self::LEGACY;
        return [
            'the current sample' => [(string) file_get_contents(dirname(__DIR__, 2) . '/202-config-sample.php')],
            'the staging sample' => [(string) file_get_contents(dirname(__DIR__, 2) . '/build/staging-config.sample.php')],
            'no database name' => [str_replace("\$dbname = 'p202';", '', $legacy)],
            'no host' => [str_replace("\$dbhost = 'db.internal:3306';", '', $legacy)],
            'a name in double quotes' => [str_replace("\$dbname = 'p202';", '$dbname = "p202";', $legacy)],
            'an include' => [$legacy . "include __DIR__ . '/more-config.php';\n"],
            'a require_once' => [$legacy . "require_once 'x.php';\n"],
            'an eval' => [$legacy . "eval('\$db = 1;');\n"],
            'a class' => [$legacy . "class Anything {}\n"],
            'a function' => [$legacy . "function f() {}\n"],
            'an arrow function' => [$legacy . "\$f = fn () => 1;\n"],
            'the DB class named' => [$legacy . "\$db = DB::getInstance();\n"],
            'DB in lower case' => [$legacy . "\$x = db();\n"],
            'a new' => [$legacy . "\$db = new mysqli(\$dbhost, \$dbuser, \$dbpass, \$dbname);\n"],
            'a qualified name' => [$legacy . "\$x = \\Other\\thing();\n"],
            'markup outside PHP' => [$legacy . "?>\n<p>hello</p>\n"],
            'empty' => [''],
            'not PHP' => ["dbname=p202\n"],
        ];
    }

    /** @dataProvider notLegacy */
    public function testAnythingElseIsNotLegacy(string $source): void
    {
        self::assertFalse(p202_setup_config_is_legacy($source));
    }

    public function testTheRenderedFileIsTheCurrentFormatWithTheSameValues(): void
    {
        $values = p202_setup_config_values(self::LEGACY) + ['dbhostro' => 'db.internal:3306'];
        $lines = preg_split('/(?<=\n)/', self::sample(), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $rendered = p202_setup_config_render($lines, $values);

        ksort($values);
        $back = p202_setup_config_values($rendered);
        ksort($back);
        self::assertSame($values, $back, 'every value comes back out unchanged, escapes included');
        self::assertFalse(p202_setup_config_is_legacy($rendered), 'what the migration writes is never legacy, so it cannot run twice');
        foreach (P202_SETUP_CONFIG_PLACEHOLDERS as $placeholder) {
            self::assertStringNotContainsString($placeholder, $rendered);
        }

        $file = tempnam(sys_get_temp_dir(), 'p202cfg');
        self::assertIsString($file);
        try {
            file_put_contents($file, $rendered);
            $out = [];
            $rc = 1;
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
            self::assertSame(0, $rc, 'the rendered file parses: ' . implode("\n", $out));
        } finally {
            unlink($file);
        }
    }

    public function testTheReplaceIsOneRenameAndLeavesNothingBehind(): void
    {
        $dir = sys_get_temp_dir() . '/p202-setup-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir));
        $path = $dir . '/202-config.php';
        try {
            file_put_contents($path, 'old');
            self::assertTrue(p202_setup_config_replace($path, "<?php\n\$dbname = 'new';\n"));
            self::assertSame("<?php\n\$dbname = 'new';\n", file_get_contents($path));
            self::assertSame(0640, fileperms($path) & 0777, 'owner/group readable only');
            self::assertSame(['202-config.php'], array_values(array_diff(scandir($dir) ?: [], ['.', '..'])), 'no temporary file is left');

            if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
                chmod($dir, 0500);
                try {
                    self::assertFalse(p202_setup_config_replace($path, 'newer'), 'a directory it cannot write says so');
                } finally {
                    chmod($dir, 0700);
                }
                self::assertSame("<?php\n\$dbname = 'new';\n", file_get_contents($path), 'and the old file is untouched');
            }
        } finally {
            foreach (array_merge(glob($dir . '/*') ?: [], glob($dir . '/.[!.]*') ?: []) as $leftover) {
                unlink($leftover);
            }
            rmdir($dir);
        }
    }
}
