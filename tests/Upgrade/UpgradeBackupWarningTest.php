<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use Tests\TestCase;

/**
 * The upgrade is one-way (plan §7.5a): the upgrade page must say, before its
 * button, that a database backup is required and that restoring it is the
 * only way back — inside the form that posts, so no branch of the page
 * renders the button without it — as the kit's warning flash, parts
 * included (CLAUDE.md #19). RELEASING.md says the same.
 * tests/live/android-abuse-limits.sh reads the rendered page.
 */
final class UpgradeBackupWarningTest extends TestCase
{
    private static function source(string $path): string
    {
        $text = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($text);

        return $text;
    }

    public function testTheWarningSitsInTheFormAboveTheButton(): void
    {
        $page = self::source('202-config/upgrade.php');
        $form = strpos($page, '<form method="post" id="upgrade-form"');
        $end = $form === false ? false : strpos($page, '</form>', $form);
        self::assertIsInt($form, 'the upgrade form');
        self::assertIsInt($end);
        $body = substr($page, $form, $end - $form);
        self::assertSame(1, substr_count($page, 'id="upgrade-submit"'), 'one button');

        $warning = strpos($body, 'id="upgrade-backup-warning"');
        $button = strpos($body, 'id="upgrade-submit"');
        self::assertIsInt($warning, 'the warning is inside the form that posts');
        self::assertIsInt($button);
        self::assertLessThan($button, $warning, 'and above its button');

        // Nothing between the warning and the button can skip it: the only
        // PHP between them is the version clause inside the flash body.
        $between = substr($body, $warning, $button - $warning);
        self::assertMatchesRegularExpression(
            '#^id="upgrade-backup-warning">\s*<i class="bi bi-exclamation-triangle"></i>\s*<div class="p202-flash__body">#',
            $between,
            'the kit\'s warning flash: its icon, then its body'
        );
        self::assertStringContainsString('<div class="alert alert-warning p202-flash" role="status" id="upgrade-backup-warning">', $body);
        foreach (['Back up your database before you press the button.', 'restoring that backup is the only way back'] as $sentence) {
            self::assertStringContainsString($sentence, $between);
        }
        self::assertStringContainsString('202_conversion_logs.dedupe_key', $between, 'and why');
        // Every PHP block between the flash and the button, by its opening
        // statement: the version clause and the closing brace, nothing that
        // could leave or hide the warning.
        preg_match_all('/<\?php\s+(.*?)\?>/s', $between, $blocks);
        self::assertSame(
            ["if (version_compare(PROSPER202::prosper202_version(), '1.9.76', '<')) { ", '} '],
            $blocks[1]
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
