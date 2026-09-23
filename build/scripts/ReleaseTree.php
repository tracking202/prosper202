<?php

declare(strict_types=1);

namespace P202Build;

/**
 * Classifies, prunes and verifies a release tree against
 * build/release-manifest.php. The command-line entry point, and what each
 * command does, is build/scripts/release-tree.php.
 */
final class ReleaseTree
{
    private const KEYS = ['ship', 'exclude', 'keep_only', 'exclude_nested', 'go_binaries', 'known_unresolved'];

    /**
     * @param array{
     *     ship: list<string>,
     *     exclude: list<string>,
     *     keep_only: array<string, list<string>>,
     *     exclude_nested: list<string>,
     *     go_binaries: list<string>,
     *     known_unresolved: array<string, string>
     * } $manifest
     */
    public function __construct(private readonly array $manifest)
    {
    }

    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("release manifest not found: {$path}");
        }
        $manifest = require $path;
        if (!is_array($manifest)) {
            throw new \RuntimeException("release manifest {$path} must return an array");
        }
        foreach (self::KEYS as $key) {
            if (!isset($manifest[$key]) || !is_array($manifest[$key])) {
                throw new \RuntimeException("release manifest {$path} is missing the '{$key}' list");
            }
        }
        /** @var array{ship: list<string>, exclude: list<string>, keep_only: array<string, list<string>>, exclude_nested: list<string>, go_binaries: list<string>, known_unresolved: array<string, string>} $manifest */

        return new self($manifest);
    }

    /**
     * Every top-level name must match exactly one of 'ship' or 'exclude'.
     *
     * @param list<string> $entries
     * @return list<string> problems
     */
    public function classify(array $entries): array
    {
        $problems = [];
        foreach ($entries as $entry) {
            $shipped = $this->matches($entry, $this->manifest['ship']);
            $excluded = $this->matches($entry, $this->manifest['exclude']);
            if ($shipped && $excluded) {
                $problems[] = "'{$entry}' matches both 'ship' and 'exclude' in build/release-manifest.php;"
                    . ' keep it in one';
            } elseif (!$shipped && !$excluded) {
                $problems[] = "'{$entry}' is not classified: add it to 'ship' (users need it at runtime)"
                    . " or 'exclude' (only developers or CI need it) in build/release-manifest.php";
            }
        }

        return $problems;
    }

    /**
     * The PR-time check, over repository-relative tracked file paths.
     *
     * @param list<string> $files
     * @return list<string> problems
     */
    public function checkTracked(array $files): array
    {
        $topLevel = [];
        foreach ($files as $file) {
            $topLevel[explode('/', $file, 2)[0]] = true;
        }
        $problems = $this->classify(array_keys($topLevel));

        // A misspelt path would ship the thing it was meant to strip, and
        // nothing else would notice.
        $exists = static function (string $path) use ($files): bool {
            foreach ($files as $file) {
                if ($file === $path || str_starts_with($file, $path . '/')) {
                    return true;
                }
            }
            return false;
        };
        foreach ($this->manifest['exclude_nested'] as $path) {
            if (!$exists($path)) {
                $problems[] = "'exclude_nested' names '{$path}', which is not tracked;"
                    . ' fix the path or remove the entry';
            }
        }
        foreach (array_keys($this->manifest['keep_only']) as $dir) {
            if (!$exists($dir)) {
                $problems[] = "'keep_only' names '{$dir}', which is not tracked; fix the path or remove the entry";
            }
        }

        return $problems;
    }

    /**
     * @return list<string> problems; nothing is deleted when there are any
     *                      classification problems
     */
    public function prune(string $stage): array
    {
        $entries = self::children($stage);
        $problems = $this->classify($entries);
        if ($problems !== []) {
            return $problems;
        }

        foreach ($entries as $entry) {
            if ($this->matches($entry, $this->manifest['exclude'])) {
                self::remove("{$stage}/{$entry}");
            }
        }

        // Parents first, so '.claude' is reduced before '.claude/skills'.
        $keepOnly = $this->manifest['keep_only'];
        ksort($keepOnly);
        foreach ($keepOnly as $dir => $keep) {
            if (!is_dir("{$stage}/{$dir}")) {
                $problems[] = "'keep_only' names '{$dir}', which is not in the staged tree";
                continue;
            }
            foreach (self::children("{$stage}/{$dir}") as $child) {
                if (!in_array($child, $keep, true)) {
                    self::remove("{$stage}/{$dir}/{$child}");
                }
            }
        }

        foreach ($this->manifest['exclude_nested'] as $path) {
            if (!file_exists("{$stage}/{$path}") && !is_link("{$stage}/{$path}")) {
                $problems[] = "'exclude_nested' names '{$path}', which is not in the staged tree";
                continue;
            }
            self::remove("{$stage}/{$path}");
        }

        return $problems;
    }

    /**
     * @param list<string>|null $tracked the commit's tracked files; when given,
     *                                   every one that ships must be in the
     *                                   tree at exactly that path
     * @return list<string> problems
     */
    public function verify(string $stage, ?array $tracked = null): array
    {
        $entries = self::children($stage);
        $problems = $this->classify($entries);
        foreach ($entries as $entry) {
            if ($this->matches($entry, $this->manifest['exclude'])) {
                $problems[] = "excluded path '{$entry}' is in the release tree";
            }
        }
        foreach ($this->manifest['keep_only'] as $dir => $keep) {
            if (!is_dir("{$stage}/{$dir}")) {
                $problems[] = "'{$dir}' is missing from the release tree";
                continue;
            }
            $children = self::children("{$stage}/{$dir}");
            foreach (array_diff($children, $keep) as $extra) {
                $problems[] = "'{$dir}/{$extra}' is in the release tree but 'keep_only' does not list it";
            }
            foreach (array_diff($keep, $children) as $missing) {
                $problems[] = "'{$dir}/{$missing}' is missing from the release tree";
            }
        }
        foreach ($this->manifest['exclude_nested'] as $path) {
            if (file_exists("{$stage}/{$path}") || is_link("{$stage}/{$path}")) {
                $problems[] = "excluded path '{$path}' is in the release tree";
            }
        }

        foreach ($this->manifest['go_binaries'] as $binary) {
            $file = "{$stage}/{$binary}";
            if (!is_file($file) || filesize($file) === 0) {
                $problems[] = "Go CLI binary '{$binary}' is missing or empty";
            } elseif (!str_ends_with($binary, '.exe') && !is_executable($file)) {
                $problems[] = "Go CLI binary '{$binary}' is not executable";
            }
        }

        if ($tracked !== null) {
            $problems = array_merge($problems, $this->verifyTrackedPaths($stage, $tracked));
        }
        $problems = array_merge($problems, self::verifyVendor($stage), self::verifyPermissions($stage));

        $result = self::resolveInChild($stage);
        if (is_string($result)) {
            $problems[] = $result;
        } else {
            $known = $this->manifest['known_unresolved'];
            foreach ($result as $name => $site) {
                if (!array_key_exists($name, $known)) {
                    $problems[] = "'{$name}' (referenced at {$site}) does not resolve through the shipped"
                        . ' vendor/autoload.php; a runtime dependency is missing from composer.json "require",'
                        . ' or shipped code depends on a dev-only class';
                }
            }
            foreach (array_keys($known) as $name) {
                if (!array_key_exists($name, $result)) {
                    $problems[] = "'known_unresolved' lists '{$name}', which no longer fails to resolve;"
                        . ' remove the entry';
                }
            }
        }

        return $problems;
    }

    /**
     * Every tracked file the manifest ships must be in the tree at exactly its
     * tracked path. Git tracks tracking202/Redirect/ beside tracking202/redirect/;
     * extracted on a case-insensitive filesystem the two merge, and the click
     * endpoints land in Redirect/, where a Linux host serves them as 404s.
     *
     * @param list<string> $tracked
     * @return list<string> problems
     */
    private function verifyTrackedPaths(string $stage, array $tracked): array
    {
        $problems = [];
        $missing = 0;
        foreach ($tracked as $path) {
            if (!$this->ships($path) || self::existsWithExactCase("{$stage}/{$path}")) {
                continue;
            }
            $missing++;
            if (count($problems) < 20) {
                $problems[] = "tracked file '{$path}' is not in the release tree at that exact path"
                    . ' (built on a case-insensitive filesystem?)';
            }
        }
        if ($missing > count($problems)) {
            $problems[] = sprintf('... %d more tracked files missing', $missing - count($problems));
        }

        return $problems;
    }

    /** Whether a tracked path survives prune(). */
    public function ships(string $path): bool
    {
        $top = explode('/', $path, 2)[0];
        if (!$this->matches($top, $this->manifest['ship']) || $this->matches($top, $this->manifest['exclude'])) {
            return false;
        }
        foreach ($this->manifest['exclude_nested'] as $nested) {
            if ($path === $nested || str_starts_with($path, $nested . '/')) {
                return false;
            }
        }
        foreach ($this->manifest['keep_only'] as $dir => $keep) {
            if (str_starts_with($path, $dir . '/')) {
                $child = explode('/', substr($path, strlen($dir) + 1), 2)[0];
                if (!in_array($child, $keep, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * vendor/ must be exactly the locked runtime set: nothing missing, and no
     * dev package (a web-reachable PHPUnit is a known remote-code-execution
     * vector on shared hosts).
     *
     * @return list<string> problems
     */
    private static function verifyVendor(string $stage): array
    {
        if (!is_file("{$stage}/vendor/autoload.php")) {
            return ['vendor/autoload.php is missing'];
        }
        $lockJson = @file_get_contents("{$stage}/composer.lock");
        if ($lockJson === false) {
            return ['composer.lock is missing from the release tree'];
        }
        try {
            $lock = json_decode($lockJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['composer.lock is not valid JSON: ' . $e->getMessage()];
        }
        if (!is_array($lock) || !isset($lock['packages']) || !is_array($lock['packages'])) {
            return ["composer.lock has no 'packages' list"];
        }
        $installedFile = "{$stage}/vendor/composer/installed.php";
        if (!is_file($installedFile)) {
            return ['vendor/composer/installed.php is missing'];
        }
        $installed = require $installedFile;
        if (
            !is_array($installed) || !isset($installed['root'], $installed['versions'])
            || !is_array($installed['versions'])
        ) {
            return ['vendor/composer/installed.php has an unexpected shape'];
        }

        $problems = [];
        if (($installed['root']['dev'] ?? null) !== false) {
            $problems[] = 'vendor/ was installed with dev dependencies; package with composer install --no-dev';
        }

        $locked = [];
        foreach ($lock['packages'] as $package) {
            $locked[(string) $package['name']] = (string) $package['version'];
        }
        $present = [];
        foreach ($installed['versions'] as $name => $info) {
            // The root package, and names that are only provided or replaced
            // by another package, have no install_path of their own.
            $isRoot = $name === ($installed['root']['name'] ?? null);
            if ($isRoot || !isset($info['install_path'], $info['pretty_version'])) {
                continue;
            }
            $present[$name] = (string) $info['pretty_version'];
        }

        foreach ($locked as $name => $version) {
            if (!isset($present[$name])) {
                $problems[] = "locked runtime package {$name} {$version} is not installed in vendor/";
            } elseif ($present[$name] !== $version) {
                $problems[] = "vendor/ has {$name} {$present[$name]} but composer.lock pins {$version}";
            } elseif (!is_dir("{$stage}/vendor/{$name}")) {
                $problems[] = "vendor/{$name} is missing although installed.php lists it";
            }
        }
        foreach (array_diff_key($present, $locked) as $name => $version) {
            $problems[] = "vendor/ has {$name} {$version}, which is not a locked runtime package";
        }

        return $problems;
    }

    /**
     * suPHP and suEXEC hosts refuse to run a script in a group- or
     * world-writable file or directory and answer 500.
     *
     * @return list<string> problems
     */
    private static function verifyPermissions(string $stage): array
    {
        $problems = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isLink()) {
                continue;
            }
            if (($file->getPerms() & 0o022) !== 0) {
                $problems[] = sprintf(
                    "'%s' is group- or world-writable (%o); shared hosts running suPHP answer 500 for it",
                    substr($file->getPathname(), strlen($stage) + 1),
                    $file->getPerms() & 0o777
                );
                if (count($problems) >= 20) {
                    $problems[] = '... further permission problems not listed';
                    break;
                }
            }
        }

        return $problems;
    }

    /**
     * Loading the release's autoloader would fix its classes into this
     * process, so resolution runs in a fresh one.
     *
     * @return array<string, string>|string unresolved name => first site, or
     *                                      a problem when the child failed
     */
    private static function resolveInChild(string $stage): array|string
    {
        $out = tempnam(sys_get_temp_dir(), 'p202-refs-');
        $log = tempnam(sys_get_temp_dir(), 'p202-refs-log-');
        if ($out === false || $log === false) {
            return 'could not create a temporary file for reference resolution';
        }
        try {
            // Output goes to files, not pipes: loading every shipped class can
            // print more warnings than a pipe buffer holds, and a child blocked
            // on a full stderr pipe would never exit.
            $command = [
                PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/release-tree.php', 'resolve-refs', $stage, $out,
            ];
            $process = proc_open($command, [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
            if (!is_resource($process)) {
                return 'could not start the reference-resolution process';
            }
            $status = proc_close($process);
            $json = (string) @file_get_contents($out);
            if ($status !== 0 || $json === '') {
                $output = trim((string) @file_get_contents($log));
                return "reference resolution did not finish (exit {$status}, no result written):\n"
                    . substr($output, -4000);
            }
            try {
                $result = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return 'reference resolution wrote unreadable output: ' . $e->getMessage();
            }
            if (!is_array($result)) {
                return 'reference resolution wrote unreadable output';
            }

            return $result;
        } finally {
            @unlink($out);
            @unlink($log);
        }
    }

    /**
     * Runs in the child. Every namespaced name the shipped PHP imports or
     * fully qualifies must resolve through the shipped autoloader. Unqualified
     * and global names are left alone: every Composer package here is
     * namespaced, and global symbols come from the app's own includes.
     *
     * Classes are resolved with findFile(), never loaded: some shipped class
     * files include the bootstrap, which connects to the database and exits.
     * Functions and constants from Composer's "files" entries are defined by
     * requiring autoload.php, which has no other side effect.
     *
     * @return array<string, string> unresolved name => first site
     */
    public static function unresolvedReferences(string $root): array
    {
        $loader = require $root . '/vendor/autoload.php';
        if (!$loader instanceof \Composer\Autoload\ClassLoader) {
            throw new \RuntimeException('vendor/autoload.php did not return a Composer ClassLoader');
        }
        $classResolves = static function (string $name) use ($loader): bool {
            if (
                class_exists($name, false) || interface_exists($name, false) || trait_exists($name, false)
                || enum_exists($name, false) || defined($name)
            ) {
                return true; // built in, or defined by autoload.php itself
            }
            $file = $loader->findFile($name);

            return is_string($file) && self::existsWithExactCase($file);
        };

        $unresolved = [];
        foreach (self::shippedPhpFiles($root) as $relative) {
            $code = file_get_contents("{$root}/{$relative}");
            if ($code === false) {
                throw new \RuntimeException("cannot read {$relative}");
            }
            foreach (self::referencesIn($code) as [$name, $kind, $line]) {
                if (isset($unresolved[$name]) || !str_contains($name, '\\')) {
                    continue;
                }
                $resolved = match ($kind) {
                    'function' => function_exists($name),
                    'const' => defined($name),
                    default => $classResolves($name),
                };
                if (!$resolved) {
                    $unresolved[$name] = "{$relative}:{$line}";
                }
            }
        }
        ksort($unresolved);

        return $unresolved;
    }

    /**
     * is_file() on a case-insensitive filesystem (macOS by default) finds
     * tracking202/Setup/X.php when the directory is tracking202/setup/, so the
     * verifier would pass on a Mac and fail on the Linux hosts that run the
     * zip. Compare each path component against the real directory listing.
     */
    private static function existsWithExactCase(string $file): bool
    {
        static $listings = [];
        if (!is_file($file)) {
            return false;
        }
        // Composer's paths contain '..' segments (vendor/composer/../../api),
        // so walk them rather than relying on realpath(), which keeps case.
        $parts = explode('/', trim(dirname($file), '/'));
        $parts[] = basename($file);
        $dir = str_starts_with($file, '/') ? '' : '.';
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                $dir = dirname($dir === '' ? '/' : $dir);
                continue;
            }
            $parent = $dir === '' ? '/' : $dir;
            if (!isset($listings[$parent])) {
                $entries = scandir($parent);
                $listings[$parent] = $entries === false ? [] : array_flip($entries);
            }
            if (!isset($listings[$parent][$part])) {
                return false;
            }
            $dir = rtrim($parent, '/') . '/' . $part;
        }

        return true;
    }

    /** @return list<string> root-relative paths, vendor/ excluded */
    private static function shippedPhpFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $file): bool => !($file->isDir() && $file->getPathname() === $root . '/vendor')
            )
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getPathname();
            $isPhp = str_ends_with($path, '.php');
            if (!$isPhp && $file->getExtension() === '' && $file->isFile()) {
                // Extensionless scripts such as bin/p202.
                $head = (string) @file_get_contents($path, false, null, 0, 64);
                $isPhp = str_starts_with($head, '#!') && str_contains(strtok($head, "\n") ?: '', 'php');
            }
            if ($isPhp) {
                $files[] = substr($path, strlen($root) + 1);
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Names a file imports (use statements at namespace level) or writes
     * fully qualified, with the kind of symbol each must be.
     *
     * @return list<array{0: string, 1: 'class'|'function'|'const', 2: int}>
     */
    public static function referencesIn(string $code): array
    {
        $tokens = token_get_all($code);
        $count = count($tokens);
        $refs = [];
        // Brace kinds: 'ns' for a bracketed namespace block, 'other' for any
        // class, function or control block. An import is a `use` outside
        // every 'other' block; inside one it is a trait use.
        $stack = [];
        $namespaceOpen = false;

        $significant = static function (int $from, int $step) use ($tokens, $count): ?int {
            for ($k = $from; $k >= 0 && $k < $count; $k += $step) {
                $t = $tokens[$k];
                if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                return $k;
            }
            return null;
        };

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                if ($token === '{') {
                    $stack[] = $namespaceOpen ? 'ns' : 'other';
                    $namespaceOpen = false;
                } elseif ($token === '}') {
                    array_pop($stack);
                } elseif ($token === ';') {
                    $namespaceOpen = false;
                }
                continue;
            }

            switch ($token[0]) {
                case T_NAMESPACE:
                    $namespaceOpen = true;
                    break;

                case T_CURLY_OPEN:
                case T_DOLLAR_OPEN_CURLY_BRACES:
                    $stack[] = 'other';
                    break;

                case T_USE:
                    $next = $significant($i + 1, 1);
                    if ($next === null || $tokens[$next] === '(' || in_array('other', $stack, true)) {
                        break; // closure use (...) or trait use
                    }
                    $text = '';
                    for ($j = $i + 1; $j < $count && $tokens[$j] !== ';'; $j++) {
                        $t = $tokens[$j];
                        if (is_array($t)) {
                            $text .= in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1];
                        } else {
                            $text .= $t;
                        }
                    }
                    foreach (self::parseImport($text) as [$name, $kind]) {
                        $refs[] = [$name, $kind, $token[2]];
                    }
                    $i = $j - 1; // resume at the ';'
                    break;

                case T_NAME_FULLY_QUALIFIED:
                    $name = ltrim($token[1], '\\');
                    $prev = $significant($i - 1, -1);
                    $next = $significant($i + 1, 1);
                    // new \A\B(...) and #[\A\B(...)] are classes despite the '('.
                    $classCall = $prev !== null && is_array($tokens[$prev])
                        && in_array($tokens[$prev][0], [T_NEW, T_ATTRIBUTE], true);
                    $kind = ($next !== null && $tokens[$next] === '(' && !$classCall) ? 'function' : 'class';
                    $refs[] = [$name, $kind, $token[2]];
                    break;
            }
        }

        return $refs;
    }

    /**
     * @return list<array{0: string, 1: 'class'|'function'|'const'}>
     */
    private static function parseImport(string $text): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        $kind = 'class';
        if (preg_match('/^(function|const) (.*)$/s', $text, $m) === 1) {
            $kind = $m[1];
            $text = $m[2];
        }

        $prefix = '';
        $items = $text;
        if (preg_match('/^([^{]*?)\s*\\\\?\s*\{(.*)\}$/s', $text, $m) === 1) {
            $prefix = trim(rtrim(trim($m[1]), '\\'));
            $items = $m[2];
        }

        $names = [];
        foreach (explode(',', $items) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue; // trailing comma in a group
            }
            $itemKind = $kind;
            if (preg_match('/^(function|const) (.*)$/s', $item, $m) === 1) {
                $itemKind = $m[1];
                $item = $m[2];
            }
            $name = trim(preg_split('/ as /i', $item)[0] ?? '');
            $name = ltrim($prefix === '' ? $name : $prefix . '\\' . $name, '\\');
            if ($name !== '') {
                /** @var 'class'|'function'|'const' $itemKind */
                $names[] = [$name, $itemKind];
            }
        }

        return $names;
    }

    /** @param list<string> $patterns */
    private function matches(string $entry, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $entry, FNM_PERIOD)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private static function children(string $dir): array
    {
        $entries = scandir($dir);
        if ($entries === false) {
            throw new \RuntimeException("cannot list {$dir}");
        }

        return array_values(array_diff($entries, ['.', '..']));
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!unlink($path)) {
                throw new \RuntimeException("cannot remove {$path}");
            }
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (self::children($path) as $child) {
            self::remove("{$path}/{$child}");
        }
        if (!rmdir($path)) {
            throw new \RuntimeException("cannot remove {$path}");
        }
    }

    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        $command = $argv[1] ?? '';
        $repoRoot = dirname(__DIR__, 2);

        try {
            if ($command === 'resolve-refs') {
                $root = $argv[2] ?? '';
                $out = $argv[3] ?? '';
                if ($root === '' || $out === '') {
                    fwrite(STDERR, "usage: release-tree.php resolve-refs <release directory> <output file>\n");
                    return 2;
                }
                $json = json_encode(self::unresolvedReferences($root), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                if (file_put_contents($out, $json) === false) {
                    throw new \RuntimeException("cannot write {$out}");
                }
                return 0;
            }

            $tree = self::load($repoRoot . '/build/release-manifest.php');

            switch ($command) {
                case 'check-manifest':
                    $problems = $tree->checkTracked(self::trackedFiles($repoRoot));
                    break;
                case 'prune':
                case 'verify':
                    $stage = rtrim($argv[2] ?? '', '/');
                    if ($stage === '' || !is_dir($stage)) {
                        fwrite(STDERR, "usage: release-tree.php {$command} <staged release directory>\n");
                        return 2;
                    }
                    if ($command === 'prune') {
                        $problems = $tree->prune($stage);
                        break;
                    }
                    $tracked = null;
                    if (isset($argv[3])) {
                        $list = file_get_contents($argv[3]);
                        if ($list === false) {
                            throw new \RuntimeException("cannot read tracked-file list {$argv[3]}");
                        }
                        $tracked = array_values(
                            array_filter(explode("\0", $list), static fn (string $f): bool => $f !== '')
                        );
                        if ($tracked === []) {
                            throw new \RuntimeException("tracked-file list {$argv[3]} is empty");
                        }
                    }
                    $problems = $tree->verify($stage, $tracked);
                    break;
                default:
                    fwrite(STDERR, "usage: release-tree.php check-manifest | prune <stage> | verify <stage>\n");
                    return 2;
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "release-tree {$command}: " . $e->getMessage() . "\n");
            return 1;
        }

        if ($problems !== []) {
            fwrite(STDERR, "release-tree {$command}: " . count($problems) . " problem(s)\n");
            foreach ($problems as $problem) {
                fwrite(STDERR, "  - {$problem}\n");
            }
            return 1;
        }
        echo "release-tree {$command}: ok\n";

        return 0;
    }

    /** @return list<string> */
    private static function trackedFiles(string $repoRoot): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(['git', '-C', $repoRoot, 'ls-files', '-z'], $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot run git ls-files');
        }
        $listing = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new \RuntimeException("git ls-files failed (exit {$status}): " . trim($stderr));
        }
        $files = array_values(array_filter(explode("\0", $listing), static fn (string $f): bool => $f !== ''));
        if ($files === []) {
            throw new \RuntimeException('git ls-files listed no files; run from a checkout');
        }

        return $files;
    }
}
