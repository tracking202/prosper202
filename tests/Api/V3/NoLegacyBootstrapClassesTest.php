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
 * The banned set is not a hand-written list. It is every class Bootstrap
 * 3.3.4 defines that Bootstrap 5.3 does not, read from the two stylesheets in
 * the repository — about six hundred names: every grid offset and push, every
 * glyphicon, panel, well, label and navbar variant — plus Flat UI Pro's icon
 * font (`fui-*`) and its component classes, plus the Bootstrap 3 data-API
 * attributes (`data-toggle=` and friends; Bootstrap 5 uses `data-bs-*`). A
 * class both versions define (`row`, `btn-primary`, `active`) is allowed,
 * because a v2 page may use it.
 *
 * Markup is checked token by token: every `class="..."` attribute in the
 * file, including those inside PHP and JavaScript strings and those written
 * with escaped quotes, split on whitespace. Scripts are checked the same way
 * for the ways they name a class without writing an attribute —
 * `classList.add`, `className =`, jQuery's `addClass` and friends, and the
 * selector strings passed to `querySelector`, `closest`, `matches` and `$()`.
 * The chrome stylesheet is checked by the classes its selectors
 * name. Detection stays textual: a page opts into the v2 shell by passing
 * 'ui' => 'v2' to template_top(), and that string is what selects it here,
 * so a page cannot migrate unchecked. The partials the shell includes at
 * runtime (the section tabs, the sub-menu) are in the chrome list, so a v2
 * tracking page is covered although the partials are not pages.
 */
final class NoLegacyBootstrapClassesTest extends TestCase
{
    private const BOOTSTRAP3 = '202-css/css/bootstrap-3.3.4.min.css';
    private const BOOTSTRAP5 = '202-css/vendor/bootstrap-5.3.8.min.css';

    /**
     * Flat UI Pro component classes a page might carry (its icon font is
     * caught by prefix), and the classic first-party button class.
     */
    private const FLAT_UI = [
        'btn-embossed', 'btn-hg', 'btn-inverse', 'btn-wide', 'btn-tip', 'btn-group-hg',
        'custom-checkbox', 'custom-radio', 'form-group-hg', 'input-hg', 'input-group-hg',
        'input-group-rounded', 'label-important', 'label-inverse', 'text-inverse',
        'navbar-embossed', 'navbar-input', 'navbar-lg', 'navbar-new', 'navbar-unread',
        'nav-list', 'dropdown-menu-inverse', 'pagination-minimal', 'pagination-plain',
        'pagination-danger', 'pagination-success', 'pagination-warning', 'pagination-info',
        'pagination-inverse', 'select-primary', 'select-danger', 'select-success',
        'select-warning', 'select-info', 'select-inverse', 'select-default', 'select-hg',
        'select-lg', 'select-sm', 'tile', 'tile-image', 'tile-title', 'tile-hot-ribbon',
        'todo', 'btn-p202',
    ];

    /** Class prefixes that belong to the classic kits and their plugins. */
    private const LEGACY_PREFIXES = ['fui-', 'glyphicon', 'btn-social-', 'bootstrap-switch', 'bootstrap-tagsinput', 'tagsinput-', 'iconbar', 'multiselect', 'fileinput'];

    /** Bootstrap 3 data-API attributes; Bootstrap 5 uses data-bs-*. */
    private const LEGACY_ATTRIBUTES = ['data-toggle=', 'data-dismiss=', 'data-target=', 'data-ride=', 'data-spy=', 'data-parent=', 'data-slide='];

    /** Shared by both shells; must be clean whatever pages exist. */
    private const CHROME = [
        '202-config/template.php',
        '202-config/functions-ui.php',
        'tracking202/_config/top.php',
        'tracking202/_config/sub-menu.php',
        '202-js/p202-chrome.js',
        '202-js/p202-ui.js',
    ];

    private const CHROME_STYLESHEET = '202-css/p202-chrome.css';

    private const SKIP_DIRS = ['vendor', 'node_modules', 'tests', '.git', '202-config/temp', '202-config/data', '202-config/geo'];

    public function testTheBannedSetIsReadFromTheTwoStylesheets(): void
    {
        $banned = $this->bannedClasses(dirname(__DIR__, 3));
        self::assertGreaterThan(400, count($banned), 'the Bootstrap 3 minus Bootstrap 5 difference was read from the stylesheets');
        foreach (['col-xs-12', 'col-sm-offset-2', 'col-md-push-3', 'panel', 'panel-default', 'panel-heading', 'glyphicon', 'glyphicon-ok', 'form-group', 'form-horizontal', 'control-label', 'help-block', 'input-group-addon', 'btn-default', 'btn-xs', 'btn-block', 'well', 'caret', 'label', 'pull-right', 'text-right', 'hidden-xs', 'visible-xs', 'img-responsive', 'navbar-default', 'navbar-toggle', 'sr-only', 'table-condensed', 'dropdown-menu-right'] as $legacy) {
            self::assertContains($legacy, $banned, "$legacy is Bootstrap 3 only and is banned");
        }
        foreach (['row', 'col-md-6', 'col-sm-12', 'btn', 'btn-primary', 'btn-sm', 'active', 'disabled', 'table', 'table-striped', 'form-control', 'input-group', 'nav', 'nav-tabs', 'breadcrumb', 'badge', 'alert', 'container', 'text-center', 'modal', 'dropdown-menu', 'list-group-item', 'pagination', 'progress-bar'] as $shared) {
            self::assertNotContains($shared, $banned, "$shared exists in both versions and is allowed");
        }
    }

    public function testTheChromeAndEveryV2PageCarryNoLegacyClass(): void
    {
        $root = dirname(__DIR__, 3);
        $banned = array_fill_keys($this->bannedClasses($root), true);
        $files = self::CHROME;
        foreach ($this->v2Pages($root) as $page) {
            $files[] = $page;
        }
        $files = array_values(array_unique($files));
        self::assertContains('202-account/ui-kit.php', $files, 'the UI kit is a v2 page and is checked');

        $offences = [];
        foreach ($files as $file) {
            $source = file_get_contents($root . '/' . $file);
            self::assertIsString($source, "$file is readable");
            foreach ($this->classAttributeTokens($source) as [$line, $token]) {
                if ($this->isLegacy($token, $banned)) {
                    $offences[] = sprintf('%s:%d: class %s', $file, $line, $token);
                }
            }
            foreach ($this->scriptClassTokens($source) as [$line, $token]) {
                if ($this->isLegacy($token, $banned)) {
                    $offences[] = sprintf('%s:%d: script names class %s', $file, $line, $token);
                }
            }
            foreach (explode("\n", $source) as $number => $line) {
                foreach (self::LEGACY_ATTRIBUTES as $attribute) {
                    if (str_contains($line, $attribute)) {
                        $offences[] = sprintf('%s:%d: attribute %s', $file, $number + 1, $attribute);
                    }
                }
            }
        }

        $stylesheet = file_get_contents($root . '/' . self::CHROME_STYLESHEET);
        self::assertIsString($stylesheet);
        foreach ($this->selectorClasses($stylesheet) as $class) {
            if ($this->isLegacy($class, $banned)) {
                $offences[] = sprintf('%s: selector .%s', self::CHROME_STYLESHEET, $class);
            }
        }

        self::assertSame([], $offences, "Bootstrap 3 / Flat UI classes on the v2 shell or in the chrome:\n" . implode("\n", $offences));
    }

    public function testTheScannerSeesClassesInsidePhpAndJavaScriptStrings(): void
    {
        $tokens = array_map(static fn (array $pair): string => $pair[1], $this->classAttributeTokens(
            "<?php\n\$html = '<div class=\"row ' . (\$x ? 'panel-default' : '') . '\">';\n?>\n<a class='btn <?php echo \$y ? \"btn-xs\" : \"\"; ?>'>\nvar s = \"<b class=\\\"caret\\\">\";\n"
        ));
        foreach (['row', 'panel-default', 'btn', 'btn-xs', 'caret'] as $expected) {
            self::assertContains($expected, $tokens, "$expected is seen");
        }
        $lines = array_map(static fn (array $pair): int => $pair[0], $this->classAttributeTokens("a\nb\n<i class=\"x\"></i>\n"));
        self::assertSame([3], $lines, 'tokens carry the line the attribute starts on');
    }

    public function testTheScannerSeesTheWaysAScriptNamesAClass(): void
    {
        $source = <<<'JS'
            element.classList.add('panel-body');
            element.classList.toggle("col-xs-4", on);
            node.className = 'glyphicon glyphicon-ok';
            other.className += " help-block";
            $(node).addClass('btn-default').removeClass('pull-right');
            document.querySelectorAll('details.form-horizontal[data-x]');
            event.target.closest('.input-group-addon');
            root.querySelector('.p202-panel > .well');
            JS;
        $tokens = array_map(static fn (array $pair): string => $pair[1], $this->scriptClassTokens($source));
        foreach (['panel-body', 'col-xs-4', 'glyphicon', 'glyphicon-ok', 'help-block', 'btn-default', 'pull-right', 'form-horizontal', 'input-group-addon', 'p202-panel', 'well'] as $expected) {
            self::assertContains($expected, $tokens, "$expected is seen");
        }
    }

    /**
     * The class names a script mentions without writing a class attribute:
     * the DOM and jQuery class APIs, a className assignment, and the class
     * parts of any selector string it passes to a query method. Textual, so
     * it reads inline scripts inside PHP files too.
     *
     * @return list<array{int, string}>
     */
    private function scriptClassTokens(string $source): array
    {
        $tokens = [];
        $add = static function (string $text, int $offset) use (&$tokens, $source): void {
            foreach (preg_split('/\s+/', trim($text)) ?: [] as $token) {
                if ($token !== '') {
                    $tokens[] = [1 + substr_count($source, "\n", 0, $offset), $token];
                }
            }
        };

        // classList.add('a', 'b'), addClass('a b'), className = 'a b'
        $calls = '(?:classList\s*\.\s*(?:add|remove|toggle|contains|replace)|(?:add|remove|toggle|has)Class)';
        preg_match_all('~\b' . $calls . '\s*\(([^)]*)\)~i', $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($matches as $match) {
            preg_match_all('~(["\'])(.*?)\1~s', $match[1][0], $strings);
            foreach ($strings[2] as $string) {
                $add($string, $match[0][1]);
            }
        }
        preg_match_all('~\bclassName\s*\+?=\s*(["\'])(.*?)\1~s', $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($matches as $match) {
            $add($match[2][0], $match[0][1]);
        }

        // Selector strings: every .class part of what is queried.
        preg_match_all('~\b(?:querySelectorAll|querySelector|closest|matches|getElementsByClassName|\$)\s*\(\s*(["\'])(.*?)\1~s', $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($matches as $match) {
            preg_match_all('~\.(-?[_a-zA-Z][_a-zA-Z0-9-]*)~', $match[2][0], $classes);
            foreach ($classes[1] as $class) {
                $add($class, $match[0][1]);
            }
        }

        return $tokens;
    }

    /**
     * @param array<string, true> $banned
     */
    private function isLegacy(string $token, array $banned): bool
    {
        if (isset($banned[$token]) || in_array($token, self::FLAT_UI, true)) {
            return true;
        }
        foreach (self::LEGACY_PREFIXES as $prefix) {
            if (str_starts_with($token, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The classes Bootstrap 3 defines and Bootstrap 5 does not.
     *
     * @return list<string>
     */
    private function bannedClasses(string $root): array
    {
        $bootstrap3 = file_get_contents($root . '/' . self::BOOTSTRAP3);
        $bootstrap5 = file_get_contents($root . '/' . self::BOOTSTRAP5);
        self::assertIsString($bootstrap3, self::BOOTSTRAP3 . ' is readable');
        self::assertIsString($bootstrap5, self::BOOTSTRAP5 . ' is readable');
        $three = $this->selectorClasses($bootstrap3);
        $five = $this->selectorClasses($bootstrap5);
        self::assertGreaterThan(500, count($three), 'Bootstrap 3 defines hundreds of classes');
        self::assertGreaterThan(1000, count($five), 'Bootstrap 5 defines over a thousand classes');
        return array_values(array_diff($three, $five));
    }

    /**
     * Every class name a stylesheet's selectors mention. Comments, url()
     * bodies and declaration blocks are removed first, so property values
     * such as `.5em` and font file names never read as classes.
     *
     * @return list<string>
     */
    private function selectorClasses(string $css): array
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        $css = (string) preg_replace('~url\([^)]*\)~', 'url()', $css);
        // The innermost blocks are the declaration blocks (no nesting in these sheets).
        $css = (string) preg_replace('~\{[^{}]*\}~', '{}', $css);
        preg_match_all('~\.(-?[_a-zA-Z][_a-zA-Z0-9-]*)~', $css, $matches);
        $classes = array_values(array_unique($matches[1]));
        sort($classes);
        return $classes;
    }

    /**
     * Every token of every class attribute in a source file, with the line
     * the attribute starts on. Attributes inside PHP and JavaScript strings
     * are included, so `'<div class="' . $x . '">'` is seen; quotes and
     * punctuation around a token are trimmed so a class named in a PHP
     * ternary inside the attribute compares as itself.
     *
     * @return list<array{int, string}>
     */
    private function classAttributeTokens(string $source): array
    {
        $tokens = [];
        // The optional backslashes catch an attribute written inside a
        // double-quoted PHP or JavaScript string: "<div class=\"panel\">".
        preg_match_all('~\bclass\s*=\s*\\\\?(["\'])(.*?)\\\\?\1~is', $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($matches as $match) {
            $line = 1 + substr_count($source, "\n", 0, $match[0][1]);
            foreach (preg_split('/\s+/', $match[2][0]) ?: [] as $token) {
                $token = trim($token, "'\"().,;:?!{}[]<>=+");
                if ($token !== '') {
                    $tokens[] = [$line, $token];
                }
            }
        }
        return $tokens;
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
            // A directory whose children the filter all rejected has no
            // children left, so the iterator yields the directory itself as a
            // leaf. Only files are readable.
            if (!$file->isFile()) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match("/['\"]ui['\"]\\s*=>\\s*['\"]v2['\"]/", $source) === 1) {
                $pages[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
            }
        }
        sort($pages);
        return $pages;
    }
}
