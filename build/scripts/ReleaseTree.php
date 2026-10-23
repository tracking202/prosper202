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
     * Directory listings read by existsWithExactCase(), per verification.
     * Cleared by verify() and unresolvedReferences(): a listing kept across
     * two verifications answers the second for a tree that has changed.
     *
     * @var array<string, array<string, int>>
     */
    private static array $listings = [];

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

        return array_merge($problems, self::caseCollisions($files));
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
        self::$listings = [];
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
        $problems = array_merge(
            $problems,
            self::caseCollisions(self::stagedPaths($stage)),
            self::verifyCase($stage),
            self::verifyVendor($stage),
            self::verifyPermissions($stage)
        );

        $result = self::resolveInChild($stage);
        if (is_string($result)) {
            $problems[] = $result;
        } else {
            $known = $this->manifest['known_unresolved'];
            foreach ($result as $name => $site) {
                if (!array_key_exists($name, $known)) {
                    $problems[] = "'{$name}' (referenced at {$site}) is not declared in the shipped code and"
                        . ' does not resolve through the shipped vendor/autoload.php; a runtime dependency is'
                        . ' missing from composer.json "require", or shipped code depends on a dev-only class';
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
     * tracked path. Staged on a case-insensitive filesystem, a tracked path can
     * be there only under another spelling, which a Linux host does not find.
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

    /**
     * Paths that differ only in case are one path on the default filesystems
     * of macOS and Windows, where most zips are extracted and many checkouts
     * made before the files are uploaded. There the two merge into whichever
     * spelling was written first, and on the Linux host they are copied to the
     * other spelling is missing. Until 1.9.79 tracking202/Redirect/ (one class)
     * sat beside tracking202/redirect/ (the click endpoints), and the zip
     * extracted on a Mac put every endpoint under Redirect/, so every tracking
     * link answered 404 (#250). Directories are compared, not only files: a
     * directory is where those two merged.
     *
     * @param list<string> $paths root-relative paths
     * @return list<string> problems
     */
    public static function caseCollisions(array $paths): array
    {
        $spellings = [];
        foreach ($paths as $path) {
            $prefix = '';
            foreach (explode('/', trim($path, '/')) as $part) {
                $prefix = $prefix === '' ? $part : "{$prefix}/{$part}";
                $spellings[mb_convert_case($prefix, MB_CASE_FOLD_SIMPLE, 'UTF-8')][$prefix] = true;
            }
        }
        ksort($spellings, SORT_STRING);

        $problems = [];
        foreach ($spellings as $variants) {
            if (count($variants) < 2) {
                continue;
            }
            $names = array_keys($variants);
            sort($names, SORT_STRING);
            $problems[] = "'" . implode("' and '", $names) . "' differ only in case: extracted on a Mac or"
                . ' Windows they merge into one, and a Linux host then finds the other missing; rename one';
        }

        return $problems;
    }

    /**
     * The shipped code's paths and class names, held to the case the tree
     * spells them in (pathCaseProblems(), classNameCaseProblems()).
     *
     * @return list<string> problems
     */
    private static function verifyCase(string $stage): array
    {
        $paths = self::stagedPaths($stage);
        $text = [];
        foreach ($paths as $path) {
            if (!str_starts_with($path, 'vendor/')) {
                $code = self::codeText("{$stage}/{$path}");
                if ($code !== null) {
                    $text[$path] = $code;
                }
            }
        }
        $problems = self::pathCaseProblems($text, $paths);
        if (!is_file("{$stage}/vendor/autoload.php")) {
            return $problems; // verifyVendor() reports it
        }
        try {
            $loadable = self::autoloadableNames($stage);
        } catch (\RuntimeException $e) {
            $problems[] = $e->getMessage();

            return $problems;
        }
        $php = [];
        foreach (self::shippedPhpFiles($stage) as $relative) {
            $php[$relative] = (string) self::codeText("{$stage}/{$relative}");
        }

        return array_merge($problems, self::classNameCaseProblems($php, $loadable));
    }

    /**
     * A file's text when it is code a path or class name could be written in:
     * not prose or data (a changelog names files it does not load), and not
     * binary (the Go CLI ships beside the code).
     */
    public static function codeText(string $file): ?string
    {
        $notCode = '/\.(png|ico|swf|gif|woff2?|jpe?g|ttf|eot|mmdb|zip|phar|jar|exe|md|txt|json|lock|csv)$/i';
        if (!is_file($file) || preg_match($notCode, $file) === 1) {
            return null;
        }
        $text = file_get_contents($file);
        if ($text === false) {
            throw new \RuntimeException("cannot read {$file}");
        }

        return str_contains(substr($text, 0, 8000), "\0") ? null : $text;
    }

    /** @return list<string> every path in the staged tree, relative to it */
    private static function stagedPaths(string $stage): array
    {
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $paths[] = substr($file->getPathname(), strlen($stage) + 1);
        }

        return $paths;
    }

    /**
     * Paths written in the code in another case than the file or directory
     * they name. A case-insensitive filesystem finds tracking202/Setup/x.php
     * when the directory is tracking202/setup/, so the code works on a Mac and
     * the include, link or asset is missing on Linux. Every path-like string
     * in a source is read, whatever builds the rest of it (__DIR__, a variable,
     * a host): its longest tail that names a real path when case is ignored
     * must name one exactly. A lone word with no extension is too common to
     * judge and is not read; nor is a path built from variables alone.
     *
     * @param array<string, string> $sources relative path => text
     * @param list<string> $paths every real file, relative to the same root
     * @return list<string> problems
     */
    public static function pathCaseProblems(array $sources, array $paths): array
    {
        $fold = static fn (string $s): string => mb_convert_case($s, MB_CASE_FOLD_SIMPLE, 'UTF-8');
        $byLast = [];
        foreach ($paths as $path) {
            $parts = explode('/', trim($path, '/'));
            for ($i = count($parts); $i >= 1; $i--) {
                $prefix = array_slice($parts, 0, $i);
                $byLast[$fold($parts[$i - 1])][implode('/', $prefix)] = $prefix;
            }
        }

        $problems = [];
        foreach ($sources as $source => $text) {
            $pathLike = '~[A-Za-z0-9_@.\-]+(?:/[A-Za-z0-9_@.\-]+)+/?|[A-Za-z0-9_\-.]+\.[A-Za-z0-9]{1,5}\b~';
            if (preg_match_all($pathLike, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
                // Not "no paths here": the file went unread.
                throw new \RuntimeException("cannot scan {$source} for paths: " . preg_last_error_msg());
            }
            foreach ($matches[0] as [$token, $offset]) {
                $segments = array_values(array_filter(
                    explode('/', $token),
                    static fn (string $s): bool => !in_array($s, ['', '.', '..'], true)
                ));
                if ($segments === []) {
                    continue;
                }
                $candidates = $byLast[$fold($segments[count($segments) - 1])] ?? [];
                for ($k = 0; $candidates !== [] && $k < count($segments); $k++) {
                    $tail = array_slice($segments, $k);
                    $length = count($tail);
                    $folded = array_map($fold, $tail);
                    $named = [];
                    foreach ($candidates as $candidate) {
                        $end = array_slice($candidate, -$length);
                        if (count($end) === $length && array_map($fold, $end) === $folded) {
                            $named[] = implode('/', $end);
                        }
                    }
                    if ($named === []) {
                        continue;
                    }
                    if ($length > 1 || preg_match('/\.[A-Za-z0-9]{1,5}$/', $tail[0]) === 1) {
                        $written = implode('/', $tail);
                        if (!in_array($written, $named, true)) {
                            $line = substr_count($text, "\n", 0, $offset) + 1;
                            $problems[] = "{$source}:{$line} writes '{$written}', which is '"
                                . implode("' or '", array_unique($named)) . "' on disk: found on a Mac,"
                                . ' missing on Linux';
                        }
                    }
                    break;
                }
            }
        }

        return $problems;
    }

    /**
     * The class names Composer's autoloader can load where case matters, as
     * it can load them: each PHP file under a PSR-4 or PSR-0 directory, named
     * by its path, and each classmap entry. Names a file's path spells in two
     * ways (a narrower PSR-4 prefix over a lowercase directory) are both kept.
     *
     * @return array<string, list<string>> lowercased name => spellings that load
     */
    public static function autoloadableNames(string $root): array
    {
        $maps = [];
        foreach (['autoload_psr4.php', 'autoload_namespaces.php', 'autoload_classmap.php'] as $file) {
            $path = "{$root}/vendor/composer/{$file}";
            if (!is_file($path)) {
                throw new \RuntimeException("{$path} is missing; run composer install");
            }
            $maps[$file] = require $path;
        }
        $names = [];
        $walk = static function (string $prefix, string $dir) use (&$names): void {
            if (!is_dir($dir)) {
                return;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                $relative = substr($file->getPathname(), strlen($dir) + 1);
                if (!str_ends_with($relative, '.php')) {
                    continue;
                }
                $segments = explode('/', substr($relative, 0, -4));
                foreach ($segments as $segment) {
                    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) !== 1) {
                        continue 2;
                    }
                }
                $name = $prefix . implode('\\', $segments);
                $names[strtolower($name)][$name] = true;
            }
        };
        foreach ($maps['autoload_psr4.php'] as $prefix => $dirs) {
            foreach ($dirs as $dir) {
                $walk($prefix, rtrim($dir, '/'));
            }
        }
        foreach ($maps['autoload_namespaces.php'] as $dirs) {
            foreach ($dirs as $dir) {
                $walk('', rtrim($dir, '/')); // PSR-0 names the whole path below the directory
            }
        }
        foreach (array_keys($maps['autoload_classmap.php']) as $name) {
            $names[strtolower($name)][$name] = true;
        }

        return array_map('array_keys', $names);
    }

    /**
     * Class names a file writes, as PHP resolves them: qualified and imported
     * names, unqualified ones where a class can stand, and string literals
     * shaped like a qualified class name (handed to class_exists() or new).
     *
     * @return list<array{string, int}> name, line
     */
    public static function classNamesIn(string $code): array
    {
        $names = [];
        foreach (self::referencesIn($code, true) as [$name, $kind, $line]) {
            if (in_array($kind, ['class', 'name', 'import'], true)) {
                $names[] = [$name, $line];
            }
        }
        foreach (token_get_all($code) as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $body = substr($token[1], 1, -1);
            $text = $token[1][0] === '"' ? stripcslashes($body) : str_replace('\\\\', '\\', $body);
            if (preg_match('/^\\\\?[A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)+$/', $text) === 1) {
                $names[] = [ltrim($text, '\\'), $token[2]];
            }
        }

        return $names;
    }

    /**
     * Class names written in another case than any spelling the autoloader
     * can load. PHP's class names ignore case, Composer's file lookup does not
     * where the filesystem does not: `new Foo\barHelper()` finds BarHelper.php
     * on a Mac and nothing on Linux, unless something already loaded it.
     *
     * @param array<string, string> $sources relative path => code
     * @param array<string, list<string>> $loadable autoloadableNames()
     * @return list<string> problems
     */
    public static function classNameCaseProblems(array $sources, array $loadable): array
    {
        $problems = [];
        foreach ($sources as $source => $code) {
            foreach (self::classNamesIn($code) as [$name, $line]) {
                $spellings = $loadable[strtolower($name)] ?? null;
                if ($spellings === null || in_array($name, $spellings, true)) {
                    continue;
                }
                $problems[] = "{$source}:{$line} writes {$name}, which the autoloader loads only as "
                    . implode(' or ', $spellings) . ': found on a Mac, not found on Linux';
            }
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
        $onDisk = [];
        foreach ($installed['versions'] as $name => $info) {
            // The root package, and names that are only provided or replaced
            // by another package, have no version of their own. A metapackage
            // has a version but no install_path: it is installed and owns no
            // files.
            $isRoot = $name === ($installed['root']['name'] ?? null);
            if ($isRoot || !isset($info['pretty_version'])) {
                continue;
            }
            $present[$name] = (string) $info['pretty_version'];
            $onDisk[$name] = isset($info['install_path']);
        }

        foreach ($locked as $name => $version) {
            if (!isset($present[$name])) {
                $problems[] = "locked runtime package {$name} {$version} is not installed in vendor/";
            } elseif ($present[$name] !== $version) {
                $problems[] = "vendor/ has {$name} {$present[$name]} but composer.lock pins {$version}";
            } elseif ($onDisk[$name] && !is_dir("{$stage}/vendor/{$name}")) {
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
        // Collected, then sorted: the walk is in the filesystem's directory
        // order, which differs between filesystems, so the same tree would
        // otherwise list its problems (and pick its first 20) differently.
        $writable = [];
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
                $writable[substr($file->getPathname(), strlen($stage) + 1)] = $file->getPerms() & 0o777;
            }
        }
        ksort($writable, SORT_STRING);

        $problems = [];
        foreach ($writable as $path => $perms) {
            if (count($problems) >= 20) {
                $problems[] = '... further permission problems not listed';
                break;
            }
            $problems[] = sprintf("'%s' is group- or world-writable (%o); shared hosts running suPHP answer 500 for it", $path, $perms);
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
     * writes qualified (Commands\Foo, resolved through the file's namespace
     * and imports, or \Full\Name) must be declared in the shipped code or
     * found by the shipped autoloader. Unqualified names, global names and
     * class names in strings are not seen: every Composer package here is
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
        self::$listings = [];
        $loader = require $root . '/vendor/autoload.php';
        if (!$loader instanceof \Composer\Autoload\ClassLoader) {
            throw new \RuntimeException('vendor/autoload.php did not return a Composer ClassLoader');
        }
        // Read every shipped file once: its references, and the classes it
        // declares. A class declared in the shipped tree is present whether or
        // not PSR-4 can find it; page controllers live beside their pages in
        // lowercase URL directories (tracking202/setup/) and are loaded by an
        // explicit require_once, which no autoloader lookup can see.
        $declared = [];
        $references = [];
        foreach (self::shippedPhpFiles($root) as $relative) {
            $code = file_get_contents("{$root}/{$relative}");
            if ($code === false) {
                throw new \RuntimeException("cannot read {$relative}");
            }
            foreach (self::declarationsIn($code) as $name) {
                $declared[strtolower($name)] = true;
            }
            $references[$relative] = self::referencesIn($code);
        }

        $classResolves = static function (string $name) use ($loader, $declared): bool {
            if (
                class_exists($name, false) || interface_exists($name, false) || trait_exists($name, false)
                || enum_exists($name, false)
            ) {
                return true; // built in, or loaded by autoload.php itself
            }
            if (isset($declared[strtolower($name)])) {
                // Present whatever the case it is written in; a spelling the
                // autoloader cannot load is verifyCase()'s.
                return true;
            }
            $file = $loader->findFile($name);

            return is_string($file) && self::existsWithExactCase($file);
        };

        // `use A\B;` may import a namespace rather than a class (B\C::run()
        // then names A\B\C, which is checked where it is written). Such an
        // import is a prefix of something the tree declares or the autoloader
        // maps, or a directory under a PSR-4 root.
        $known = array_merge(
            array_keys($declared),
            array_map('strtolower', array_keys($loader->getClassMap())),
            array_map('strtolower', array_keys($loader->getPrefixesPsr4())),
            array_map('strtolower', array_keys($loader->getPrefixes())),
        );
        $psr4 = $loader->getPrefixesPsr4();
        $isNamespace = static function (string $name) use ($known, $psr4): bool {
            $prefix = strtolower($name) . '\\';
            foreach ($known as $candidate) {
                if (str_starts_with($candidate, $prefix)) {
                    return true;
                }
            }
            foreach ($psr4 as $root => $dirs) {
                if (!str_starts_with($name . '\\', $root)) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($name, strlen($root)));
                foreach ($dirs as $dir) {
                    if (self::existsWithExactCase(rtrim($dir, '/') . '/' . $relative)) {
                        return true;
                    }
                }
            }
            return false;
        };

        $unresolved = [];
        foreach ($references as $relative => $refs) {
            foreach ($refs as [$name, $kind, $line]) {
                if (isset($unresolved[$name]) || !str_contains($name, '\\')) {
                    continue;
                }
                $resolved = match ($kind) {
                    'function' => function_exists($name),
                    'const' => defined($name),
                    'import' => $classResolves($name) || $isNamespace($name),
                    'name' => $classResolves($name) || defined($name),
                    default => $classResolves($name), // 'class': a constant cannot answer
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
        if (!file_exists($file)) {
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
            if (!isset(self::$listings[$parent])) {
                $entries = scandir($parent);
                self::$listings[$parent] = $entries === false ? [] : array_flip($entries);
            }
            if (!isset(self::$listings[$parent][$part])) {
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
                // Extensionless scripts such as bin/p202. An unreadable file
                // is a problem, not a non-PHP file.
                $head = file_get_contents($path, false, null, 0, 64);
                if ($head === false) {
                    throw new \RuntimeException("cannot read {$path}");
                }
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
     * Names a file imports (use statements at namespace level, kind 'import'
     * for a class or namespace) or writes qualified, with the kind of symbol
     * each must be. A qualified name (Commands\Foo, namespace\Foo) is resolved
     * the way PHP resolves it: its first segment through the current
     * namespace's class imports, otherwise under the current namespace.
     * 'class' is a position only a class can fill (new, instanceof, extends,
     * implements, an attribute, a trait use, before ::); 'name' is a type, a
     * catch target or a bare qualified name, which is a class or a namespaced
     * constant.
     *
     * With $unqualified, a name written without a namespace in a position a
     * class can fill (one of those, the rest of an implements, extends, trait
     * or catch list, a parameter, property, return or constant type) is
     * listed too, as 'class', resolved as PHP resolves it: through an import
     * when one has that alias (the import is then what the autoloader is
     * asked for, so the name itself is not listed), otherwise under the
     * current namespace. A word that only reads as a type there (a constant
     * beside |, a constant before a variable) is listed as well.
     *
     * @return list<array{0: string, 1: 'class'|'name'|'function'|'const'|'import', 2: int}>
     */
    public static function referencesIn(string $code, bool $unqualified = false): array
    {
        $tokens = token_get_all($code);
        $count = count($tokens);
        $refs = [];
        // Brace kinds: 'ns' for a bracketed namespace block, 'other' for any
        // class, function or control block. An import is a `use` outside
        // every 'other' block; inside one it is a trait use.
        $stack = [];
        $namespaceOpen = false;
        $namespace = '';
        $aliases = []; // lowercased alias => imported class or namespace
        // Inside an implements, extends, trait-use or catch list every name is
        // a class; the list ends at one of these.
        $classListEnds = [];

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
        // What a name is depends on where it sits. After `new`, `instanceof`,
        // `extends`, `implements`, `#[` or a trait `use`, or before `::`, only
        // a class can stand ('class'). Before '(' it is a function call ('function').
        // Anywhere else — a type, a catch target, a bare constant — the same
        // spelling is a class or a namespaced constant ('name'), which the
        // resolver tries in that order. Only 'name' may be answered by a
        // constant: a class the tree lost is not made present by a constant
        // of the same name.
        $kindAt = static function (int $i) use ($tokens, $significant): string {
            $prev = $significant($i - 1, -1);
            $next = $significant($i + 1, 1);
            $prevId = $prev !== null && is_array($tokens[$prev]) ? $tokens[$prev][0] : null;
            $nextId = $next !== null && is_array($tokens[$next]) ? $tokens[$next][0] : null;
            if (
                in_array($prevId, [T_NEW, T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS, T_ATTRIBUTE, T_USE], true)
                || $nextId === T_DOUBLE_COLON
            ) {
                return 'class';
            }
            if ($next !== null && $tokens[$next] === '(') {
                return 'function';
            }

            return 'name';
        };

        // Whether an unqualified word stands where a class can: kindAt()'s
        // class positions, a list kept open above, or a type.
        $notClasses = [
            'self', 'static', 'parent', 'int', 'float', 'string', 'bool', 'array', 'callable', 'iterable',
            'object', 'mixed', 'void', 'never', 'null', 'false', 'true',
        ];
        $id = static fn (?int $k): mixed => $k === null ? null : (is_array($tokens[$k]) ? $tokens[$k][0] : $tokens[$k]);
        $notBefore = [
            T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION,
            T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_GOTO,
        ];
        $classAt = static function (
            int $i,
            bool $inList
        ) use (
            $tokens,
            $significant,
            $kindAt,
            $notClasses,
            $id,
            $notBefore
        ): bool {
            if (in_array(strtolower($tokens[$i][1]), $notClasses, true)) {
                return false;
            }
            $prev = $significant($i - 1, -1);
            $next = $significant($i + 1, 1);
            if (in_array($id($prev), $notBefore, true)) {
                return false; // a member, a declaration's own name, a label
            }
            if ($id($prev) === T_CASE && $id($next) !== T_DOUBLE_COLON) {
                return false; // an enum case; case Foo::BAR: names a class
            }
            if ($id($prev) === T_CONST) {
                return $id($next) === T_STRING; // const Type NAME = …, not const NAME = …
            }
            if ($kindAt($i) === 'class' || $inList) {
                return true;
            }
            $nextId = $id($next);
            if ($nextId === '(') {
                return false; // a call; new Foo( is a class position above
            }
            if ($nextId === T_VARIABLE || $nextId === T_ELLIPSIS || $nextId === '|' || $id($prev) === '|') {
                return true; // Type $x, Type ...$x, A|B
            }
            // PHP 8.1 tokenizes every & as one of these two, never as '&'.
            if ($nextId === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG) {
                return true; // Type &$x
            }
            $and = T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG;
            if ($nextId === $and || $id($prev) === $and) {
                return true; // A&B
            }
            $colon = $id($prev) === '?' ? $significant($prev - 1, -1) : $prev;
            if ($id($colon) === ':' && $id($significant($colon - 1, -1)) === ')') {
                return true; // ): Type, ): ?Type
            }

            return false;
        };

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                if (in_array($token, $classListEnds, true)) {
                    $classListEnds = [];
                }
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
                    // namespace A\B; or namespace A\B { }; a bare namespace { }
                    // is the global one. Imports belong to the namespace that
                    // made them.
                    $namespace = '';
                    $aliases = [];
                    for ($j = $i + 1; $j < $count && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                        $t = $tokens[$j];
                        if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                            $namespace = $t[1];
                        }
                    }
                    $i = $j - 1; // resume at the ';' or '{'
                    $namespaceOpen = true;
                    break;

                case T_CURLY_OPEN:
                case T_DOLLAR_OPEN_CURLY_BRACES:
                    $stack[] = 'other';
                    break;

                case T_USE:
                    $next = $significant($i + 1, 1);
                    if ($next === null || $tokens[$next] === '(' || in_array('other', $stack, true)) {
                        if ($next !== null && $tokens[$next] !== '(') {
                            $classListEnds = [';', '{'];
                        }
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
                    foreach (self::parseImport($text) as [$name, $kind, $alias]) {
                        if ($kind === 'class') {
                            $aliases[strtolower($alias)] = $name;
                            $kind = 'import';
                        }
                        $refs[] = [$name, $kind, $token[2]];
                    }
                    $i = $j - 1; // resume at the ';'
                    break;

                case T_IMPLEMENTS:
                case T_EXTENDS:
                    $classListEnds = ['{'];
                    break;

                case T_CATCH:
                    $classListEnds = [')'];
                    break;

                case T_STRING:
                    if (!$unqualified || !$classAt($i, $classListEnds !== [])) {
                        break;
                    }
                    if (isset($aliases[strtolower($token[1])])) {
                        break; // the import is what is asked for, and is listed
                    }
                    $refs[] = [ltrim($namespace . '\\' . $token[1], '\\'), 'class', $token[2]];
                    break;

                case T_NAME_FULLY_QUALIFIED:
                    $refs[] = [ltrim($token[1], '\\'), $kindAt($i), $token[2]];
                    break;

                case T_NAME_RELATIVE:
                    // namespace\A\B is A\B under the current namespace.
                    $name = substr($token[1], strlen('namespace\\'));
                    $refs[] = [ltrim($namespace . '\\' . $name, '\\'), $kindAt($i), $token[2]];
                    break;

                case T_NAME_QUALIFIED:
                    [$first, $rest] = explode('\\', $token[1], 2);
                    $name = isset($aliases[strtolower($first)])
                        ? $aliases[strtolower($first)] . '\\' . $rest
                        : ltrim($namespace . '\\' . $token[1], '\\');
                    $refs[] = [$name, $kindAt($i), $token[2]];
                    break;
            }
        }

        return $refs;
    }

    /**
     * Fully qualified names of the classes, interfaces, traits and enums a
     * file declares. Skips `Foo::class` and anonymous `new class`.
     *
     * @return list<string>
     */
    public static function declarationsIn(string $code): array
    {
        $tokens = token_get_all($code);
        $count = count($tokens);
        $namespace = '';
        $names = [];
        $prev = null; // last significant token
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($t) && $t[0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    $n = $tokens[$j];
                    if ($n === ';' || $n === '{') {
                        break;
                    }
                    if (is_array($n) && in_array($n[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                        $namespace = $n[1];
                    }
                }
                $i = $j;
                $prev = null;
                continue;
            }
            $isDeclaration = is_array($t) && in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
                && !(is_array($prev) && in_array($prev[0], [T_DOUBLE_COLON, T_NEW], true));
            if ($isDeclaration) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $n = $tokens[$j];
                    if (is_array($n) && $n[0] === T_WHITESPACE) {
                        continue;
                    }
                    if (is_array($n) && $n[0] === T_STRING) {
                        $names[] = ltrim($namespace . '\\' . $n[1], '\\');
                    }
                    break;
                }
            }
            $prev = $t;
        }

        return $names;
    }

    /**
     * @return list<array{0: string, 1: 'class'|'function'|'const', 2: string}> name, kind, alias
     */
    private static function parseImport(string $text): array
    {
        $collapsed = preg_replace('/\s+/', ' ', $text);
        if ($collapsed === null) {
            throw new \RuntimeException('cannot parse import: ' . preg_last_error_msg() . ": use {$text}");
        }
        $text = trim($collapsed);
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
            $parts = preg_split('/ as /i', $item);
            if ($parts === false) {
                throw new \RuntimeException('cannot parse import: ' . preg_last_error_msg() . ": use {$text}");
            }
            $name = trim($parts[0]);
            $name = ltrim($prefix === '' ? $name : $prefix . '\\' . $name, '\\');
            if ($name !== '') {
                // The alias is the `as` name, otherwise the last segment.
                $slash = strrpos($name, '\\');
                $alias = isset($parts[1]) ? trim($parts[1]) : ($slash === false ? $name : substr($name, $slash + 1));
                /** @var 'class'|'function'|'const' $itemKind */
                $names[] = [$name, $itemKind, $alias];
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
    public static function trackedFiles(string $repoRoot): array
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
