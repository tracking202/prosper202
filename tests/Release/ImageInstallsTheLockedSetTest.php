<?php

declare(strict_types=1);

namespace Tests\Release;

use Tests\TestCase;

/**
 * The container image installs composer's locked set, the one the release
 * zip ships. With composer.json alone an install resolves whatever matches it
 * that day, so two images of one commit could run different dependencies and
 * an upstream release could change a published image with no commit; and
 * `composer install` against a lock that has drifted from composer.json only
 * warns and installs the old set, which `composer validate` refuses.
 *
 * The Dockerfile is read by its instructions: the install RUN must directly
 * follow `COPY composer.json composer.lock <WORKDIR>/` (the vendor layer is
 * keyed on both files) and run `composer validate`, then
 * `composer install --no-dev`, as one `&&` chain of plain words. Whatever the
 * reading does not know near composer (quoting, another separator, a RUN flag,
 * a heredoc, a JSON form, an escape directive, SHELL, an unknown instruction,
 * a composer variable other than the ones listed) is refused by name, the loud
 * direction. The Docker Image workflow checks the built image's vendor/
 * against composer.lock; this holds the shape that makes it so.
 *
 * The entrypoint, which installs at startup when vendor/ is missing (the base
 * image with a checkout bind-mounted), is run as it is, against the real
 * `composer validate`.
 */
final class ImageInstallsTheLockedSetTest extends TestCase
{
    private const REPO = __DIR__ . '/../..';

    private const COPY = 'COPY composer.json composer.lock /var/www/html/';

    private const RUN = "RUN composer validate --no-check-publish --no-check-all \\\n"
        . '    && composer install --no-dev --no-interaction --no-scripts --no-autoloader --no-progress';

    /** Composer variables the Dockerfile may set: COMPOSER renames composer.json, and its lock with it. */
    private const COMPOSER_VARIABLES = ['COMPOSER_ALLOW_SUPERUSER'];

    private const INSTRUCTIONS = [
        'FROM', 'RUN', 'CMD', 'LABEL', 'MAINTAINER', 'EXPOSE', 'ENV', 'ADD', 'COPY', 'ENTRYPOINT', 'VOLUME',
        'USER', 'WORKDIR', 'ARG', 'ONBUILD', 'STOPSIGNAL', 'HEALTHCHECK', 'SHELL',
    ];

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            self::removeTree($dir);
        }
        parent::tearDown();
    }

    public function testTheDefaultStageInstallsTheLockedSet(): void
    {
        self::assertFileExists(self::REPO . '/composer.lock');
        [$problems, $installs] = self::read(self::file('Dockerfile'), self::file('composer.json'));

        self::assertSame([], $problems, "The image does not install composer.lock's set:\n" . implode("\n", $problems));
        self::assertSame(1, $installs, 'the reading found the install');
    }

    /** @dataProvider equivalentShapes */
    public function testAnEquivalentSpellingIsRead(string $from, string $to): void
    {
        $dockerfile = self::planted(self::file('Dockerfile'), $from, $to);
        [$problems, $installs] = self::read($dockerfile, self::file('composer.json'));

        self::assertSame([], $problems, implode("\n", $problems));
        self::assertSame(1, $installs);
    }

    /** @return iterable<string, array{string, string}> */
    public static function equivalentShapes(): iterable
    {
        yield 'the copy relative to the workdir' => [self::COPY, 'COPY composer.json composer.lock ./'];
        yield 'the copy in the other order, owned' => [
            self::COPY, 'COPY --chown=www-data:www-data composer.lock ./composer.json /var/www/html/',
        ];
        yield 'the chain on one line, fewer flags' => [
            self::RUN, 'RUN composer validate && composer install --no-dev',
        ];
    }

    /** @dataProvider plantedShapes */
    public function testAPlantedShapeIsRefused(
        string $from,
        string $to,
        string $expected,
        string $file = 'Dockerfile'
    ): void {
        $dockerfile = self::file('Dockerfile');
        $composer = self::file('composer.json');
        if ($file === 'Dockerfile') {
            $dockerfile = self::planted($dockerfile, $from, $to);
        } else {
            $composer = self::planted($composer, $from, $to);
        }

        [$problems] = self::read($dockerfile, $composer);

        self::assertNotSame([], $problems, 'the planted shape was read as an install from the lock');
        self::assertStringContainsString($expected, implode("\n", $problems));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string, 3?: string}> */
    public static function plantedShapes(): iterable
    {
        $before = 'must directly follow COPY composer.json composer.lock';
        yield 'composer.json copied alone (1.9.78)' => [
            self::COPY, 'COPY composer.json /var/www/html/composer.json', $before,
        ];
        yield 'the lock copied by a glob, which skips an ignored lock in silence' => [
            self::COPY, 'COPY composer.* /var/www/html/', $before,
        ];
        yield 'the whole context copied first' => [self::COPY, 'COPY . /var/www/html/', $before];
        yield 'the files copied beside the workdir' => [
            self::COPY, 'COPY composer.json composer.lock /var/www/', $before,
        ];
        yield 'the files copied under a moved workdir' => [
            self::COPY, "WORKDIR /srv\n" . self::COPY, $before,
        ];
        yield 'the files copied from another stage' => [
            self::COPY, 'COPY --from=base composer.json composer.lock /var/www/html/', 'COPY --from',
        ];
        yield 'an instruction between the copy and the install' => [
            self::COPY, self::COPY . "\nENV P202=1", $before,
        ];
        yield 'no validate' => [
            self::RUN, 'RUN composer install --no-dev --no-interaction', 'composer validate',
        ];
        yield 'validate after the install' => [
            self::RUN, 'RUN composer install --no-dev && composer validate', 'composer validate',
        ];
        yield 'validate told not to check the lock' => [
            '--no-check-all', '--no-check-all --no-check-lock', '--no-check-lock',
        ];
        yield "validate's failure ignored" => [
            '--no-check-all \\', '--no-check-all || true \\', "'|' in a RUN",
        ];
        yield 'a semicolon where && was' => ['    && composer install', '    ; composer install', "';' in a RUN"];
        yield 'the dev set' => [
            ' --no-dev --no-interaction --no-scripts', ' --no-interaction --no-scripts', 'without --no-dev',
        ];
        yield 'an update' => ['&& composer install', '&& composer update', 'composer update'];
        yield 'another working directory' => [
            '--no-dev --no-interaction --no-scripts', '--no-dev -d /tmp --no-interaction --no-scripts', '-d',
        ];
        yield 'composer run by another name' => [
            '&& composer install', '&& php /usr/local/bin/composer install', 'names composer in a way',
        ];
        yield 'a flag before the command' => ['&& composer install', '&& composer -n install', 'subcommand'];
        yield 'quoting' => ['--no-progress', "--no-progress '--dev'", "''' in a RUN"];
        yield 'a cache mount' => [
            'RUN composer validate', 'RUN --mount=type=cache,target=/root/.cache composer validate', 'RUN flag',
        ];
        yield 'a JSON-form install' => [self::RUN, 'RUN ["composer", "install", "--no-dev"]', 'JSON form'];
        yield 'a heredoc install' => [self::RUN, "RUN <<EOF\ncomposer install --no-dev\nEOF", 'heredoc'];
        yield 'composer renamed' => [self::COPY, "ENV COMPOSER=composer-other.json\n" . self::COPY, 'COMPOSER='];
        yield 'vendor/ moved by an ARG' => [
            self::COPY, "ARG COMPOSER_VENDOR_DIR\n" . self::COPY, 'COMPOSER_VENDOR_DIR',
        ];
        yield 'the install gone' => [self::RUN, 'RUN true', 'installs nothing'];
        yield 'vendor/ brought from another stage' => [
            'RUN composer dump-autoload',
            "COPY --from=base /var/www/html/vendor /var/www/html/vendor\nRUN composer dump-autoload",
            'vendor',
        ];
        yield 'another shell' => [
            'WORKDIR /var/www/html', "SHELL [\"/bin/sh\", \"-c\"]\nWORKDIR /var/www/html", 'SHELL',
        ];
        yield 'an escape directive' => ['# Stage layout:', "# escape=`\n# Stage layout:", 'escape'];
        yield 'an ADD of the lock' => [self::COPY, "ADD composer.lock /var/www/html/\n" . self::COPY, 'ADD'];
        yield 'the lock switched off in composer.json' => [
            '"config": {', "\"config\": {\n    \"lock\": false,", 'config.lock', 'composer.json',
        ];
    }

    public function testTheEntrypointInstallsFromAFreshLock(): void
    {
        $run = $this->runEntrypoint(self::file('composer.json'), self::file('composer.lock'));

        self::assertSame(0, $run['exit'], $run['output']);
        self::assertSame(['validate', 'install'], self::subcommands($run['calls']), $run['output']);
        self::assertContains('--no-dev', $run['calls'][1], 'APP_ENV is unset: the production install');
        self::assertTrue($run['started'], 'the container command ran');
    }

    public function testTheEntrypointRefusesALockThatHasDrifted(): void
    {
        $lock = self::file('composer.lock');
        $json = json_decode(self::file('composer.json'), true, 512, JSON_THROW_ON_ERROR);
        // A locked package required by name: the lock still satisfies it, so
        // install would only warn; only the content hash has moved.
        $locked = json_decode($lock, true, 512, JSON_THROW_ON_ERROR)['packages'];
        $extra = array_values(array_diff(array_column($locked, 'name'), array_keys($json['require'])))[0];
        $json['require'][$extra] = '*';

        $run = $this->runEntrypoint(json_encode($json, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), $lock);

        self::assertSame(2, $run['exit'], "composer validate's error status\n" . $run['output']);
        self::assertStringContainsString('lock file is not up to date', $run['output']);
        self::assertSame(['validate'], self::subcommands($run['calls']));
        self::assertFalse($run['started'], 'the container did not start');
    }

    public function testTheEntrypointRefusesToResolveWithoutALock(): void
    {
        $run = $this->runEntrypoint(self::file('composer.json'), null);

        self::assertSame(1, $run['exit'], $run['output']);
        self::assertStringContainsString('composer.lock is missing', $run['output']);
        self::assertSame([], $run['calls']);
        self::assertFalse($run['started']);
    }

    public function testTheEntrypointLeavesAnInstalledVendorAlone(): void
    {
        $run = $this->runEntrypoint(self::file('composer.json'), null, vendorInstalled: true);

        self::assertSame(0, $run['exit'], $run['output']);
        self::assertSame([], $run['calls']);
        self::assertTrue($run['started']);
    }

    /**
     * Runs build/scripts/docker-entrypoint.sh itself, its `cd /var/www/html`
     * sent to a scratch directory by an exported function. `composer` is a
     * stub that runs the real composer for validate (whose verdict on the
     * lock is the subject) and stands in for install, which needs a network.
     *
     * @return array{exit: int, output: string, calls: list<list<string>>, started: bool}
     */
    private function runEntrypoint(string $composerJson, ?string $composerLock, bool $vendorInstalled = false): array
    {
        $composer = trim((string) shell_exec('command -v composer 2>/dev/null'));
        if ($composer === '') {
            self::markTestSkipped('composer is not on PATH; the entrypoint is tested against its real validate');
        }
        $root = sys_get_temp_dir() . '/p202-entrypoint-' . bin2hex(random_bytes(6));
        $this->tempDirs[] = $root;
        foreach (["{$root}/app/vendor", "{$root}/bin", "{$root}/home"] as $dir) {
            self::assertTrue(mkdir($dir, 0755, true), "cannot create {$dir}");
        }
        self::write("{$root}/app/composer.json", $composerJson);
        if ($composerLock !== null) {
            self::write("{$root}/app/composer.lock", $composerLock);
        }
        if ($vendorInstalled) {
            self::write("{$root}/app/vendor/autoload.php", "<?php\n");
        }
        self::write("{$root}/bin/composer", "#!/bin/bash\n"
            . "printf '%s\\n' \"\$*\" >> \"\$P202_TEST_COMPOSER_LOG\"\n"
            . "if [ \"\$1\" = validate ]; then exec \"\$P202_TEST_REAL_COMPOSER\" \"\$@\"; fi\n"
            . "if [ \"\$1\" = install ]; then : > vendor/autoload.php; exit 0; fi\n"
            . "echo \"the stub does not run composer \$1\" >&2\nexit 97\n");
        self::assertTrue(chmod("{$root}/bin/composer", 0755));

        $env = [
            'PATH' => "{$root}/bin:" . (string) getenv('PATH'),
            'HOME' => "{$root}/home",
            'COMPOSER_HOME' => "{$root}/home",
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'BASH_FUNC_cd%%' => '() { builtin cd "$P202_TEST_APP_DIR"; }',
            'P202_TEST_APP_DIR' => "{$root}/app",
            'P202_TEST_COMPOSER_LOG' => "{$root}/calls.log",
            'P202_TEST_REAL_COMPOSER' => $composer,
        ];
        $process = proc_open(
            ['bash', self::REPO . '/build/scripts/docker-entrypoint.sh', 'touch', "{$root}/started"],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            "{$root}/home",
            $env
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);

        $log = is_file("{$root}/calls.log") ? (string) file_get_contents("{$root}/calls.log") : '';
        $calls = [];
        foreach (preg_split('/\n/', $log, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            $calls[] = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return ['exit' => $exit, 'output' => $output, 'calls' => $calls, 'started' => is_file("{$root}/started")];
    }

    /**
     * @param list<list<string>> $calls
     * @return list<string>
     */
    private static function subcommands(array $calls): array
    {
        return array_map(static fn (array $call): string => $call[0] ?? '', $calls);
    }

    /**
     * @return array{0: list<string>, 1: int} problems, and how many composer
     *                                        installs the reading checked
     */
    private static function read(string $dockerfile, string $composerJson): array
    {
        $problems = [];
        $config = json_decode($composerJson, true, 512, JSON_THROW_ON_ERROR);
        if (($config['config']['lock'] ?? true) !== true) {
            $problems[] = 'composer.json sets config.lock: composer then ignores composer.lock, and validate and'
                . ' install both pass with the set resolved fresh';
        }

        /** @var array<string, array{workdir: ?string, parent: ?string, installs: int}> $stages */
        $stages = [];
        $stage = null;
        $installs = 0;
        $previous = null;
        foreach (self::instructions($dockerfile, $problems) as [$line, $name, $args]) {
            $mentionsComposer = stripos($args, 'composer') !== false;
            if (str_contains($args, '<<') && in_array($name, ['RUN', 'COPY', 'ADD'], true)) {
                $problems[] = "Dockerfile:{$line}: a heredoc, which this test does not read; teach read() first";
                $previous = null;
                continue;
            }
            switch ($name) {
                case 'FROM':
                    $words = preg_split('/\s+/', $args, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    $words = array_values(array_filter($words, static fn (string $w): bool => $w[0] !== '-'));
                    $image = strtolower($words[0] ?? '');
                    $named = count($words) === 3 && strtolower($words[1]) === 'as';
                    $stage = $named ? strtolower($words[2]) : '#' . count($stages);
                    $stages[$stage] = [
                        'workdir' => isset($stages[$image]) ? $stages[$image]['workdir'] : null,
                        'parent' => isset($stages[$image]) ? $image : null,
                        'installs' => 0,
                    ];
                    break;
                case 'WORKDIR':
                    if ($stage !== null) {
                        $stages[$stage]['workdir'] = self::resolve(trim($args), $stages[$stage]['workdir']);
                    }
                    break;
                case 'SHELL':
                    $problems[] = "Dockerfile:{$line}: SHELL changes what a RUN's && means; this test reads /bin/sh -c";
                    break;
                case 'ENV':
                case 'ARG':
                    preg_match_all('/\bCOMPOSER[A-Z0-9_]*/', $args, $m);
                    foreach (array_diff($m[0], self::COMPOSER_VARIABLES) as $variable) {
                        $problems[] = "Dockerfile:{$line}: {$name} sets {$variable}=…, which can move the files"
                            . ' composer installs from or to; list it in COMPOSER_VARIABLES only if it cannot';
                    }
                    break;
                case 'COPY':
                case 'ADD':
                    $problems = [...$problems, ...self::copyProblems($line, $name, $args)];
                    break;
                case 'RUN':
                    if (!$mentionsComposer) {
                        break;
                    }
                    [$runProblems, $installed] = self::runProblems($line, $args);
                    $problems = [...$problems, ...$runProblems];
                    if ($installed) {
                        $installs++;
                        if ($stage !== null) {
                            $stages[$stage]['installs']++;
                        }
                        $workdir = $stage === null ? null : $stages[$stage]['workdir'];
                        if ($previous === null || !self::copiesTheLockInto($previous, $workdir)) {
                            $problems[] = "Dockerfile:{$line}: composer install must directly follow COPY composer.json"
                                . ' composer.lock ' . ($workdir ?? '<a WORKDIR this test can read>') . '/ (both files,'
                                . ' named, into the directory composer runs in; nothing between)';
                        }
                    }
                    break;
                default:
                    if ($mentionsComposer && $name !== 'CMD' && $name !== 'ENTRYPOINT' && $name !== 'LABEL') {
                        $problems[] = "Dockerfile:{$line}: {$name} names composer, which this test does not read";
                    }
            }
            $previous = $name === 'COPY' ? $args : null;
        }

        // The default target is the last stage; it holds what its ancestry installed.
        $installed = 0;
        for ($at = array_key_last($stages); $at !== null; $at = $stages[$at]['parent']) {
            $installed += $stages[$at]['installs'];
        }
        if ($stages !== [] && $installed === 0) {
            $problems[] = 'the default stage installs nothing from composer.lock';
        }

        return [$problems, $installs];
    }

    /**
     * Instructions with continuation lines joined and comments dropped.
     *
     * @param list<string> $problems
     * @return list<array{int, string, string}> line, instruction, arguments
     */
    private static function instructions(string $dockerfile, array &$problems): array
    {
        $lines = preg_split('/\r?\n/', $dockerfile) ?: [];
        foreach ($lines as $k => $text) {
            if (preg_match('/^#\s*([a-z]+)\s*=/i', $text, $m) !== 1) {
                break;
            }
            if (strtolower($m[1]) === 'escape') {
                $problems[] = 'Dockerfile:' . ($k + 1) . ': an escape directive; this test joins lines on \\ only';
            }
        }

        $instructions = [];
        $pending = null;
        foreach ($lines as $k => $text) {
            if (preg_match('/^\s*#/', $text) === 1 || ($pending === null && trim($text) === '')) {
                continue;
            }
            $continues = preg_match('/\\\\\s*$/', $text) === 1;
            $body = $continues ? (string) preg_replace('/\\\\\s*$/', '', $text) : $text;
            if ($pending === null) {
                $pending = [$k + 1, $body];
            } else {
                $pending[1] .= ' ' . $body;
            }
            if ($continues) {
                continue;
            }
            [$line, $joined] = $pending;
            $pending = null;
            $parts = preg_split('/\s+/', trim($joined), 2) ?: [];
            $name = strtoupper($parts[0] ?? '');
            if (!in_array($name, self::INSTRUCTIONS, true)) {
                $problems[] = "Dockerfile:{$line}: '{$name}' is not an instruction this test knows";
                continue;
            }
            $instructions[] = [$line, $name, trim($parts[1] ?? '')];
        }

        return $instructions;
    }

    /** @return list<string> */
    private static function copyProblems(int $line, string $name, string $args): array
    {
        $touches = preg_match('#composer\.(json|lock)|(^|[\s/"])vendor([\s/"]|$)#', $args) === 1;
        if (!$touches) {
            return [];
        }
        if ($name === 'ADD') {
            return ["Dockerfile:{$line}: ADD names composer's files or vendor/; this test reads only COPY"];
        }
        if (str_starts_with($args, '[') || preg_match('/["\'$]/', $args) === 1) {
            return ["Dockerfile:{$line}: COPY in JSON form, quoted or with a variable, which this test does not read"];
        }
        foreach (preg_split('/\s+/', $args, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (str_starts_with($word, '--from')) {
                return ["Dockerfile:{$line}: COPY --from brings composer's files or vendor/ from another image or"
                    . ' stage, past the install this test reads'];
            }
            if (str_starts_with($word, '--') && !preg_match('/^--(chown|chmod)=/', $word)) {
                return ["Dockerfile:{$line}: COPY {$word}, a flag this test does not read"];
            }
        }

        return [];
    }

    /** Whether COPY $args puts composer.json and composer.lock, named, into $workdir. */
    private static function copiesTheLockInto(string $args, ?string $workdir): bool
    {
        if ($workdir === null) {
            return false;
        }
        $words = array_values(array_filter(
            preg_split('/\s+/', $args, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            static fn (string $w): bool => !str_starts_with($w, '--')
        ));
        $destination = array_pop($words);
        $sources = array_map(static fn (string $s): string => (string) preg_replace('#^(\./)+#', '', $s), $words);
        sort($sources);
        if ($sources !== ['composer.json', 'composer.lock'] || $destination === null) {
            return false;
        }

        return self::resolve($destination, $workdir) === $workdir;
    }

    /**
     * The install RUN, read as `&&`-joined commands of plain words.
     *
     * @return array{0: list<string>, 1: bool} problems, and whether it installs
     */
    private static function runProblems(int $line, string $args): array
    {
        if (str_starts_with($args, '[')) {
            return [["Dockerfile:{$line}: a RUN in JSON form names composer; this test reads the shell form"], false];
        }
        if (str_starts_with($args, '--')) {
            return [["Dockerfile:{$line}: a RUN flag on a RUN that names composer; this test does not read it"], false];
        }
        $unread = preg_replace('/&&/', '', $args);
        if (preg_match('/[;|&`$()<>{}\'"\\\\#\n]/', (string) $unread, $m) === 1) {
            return [["Dockerfile:{$line}: '{$m[0]}' in a RUN that names composer, which this test does not read;"
                . ' write it as commands joined by &&'], false];
        }

        $problems = [];
        $validated = false;
        $installs = false;
        foreach (explode('&&', $args) as $command) {
            $words = preg_split('/\s+/', trim($command), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (stripos($command, 'composer') === false) {
                continue;
            }
            if (($words[0] ?? '') !== 'composer') {
                $problems[] = "Dockerfile:{$line}: '" . trim($command) . "' names composer in a way this test"
                    . ' does not read';
                continue;
            }
            $subcommand = $words[1] ?? '';
            $flags = array_slice($words, 2);
            foreach ($flags as $flag) {
                if (str_starts_with($flag, '--working-dir') || preg_match('/^-[a-z]*d/', $flag) === 1) {
                    $problems[] = "Dockerfile:{$line}: composer {$subcommand} {$flag} runs composer in another"
                        . ' directory than the one the lock was copied to';
                }
            }
            switch ($subcommand) {
                case 'validate':
                    if (in_array('--no-check-lock', $flags, true)) {
                        $problems[] = "Dockerfile:{$line}: composer validate --no-check-lock passes a lock that has"
                            . ' drifted from composer.json';
                    } else {
                        $validated = true;
                    }
                    break;
                case 'install':
                    $installs = true;
                    if (!$validated) {
                        $problems[] = "Dockerfile:{$line}: composer install without composer validate before it, in"
                            . ' the same && chain: install only warns when the lock has drifted';
                    }
                    if (!in_array('--no-dev', $flags, true)) {
                        $problems[] = "Dockerfile:{$line}: composer install without --no-dev installs the dev set";
                    }
                    foreach (array_intersect($flags, ['--dev', '--dry-run', '--download-only']) as $flag) {
                        $problems[] = "Dockerfile:{$line}: composer install {$flag}";
                    }
                    break;
                case 'dump-autoload':
                    break;
                default:
                    $problems[] = str_starts_with($subcommand, '-')
                        ? "Dockerfile:{$line}: a flag before composer's subcommand, which this test does not read"
                        : "Dockerfile:{$line}: composer {$subcommand}, which resolves or changes the set outside"
                        . ' the lock (or which this test does not know)';
            }
        }

        return [$problems, $installs];
    }

    /** An absolute path with . and repeated slashes resolved, or null when it cannot be read. */
    private static function resolve(string $path, ?string $from): ?string
    {
        if ($path === '' || preg_match('/[$*?\[\'"]/', $path) === 1) {
            return null;
        }
        if (!str_starts_with($path, '/')) {
            if ($from === null) {
                return null;
            }
            $path = $from . '/' . $path;
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return null;
            }
            $parts[] = $part;
        }

        return '/' . implode('/', $parts);
    }

    private static function planted(string $text, string $from, string $to): string
    {
        self::assertSame(1, substr_count($text, $from), "the plant's anchor occurs once: {$from}");
        $planted = str_replace($from, $to, $text);
        self::assertStringContainsString($to, $planted, 'the plant landed');

        return $planted;
    }

    private static function file(string $path): string
    {
        $contents = file_get_contents(self::REPO . '/' . $path);
        self::assertIsString($contents, "cannot read {$path}");

        return $contents;
    }

    private static function write(string $path, string $contents): void
    {
        self::assertNotFalse(file_put_contents($path, $contents), "cannot write {$path}");
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $child) {
            self::removeTree("{$path}/{$child}");
        }
        rmdir($path);
    }
}
