<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * Pages on the v2 shell, and the chrome both shells render, carry no
 * Bootstrap 3 or Flat UI Pro class.
 *
 * The v2 shell does not load those stylesheets, so a leftover `col-xs-6` or
 * `panel panel-default` renders as nothing at all — no error, no warning, an
 * unstyled block that looks like a page that was never finished. The chrome
 * is shared by both shells and styled by its own sheet, so a Bootstrap class
 * of either version there breaks one of them.
 *
 * Detection is textual, like the other structural tests: a page opts into the
 * v2 shell by passing 'ui' => 'v2' to template_top(), and that string is what
 * selects it here, so a page cannot migrate without being checked.
 */
final class NoLegacyBootstrapClassesTest extends TestCase
{
    /** Substrings that only occur in Bootstrap 3 / Flat UI Pro markup. */
    private const LEGACY = [
        'col-xs-',
        'col-sm-offset-',
        'col-md-offset-',
        'glyphicon',
        'panel-default',
        'panel-heading',
        'panel-body',
        'form-horizontal',
        'control-label',
        'help-block',
        'input-group-addon',
        'label label-',
        'label-important',
        'data-toggle=',
        'fui-',
        'btn-xs',
        'btn-p202',
        'pull-right',
        'pull-left',
        'text-right',
        'text-left',
        'hidden-xs',
        'visible-xs',
        'img-responsive',
        'navbar-default',
        'class="caret"',
    ];

    /** Shared by both shells; must be clean whatever pages exist. */
    private const CHROME = [
        '202-config/template.php',
        '202-config/functions-ui.php',
        'tracking202/_config/top.php',
    ];

    private const SKIP_DIRS = ['vendor', 'node_modules', 'tests', '.git', '202-config/temp', '202-config/data', '202-config/geo'];

    public function testTheChromeAndEveryV2PageCarryNoLegacyClass(): void
    {
        $root = dirname(__DIR__, 3);
        $files = self::CHROME;
        foreach ($this->v2Pages($root) as $page) {
            $files[] = $page;
        }
        $files = array_values(array_unique($files));
        self::assertContains('202-account/ui-kit.php', $files, 'the UI kit is a v2 page and is checked');

        $offences = [];
        foreach ($files as $file) {
            $lines = file($root . '/' . $file, FILE_IGNORE_NEW_LINES);
            self::assertIsArray($lines, "$file is readable");
            foreach ($lines as $number => $line) {
                foreach (self::LEGACY as $needle) {
                    if (str_contains($line, $needle)) {
                        $offences[] = sprintf('%s:%d: %s', $file, $number + 1, $needle);
                    }
                }
            }
        }

        self::assertSame([], $offences, "Bootstrap 3 / Flat UI classes on the v2 shell or in the chrome:\n" . implode("\n", $offences));
    }

    /**
     * Every PHP file under the repository that opts into the v2 shell.
     *
     * @return list<string> repo-relative paths
     */
    private function v2Pages(string $root): array
    {
        $pages = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $file) use ($root): bool {
                    $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
                    foreach (self::SKIP_DIRS as $skip) {
                        if ($relative === $skip || str_starts_with($relative, $skip . '/')) {
                            return false;
                        }
                    }
                    return $file->isDir() || str_ends_with($file->getFilename(), '.php');
                }
            )
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match("/['\"]ui['\"]\\s*=>\\s*['\"]v2['\"]/", $source) === 1) {
                $pages[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
            }
        }
        sort($pages);
        return $pages;
    }
}
