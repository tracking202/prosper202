#!/usr/bin/env bash
#
# Print the path to a usable p202 binary, building it if necessary.
# Resolution order:
#   1. release-bundled binary for this platform (go-cli/dist/<os>-<arch>/p202)
#   2. a locally built binary (go-cli/p202)
#   3. build from source with `make -C go-cli build` (requires Go)
set -euo pipefail

# scripts -> onboard-prosper202 -> skills -> .claude -> repo root
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
CLI_DIR="$ROOT/go-cli"

os="$(uname -s | tr '[:upper:]' '[:lower:]')"
arch="$(uname -m)"
case "$arch" in
    x86_64|amd64) arch=amd64 ;;
    aarch64|arm64) arch=arm64 ;;
esac

bundled="$CLI_DIR/dist/${os}-${arch}/p202"
if [ -x "$bundled" ]; then
    echo "$bundled"
    exit 0
fi

if [ -x "$CLI_DIR/p202" ]; then
    echo "$CLI_DIR/p202"
    exit 0
fi

if command -v go >/dev/null 2>&1; then
    make -C "$CLI_DIR" build >&2
    echo "$CLI_DIR/p202"
    exit 0
fi

echo "Error: no p202 binary found and Go is not installed to build one." >&2
echo "Either use a release zip (it bundles go-cli/dist/<platform>/p202) or install Go." >&2
exit 1
