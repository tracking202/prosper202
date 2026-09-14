<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * A class name declared more than once in the global namespace is a hazard
 * PHP will not warn about until it is too late:
 *
 *  - If two declarations ever load in the same request, PHP fatals with
 *    "Cannot redeclare class". Only one of the three INDEXES copies guards
 *    itself with class_exists(); the others rely on include paths staying
 *    disjoint forever.
 *  - The copies drift. INDEXES::get_country_id() takes ($name, $code) in two
 *    files and ($db, $name, $code) in the third, so a call written for one is
 *    an ArgumentCountError under the other.
 *  - It blinds static analysis. PHPStan resolves the name to whichever copy
 *    it sees first and then reports every call against the others as an arity
 *    error — nine false positives that hid behind a baseline until someone
 *    traced them.
 *
 * The known duplicates are allowlisted below with what makes each tolerable.
 * This test exists to stop the list from growing.
 */
final class DuplicateGlobalClassTest extends TestCase
{
    /**
     * @var array<string, string> class name => why it is tolerated for now
     */
    private const KNOWN_DUPLICATES = [
        // Alternate copies of one config file (live, sample, and two build
        // templates). Exactly one is ever loaded.
        'DB' => 'config file templates; only one is ever included',
        // Tracking (connect2.php) and UI/API (connect.php via
        // functions-tracking202.php) each declare their own, with different
        // signatures; class-indexes.php yields to whichever loaded first.
        //
        // Measured, rather than assumed, by booting each path and reflecting
        // on the class that won:
        //
        //  - UI/API (connect.php:306 includes functions-tracking202.php)
        //    wins with a 12-method copy that is itself half static and half
        //    not: get_ip_id, insert_ip, get_site_domain_id and
        //    get_site_url_id are static; get_country_id, get_city_id,
        //    get_isp_id, get_keyword_id and get_c1_id..get_c4_id are not.
        //    It has no get_browser_id / get_platform_id / get_device_id.
        //  - Tracking (tracking202/* includes connect2.php) wins with an
        //    all-static copy whose methods take $db first, and which carries
        //    hand-written $dbOr... overloads on seven of them so both
        //    calling conventions reach it.
        //  - class-indexes.php (all static, 20 methods, no $db) is the only
        //    guarded copy and therefore never wins on either path. Its one
        //    includer, functions-indexes.php, is a shim of 15 global
        //    wrappers that nothing calls — and 11 of the 14 callable ones
        //    raise an Error against the copy the UI path actually loads
        //    ("Non-static method ... cannot be called statically" for eight,
        //    "Call to undefined method" for the three missing ones).
        //
        // So "each execution path loads exactly one" is true, and is what
        // keeps this from being a live fault; the shim is dead code, not a
        // working abstraction over the three.
        //
        // Note for whoever picks this up: class_exists()-guarding the two
        // unguarded copies is NOT the safe floor it looks like. They have
        // different method sets and different calling conventions, so a
        // guard would turn "Cannot redeclare class INDEXES" — loud, at
        // include time — into the wrong implementation silently answering
        // calls written for the other one. Consolidating onto one class and
        // one convention is the fix; guarding is a regression.
        'INDEXES' => 'per-bootstrap copies with incompatible signatures',
        // Full and slim dataengine implementations. The slim copy is the
        // guarded one, so a path that loads it first would make the full
        // copy fatal; call sites defend with method_exists() instead (see
        // functions-upgrade.php around the 1.9.3 and 1.9.31 steps).
        'DataEngine' => 'full and slim implementations',
    ];

    /** @return array<string, string[]> global class name => files declaring it */
    private function globalClassDeclarations(): array
    {
        $root = dirname(__DIR__, 3);
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file): bool {
                    $name = $file->getFilename();
                    return !in_array($name, ['vendor', 'node_modules', '.git'], true);
                }
            )
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string)file_get_contents($file->getPathname());
            // Namespaced classes cannot collide with global ones.
            if (preg_match('/^\s*namespace\s+[^;{\s]+/m', $source) === 1) {
                continue;
            }
            if (preg_match_all('/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_]\w*)/m', $source, $matches) < 1) {
                continue;
            }
            foreach ($matches[1] as $class) {
                $found[$class][] = str_replace($root . '/', '', $file->getPathname());
            }
        }

        return $found;
    }

    public function testTheScannerFindsGlobalClassesAtAll(): void
    {
        // Without this a broken scan would make the test below vacuous.
        $this->assertGreaterThan(20, count($this->globalClassDeclarations()));
    }

    public function testNoNewGlobalClassIsDeclaredTwice(): void
    {
        $unexpected = [];
        foreach ($this->globalClassDeclarations() as $class => $files) {
            if (count($files) < 2 || array_key_exists($class, self::KNOWN_DUPLICATES)) {
                continue;
            }
            $unexpected[$class] = $files;
        }

        $this->assertSame([], $unexpected, sprintf(
            "These global classes are declared in more than one file:\n%s\n"
            . 'Two declarations reachable from one request is a fatal redeclare, and copies drift '
            . 'into incompatible signatures. Give the class a namespace, or extract it to a single '
            . 'file both callers include.',
            implode("\n", array_map(
                static fn(string $c, array $f): string => "  $c: " . implode(', ', $f),
                array_keys($unexpected),
                $unexpected
            ))
        ));
    }

    public function testTheKnownDuplicatesAreStillDuplicated(): void
    {
        // If one is cleaned up, drop it from the allowlist rather than
        // leaving a stale exemption that would hide a future collision.
        $declarations = $this->globalClassDeclarations();
        foreach (array_keys(self::KNOWN_DUPLICATES) as $class) {
            $this->assertGreaterThan(
                1,
                count($declarations[$class] ?? []),
                "$class is no longer declared twice — remove it from KNOWN_DUPLICATES."
            );
        }
    }
}
