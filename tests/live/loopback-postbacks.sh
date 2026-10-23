#!/bin/bash
# For the passes whose traffic source posts back to this machine: the
# instance itself, or a capture server on 127.0.0.1.
#
# The postback sender calls only public addresses (Prosper202\Notifications\
# PostbackSender, 8PPJ); an operator whose network really is on their own
# machine admits it with P202_WEBHOOK_ALLOW_NETWORKS in 202-config.php. So
# for the pass's duration the instance's 202-config.php admits 127.0.0.1/32,
# and p202_restore_postbacks (from the pass's cleanup) puts the file back as
# it was. The instance is the checkout these passes live in, which is where
# they run the cron jobs from too; P202_ROOT names another.
#
# The original is kept in a file of its own, not in the pass's $OUT: a pass
# that removes $OUT before its exit trap runs would leave the allowlist in
# place for every pass after it (measured: android-abuse-limits.sh does).
#
# Sourced, not executed.

P202_POSTBACK_ROOT=${P202_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}
P202_POSTBACK_BACKUP=""

p202_allow_loopback_postbacks() {
    local config="$P202_POSTBACK_ROOT/202-config.php"
    [ -f "$config" ] || { echo "no 202-config.php in $P202_POSTBACK_ROOT: set P202_ROOT to the served instance" >&2; return 1; }
    P202_POSTBACK_BACKUP=$(mktemp "${TMPDIR:-/tmp}/p202-config-before-loopback-postbacks.XXXXXX") || return 1
    cp "$config" "$P202_POSTBACK_BACKUP" || return 1
    printf "\n// tests/live/loopback-postbacks.sh: this pass's postbacks to 127.0.0.1 (removed on exit)\nif (!defined('P202_WEBHOOK_ALLOW_NETWORKS')) { define('P202_WEBHOOK_ALLOW_NETWORKS', '127.0.0.1/32'); }\n" >> "$config"
    "${PHP:-php}" -l "$config" > /dev/null || { echo "202-config.php no longer parses" >&2; return 1; }
    # The web server revalidates opcache every 2 s.
    "${PHP:-php}" -r 'usleep(3500000);'
}

p202_restore_postbacks() {
    if [ -n "$P202_POSTBACK_BACKUP" ] && [ -f "$P202_POSTBACK_BACKUP" ]; then
        cp "$P202_POSTBACK_BACKUP" "$P202_POSTBACK_ROOT/202-config.php" && rm -f "$P202_POSTBACK_BACKUP"
        P202_POSTBACK_BACKUP=""
    fi
}
