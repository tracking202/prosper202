<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\NotFoundPage;

/**
 * 1.9.79 renamed tracking202/setup/ and tracking202/analyze/ to Setup/ and
 * Analyze/ (#250). A signed-in visitor who follows an old link reaches the
 * 404, which offers the page at its current address; this is the part that
 * decides what that address is, and whether a page ran from a leftover copy.
 */
final class NotFoundPageTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/p202-not-found-' . bin2hex(random_bytes(6));
        $files = [
            'tracking202/Setup/aff_campaigns.php',
            'tracking202/Setup/index.php',
            'tracking202/Setup/_includes/campaign_form.php',
            'tracking202/Analyze/keywords.php',
            '202-account/index.php',
            'docs/Guide.php',
            'docs/guide.php',
            '.hidden/page.php',
            'vendor/autoload.php',
        ];
        foreach ($files as $file) {
            $path = "{$this->root}/{$file}";
            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true)) {
                self::fail('cannot create ' . dirname($path));
            }
            if (file_put_contents($path, "<?php\n") === false) {
                self::fail("cannot write {$path}");
            }
        }
        if (is_file("{$this->root}/DOCS/GUIDE.PHP")) {
            self::markTestSkipped('the temp directory is on a case-insensitive filesystem');
        }
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testTheRequestPathIsReadBelowTheInstallsBase(): void
    {
        self::assertSame('tracking202/setup/x.php', NotFoundPage::requestPath('/tracking202/setup/x.php?view=a', '/'));
        self::assertSame(
            'tracking202/setup/x.php',
            NotFoundPage::requestPath('/p202/tracking202/setup/x.php', '/p202/')
        );
        self::assertSame('tracking202/setup/a b.php', NotFoundPage::requestPath('/tracking202/setup/a%20b.php', '/'));
        self::assertNull(NotFoundPage::requestPath('/elsewhere/x.php', '/p202/'));
    }

    /** @return iterable<string, array{string, ?string}> asked for, offered */
    public static function addresses(): iterable
    {
        yield 'a page in a renamed folder' => [
            'tracking202/setup/aff_campaigns.php',
            'tracking202/Setup/aff_campaigns.php',
        ];
        yield 'the renamed folder itself' => ['tracking202/setup', 'tracking202/Setup/'];
        yield 'the renamed folder, with its slash' => ['tracking202/setup/', 'tracking202/Setup/'];
        yield 'the other renamed folder' => ['tracking202/analyze/keywords.php', 'tracking202/Analyze/keywords.php'];
        yield 'a page the new version does not have' => ['tracking202/setup/gone.php', null];
        yield 'an include, which is no page' => ['tracking202/setup/_includes/campaign_form.php', null];
        yield 'another case, one match' => ['202-ACCOUNT/index.php', '202-account/index.php'];
        yield 'another case, two spellings on disk' => ['docs/GUIDE.php', null];
        yield 'the page itself' => ['202-account/index.php', null];
        yield 'nothing there' => ['tracking202/nowhere.php', null];
        yield 'out of the install' => ['../etc/passwd', null];
        yield 'up and back down' => ['tracking202/../202-account/index.php', null];
        yield 'hidden' => ['.HIDDEN/page.php', null];
        yield 'vendor' => ['Vendor/autoload.php', null];
    }

    /** @dataProvider addresses */
    public function testTheAddressOffered(string $requested, ?string $offered): void
    {
        self::assertSame($offered, NotFoundPage::suggestion($this->root, $requested));
    }

    /**
     * Copied over the old files on Linux, the old folder stays beside the new
     * one; its pages would otherwise be matched exactly and offered as they
     * are.
     */
    public function testARenamedFolderIsOfferedOverALeftoverCopy(): void
    {
        $this->write('tracking202/setup/aff_campaigns.php');

        self::assertSame(
            'tracking202/Setup/aff_campaigns.php',
            NotFoundPage::suggestion($this->root, 'tracking202/setup/aff_campaigns.php')
        );
    }

    public function testAPageRunFromALeftoverCopyIsKnown(): void
    {
        $this->write('tracking202/setup/aff_campaigns.php');

        self::assertSame(
            'tracking202/setup/aff_campaigns.php',
            NotFoundPage::leftoverCopy($this->root, "{$this->root}/tracking202/setup/aff_campaigns.php")
        );
        self::assertNull(NotFoundPage::leftoverCopy($this->root, "{$this->root}/tracking202/Setup/aff_campaigns.php"));
        self::assertNull(NotFoundPage::leftoverCopy($this->root, "{$this->root}/202-account/index.php"));
        self::assertNull(
            NotFoundPage::leftoverCopy("{$this->root}/tracking202", "{$this->root}/202-account/index.php")
        );
    }

    /**
     * A server on a disk that ignores case hands the page over as it was asked
     * for, setup/, while the one folder there is Setup/.
     */
    public function testAPageAskedForInTheOldSpellingIsNoLeftover(): void
    {
        self::assertNull(NotFoundPage::leftoverCopy($this->root, "{$this->root}/tracking202/setup/aff_campaigns.php"));
    }

    public function testALinkFromTheOldSpellingToTheNewFolderIsNoLeftover(): void
    {
        if (!symlink("{$this->root}/tracking202/Analyze", "{$this->root}/tracking202/analyze")) {
            self::fail('cannot link');
        }

        self::assertNull(NotFoundPage::leftoverCopy($this->root, "{$this->root}/tracking202/analyze/keywords.php"));
    }

    public function testAnOldFolderWithNoNewOneIsNoLeftover(): void
    {
        $this->remove("{$this->root}/tracking202/Analyze");
        $this->write('tracking202/analyze/keywords.php');

        self::assertNull(NotFoundPage::leftoverCopy($this->root, "{$this->root}/tracking202/analyze/keywords.php"));
    }

    public function testFoldersAsShippedHaveNoProblem(): void
    {
        self::assertSame([], NotFoundPage::folderProblems($this->root));
    }

    public function testAnOldFolderBesideTheNewIsALeftover(): void
    {
        $this->write('tracking202/setup/aff_campaigns.php');

        self::assertSame(
            [['old' => 'tracking202/setup', 'new' => 'tracking202/Setup', 'problem' => 'leftover']],
            NotFoundPage::folderProblems($this->root)
        );
    }

    /** Copied over the old folder on a disk that ignores case, then uploaded. */
    public function testTheOldNameAloneIsMisspelled(): void
    {
        if (!rename("{$this->root}/tracking202/Analyze", "{$this->root}/tracking202/analyze")) {
            self::fail('cannot rename');
        }

        self::assertSame(
            [['old' => 'tracking202/analyze', 'new' => 'tracking202/Analyze', 'problem' => 'misspelled']],
            NotFoundPage::folderProblems($this->root)
        );
    }

    /** A git pull of the rename on a disk that ignores case deletes both. */
    public function testNeitherNameIsMissing(): void
    {
        $this->remove("{$this->root}/tracking202/Analyze");

        self::assertSame(
            [['old' => 'tracking202/analyze', 'new' => 'tracking202/Analyze', 'problem' => 'missing']],
            NotFoundPage::folderProblems($this->root)
        );
    }

    public function testALinkFromTheOldNameIsNoProblem(): void
    {
        if (!symlink("{$this->root}/tracking202/Setup", "{$this->root}/tracking202/setup")) {
            self::fail('cannot link');
        }

        self::assertSame([], NotFoundPage::folderProblems($this->root));
    }

    public function testAnUnreadableParentMakesNoClaim(): void
    {
        self::assertSame([], NotFoundPage::folderProblems("{$this->root}/202-account"));
    }

    private function write(string $file): void
    {
        $path = "{$this->root}/{$file}";
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true)) {
            self::fail('cannot create ' . dirname($path));
        }
        if (file_put_contents($path, "<?php\n") === false) {
            self::fail("cannot write {$path}");
        }
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $child) {
            $this->remove("{$path}/{$child}");
        }
        rmdir($path);
    }
}
