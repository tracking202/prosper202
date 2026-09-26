<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;

/**
 * Where an update archive may write a file (resolve_update_write_path(),
 * 202-config/functions.php), executed against a real directory tree with
 * real symlinks.
 *
 * 202-account/auto-upgrade.php had its own get_safe_upgrade_path(), which
 * refused `..` and nothing else: a symlinked directory or file inside the
 * install sent the write outside it (#165, #173). The premium updater and the
 * auto-update checked a symlink only when the file existed, and a dangling
 * one does not "exist" to file_exists() while fopen() follows it. All three
 * now write through this one function, and the last test pins that.
 */
final class UpdateWritePathTest extends TestCase
{
    private string $base;
    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/202-config/functions.php';
        $root = sys_get_temp_dir() . '/p202-rv3-update-' . bin2hex(random_bytes(4));
        mkdir($root . '/base/lib', 0755, true);
        mkdir($root . '/outside', 0755, true);
        $this->base = (string) realpath($root . '/base');
        $this->outside = (string) realpath($root . '/outside');
        file_put_contents($this->base . '/lib/kept.php', 'x');
        file_put_contents($this->outside . '/victim.php', 'x');
        symlink($this->outside, $this->base . '/linkdir');
        symlink($this->outside . '/victim.php', $this->base . '/lib/linkfile.php');
        symlink($this->outside . '/not-yet.php', $this->base . '/lib/dangling.php');
    }

    protected function tearDown(): void
    {
        $root = dirname($this->base);
        foreach (['/base/lib/linkfile.php', '/base/lib/dangling.php', '/base/linkdir'] as $link) {
            @unlink($root . $link);
        }
        foreach (['/base/lib/kept.php', '/base/lib/new.php', '/outside/victim.php', '/outside/not-yet.php'] as $file) {
            @unlink($root . $file);
        }
        @rmdir($root . '/base/lib/new');
        @rmdir($root . '/base/lib');
        @rmdir($root . '/base');
        @rmdir($root . '/outside');
        @rmdir($root);
        parent::tearDown();
    }

    public function testAnOrdinaryEntryIsWrittenInsideTheInstall(): void
    {
        self::assertSame($this->base . '/lib/kept.php', resolve_update_write_path($this->base, 'lib/kept.php'), 'an existing file');
        self::assertSame($this->base . '/lib/new.php', resolve_update_write_path($this->base, 'lib/new.php'), 'a new one');
    }

    /** @return array<string, array{0: string}> */
    public static function escapes(): array
    {
        return [
            'a parent segment' => ['lib/../../outside/victim.php'],
            'a leading slash and a parent segment' => ['/../outside/victim.php'],
            'a symlinked directory' => ['linkdir/victim.php'],
            'a symlinked file' => ['lib/linkfile.php'],
            'a dangling symlink' => ['lib/dangling.php'],
            'a NUL byte' => ["lib/a\0.php"],
            'nothing' => [''],
        ];
    }

    /** @dataProvider escapes */
    public function testAnEntryThatLeavesTheInstallIsRefused(string $entry): void
    {
        self::assertFalse(resolve_update_write_path($this->base, $entry));
        self::assertSame('x', file_get_contents($this->outside . '/victim.php'), 'nothing outside was touched');
        self::assertFileDoesNotExist($this->outside . '/not-yet.php');
    }

    /**
     * The 1-click upgrade pages replace the install's files, and asked only
     * that the caller be signed in (#165). They ask for access_to_settings,
     * as Settings itself does, directly after the login check.
     */
    public function testTheUpgradePagesAskForTheSettingsPermission(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['202-account/auto-upgrade.php', '202-account/auto-upgrade-premium.php'] as $file) {
            $code = '';
            foreach (\PhpToken::tokenize((string) file_get_contents($root . '/' . $file)) as $token) {
                if (!$token->is([T_COMMENT, T_DOC_COMMENT, T_WHITESPACE])) {
                    $code .= $token->text;
                }
            }
            self::assertStringContainsString("AUTH::require_user();if(!isset(\$userObj)||!\$userObj->hasPermission('access_to_settings')){header('location: '.get_absolute_url().'202-account/');exit;}", $code,
                "$file refuses a user without access_to_settings before anything else");
        }
    }

    public function testEveryUpdaterWritesThroughIt(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['202-account/auto-upgrade.php' => 1, '202-account/auto-upgrade-premium.php' => 1, '202-config/functions.php' => 2] as $file => $expected) {
            $source = (string) file_get_contents($root . '/' . $file);
            self::assertSame($expected, preg_match_all('/\bresolve_update_write_path\(/', $source), "$file writes update files through resolve_update_write_path()");
            self::assertStringNotContainsString('get_safe_upgrade_path', $source, "$file has no path check of its own");
            self::assertSame(0, preg_match('/\$targetFile\s*=\s*resolve_update_target_path\(/', $source), "$file resolves no file target without the symlink check");
        }
    }
}
