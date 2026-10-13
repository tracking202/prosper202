<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;

/**
 * Every endpoint a Setup page posts to asks for the Setup section's
 * permission, as the page does (CLAUDE.md #5).
 *
 * The pages refuse a role without access_to_setup_section; the ajax files
 * they post to asked for nothing beyond a signed-in session and its token,
 * so a Campaign viewer could create trackers (generate_tracking_link.php),
 * rewrite redirectors (rotator.php) or a traffic source's variables
 * (custom_variables.php) by posting to them directly.
 *
 * What it reads: every `ajax/<name>.php` the files under tracking202/setup
 * name, and in each, the statement right after the top-level
 * `AUTH::require_user();`. That statement must be a call of
 * `AUTH::require_permissions(...)` whose arguments are string literals
 * including access_to_setup_section (and, for the variables endpoint,
 * remove_traffic_source, the gate its button sits behind). "Right after, at
 * the top level" is the whole claim: nothing can run between the session
 * check and the permission check, and no condition can skip it. A file with
 * no top-level `AUTH::require_user();`, or with more than one, is refused
 * rather than guessed at. The helper itself is executed in a child process
 * with a role that lacks the permission and with one that has it.
 */
final class SetupAjaxRequiresPermissionTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    /** Endpoints whose page gates them by more than the section. */
    private const ALSO = [
        'custom_variables.php' => ['remove_traffic_source'],
    ];

    /** @return list<string> */
    private static function endpoints(): array
    {
        $names = [];
        $files = new \RecursiveIteratorIterator(\Tests\Support\SourceScan::tree(self::ROOT . 'tracking202/setup'));
        foreach ($files as $file) {
            if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'js'], true)) {
                continue;
            }
            if (preg_match_all('#ajax/([a-z0-9_]+\.php)#', (string) file_get_contents($file->getPathname()), $m) > 0) {
                array_push($names, ...$m[1]);
            }
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    public function testEverySetupEndpointAsksForTheSectionFirst(): void
    {
        $endpoints = self::endpoints();
        self::assertGreaterThanOrEqual(7, count($endpoints), 'the scan found too few Setup endpoints: it is blind, not the tree clean (' . implode(', ', $endpoints) . ')');
        foreach ($endpoints as $name) {
            $path = self::ROOT . 'tracking202/ajax/' . $name;
            self::assertFileExists($path, "a Setup page posts to ajax/$name");
            $asked = self::permissionsAskedFirst((string) file_get_contents($path), $name);
            $need = array_merge(['access_to_setup_section'], self::ALSO[$name] ?? []);
            self::assertSame([], array_values(array_diff($need, $asked)), "ajax/$name must call AUTH::require_permissions(" . implode(', ', array_map(static fn ($p) => "'$p'", $need)) . ') right after AUTH::require_user();');
        }
    }

    /**
     * The permissions named by the statement right after the one top-level
     * `AUTH::require_user();`, or a failure saying why it cannot be read.
     *
     * @return list<string>
     */
    private static function permissionsAskedFirst(string $source, string $name): array
    {
        $tokens = array_values(array_filter(token_get_all($source), static fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $text = static fn ($t): string => is_array($t) ? $t[1] : $t;
        $depth = 0;
        $after = [];
        foreach ($tokens as $i => $t) {
            $s = $text($t);
            if ($s === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($s === '}') {
                $depth--;
            }
            if ($depth === 0 && strcasecmp($s, 'AUTH') === 0
                && $text($tokens[$i + 1] ?? '') === '::' && strcasecmp($text($tokens[$i + 2] ?? ''), 'require_user') === 0
                && $text($tokens[$i + 3] ?? '') === '(' && $text($tokens[$i + 4] ?? '') === ')' && $text($tokens[$i + 5] ?? '') === ';'
                && !in_array($text($tokens[$i - 1] ?? ''), ['\\', '->', '::', 'new', 'function'], true)) {
                $after[] = $i + 6;
            }
        }
        self::assertCount(1, $after, "ajax/$name: expected exactly one top-level `AUTH::require_user();`, found " . count($after));
        $i = $after[0];
        $call = array_map($text, array_slice($tokens, $i, 4));
        self::assertSame(['AUTH', '::', 'require_permissions', '('], $call, "ajax/$name: the statement after AUTH::require_user(); is not AUTH::require_permissions(...)");
        $asked = [];
        for ($j = $i + 4; $j < count($tokens); $j++) {
            $s = $text($tokens[$j]);
            if ($s === ')') {
                self::assertSame(';', $text($tokens[$j + 1] ?? ''), "ajax/$name: AUTH::require_permissions(...) is not a statement of its own");

                return $asked;
            }
            if ($s === ',') {
                continue;
            }
            self::assertTrue(is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING, "ajax/$name: require_permissions() takes string literals only, got `$s`");
            $asked[] = substr($s, 1, -1);
        }
        self::fail("ajax/$name: AUTH::require_permissions( is never closed");
    }

    /** The helper refuses a role without the permission, by name, and lets one with it through. */
    public function testTheHelperRefusesByNameAndPassesARoleThatHasIt(): void
    {
        $auth = realpath(self::ROOT . '202-config/functions-auth.php');
        foreach ([[[], 'refused'], [['access_to_setup_section', 'remove_traffic_source'], 'passed']] as [$held, $want]) {
            $script = '<?php class User { public function __construct(private array $p) {} public function hasPermission($x) { return in_array($x, $this->p, true); } }'
                . ' require ' . var_export($auth, true) . ';'
                . ' $userObj = new User(' . var_export($held, true) . ');'
                . " AUTH::require_permissions('access_to_setup_section', 'remove_traffic_source'); echo 'passed';";
            $file = tempnam(sys_get_temp_dir(), 'p202perm');
            file_put_contents($file, $script);
            $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
            unlink($file);
            if ($want === 'passed') {
                self::assertSame('passed', trim($out), 'a role with both permissions is let through');
            } else {
                self::assertSame("This account's role does not have the 'access_to_setup_section' permission.", trim($out), 'a role without the section is refused by name');
            }
        }
    }
}
