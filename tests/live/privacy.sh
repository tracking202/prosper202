#!/bin/bash
# The privacy setting a pass runs under, shared by every pass that records a
# click or reads a click cookie or a stored visitor address. Sourced, not
# executed: it defines p202_track_in_full and p202_privacy_restore.
#
# A pass clicks from this machine (127.0.0.1, or a TEST-NET address in
# X-Forwarded-For), which GeoIP cannot place, and a new account's privacy
# setting ('eu' since 1.9.79) holds such a visitor back: the address is
# stored masked and no click or p202vid cookie is set. A pass whose subject
# is not privacy tracks every visitor in full for its run, whatever a seed or
# an earlier pass left behind.
#
#   p202_track_in_full BASE KEY [USER_ID...]
#
# Sets user 1 (the install's setting, which the click path applies with the
# link owner's: the stricter of the two is in force), the account KEY acts
# as, and each other account whose links the pass clicks (USER_ID) to
# 'disabled' through PUT /users/{id}/preferences, with KEY (a Super user's),
# and puts each back on exit, before the pass's own EXIT trap runs.
# Call it after the pass sets that trap: a trap set later replaces this one.
# Returns non-zero, having said why, when a setting cannot be read or set.

P202_PRIVACY_BASE=
P202_PRIVACY_KEY=
P202_PRIVACY_KEPT=()
P202_PRIVACY_PASS_TRAP=
P202_PRIVACY_TRAPPED=
P202_PRIVACY_RC=0

# p202__privacy_call METHOD ID [BODY] -> prints "STATUS SETTING"
p202__privacy_call() {
    local out status setting
    out=$(mktemp) || return 1
    status=$(curl -sS -o "$out" -w '%{http_code}' -X "$1" -H "Authorization: Bearer $P202_PRIVACY_KEY" \
        -H 'Content-Type: application/json' ${3:+--data "$3"} "$P202_PRIVACY_BASE/api/v3/users/$2/preferences") \
        || status=000
    setting=$(python3 -c 'import json,sys; print(json.load(sys.stdin)["data"]["user_pref_privacy"])' \
        < "$out" 2>/dev/null) || setting=
    rm -f "$out"
    printf '%s %s\n' "${status:-000}" "${setting:-?}"
}

# The restore goes on the EXIT trap, ahead of the pass's own.
p202__privacy_trap() {
    [ -n "$P202_PRIVACY_TRAPPED" ] && return 0
    P202_PRIVACY_TRAPPED=1
    local current
    local -a words=()
    current=$(trap -p EXIT)
    # trap -p prints the trap quoted for the shell: trap -- 'COMMAND' EXIT
    [ -n "$current" ] && eval "words=($current)"
    P202_PRIVACY_PASS_TRAP=${words[2]:-}
    # The pass's trap runs after the restore and sees the pass's exit status
    # in $?; errexit is off for that, or setting it would end the trap there.
    trap 'P202_PRIVACY_RC=$?; p202_privacy_restore; set +e; (exit "$P202_PRIVACY_RC");
        eval "$P202_PRIVACY_PASS_TRAP"' EXIT
}

p202_track_in_full() {
    P202_PRIVACY_BASE=${1%/}
    P202_PRIVACY_KEY=$2
    shift 2
    if [ -z "$P202_PRIVACY_KEY" ]; then
        echo "P202_API_KEY is not set: this pass clicks from this machine and sets its account's privacy to" \
            "disabled through the API first (tests/live/privacy.sh)" >&2
        return 1
    fi
    p202__privacy_trap
    local id status before after seen="" me
    me=$(curl -sS -H "Authorization: Bearer $P202_PRIVACY_KEY" "$P202_PRIVACY_BASE/api/v3/capabilities" \
        | python3 -c 'import json,sys; print(int(json.load(sys.stdin)["data"]["principal"]["user_id"]))' 2>/dev/null) \
        || me=
    if [ -z "$me" ]; then
        echo "could not read whose key P202_API_KEY is (GET /capabilities)" >&2
        return 1
    fi
    for id in 1 "$me" "$@"; do
        case " $seen " in *" $id "*) continue ;; esac
        seen="${seen:+$seen }$id"
        read -r status before < <(p202__privacy_call GET "$id")
        if [ "$status" != 200 ] || [ "$before" = "?" ]; then
            echo "could not read user $id's privacy setting (GET /users/$id/preferences answered $status)" >&2
            return 1
        fi
        P202_PRIVACY_KEPT+=("$id=$before")
        [ "$before" = disabled ] && continue
        read -r status after < <(p202__privacy_call PUT "$id" '{"user_pref_privacy":"disabled"}')
        if [ "$status" != 200 ] || [ "$after" != disabled ]; then
            echo "could not set user $id's privacy to disabled (PUT /users/$id/preferences answered $status)" >&2
            return 1
        fi
    done
    printf '  privacy: user(s) %s set to disabled for this pass, put back on exit\n' "$seen" >&2
}

# Puts back every setting p202_track_in_full changed; safe to call twice.
p202_privacy_restore() {
    local kept id before status after
    for kept in "${P202_PRIVACY_KEPT[@]}"; do
        id=${kept%%=*}
        before=${kept#*=}
        [ "$before" = disabled ] && continue
        read -r status after < <(p202__privacy_call PUT "$id" "{\"user_pref_privacy\":\"$before\"}")
        if [ "$status" != 200 ] || [ "$after" != "$before" ]; then
            echo "could not put user $id's privacy back to $before (PUT answered $status); set it by hand" >&2
        fi
    done
    P202_PRIVACY_KEPT=()
}
