#!/usr/bin/env bash
#
# CI only: answer my.tracking202.com from a local HTTPS stub, so a job that
# installs Prosper202 never sends traffic to production. Production rejects
# unknown licence keys (since 2026-10-09) and CI's key is made up, and the
# installer checks the key before it creates the account.
#
# A throwaway certificate for my.tracking202.com is trusted by the runner's
# CA store and the name points at 127.0.0.1 in /etc/hosts, so TLS
# verification stays fully on and no product code knows about the stub.
# The licence check answers "Key valid" + paid:true for a non-empty key and
# 404 "Key not found" for an empty one; a key ending in one of the suffixes
# in Stub.ANSWERS (and -outage, -redirect...) gets that answer instead, for
# tests/live/cli-licence-gate.sh. Every other path answers 200 {}.
#
#   licence-stub.sh start <dir>   start it, and prove PHP's curl verifies it
#   licence-stub.sh stop <dir>    stop it, and list the requests it answered
#
# It edits /etc/hosts and the system CA store with sudo and listens on 443,
# so it refuses to run anywhere but a GitHub Actions runner.

set -euo pipefail

ACTION="${1:-}"
DIR="${2:-}"
URL=https://my.tracking202.com/api/v2/validate-customers-key

if [ "${GITHUB_ACTIONS:-}" != "true" ]; then
    echo "licence-stub.sh: CI only (it edits /etc/hosts and the system CA store)." >&2
    exit 2
fi
if [ -z "$DIR" ] || { [ "$ACTION" != start ] && [ "$ACTION" != stop ]; }; then
    echo "usage: licence-stub.sh start|stop <dir>" >&2
    exit 2
fi

if [ "$ACTION" = stop ]; then
    if [ -f "$DIR/stub.pid" ]; then
        sudo kill "$(cat "$DIR/stub.pid")" || true
    fi
    if [ -f "$DIR/requests.log" ]; then
        echo "Requests sent to my.tracking202.com:"
        sort "$DIR/requests.log" | uniq -c
    fi
    exit 0
fi

mkdir -p "$DIR"
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=my.tracking202.com" \
    -addext "subjectAltName=DNS:my.tracking202.com" \
    -keyout "$DIR/key.pem" -out "$DIR/cert.pem" 2>/dev/null
sudo cp "$DIR/cert.pem" /usr/local/share/ca-certificates/p202-ci-stub.crt
sudo update-ca-certificates > /dev/null
echo "127.0.0.1 my.tracking202.com" | sudo tee -a /etc/hosts > /dev/null

cat > "$DIR/stub.py" <<'PY'
import http.server, json, os, ssl, sys, urllib.parse

class Stub(http.server.BaseHTTPRequestHandler):
    def send_raw(self, status, content_type, body):
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def send_json(self, status, payload):
        self.send_raw(status, "application/json", json.dumps(payload).encode())

    # Every answer the real service gives, plus three it never does,
    # chosen by the key's suffix; "-redirect", "-redirect-away" and
    # "-redirect-loop" (in do_POST) exercise the validator's own
    # redirect handling. tests/live/cli-licence-gate.sh sets the
    # install's key to each; keep the two lists in step.
    ANSWERS = {
        "-unpaid":   (200, {"code": 200, "msg": "Key valid", "paid": False}),
        "-no-paid":  (200, {"code": 200, "msg": "Key valid"}),
        "-odd-paid": (200, {"code": 200, "msg": "Key valid", "paid": "yes"}),
        "-stranger": (200, {"code": 200, "msg": "Temporarily unavailable"}),
        "-invalid":  (404, {"code": 404, "msg": "Key not found"}),
        "-refused":  (404, {"code": 403, "msg": "API key requires an active CPM* Newsletter subscription."}),
        "-gateway":  (404, {"message": "Not Found"}),
    }

    def redirect(self, location):
        self.send_response(302)
        self.send_header("Location", location)
        self.send_header("Content-Length", "0")
        self.end_headers()

    def do_POST(self):
        length = int(self.headers.get("Content-Length") or 0)
        form = urllib.parse.parse_qs(self.rfile.read(length).decode("utf-8", "replace"))
        path = self.path.rstrip("/")
        if path == "/api/v2/validate-customers-key/moved":
            # Where "-redirect" keys are sent: the validator followed a
            # same-host HTTPS redirect with the POST body intact.
            key = form.get("key", [""])[0].strip()
            return self.send_json(200, {"code": 200, "msg": "Key valid", "paid": not key.endswith("-unpaid-redirect")})
        if path == "/api/v2/validate-customers-key":
            key = form.get("key", [""])[0].strip()
            if not key:
                return self.send_json(404, {"code": 404, "msg": "Key not found"})
            if key.endswith("-outage"):
                return self.send_raw(503, "text/html", b"<html><body>Service Unavailable</body></html>")
            if key.endswith("-redirect"):
                return self.redirect("https://my.tracking202.com/api/v2/validate-customers-key/moved")
            if key.endswith("-redirect-away"):
                return self.redirect("https://evil.example/api/v2/validate-customers-key")
            if key.endswith("-redirect-loop"):
                return self.redirect("https://my.tracking202.com/api/v2/validate-customers-key")
            for suffix, (status, payload) in self.ANSWERS.items():
                if key.endswith(suffix):
                    return self.send_json(status, payload)
            return self.send_json(200, {"code": 200, "msg": "Key valid", "paid": True})
        self.send_json(200, {})

    def do_GET(self):
        self.send_json(200, {})

    def log_message(self, fmt, *args):
        sys.stderr.write("%s %s\n" % (self.command, self.path.split("?")[0]))
        sys.stderr.flush()

with open(sys.argv[3], "w") as pid:
    pid.write(str(os.getpid()))
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
ctx.load_cert_chain(sys.argv[1], sys.argv[2])
server = http.server.ThreadingHTTPServer(("127.0.0.1", 443), Stub)
server.socket = ctx.wrap_socket(server.socket, server_side=True)
server.serve_forever()
PY

# Port 443 needs root; the log redirect runs inside the root shell too.
sudo sh -c 'exec python3 "$1" "$2" "$3" "$4" > "$5" 2>&1' _ \
    "$DIR/stub.py" "$DIR/cert.pem" "$DIR/key.pem" "$DIR/stub.pid" "$DIR/requests.log" &
for _ in $(seq 1 20); do
    if curl -sf -X POST -d key=probe "$URL" | grep -q 'Key valid'; then
        break
    fi
    sleep 0.5
done
curl -sf -X POST -d key=probe "$URL" | grep -q 'Key valid'

# PHP's curl must verify the stub's certificate exactly as it would prod's.
cat > "$DIR/verify.php" <<'PHP'
<?php
$c = curl_init(getenv('URL'));
curl_setopt_array($c, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => 'key=probe',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$r = curl_exec($c);
if (strpos((string)$r, 'Key valid') === false) {
    fwrite(STDERR, 'PHP curl to the stub failed: ' . curl_error($c) . "\n");
    exit(1);
}
echo "PHP curl verified the stub over TLS\n";
PHP
URL="$URL" php "$DIR/verify.php"
