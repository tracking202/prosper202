#!/bin/bash
# The scratch-database guard, shared by every pass here.
#
# One implementation, and a port of tests/browser/lib/db.js rather than a
# second opinion: the two harnesses truncate the same tables on the same
# instance, so a name one of them refuses and the other accepts is a trap.
#
# The first version of THIS file matched substrings and allowed anything
# containing "live", to admit the p202_live default. `production_live` passed
# it. The word has to be a whole segment now, "live" is not one of the words,
# and the default name below was changed to match.
#
# Sourced, not executed: it defines p202_require_scratch_db, and the caller
# passes the name it is about to truncate.

# Names that read as disposable. Deliberately conservative — a database called
# `prosper202` is somebody's install until proven otherwise.
P202_SCRATCH_RE='(^|[_-])(test|tests|scratch|tmp|temp|ci|eval|evals|fixture|sandbox|w[0-9]+|probe|check)([_-]|$)'

p202_require_scratch_db() { # $1 = database name
    local name="$1"
    if [[ "$name" =~ $P202_SCRATCH_RE ]]; then
        return 0
    fi
    if [ "${P202_DB_ALLOW_DESTRUCTIVE:-}" = "yes-i-mean-it" ]; then
        return 0
    fi
    cat >&2 <<MSG
Refusing to write to "$name": this pass truncates tables and that name does not
look like a scratch database. Point P202_DB at a throwaway instance (a name
containing test/scratch/tmp/ci/eval, or ending in a digit suffix like _w1), or
if you really mean it:
  P202_DB_ALLOW_DESTRUCTIVE=yes-i-mean-it
MSG
    return 1
}
