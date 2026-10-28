#!/usr/bin/env bash
#
# Package a self-contained Prosper202 release zip.
#
# The artifact bundles Composer dependencies (vendor/) and pre-built Go CLI
# binaries, so shared-hosting and one-click-installer users can deploy with no
# Composer, no Go toolchain, and no terminal at all.
#
#   "Download the release, not the git clone."
#
# Usage: build/scripts/package-release.sh
# Output: dist/prosper202-<version>.zip  (+ a printed SHA256)
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

# --- tooling preflight: fail loudly and early, never half-build an artifact ---
missing=""
for tool in php composer go git zip; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        missing="$missing $tool"
    fi
done
if [ -n "$missing" ]; then
    echo "Error: required tool(s) not installed:$missing" >&2
    exit 1
fi

# --- the bundled vendor/ is installed from the committed lock, never resolved ---
# Without a lock, composer resolves "latest" at build time: the zip would ship
# a dependency set nothing tested, and two builds of one tag could differ.
if ! git cat-file -e HEAD:composer.lock 2>/dev/null; then
    echo "Error: composer.lock is not committed at HEAD. Run composer update and commit composer.lock." >&2
    exit 1
fi

# --- version: single source of truth is 202-config/version.php ---
VERSION="$(php -r 'require "202-config/version.php"; echo PROSPER202_VERSION;')"
if [ -z "$VERSION" ]; then
    echo "Error: could not read PROSPER202_VERSION from 202-config/version.php" >&2
    exit 1
fi
echo "Packaging Prosper202 v${VERSION}"

DIST_DIR="$REPO_ROOT/dist"
STAGE_ROOT="$(mktemp -d)"
STAGE="$STAGE_ROOT/prosper202"
TOOLS="$STAGE_ROOT/tools"
trap 'rm -rf "$STAGE_ROOT"' EXIT

mkdir -p "$DIST_DIR" "$STAGE" "$TOOLS/build/scripts"

# --- the tree must be staged on a case-sensitive filesystem ---
# Git tracks tracking202/Redirect/ (RedirectHelper.php) next to
# tracking202/redirect/ (the click endpoints). A case-insensitive filesystem,
# macOS's default, merges them on extraction, and the zip then puts dl.php,
# go.php and rtr.php under Redirect/, where a Linux host serves them as 404s.
touch "$STAGE_ROOT/case-probe"
if [ -e "$STAGE_ROOT/CASE-PROBE" ]; then
    echo "Error: $STAGE_ROOT is on a case-insensitive filesystem, which would merge" >&2
    echo "tracking202/Redirect/ into tracking202/redirect/ and break click tracking." >&2
    echo "Build on Linux (the Release workflow does), or set TMPDIR to a case-sensitive volume." >&2
    exit 1
fi
rm -f "$STAGE_ROOT/case-probe"

# --- 1. clean export of tracked files only (no .git, no vendor/, no dist/) ---
echo "==> Exporting tracked files..."
git archive --format=tar HEAD | tar -x -C "$STAGE"

# The manifest and its checker are taken from the same commit as the source,
# and copied out before pruning removes build/ from the tree.
cp "$STAGE/build/release-manifest.php" "$TOOLS/build/"
cp "$STAGE/build/scripts/release-tree.php" "$STAGE/build/scripts/ReleaseTree.php" "$TOOLS/build/scripts/"

# --- 2. bake production Composer deps into vendor/, exactly as locked ---
# validate exits non-zero when composer.lock no longer matches composer.json;
# install would only warn and carry on with the stale lock.
echo "==> Installing Composer dependencies from composer.lock (--no-dev)..."
composer validate --no-check-publish --no-check-all --working-dir="$STAGE"
composer install --no-dev --optimize-autoloader --no-interaction \
    --working-dir="$STAGE"
if [ ! -f "$STAGE/vendor/autoload.php" ]; then
    echo "Error: composer install did not produce vendor/autoload.php" >&2
    exit 1
fi

# --- 3. cross-build the Go CLI from the STAGED (archived) source ---
# Build inside $STAGE, not $REPO_ROOT, so the binaries match the exact source
# bundled in the zip — a dirty working tree can't ship binaries whose behavior
# diverges from the shipped source. The Makefile writes to its own dist/, so the
# binaries land directly under $STAGE/go-cli/dist. VERSION is passed through so
# the CLI's -X main.version matches the zip name. It also keeps $REPO_ROOT clean.
echo "==> Building Go CLI for all platforms..."
make -C "$STAGE/go-cli" all VERSION="$VERSION"

# --- 4. strip everything build/release-manifest.php does not ship ---
# The zip is extracted straight into a web root, so tests, dev tooling and
# notes would be deployed and reachable. The manifest is fail-closed: an
# unclassified top-level path stops the build here, before anything is removed.
echo "==> Stripping developer files..."
php "$TOOLS/build/scripts/release-tree.php" prune "$STAGE"

# The optimized classmap was generated before pruning and still maps the
# removed files; regenerate it from the tree that ships.
composer dump-autoload --no-dev --optimize --no-interaction --working-dir="$STAGE"

# suPHP/suEXEC hosts answer 500 for scripts in group- or world-writable files,
# and what tar and composer create depends on the builder's umask.
chmod -R go-w "$STAGE"

# --- 5. verify the tree is what a no-terminal host needs ---
echo "==> Verifying the release tree..."
# The tracked-file list lets verify confirm every shipped file kept its exact
# path; the case-probe above is the fast failure, this is the proof.
git ls-tree -r -z --name-only HEAD > "$TOOLS/tracked.list"
php "$TOOLS/build/scripts/release-tree.php" verify "$STAGE" "$TOOLS/tracked.list"

# --- 6. zip + checksum ---
ZIP_PATH="$DIST_DIR/prosper202-${VERSION}.zip"
rm -f "$ZIP_PATH"
echo "==> Creating $ZIP_PATH ..."
( cd "$STAGE_ROOT" && zip -rq "$ZIP_PATH" prosper202 )

echo ""
echo "Release artifact ready:"
echo "  $ZIP_PATH"
if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$ZIP_PATH"
elif command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$ZIP_PATH"
fi
