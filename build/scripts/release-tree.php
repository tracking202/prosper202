<?php

/**
 * Holds the release tree to build/release-manifest.php. The logic is in
 * ReleaseTree.php next to this file.
 *
 *   php build/scripts/release-tree.php check-manifest
 *       Classify the repository's tracked files. Runs on every pull request so
 *       a new top-level path is classified in the PR that adds it.
 *   php build/scripts/release-tree.php prune <stage>
 *       Remove everything the manifest excludes from a staged release tree.
 *       Refuses, before deleting anything, when a path is unclassified.
 *   php build/scripts/release-tree.php verify <stage>
 *       Check a pruned tree is what a no-terminal host needs: nothing
 *       excluded, vendor/ exactly the locked runtime set, every class the
 *       shipped PHP names resolvable through the shipped autoloader, the Go
 *       binaries present, and no group- or world-writable file.
 *
 * Each command prints its problems and exits 1 when there are any.
 * build/scripts/package-release.sh runs prune and verify on every build.
 */

declare(strict_types=1);

require_once __DIR__ . '/ReleaseTree.php';

exit(\P202Build\ReleaseTree::main($argv ?? []));
