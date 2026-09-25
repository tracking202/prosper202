<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * No page, fragment, script or stylesheet in the tree carries a Bootstrap 3,
 * Flat UI Pro or Font Awesome 4 class.
 *
 * The shell loads none of those stylesheets — U8 deleted them with the
 * classic shell — so a leftover `col-xs-6` or `panel panel-default` renders
 * as nothing at all: no error, no warning, an unstyled block that looks like
 * a page that was never finished. Until U8 this test checked the chrome and
 * the pages that opted into the v2 shell; with one shell there is nothing to
 * opt into, so it checks every PHP, JavaScript and HTML file the install
 * serves, and every first-party stylesheet's selectors.
 *
 * The banned set is not a hand-written list. It is every class Bootstrap
 * 3.3.4 defines that Bootstrap 5.3 does not — about six hundred names: every
 * grid offset and push, every glyphicon, panel, well, label and navbar
 * variant. The 3.3.4 stylesheet is gone, so the difference was read from it
 * once, before the deletion, into tests/fixtures/ui/bootstrap3-only-classes.txt;
 * the subtraction is re-checked against the live Bootstrap 5 files here, so
 * a class a v2 page may use can never be banned. Added to it: Flat UI Pro's
 * icon font (`fui-*`) and its component classes, Font Awesome 4 (`fa`,
 * `fa-*`), and the Bootstrap 3 data-API attributes (`data-toggle=` and
 * friends; Bootstrap 5 uses `data-bs-*`). A class both versions define
 * (`row`, `btn-primary`, `active`) is allowed.
 *
 * Markup is checked token by token: every `class="..."` attribute in the
 * file, including those inside PHP and JavaScript strings and those written
 * with escaped quotes, split on whitespace. Scripts are checked the same way
 * for the ways they name a class without writing an attribute —
 * `classList.add`, `className =`, jQuery's `addClass` and friends, and the
 * selector strings passed to `querySelector`, `closest`, `matches` and `$()`.
 * A class a script assembles at runtime is out of reach here; the browser
 * passes' noLegacyClasses check reads the live DOM for those.
 */
final class NoLegacyBootstrapClassesTest extends TestCase
{
    private const BANNED_FIXTURE = 'tests/fixtures/ui/bootstrap3-only-classes.txt';
    private const BOOTSTRAP5 = ['202-css/vendor/bootstrap-5.3.8.min.css', '202-css/vendor/bootstrap-icons-1.13.1/bootstrap-icons.min.css'];

    /**
     * Flat UI Pro component classes a page might carry (its icon font is
     * caught by prefix), the classic first-party button class, and Font
     * Awesome 4's base class (its icons are caught by prefix).
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
        'todo', 'btn-p202', 'fa',
    ];

    /** Class prefixes that belong to the classic kits and their plugins. */
    private const LEGACY_PREFIXES = ['fui-', 'fa-', 'glyphicon', 'btn-social-', 'bootstrap-switch', 'bootstrap-tagsinput', 'tagsinput-', 'iconbar', 'multiselect', 'fileinput'];

    /** Bootstrap 3 data-API attributes; Bootstrap 5 uses data-bs-*. */
    private const LEGACY_ATTRIBUTES = ['data-toggle=', 'data-dismiss=', 'data-target=', 'data-ride=', 'data-spy=', 'data-parent=', 'data-slide='];

    /**
     * Files the sweep must reach, so a filter that silently skips a family
     * fails here rather than passing on less: the chrome, the kit, a page, a
     * fragment, a pre-login page, the update banner and the shared scripts.
     */
    private const MUST_SCAN = [
        '202-config/template.php',
        '202-config/functions-ui.php',
        '202-config/functions-ui-partials.php',
        '202-config/functions-standalone-ui.php',
        '202-config/functions-update-banner.php',
        'tracking202/_config/top.php',
        'tracking202/_config/sub-menu.php',
        '202-account/ui-kit.php',
        '202-account/ajax/update-needed.php',
        'tracking202/setup/aff_campaigns.php',
        'tracking202/ajax/click_history.php',
        '202-login.php',
        '202-js/p202-chrome.js',
        '202-js/p202-ui.js',
    ];

    /**
     * Not served, or not ours: everything else the install serves is scanned.
     * build/logs is PHPUnit's own report output (phpunit.xml), ignored by git.
     * sdk holds the mobile SDKs, which the install does not serve; running
     * the Android SDK's live pass leaves Gradle's HTML test reports under
     * its (git-ignored) build directories, and those read as page markup.
     */
    private const SKIP_DIRS = ['vendor', 'node_modules', 'tests', '.git', 'documentation', 'docs', 'build/logs', 'sdk', '202-js/vendor', '202-css/vendor', '202-config/temp', '202-config/data', '202-config/geo'];

    public function testTheBannedSetIsTheRecordedBootstrap3Difference(): void
    {
        $root = dirname(__DIR__, 3);
        $banned = $this->bannedClasses($root);
        self::assertGreaterThan(550, count($banned), 'the Bootstrap 3 minus Bootstrap 5 difference was read from the fixture');
        foreach (['col-xs-12', 'col-sm-offset-2', 'col-md-push-3', 'panel', 'panel-default', 'panel-heading', 'glyphicon', 'glyphicon-ok', 'form-group', 'form-horizontal', 'control-label', 'help-block', 'input-group-addon', 'btn-default', 'btn-xs', 'btn-block', 'well', 'caret', 'label', 'pull-right', 'text-right', 'hidden-xs', 'visible-xs', 'img-responsive', 'navbar-default', 'navbar-toggle', 'sr-only', 'table-condensed', 'dropdown-menu-right', 'input-sm'] as $legacy) {
            self::assertContains($legacy, $banned, "$legacy is Bootstrap 3 only and is banned");
        }
        foreach (['row', 'col-md-6', 'col-sm-12', 'btn', 'btn-primary', 'btn-sm', 'active', 'disabled', 'table', 'table-striped', 'form-control', 'input-group', 'nav', 'nav-tabs', 'breadcrumb', 'badge', 'alert', 'container', 'text-center', 'modal', 'dropdown-menu', 'list-group-item', 'pagination', 'progress-bar'] as $shared) {
            self::assertNotContains($shared, $banned, "$shared exists in both versions and is allowed");
        }
        $five = [];
        foreach (self::BOOTSTRAP5 as $sheet) {
            $css = file_get_contents($root . '/' . $sheet);
            self::assertIsString($css, "$sheet is readable");
            $five = array_merge($five, $this->selectorClasses($css));
        }
        self::assertGreaterThan(1000, count($five), 'Bootstrap 5 and its icons define over a thousand classes');
        self::assertSame([], array_values(array_intersect($banned, $five)), 'no class the shell\'s own stylesheets define is banned');
    }

    public function testNoFileInTheTreeCarriesALegacyClass(): void
    {
        $root = dirname(__DIR__, 3);
        $banned = array_fill_keys($this->bannedClasses($root), true);
        $files = $this->servedFiles($root);
        self::assertGreaterThan(400, count($files), 'the sweep walked the tree');
        foreach (self::MUST_SCAN as $file) {
            self::assertContains($file, $files, "$file is swept");
        }

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

        $sheets = glob($root . '/202-css/*.css') ?: [];
        self::assertNotEmpty($sheets, 'the first-party stylesheets were found');
        foreach ($sheets as $sheet) {
            $relative = substr($sheet, strlen($root) + 1);
            foreach ($this->selectorClasses((string) file_get_contents($sheet)) as $class) {
                if ($this->isLegacy($class, $banned)) {
                    $offences[] = sprintf('%s: selector .%s', $relative, $class);
                }
            }
        }

        self::assertSame([], $offences, "Bootstrap 3 / Flat UI / Font Awesome 4 classes, which no stylesheet the shell loads styles:\n" . implode("\n", $offences));
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
     * The classes Bootstrap 3 defines and Bootstrap 5 does not, as recorded
     * before U8 deleted the Bootstrap 3 stylesheet.
     *
     * @return list<string>
     */
    private function bannedClasses(string $root): array
    {
        $text = file_get_contents($root . '/' . self::BANNED_FIXTURE);
        self::assertIsString($text, self::BANNED_FIXTURE . ' is readable');
        $classes = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            self::assertMatchesRegularExpression('/^-?[_a-zA-Z][_a-zA-Z0-9-]*$/', $line, 'one class name per line in ' . self::BANNED_FIXTURE);
            $classes[] = $line;
        }
        return $classes;
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
     * Every PHP, JavaScript and HTML file the install serves or renders.
     *
     * @return list<string> repo-relative paths
     */
    private function servedFiles(string $root): array
    {
        $files = [];
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
                    return $file->isDir() || preg_match('/\.(php|js|html?)$/', $file->getFilename()) === 1;
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
            $files[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
        }
        sort($files);
        return $files;
    }
}
