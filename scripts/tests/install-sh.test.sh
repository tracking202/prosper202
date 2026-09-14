#!/usr/bin/env bash
#
# Behavioural tests for install.sh.
#
# install.sh is the highest-stakes script in the repository and had no test of
# any kind. It was also excluded from the ShellCheck gate while it carried 22
# warnings, so nothing mechanical looked at it at all. Working those warnings
# off meant editing eight functions, and "ShellCheck is now quiet" says
# nothing about whether those functions still do what they did — a
# lint-clean installer that no longer detects a missing extension is strictly
# worse than a noisy one that does.
#
# So these tests pin the behaviour the cleanup touched, and they call the real
# functions rather than re-implementing them:
#
#   * check_php_extensions builds an array and echoes it space-joined; two
#     other functions take that string and split it. All three used the name
#     `missing` for both the array and the string, which is what ShellCheck
#     reported as SC2178/SC2128 and what a dropped `local` would turn into a
#     silent bug. The array is now `missing_extensions`, and a half-finished
#     rename must fail here.
#   * check_php_version, and six other sites, changed from
#     `local x=$(cmd)` to a separate declaration and assignment (SC2155).
#   * three `cd "$SCRIPT_DIR"` calls gained `|| return 1` / `|| exit 1`
#     (SC2164). An installer that carries on in the wrong directory installs
#     into the wrong tree, so the guard firing is itself worth a test.
#
# install.sh ends in `main "$@"`, so it cannot simply be sourced. The copy
# below suppresses that one line and nothing else.
#
# Usage: scripts/tests/install-sh.test.sh
# Exits 0 when every case passes, 1 otherwise.

set -uo pipefail

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
INSTALLER="$HERE/../../install.sh"

if [ ! -f "$INSTALLER" ]; then
    echo "cannot find install.sh at $INSTALLER" >&2
    exit 1
fi

pass=0
fail=0

ok() { echo "  ok   - $1"; pass=$((pass + 1)); }
no() { echo "  FAIL - $1"; echo "         $2"; fail=$((fail + 1)); }

check() {
    local label=$1 expected=$2 actual=$3
    if [ "$actual" = "$expected" ]; then
        ok "$label"
    else
        no "$label" "expected '$expected', got '$actual'"
    fi
}

contains() {
    local label=$1 needle=$2 haystack=$3
    case "$haystack" in
        *"$needle"*) ok "$label" ;;
        *) no "$label" "expected to find '$needle' in '$haystack'" ;;
    esac
}

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

# Suppress only the final `main "$@"` so the definitions can be sourced. If
# that line ever changes shape this must fail loudly rather than source a
# script that then runs a real installation.
if ! grep -qx 'main "\$@"' "$INSTALLER"; then
    echo "install.sh no longer ends in a bare 'main \"\$@\"' call; update this test" >&2
    exit 1
fi
sed 's/^main "\$@"$/: # main call suppressed by install-sh.test.sh/' \
    "$INSTALLER" > "$WORK/installer.sh"

# shellcheck disable=SC1090,SC1091
source "$WORK/installer.sh"

echo "install.sh"

# ---------------------------------------------------------------- extensions

# The producer. With a real PHP present every required extension resolves, so
# this is the "nothing missing" path.
out=$(check_php_extensions); rc=$?
check "check_php_extensions succeeds when nothing is missing" "0" "$rc"
check "check_php_extensions prints nothing when nothing is missing" "" "$out"

# Force every extension to be missing by shadowing `php -m`. This is the case
# a half-finished rename of the array silently breaks: appends land in one
# variable and the length test reads another, so the function reports success
# and the installer never offers to install anything.
php() { if [ "${1:-}" = "-m" ]; then echo "Core"; else command php "$@"; fi; }

out=$(check_php_extensions); rc=$?
check "check_php_extensions fails when extensions are missing" "1" "$rc"
check "check_php_extensions echoes them space-joined" \
    "mysqli pdo curl json mbstring" "$out"

# One missing extension among present ones: the array must carry exactly it.
php() {
    if [ "${1:-}" = "-m" ]; then printf 'Core\nmysqli\npdo\ncurl\njson\n'
    else command php "$@"; fi
}
out=$(check_php_extensions); rc=$?
check "check_php_extensions fails on a single missing extension" "1" "$rc"
check "check_php_extensions names only the missing one" "mbstring" "$out"
unset -f php

# ------------------------------------------------------- the string consumers

# The two consumers take the producer's output and word-split it. They share
# the name `missing` with the producer's array, which is the trap the rename
# removes; these assert they still read the string correctly.
DETECTED_OS="Ubuntu 24.04"
out=$(show_extension_install_instructions "mysqli mbstring" 2>&1)
contains "ubuntu instructions name every missing extension" "-mysqli" "$out"
contains "ubuntu instructions do not stop at the first" "-mbstring" "$out"
contains "ubuntu instructions use apt" "sudo apt install" "$out"

DETECTED_OS="Fedora 40"
out=$(show_extension_install_instructions "mysqli mbstring" 2>&1)
contains "fedora instructions name every missing extension" \
    "php-mysqli php-mbstring" "$out"
contains "fedora instructions use dnf" "sudo dnf install" "$out"

DETECTED_OS="Arch Linux"
out=$(show_extension_install_instructions "mysqli" 2>&1)
contains "arch instructions fall through to pacman" "pacman" "$out"

# install.sh's globals are set here and read by the functions sourced above.
# ShellCheck cannot follow a `source` of a generated file, so it reports the
# last assignment of each as unused; they are the inputs the case depends on.
# shellcheck disable=SC2034
DETECTED_OS="Plan 9"
out=$(show_extension_install_instructions "mysqli" 2>&1)
contains "an unknown OS still says something" "missing PHP extensions" "$out"

# ------------------------------------------------------------- php version

DETECTED_PHP="8.3.0"; check_php_version
check "check_php_version accepts the 8.3 floor" "0" "$?"
DETECTED_PHP="8.4.12"; check_php_version
check "check_php_version accepts 8.4" "0" "$?"
DETECTED_PHP="9.0.0"; check_php_version
check "check_php_version accepts a new major" "0" "$?"
DETECTED_PHP="8.2.29"; check_php_version
check "check_php_version rejects 8.2" "1" "$?"
DETECTED_PHP="7.4.33"; check_php_version
check "check_php_version rejects 7.4" "1" "$?"
# shellcheck disable=SC2034  # read by check_php_version in the sourced file
DETECTED_PHP=""; check_php_version
check "check_php_version rejects an undetected version" "1" "$?"

# ------------------------------------------------------------------ composer

# DETECTED_COMPOSER was assigned in five places and read in none, which is
# what ShellCheck reported as SC2034. It now supplies the detail column of the
# Composer step, the way DETECTED_PHP already did for the PHP Version step.
DETECTED_COMPOSER=""
if detect_composer; then
    if [ -n "$DETECTED_COMPOSER" ]; then
        ok "detect_composer records what it found"
    else
        no "detect_composer records what it found" "DETECTED_COMPOSER is empty"
    fi
else
    ok "detect_composer records what it found (skipped: no composer present)"
fi

if grep -q 'print_step "Composer" "ok" "\$DETECTED_COMPOSER"' "$INSTALLER"; then
    ok "the Composer step reports the detected version, not a bare 'Found'"
else
    no "the Composer step reports the detected version, not a bare 'Found'" \
        "print_step for Composer no longer reads DETECTED_COMPOSER"
fi

# --------------------------------------------------------------- the cd guard

# Without `|| return`, a failed cd leaves the shell wherever the caller was
# and the installer proceeds against the wrong tree. Both guarded functions
# must refuse instead, and say why.
# shellcheck disable=SC2034  # read by the cd guards in the sourced file
SCRIPT_DIR="$WORK/definitely-not-created"
out=$(install_dependencies 2>&1); rc=$?
check "install_dependencies refuses an unusable SCRIPT_DIR" "1" "$rc"
contains "install_dependencies says which directory" "Cannot enter" "$out"

out=$(start_docker_containers 2>&1); rc=$?
check "start_docker_containers refuses an unusable SCRIPT_DIR" "1" "$rc"
contains "start_docker_containers says which directory" "Cannot enter" "$out"

echo
echo "passed: $pass  failed: $fail"
[ "$fail" -eq 0 ]
