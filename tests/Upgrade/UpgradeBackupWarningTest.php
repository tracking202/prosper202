<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use Tests\TestCase;

/**
 * The upgrade is one-way (plan §7.5a): every page that starts a database
 * upgrade must say, before its button, that a database backup is required and
 * that restoring it is the only way back — inside the form that posts, so no
 * branch of the page renders the button without it — as the kit's warning
 * flash, parts included (CLAUDE.md #19). RELEASING.md says the same.
 *
 * The words live in one place, p202_upgrade_backup_warning(), which is pure
 * and so is executed here rather than read. The 1-click pages used to carry
 * their own sentence ("back up your database before upgrading"), which read
 * as a precaution; a page that starts an upgrade and is not in PAGES fails
 * testEveryCallerOfTheLadderIsAPageHereOrListedWithItsReason, so a fourth one
 * cannot ship its own wording either.
 * tests/live/android-abuse-limits.sh reads the rendered upgrade page.
 */
final class UpgradeBackupWarningTest extends TestCase
{
    /** Every page that starts a database upgrade from a form a person submits. */
    private const PAGES = [
        '202-config/upgrade.php',
        '202-account/auto-upgrade.php',
        '202-account/auto-upgrade-premium.php',
    ];

    /**
     * Calls of the ladder that are not a page with a button, and why.
     *
     * functions.php: update_needed() would replace the files and run the
     * ladder with no form to warn in, when the version feed advertises the
     * release one patch above the running one and marks it autoupgrade. It
     * cannot today, because nothing calls it:
     * 202-account/ajax/check-for-update.php did up to 1.9.28 and has called
     * check_premium_update() since 1.9.55. Called, it would still find no
     * release: it reads items[0] from premium-p202/version, which serves a
     * flat {"version": ...} object (fetched 2026-10-03). The call site is
     * still there, so the file is listed, and testNothingCallsUpdateNeeded
     * holds this reason to the tree.
     */
    private const NO_FORM = [
        '202-config/functions.php',
    ];

    private const CALL = '<?php echo p202_upgrade_backup_warning((string) PROSPER202::prosper202_version()); ?>';

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function source(string $path): string
    {
        $text = file_get_contents(self::root() . '/' . $path);
        self::assertIsString($text);

        return $text;
    }

    public function testTheWarningSaysTheUpgradeIsOneWayInTheKitsFlash(): void
    {
        require_once self::root() . '/202-config/functions-ui.php';

        foreach (['1.9.55', '1.9.75', '1.9.76', '1.9.77', '2.0.0'] as $from) {
            $html = p202_upgrade_backup_warning($from);
            self::assertStringStartsWith(
                '<div class="alert alert-warning p202-flash" role="status" id="upgrade-backup-warning">'
                . '<i class="bi bi-exclamation-triangle"></i><div class="p202-flash__body">',
                $html,
                "the kit's warning flash: its icon, then its body (from $from)"
            );
            self::assertStringEndsWith('</div></div>', $html);
            $sentences = [
                'Back up your database before you press the button.',
                'restoring that backup is the only way back',
            ];
            foreach ($sentences as $sentence) {
                self::assertStringContainsString($sentence, $html, "from $from");
            }
            // Why the old files are no rollback, only where it is true.
            self::assertSame(
                version_compare($from, '1.9.76', '<'),
                str_contains($html, '<code>202_conversion_logs.dedupe_key</code> required (NOT NULL)'),
                "the dedupe_key clause from $from"
            );
        }
    }

    public function testEveryPageShowsItInTheFormThatPostsDirectlyAboveItsButton(): void
    {
        foreach (self::PAGES as $path) {
            $page = self::source($path);
            self::assertSame(1, substr_count($page, 'id="upgrade-submit"'), "$path: one upgrade button");
            self::assertSame(1, substr_count($page, 'p202_upgrade_backup_warning('), "$path: one warning");
            $ownWording = 'Back up your database before upgrading';
            self::assertStringNotContainsString($ownWording, $page, "$path: not its own wording");

            $button = strpos($page, 'id="upgrade-submit"');
            self::assertIsInt($button);
            $form = strrpos(substr($page, 0, $button), '<form method="post"');
            self::assertIsInt($form, "$path: the button is in a form that posts");
            self::assertFalse(strpos(substr($page, $form, $button - $form), '</form>'), "$path: the same form");

            $call = strpos($page, self::CALL, $form);
            self::assertIsInt($call, "$path: the warning, called exactly so, inside that form");
            self::assertLessThan($button, $call, "$path: above the button");

            // Nothing between the warning and the button can skip or hide it:
            // whitespace, and at most the actions wrapper the button sits in.
            $between = substr($page, $call + strlen(self::CALL), $button - $call - strlen(self::CALL));
            self::assertMatchesRegularExpression(
                '#^\s*(<div class="p202-form-actions">\s*)?<button [^>]*$#',
                $between,
                "$path: only the button's own markup follows the warning"
            );
        }
    }

    /**
     * The tokens of every PHP file the app serves or runs, by path.
     *
     * @return iterable<string, list<array{int, string, int}|string>>
     */
    private static function appSources(): iterable
    {
        $files = new \RecursiveIteratorIterator(
            \Tests\Support\SourceScan::tree(self::root())
        );
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $path = substr($file->getPathname(), strlen(self::root()) + 1);
            if ($file->getExtension() !== 'php' || preg_match('#^(vendor|tests|node_modules|\.git)/#', $path) === 1) {
                continue;
            }
            yield $path => token_get_all((string) file_get_contents($file->getPathname()));
        }
    }

    /**
     * The nearest token from $i in $step's direction that is not whitespace or
     * a comment, as lower-case text ('' at either end of the file).
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function neighbour(array $tokens, int $i, int $step): string
    {
        for ($j = $i + $step; isset($tokens[$j]); $j += $step) {
            $token = $tokens[$j];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return strtolower(is_array($token) ? $token[1] : $token);
        }

        return '';
    }

    /**
     * The value of a T_CONSTANT_ENCAPSED_STRING, escapes decoded as PHP does:
     * '\update_needed', '\\update_needed' and "\x75pdate_needed" are each a
     * name call_user_func() resolves. Such a token carries no interpolation,
     * so every escape is one of these; any other backslash is kept.
     */
    private static function literalValue(string $literal): string
    {
        $literal = ltrim($literal, 'bB');
        $body = substr($literal, 1, -1);
        if ($literal[0] === "'") {
            return (string) preg_replace('/\\\\([\\\\\'])/', '$1', $body);
        }
        $simple = ['n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\e", 'f' => "\f",
            '\\' => '\\', '$' => '$', '"' => '"'];

        return (string) preg_replace_callback(
            '/\\\\(?:([nrtvef\\\\$"])|([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]+)\})/',
            static function (array $m) use ($simple): string {
                if (($m[1] ?? '') !== '') {
                    return $simple[$m[1]];
                }
                if (($m[2] ?? '') !== '') {
                    return chr(octdec($m[2]) & 0xFF);
                }
                if (($m[3] ?? '') !== '') {
                    return chr(hexdec($m[3]));
                }
                // UTF-8 by hand: PHP encodes a surrogate too, where mb_chr()
                // returns false.
                $cp = (int) hexdec($m[4]);
                if ($cp < 0x80) {
                    return chr($cp);
                }
                if ($cp < 0x800) {
                    return chr(0xC0 | $cp >> 6) . chr(0x80 | $cp & 0x3F);
                }
                if ($cp < 0x10000) {
                    return chr(0xE0 | $cp >> 12) . chr(0x80 | $cp >> 6 & 0x3F) . chr(0x80 | $cp & 0x3F);
                }

                return chr(0xF0 | $cp >> 18) . chr(0x80 | $cp >> 12 & 0x3F)
                    . chr(0x80 | $cp >> 6 & 0x3F) . chr(0x80 | $cp & 0x3F);
            },
            $body
        );
    }

    public function testEveryCallerOfTheLadderIsAPageHereOrListedWithItsReason(): void
    {
        $callers = [];
        foreach (self::appSources() as $path => $tokens) {
            foreach ($tokens as $i => $token) {
                // Any class's upgrade_databases, called statically: a wider net
                // than UPGRADE:: alone, so a lookalike shows up here instead
                // of passing unseen (CLAUDE.md #21).
                if (is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'upgrade_databases') {
                    $prev = $i - 1;
                    while (is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                        $prev--;
                    }
                    if (is_array($tokens[$prev]) && $tokens[$prev][0] === T_DOUBLE_COLON) {
                        $callers[$path] = true;
                    }
                }
            }
        }
        $callers = array_keys($callers);
        sort($callers);

        $expected = array_merge(self::PAGES, self::NO_FORM);
        sort($expected);
        self::assertSame(
            $expected,
            $callers,
            'a page that starts an upgrade shows the warning (add it to PAGES), or is listed with its reason'
        );
    }

    /**
     * NO_FORM's reason for functions.php, held to the tree. A call by name
     * (bare, \-qualified, namespace\-relative or imported with use function)
     * or a quoted string whose value names it, with or without the leading
     * backslash and however its escapes spell it (a callable for
     * call_user_func(), array_map(), ...), is a use; a quoted string that is
     * an array key or a subscript is the session flag of the same name. A
     * name assembled at runtime, or written inside a heredoc or an
     * interpolated string, is not seen.
     */
    public function testNothingCallsUpdateNeeded(): void
    {
        $names = ['update_needed', '\\update_needed', 'namespace\\update_needed'];
        $uses = [];
        foreach (self::appSources() as $path => $tokens) {
            foreach ($tokens as $i => $token) {
                if (!is_array($token)) {
                    continue;
                }
                $named = in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)
                    && in_array(strtolower($token[1]), $names, true);
                $quoted = $token[0] === T_CONSTANT_ENCAPSED_STRING
                    && in_array(strtolower(self::literalValue($token[1])), ['update_needed', '\\update_needed'], true);
                if (!$named && !$quoted) {
                    continue;
                }
                $prev = self::neighbour($tokens, $i, -1);
                $next = self::neighbour($tokens, $i, 1);
                if ($named && in_array($prev, ['->', '?->', '::'], true)) {
                    continue; // a method or class constant: another symbol
                }
                if ($named && $path === '202-config/functions.php' && $prev === 'function' && $next === '(') {
                    continue; // the declaration
                }
                if ($quoted && ($next === '=>' || ($prev === '[' && $next === ']'))) {
                    continue; // $_SESSION['update_needed'] and the banner's key
                }
                $uses[] = "$path:{$token[2]}";
            }
        }

        self::assertSame(
            [],
            $uses,
            'update_needed() is used again: it replaces the files and runs the ladder with no form to warn in. '
            . 'Give that path the backup warning, then correct NO_FORM\'s reason'
        );
    }

    public function testReleasingSaysTheSame(): void
    {
        $doc = self::source('RELEASING.md');
        self::assertStringContainsString('## Upgrades are one-way: a database backup is required', $doc);
        self::assertStringContainsString('Restoring a backup taken before the upgrade is the only way', $doc);
        self::assertStringContainsString('`202_conversion_logs.dedupe_key` `NOT NULL`', $doc);
    }
}
