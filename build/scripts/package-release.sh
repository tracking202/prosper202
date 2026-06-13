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
trap 'rm -rf "$STAGE_ROOT"' EXIT

mkdir -p "$DIST_DIR" "$STAGE"

# --- 1. clean export of tracked files only (no .git, no vendor/, no dist/) ---
echo "==> Exporting tracked files..."
git archive --format=tar HEAD | tar -x -C "$STAGE"

# --- 2. bake production Composer deps into vendor/ ---
echo "==> Installing Composer dependencies (--no-dev)..."
composer install --no-dev --optimize-autoloader --no-interaction \
    --working-dir="$STAGE"
if [ ! -f "$STAGE/vendor/autoload.php" ]; then
    echo "Error: composer install did not produce vendor/autoload.php" >&2
    exit 1
fi

# --- 3. cross-build the Go CLI; stage binaries under go-cli/dist ---
# VERSION is passed through so the CLI's -X main.version matches the zip name.
echo "==> Building Go CLI for all platforms..."
make -C "$REPO_ROOT/go-cli" all VERSION="$VERSION"
mkdir -p "$STAGE/go-cli/dist"
cp -R "$REPO_ROOT/go-cli/dist/." "$STAGE/go-cli/dist/"

# --- 4. strip dev-only cruft from the shipped artifact ---
echo "==> Stripping dev artifacts..."
rm -rf "$STAGE/.github" "$STAGE/build/logs" "$STAGE/build/debug"

# --- 5. zip + checksum ---
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
