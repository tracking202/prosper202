<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * Every first-party `p202-*` / `p202c-*` class in markup is consumed by
 * something.
 *
 * A component class nobody consumes is invisible in exactly the way a typo
 * cannot be: the browser accepts any class name, so `p202-flash--warn` — a
 * modifier that was never written into a stylesheet — renders as an ordinary
 * element with no error, no warning, and none of the styling the markup was
 * written to get. Two of these shipped in one feature; the second was found
 * by reading, which is not a method that scales.
 *
 * Consumed means one of two things, both of which are somebody acting on the
 * class:
 *
 *  - a stylesheet in 202-css/ has a rule whose selector names it, or
 *  - a script in 202-js/ selects on it, or adds and removes it.
 *
 * The second is not a loophole: `p202-copy-label` carries no styling at all
 * and exists so custom.php can find the span inside a copy button. A class
 * that neither styles anything nor is reachable from a script does nothing.
 *
 * What this does NOT check is the reverse direction. A class defined in the
 * stylesheets and used nowhere is dead CSS, not broken markup, and the
 * component sheet deliberately defines the whole kit whether or not a page
 * has reached for a given part yet — 202-account/ui-kit.php renders them.
 *
 * Nor does it see a class assembled at runtime. A name that only exists once
 * PHP or JavaScript has run — `class="p202-pill <?php echo $tone; ?>"`, where
 * $tone comes from a map of modifier names — leaves no literal token in the
 * attribute, so a typo in that map is still only caught by looking. Keeping
 * such maps next to the markup that uses them, with the modifier spelled out,
 * is what makes them reviewable.
 */
final class ComponentClassIsConsumedTest extends TestCase
{
    private const SKIP_DIRS = ['vendor', 'node_modules', '.git', '202-config/temp', '202-config/data', '202-config/geo'];

    /** Markup lives in these; .css files are the definitions, not the uses. */
    private const MARKUP_EXTENSIONS = ['php', 'html', 'js'];

    /** A first-party component or chrome class: p202-foo__bar--baz, p202c-foo. */
    private const CLASS_PATTERN = 'p202c?-[A-Za-z0-9_-]+';

    public function testTheScannerFindsClassesToCheck(): void
    {
        // A silent zero would make the assertion below pass vacuously.
        $used = $this->usedClasses(self::repoRoot());
        self::assertGreaterThan(
            50,
            count($used),
            'Far fewer first-party component classes than expected — the extractor is probably broken.'
        );
        foreach (['p202-panel', 'p202-panel__body', 'p202-pill', 'p202c-subnav'] as $expected) {
            self::assertArrayHasKey($expected, $used, "the extractor found $expected");
        }
    }

    public function testTheStylesheetsAndScriptsWereRead(): void
    {
        $consumers = $this->consumedClasses(self::repoRoot());
        self::assertGreaterThan(
            50,
            count($consumers),
            'Far fewer definitions than expected — the stylesheet reader is probably broken.'
        );
        foreach (['p202-panel', 'p202-empty__title', 'p202-flash__body'] as $expected) {
            self::assertArrayHasKey($expected, $consumers, "the reader found $expected");
        }
    }

    public function testEveryClassInMarkupIsStyledOrScripted(): void
    {
        $root = self::repoRoot();
        $consumers = $this->consumedClasses($root);

        $orphans = [];
        foreach ($this->usedClasses($root) as $class => $files) {
            if (isset($consumers[$class])) {
                continue;
            }
            $orphans[] = sprintf('%s  used in %s', $class, implode(', ', array_slice(array_keys($files), 0, 4)));
        }

        self::assertSame(
            [],
            $orphans,
            "These classes appear in markup but no stylesheet rule and no script names them, so they do nothing.\n"
            . "Define the class in 202-css/, or use the one the component actually has — 202-account/ui-kit.php\n"
            . "renders every component in every state.\n  " . implode("\n  ", $orphans)
        );
    }

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Every first-party class named by a stylesheet rule or reached by a script.
     *
     * @return array<string, string> class => the file that consumes it
     */
    private function consumedClasses(string $root): array
    {
        $consumers = [];

        foreach (glob($root . '/202-css/*.css') ?: [] as $sheet) {
            foreach ($this->selectorClasses((string) file_get_contents($sheet)) as $class) {
                $consumers[$class] ??= ltrim(str_replace($root, '', $sheet), '/');
            }
        }

        // 202-js/*.php are scripts served as JavaScript; they carry both
        // selectors and, in custom.php's case, inline <style> rules.
        $scripts = array_merge(glob($root . '/202-js/*.js') ?: [], glob($root . '/202-js/*.php') ?: []);
        foreach ($scripts as $script) {
            $source = (string) file_get_contents($script);
            $relative = ltrim(str_replace($root, '', $script), '/');
            // '.p202-foo' in a selector string or a CSS rule.
            foreach ($this->selectorClasses($source) as $class) {
                $consumers[$class] ??= $relative;
            }
            // classList.add('p202-foo'), classList.toggle("p202-foo", on).
            if (preg_match_all('/classList\.[A-Za-z]+\(\s*[\'"](' . self::CLASS_PATTERN . ')/', $source, $m) > 0) {
                foreach ($m[1] as $class) {
                    $consumers[$class] ??= $relative;
                }
            }
            // jQuery addClass('p202-foo') / removeClass / hasClass / toggleClass.
            if (preg_match_all('/(?:add|remove|has|toggle)Class\(\s*[\'"]([^\'"]+)/', $source, $m) > 0) {
                foreach ($m[1] as $attr) {
                    foreach (preg_split('/\s+/', $attr) ?: [] as $token) {
                        if (preg_match('/^(' . self::CLASS_PATTERN . ')$/', $token, $t) === 1) {
                            $consumers[$t[1]] ??= $relative;
                        }
                    }
                }
            }
        }

        return $consumers;
    }

    /**
     * The first-party classes a CSS selector (or a selector string) names.
     *
     * @return list<string>
     */
    private function selectorClasses(string $source): array
    {
        if (preg_match_all('/\.(' . self::CLASS_PATTERN . ')/', $source, $m) === 0) {
            return [];
        }
        return $m[1];
    }

    /**
     * Every first-party class written into a class attribute, anywhere in the
     * tree.
     *
     * Attributes are matched inside PHP and JavaScript strings too, including
     * the escaped-quote form (`class=\"p202-panel\"`) that appears when markup
     * is built inside a double-quoted PHP string.
     *
     * @return array<string, array<string, true>> class => set of files
     */
    private function usedClasses(string $root): array
    {
        $used = [];
        foreach ($this->markupFiles($root) as $relative => $source) {
            // The capture is lazy and closes on a backreference to the opening
            // quote, so an attribute written inside a double-quoted PHP string
            // — "<div class=\"p202-panel\">" — ends at the escaped quote. A
            // greedy [^"']* swallows the backslash instead and the token then
            // matches nothing, which is a scanner that silently sees less
            // than it claims to.
            if (preg_match_all('~\bclass\s*=\s*\\\\?(["\'])(.*?)\\\\?\1~is', $source, $m, PREG_SET_ORDER) === 0) {
                continue;
            }
            foreach ($m as $match) {
                foreach (preg_split('/\s+/', trim($match[2])) ?: [] as $token) {
                    // Punctuation around a class named through a PHP ternary
                    // or concatenation inside the attribute.
                    $token = trim($token, "'\"().,;:?!{}[]<>=+");
                    if (preg_match('/^(' . self::CLASS_PATTERN . ')$/', $token, $t) === 1) {
                        $used[$t[1]][$relative] = true;
                    }
                }
            }
        }
        return $used;
    }

    /**
     * @return array<string, string> relative path => contents
     */
    private function markupFiles(string $root): array
    {
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
                    if ($file->isDir()) {
                        return true;
                    }
                    return in_array(strtolower($file->getExtension()), self::MARKUP_EXTENSIONS, true);
                }
            )
        );

        $files = [];
        foreach ($iterator as $file) {
            // A directory whose children the filter all rejected has no
            // children left, so the iterator yields the directory itself as a
            // leaf. Only files are readable.
            if (!$file->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
            $files[$relative] = (string) file_get_contents($file->getPathname());
        }
        return $files;
    }
}
