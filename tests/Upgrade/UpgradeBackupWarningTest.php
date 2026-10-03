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
 * testEveryCallerOfTheLadderIsAPageHereOrTheSilentUpdater, so a fourth one
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
     * functions.php's update_needed() upgrades without anyone pressing
     * anything, and only when the release feed marks a release autoupgrade —
     * a decision made on the feed, so there is no form here to warn in.
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

    public function testEveryCallerOfTheLadderIsAPageHereOrTheSilentUpdater(): void
    {
        $callers = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::root(), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $path = substr($file->getPathname(), strlen(self::root()) + 1);
            if ($file->getExtension() !== 'php' || preg_match('#^(vendor|tests|node_modules|\.git)/#', $path) === 1) {
                continue;
            }
            $tokens = token_get_all((string) file_get_contents($file->getPathname()));
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

    public function testReleasingSaysTheSame(): void
    {
        $doc = self::source('RELEASING.md');
        self::assertStringContainsString('## Upgrades are one-way: a database backup is required', $doc);
        self::assertStringContainsString('Restoring a backup taken before the upgrade is the only way', $doc);
        self::assertStringContainsString('`202_conversion_logs.dedupe_key` `NOT NULL`', $doc);
    }
}
