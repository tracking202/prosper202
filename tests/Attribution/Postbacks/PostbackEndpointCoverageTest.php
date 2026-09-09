<?php

declare(strict_types=1);

namespace Tests\Attribution\Postbacks;

use Api\V3\Attribution\PostbackProtocol;
use Api\V3\Attribution\Protocols;
use Tests\TestCase;

/**
 * Every public postback entry point under /.well-known/ is the shared
 * prelude plus one PostbackEndpoint::serve() line, and the set of protocols
 * served there is exactly the set the server advertises and filters on
 * (Protocols::NAMES).
 *
 * The consolidation moved the HTTP trust boundary — the peer-keyed rate
 * limit, the body cap, the error envelopes — into PostbackEndpoint, and the
 * plumbing that has to run before the framework exists — the security
 * headers, the unconfigured-install 503, the autoloader, the configuration
 * require — into .well-known/postback-prelude.php. Each is now written once
 * and asserted here once: an entry point that re-implements any of it, or a
 * protocol registered in Protocols but served nowhere (the capabilities
 * document would advertise it; the filters would accept it; no device could
 * reach it), is the shape this test refuses. Discovery is by file and by
 * source text, so a new entry point cannot opt out by forgetting to
 * register.
 */
final class PostbackEndpointCoverageTest extends TestCase
{
    /** The one file under /.well-known/ that is a fragment rather than an entry point. */
    private const PRELUDE_PATH = '.well-known/postback-prelude.php';

    /** @return array<string, string> repo-relative path => source, for every PHP file under /.well-known */
    private function wellKnownSources(): array
    {
        $root = dirname(__DIR__, 3);
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/.well-known', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[str_replace($root . '/', '', $file->getPathname())] = (string)file_get_contents($file->getPathname());
            }
        }
        ksort($found);
        return $found;
    }

    /**
     * The entry points, with the shared prelude removed — and nothing else
     * removed, so a stray script dropped into the tree fails rather than
     * quietly escaping every assertion below.
     *
     * @return array<string, string> repo-relative path => source
     */
    private function entryPoints(): array
    {
        $sources = $this->wellKnownSources();
        $this->assertArrayHasKey(
            self::PRELUDE_PATH,
            $sources,
            'the shared prelude must live at ' . self::PRELUDE_PATH . '; the entry points require it by that path'
        );
        unset($sources[self::PRELUDE_PATH]);

        $this->assertNotEmpty($sources, 'the scanner found no entry points; a silent zero would pass vacuously');
        foreach (array_keys($sources) as $path) {
            $this->assertSame(
                1,
                preg_match('#^\.well-known/[^/]+/[^/]+/index\.php$#', $path),
                "$path is neither a two-segment well-known directory index nor the shared prelude"
            );
        }
        return $sources;
    }

    private function preludeSource(): string
    {
        $sources = $this->wellKnownSources();
        $this->assertArrayHasKey(self::PRELUDE_PATH, $sources, self::PRELUDE_PATH . ' is missing');
        return $sources[self::PRELUDE_PATH];
    }

    /**
     * The protocol class an entry point hands to PostbackEndpoint::serve().
     *
     * @return class-string<PostbackProtocol>
     */
    private function servedProtocol(string $path, string $source): string
    {
        $this->assertSame(
            1,
            preg_match('/PostbackEndpoint::serve\(\s*new\s+\\\\?(?:Api\\\\V3\\\\Attribution\\\\)?(\w+Protocol)\s*\(/', $source, $m),
            "$path must serve exactly through PostbackEndpoint::serve(new <Protocol>(...))"
        );
        $class = 'Api\\V3\\Attribution\\' . $m[1];
        $this->assertTrue(class_exists($class), "$path names $class, which does not exist");
        $this->assertTrue(is_subclass_of($class, PostbackProtocol::class), "$class must implement PostbackProtocol");
        /** @var class-string<PostbackProtocol> $class */
        return $class;
    }

    /**
     * The prelude is the single owner of everything that has to happen
     * before a protocol is picked, including the one responder allowed
     * before the framework loads: the unconfigured-install 503. Everything
     * else must go through Bootstrap (via PostbackEndpoint), so the envelope
     * has one owner.
     */
    public function testTheSharedPreludeOwnsTheEntryPointPlumbing(): void
    {
        $source = $this->preludeSource();

        foreach (['X-Content-Type-Options: nosniff', 'X-Frame-Options: DENY', 'Cache-Control: no-store'] as $header) {
            $this->assertStringContainsString($header, $source, self::PRELUDE_PATH . " must send the $header header for every endpoint");
        }
        $this->assertStringContainsString('http_response_code(503)', $source, self::PRELUDE_PATH . ' lacks the pre-autoload 503 responder');
        $this->assertSame(1, preg_match('/require(?:_once)?[^\n;]*vendor\/autoload\.php/', $source), self::PRELUDE_PATH . ' must load the autoloader');
    }

    /**
     * Ordering, and the reason the obvious tidy-up is wrong (see the comment
     * in the prelude): the request-shape checks that need no database run
     * above the configuration require, because 202-config.php builds
     * DB::getInstance() at file scope and so costs two MySQL connections on
     * every request that reaches it. The rate limiter cannot join them —
     * ServerStateStore scopes its state directory by the $dbname/$dbhost
     * globals that require defines, and without them it falls back to the
     * unscoped path and counts the limit in a bucket the web tier never
     * reads.
     */
    public function testTheDatabaseFreeChecksRunBeforeConfigurationIsLoaded(): void
    {
        $source = $this->preludeSource();

        $this->assertSame(1, preg_match('/PostbackEndpoint::preflight\(/', $source, $m, PREG_OFFSET_CAPTURE), self::PRELUDE_PATH . ' must run the database-free request-shape checks');
        $preflightAt = $m[0][1];
        $this->assertSame(1, preg_match('/require(?:_once)?[^\n;]*202-config\.php/', $source, $m, PREG_OFFSET_CAPTURE), self::PRELUDE_PATH . ' must load the configuration at file scope');
        $configAt = $m[0][1];

        $this->assertLessThan(
            $configAt,
            $preflightAt,
            self::PRELUDE_PATH . ': the method and body-size checks must run before 202-config.php, which opens two MySQL connections'
        );
        $this->assertStringNotContainsString(
            'softIpRateLimit',
            $source,
            self::PRELUDE_PATH . ' must not hoist the rate limiter above the configuration require: ServerStateStore would resolve the unscoped state directory and count the limit where the web tier never looks'
        );
    }

    /**
     * The prelude is web-reachable — both shipped server configs exempt
     * /.well-known/ from the dotfile deny and hand every *.php beneath it to
     * PHP — so it must do nothing at all when it is requested directly
     * rather than included. Executed, not read: a guard that is only
     * inspected as source text is a guard nobody has run.
     */
    public function testTheSharedPreludeIsInertWhenRequestedDirectly(): void
    {
        if (!function_exists('exec')) {
            $this->markTestSkipped('exec() is disabled, so the prelude guard cannot be exercised');
        }
        $prelude = dirname(__DIR__, 3) . '/' . self::PRELUDE_PATH;
        $require = 'require ' . var_export($prelude, true) . '; echo "REACHED-END";';

        $this->assertSame(
            '',
            $this->runPhp($require),
            'requested directly the prelude must produce nothing: running it would load configuration, and with it two MySQL connections, outside the rate limiter'
        );
        $this->assertNotSame(
            '',
            $this->runPhp('define("P202_POSTBACK_ENTRY", "test"); $_SERVER["REQUEST_METHOD"] = "GET"; ' . $require),
            'with the entry-point constant defined the prelude must run — the 503 body on a checkout with no 202-config.php, REACHED-END on an installed one. An always-empty result would make the assertion above vacuous.'
        );
    }

    private function runPhp(string $code): string
    {
        $output = [];
        $status = 0;
        exec(
            escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d error_reporting=0 -r '
                . escapeshellarg($code) . ' 2>/dev/null',
            $output,
            $status
        );
        return implode("\n", $output);
    }

    public function testEveryWellKnownEntryPointServesThroughTheSharedEndpoint(): void
    {
        foreach ($this->entryPoints() as $path => $source) {
            $this->servedProtocol($path, $source);
            $this->assertSame(
                1,
                preg_match('/define\(\s*[\'"]P202_POSTBACK_ENTRY[\'"]/', $source),
                "$path must declare itself an entry point, or the prelude will refuse to run for it"
            );
            $this->assertSame(
                1,
                preg_match('/require(?:_once)?[^\n;]*postback-prelude\.php/', $source),
                "$path must load the shared prelude rather than repeating it"
            );
            // The prelude is the only thing an entry point loads: the
            // autoloader and the configuration are its business, and loading
            // configuration here would put it back above the checks that
            // exist to keep a flood off the database. Matched as a require
            // rather than as a filename so a docblock may still name them.
            $this->assertSame(
                0,
                preg_match('/require(?:_once)?[^\n;]*(?:202-config|vendor\/autoload)/', $source),
                "$path must leave loading the autoloader and 202-config.php to the shared prelude"
            );
            // Everything below belongs to the prelude or to PostbackEndpoint.
            // A copy of any of it here is the drift this consolidation exists
            // to prevent — a header added to one endpoint and not the other,
            // a second 503 body, a hand-rolled error envelope.
            $plumbing = [
                'softIpRateLimit', 'php://input', 'REQUEST_METHOD', 'new PostbackReceiver',
                'errorResponse(', 'http_response_code(', 'header(',
            ];
            foreach ($plumbing as $duplicated) {
                $this->assertStringNotContainsString(
                    $duplicated,
                    $source,
                    "$path re-implements shared plumbing ($duplicated) instead of using the prelude and PostbackEndpoint"
                );
            }
        }
    }

    public function testTheServedProtocolsAreExactlyTheRegisteredOnes(): void
    {
        $served = [];
        foreach ($this->entryPoints() as $path => $source) {
            $class = $this->servedProtocol($path, $source);
            $protocol = new $class();
            $name = $protocol->name();
            $this->assertContains($name, Protocols::NAMES, "$path serves protocol '$name', which Protocols::NAMES does not list");
            $this->assertArrayNotHasKey(
                $name,
                $served,
                "protocol '$name' is served by two entry points: " . ($served[$name] ?? '?') . " and $path"
            );
            $served[$name] = $path;

            // The probe's endpoint slug is the well-known path with its
            // segments joined, so an operator can match one to the other.
            $segments = explode('/', $path);
            $this->assertSame(
                $segments[1] . '-' . $segments[2],
                $protocol->describe()['endpoint'],
                "$path: describe()['endpoint'] must name its own path"
            );
        }

        $this->assertEqualsCanonicalizing(
            Protocols::NAMES,
            array_keys($served),
            'every registered protocol must be served by an entry point, and vice versa'
        );
        foreach (Protocols::ALIASES as $alias => $name) {
            $this->assertContains($name, Protocols::NAMES, "alias '$alias' resolves to an unregistered protocol");
            $this->assertNotContains($alias, Protocols::NAMES, "alias '$alias' collides with a canonical name");
        }
    }
}
