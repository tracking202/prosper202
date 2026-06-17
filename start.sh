#!/usr/bin/env bash
#
# One command from a clean checkout to a running Prosper202 with the database
# step already wired — no .env editing, no manual config file.
#
#   ./start.sh
#
set -euo pipefail
cd "$(dirname "$0")"

if ! command -v docker >/dev/null 2>&1; then
    echo "Error: Docker is not installed. See the README 'From source' track for" >&2
    echo "the non-Docker install.sh path, or the 'Download & Upload' track." >&2
    exit 1
fi

# Generate a 32-char hex secret from whatever's available. openssl isn't
# guaranteed to be installed, and under `set -o pipefail` a `tr </dev/urandom |
# head` pipe would fail (head closes early -> SIGPIPE), so read a fixed number of
# bytes with head first, then format.
gen_secret() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex 16
    elif [ -r /dev/urandom ]; then
        head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n'
    else
        echo "Error: need 'openssl' or a readable /dev/urandom to generate a DB password." >&2
        exit 1
    fi
}

# Generate .env with a random DB password on first run. The password is baked
# into the database volume on first start, so we never overwrite an existing one.
if [ ! -f .env ]; then
    echo "Creating .env with a generated database password..."
    {
        echo "MYSQL_ROOT_PASSWORD=$(gen_secret)"
        echo "APP_ENV=development"
    } > .env
fi

docker compose up -d --build

cat <<'EOF'

Prosper202 is starting up.

  Open:  http://localhost:8000

The setup wizard opens with the database step already done — just create your
admin account to finish.

SECURITY: the app is reachable before any account exists. Anyone who can reach
port 8000 before you finish the wizard can claim the install. Complete the
wizard now, especially on a machine with a public address.
EOF
