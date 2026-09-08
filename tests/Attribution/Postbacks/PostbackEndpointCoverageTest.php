<?php

declare(strict_types=1);

namespace Tests\Attribution\Postbacks;

use Api\V3\Attribution\PostbackProtocol;
use Api\V3\Attribution\Protocols;
use Tests\TestCase;

/**
 * Every public postback entry point under /.well-known/ serves through the
 * shared PostbackEndpoint, and the set of protocols served there is exactly
 * the set the server advertises and filters on (Protocols::NAMES).
 *
 * The consolidation moved the HTTP trust boundary — the peer-keyed rate
 * limit, the body cap, the error envelopes — into PostbackEndpoint so it is
 * written once. An entry point that re-implements any of it, or a protocol
 * registered in Protocols but served nowhere (the capabilities document
 * would advertise it; the filters would accept it; no device could reach
 * it), is the shape this test refuses. Discovery is by file and by source
 * text, so a new entry point cannot opt out by forgetting to register.
 */
final class PostbackEndpointCoverageTest extends TestCase
{
    /** @return array<string, string> repo-relative path => source */
    private function entryPoints(): array
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

    public function testEveryWellKnownEntryPointServesThroughTheSharedEndpoint(): void
    {
        $entryPoints = $this->entryPoints();
        $this->assertNotEmpty($entryPoints, 'the scanner found no entry points; a silent zero would pass vacuously');

        foreach ($entryPoints as $path => $source) {
            $this->assertSame(1, preg_match('#^\.well-known/[^/]+/[^/]+/index\.php$#', $path), "$path is not a two-segment well-known directory index");
            $this->servedProtocol($path, $source);
            // The one responder allowed before the framework loads: the
            // unconfigured-install 503. Everything else must go through
            // Bootstrap (via PostbackEndpoint), so the envelope has one owner.
            $this->assertStringContainsString('http_response_code(503)', $source, "$path lacks the pre-autoload 503 responder");
            foreach (['softIpRateLimit', 'php://input', 'REQUEST_METHOD', 'new PostbackReceiver', 'errorResponse('] as $plumbing) {
                $this->assertStringNotContainsString($plumbing, $source, "$path re-implements shared plumbing ($plumbing) instead of using PostbackEndpoint");
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
