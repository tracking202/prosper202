<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * Every code path that authenticates a caller by the API key they present
 * must account for that key's scope.
 *
 * Scoping was added to the v3 API, and the legacy surfaces cannot enforce it,
 * so they refuse a scoped key outright rather than granting more than it
 * allows. That refusal was applied to api/v1/functions.php and
 * api/v2/functions.php — and missed on api/v2/app.php, the Slim attribution
 * app, which is a third path nobody remembered. A propose-only `read,stage`
 * key, correctly refused every write on v3, kept full write access to the
 * attribution models endpoints for as long as that gap existed.
 *
 * CLAUDE.md #5: "When implementing a security measure, grep for every
 * analogous code path and apply the same pattern. Spot-checking misses these."
 * This is that grep, run on every commit.
 */
final class ApiKeyAuthPathScopeTest extends TestCase
{
    /**
     * Evidence that a file accounts for key scope: either the legacy refusal
     * or the v3 scope machinery.
     */
    private const SCOPE_MARKERS = [
        'scoped and only valid for the v3',
        'parseScopes',
        'hasScope',
        'KNOWN_SCOPE_AREAS',
        'requireScope',
    ];

    /**
     * Files that authenticate a caller from a presented API key, mapped to
     * the source that proves it. Discovery is textual so a new path cannot
     * hide behind type inference.
     *
     * @return array<string, string> repo-relative path => file source
     */
    private function authenticatingFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn(\SplFileInfo $f): bool =>
                    !in_array($f->getFilename(), ['vendor', 'node_modules', '.git', 'tests'], true)
            )
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string)file_get_contents($file->getPathname());
            if ($this->hasAuthenticatingSelect($source)) {
                $found[str_replace($root . '/', '', $file->getPathname())] = $source;
            }
        }

        return $found;
    }

    /**
     * True when the source contains a SELECT from 202_api_keys filtered by
     * api_key — the shape that resolves a user from a credential the caller
     * supplied. Looking up keys by user_id (listing them) and deleting one by
     * value are key *management*, not authentication, and do not count.
     */
    private function hasAuthenticatingSelect(string $source): bool
    {
        if (preg_match_all('/\bFROM\s+`?202_api_keys`?\b/i', $source, $m, PREG_OFFSET_CAPTURE) < 1) {
            return false;
        }

        foreach ($m[0] as [$matchText, $offset]) {
            // The statement's verb: look back a bounded distance and take the
            // nearest one, so a DELETE later in the file cannot be read as a
            // SELECT (or the reverse).
            $before = substr($source, max(0, $offset - 200), min(200, $offset));
            if (preg_match_all('/\b(SELECT|DELETE|INSERT|UPDATE|ALTER|CREATE|SHOW)\b/i', $before, $verbs) < 1) {
                continue;
            }
            $verb = strtoupper((string)end($verbs[1]));
            if ($verb !== 'SELECT') {
                continue;
            }
            // ...filtered by the presented key ALONE. A WHERE that also
            // names user_id is key management: the caller's identity is
            // already established and the key is the object being acted on,
            // not the credential being verified.
            $after = substr($source, $offset + strlen($matchText), 200);
            if (preg_match('/\bWHERE\b([^;]{0,160})/is', $after, $where) !== 1) {
                continue;
            }
            $clause = $where[1];
            if (preg_match('/`?api_key`?\s*=/i', $clause) !== 1) {
                continue;
            }
            if (preg_match('/`?user_id`?\s*=/i', $clause) === 1) {
                continue;
            }
            return true;
        }

        return false;
    }

    public function testTheScannerFindsTheKnownAuthenticationPaths(): void
    {
        // If this drops to zero the assertion below passes vacuously.
        $files = array_keys($this->authenticatingFiles());
        sort($files);

        $this->assertSame(
            ['api/v1/functions.php', 'api/v2/app.php', 'api/v2/functions.php', 'api/v3/Auth.php'],
            $files,
            'The set of API-key authentication paths changed. If a path was added, make sure it '
            . 'handles key scope; if one was removed, update this list.'
        );
    }

    public function testEveryAuthenticationPathAccountsForKeyScope(): void
    {
        $unguarded = [];
        foreach ($this->authenticatingFiles() as $path => $source) {
            $guarded = false;
            foreach (self::SCOPE_MARKERS as $marker) {
                if (str_contains($source, $marker)) {
                    $guarded = true;
                    break;
                }
            }
            if (!$guarded) {
                $unguarded[] = $path;
            }
        }

        $this->assertSame([], $unguarded, sprintf(
            "These files authenticate a caller from a presented API key without accounting for "
            . "its scope:\n  %s\n"
            . 'A scoped key is an attenuated credential. A surface that cannot enforce the scope '
            . 'must refuse the key (see api/v1/functions.php) rather than granting everything the '
            . "user's roles allow.",
            implode("\n  ", $unguarded)
        ));
    }
}
