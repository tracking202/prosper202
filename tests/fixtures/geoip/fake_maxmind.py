#!/usr/bin/env python3
"""A local stand-in for MaxMind's GeoLite download endpoint, over TLS.

The sandbox and CI cannot reach MaxMind (and have no licence key), so the
refresh's real transport (202-config/GeoIp/GuardedCurlTransport.php) is
exercised against this. It answers as MaxMind's does (measured against
download.maxmind.com on 2026-10-23):

  GET /geoip/databases/<edition>/download?suffix=tar.gz
  GET /geoip/databases/<edition>/download?suffix=tar.gz.sha256
      HTTP Basic auth with --account and --license, else 401 with
      'www-authenticate: Basic realm="geoip-download"' and MaxMind's body;
      a suffix it does not know is 400. A good request is answered 302 to a
      presigned-looking URL on this server, /r2/<edition>.<suffix>?..., as
      MaxMind redirects to Cloudflare R2.
  GET /r2/<file>?X-Amz-...
      Serves --serve-dir/<file>. A request that carries an Authorization
      header is refused 400, as S3-style storage refuses a second auth
      mechanism on a presigned URL. <file>.status, when present, holds a
      status to answer instead (e.g. 429, 500).

What is served is whatever the test writes into --serve-dir:
<edition>.tar.gz and <edition>.tar.gz.sha256.

Control (same server):
  GET  /control/requests   every request seen: path, query, whether it carried
                           Authorization, and the status answered
  POST /control/reset      forget them

Usage: fake_maxmind.py --port 8151 --tls-dir DIR --serve-dir DIR --account 123456 --license KEY
It writes DIR/cert.pem (self-signed for 127.0.0.1, the CA the client is
given) and DIR/key.pem when missing, then prints "READY <port>" on stdout.
"""
import argparse
import base64
import json
import os
import ssl
import subprocess
import threading
import urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

LOCK = threading.Lock()
REQUESTS = []
UNAUTHENTICATED = b'Your account ID or license key could not be authenticated.'


class Handler(BaseHTTPRequestHandler):
    server_version = 'fake-maxmind'

    def log_message(self, fmt, *args):  # noqa: D401 - quiet
        pass

    def _record(self, status):
        parsed = urllib.parse.urlsplit(self.path)
        with LOCK:
            REQUESTS.append({
                'method': self.command,
                'path': parsed.path,
                'query': parsed.query,
                'authorization': self.headers.get('Authorization') is not None,
                'status': status,
            })

    def _send(self, status, body=b'', headers=None):
        self._record(status)
        self.send_response(status)
        for name, value in (headers or {}).items():
            self.send_header(name, value)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        if self.command != 'HEAD':
            self.wfile.write(body)

    def do_POST(self):
        if self.path == '/control/reset':
            with LOCK:
                REQUESTS.clear()
            self.send_response(204)
            self.end_headers()
            return
        self._send(404, b'not found')

    def do_GET(self):
        parsed = urllib.parse.urlsplit(self.path)
        if parsed.path == '/control/requests':
            with LOCK:
                body = json.dumps({'requests': REQUESTS}).encode()
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return

        parts = parsed.path.split('/')
        if len(parts) == 6 and parts[1:3] == ['geoip', 'databases'] and parts[4] == 'download' and parts[5] == '':
            parts = parts[:5]
        if len(parts) == 5 and parts[1:3] == ['geoip', 'databases'] and parts[4] == 'download':
            edition = parts[3]
            suffix = urllib.parse.parse_qs(parsed.query).get('suffix', [''])[0]
            if self.headers.get('Authorization') != self.server.expected_auth:
                self._send(401, UNAUTHENTICATED, {'www-authenticate': 'Basic realm="geoip-download"', 'Content-Type': 'text/plain;charset=UTF-8'})
                return
            if suffix not in ('tar.gz', 'tar.gz.sha256'):
                self._send(400, b'Invalid suffix')
                return
            location = 'https://127.0.0.1:%d/r2/%s.%s?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Signature=fake' % (
                self.server.server_address[1], edition, suffix)
            self._send(302, b'', {'Location': location})
            return

        if parsed.path.startswith('/r2/'):
            if self.headers.get('Authorization') is not None:
                self._send(400, b'<Error><Code>InvalidArgument</Code><Message>Only one auth mechanism allowed</Message></Error>')
                return
            name = os.path.basename(parsed.path)
            path = os.path.join(self.server.serve_dir, name)
            status_file = path + '.status'
            if os.path.exists(status_file):
                with open(status_file) as f:
                    self._send(int(f.read().strip()), b'overridden by the test')
                return
            if not os.path.exists(path):
                self._send(404, b'<Error><Code>NoSuchKey</Code></Error>')
                return
            with open(path, 'rb') as f:
                self._send(200, f.read(), {'Content-Type': 'application/gzip'})
            return

        self._send(404, b'not found')


def ensure_cert(tls_dir):
    cert, key = os.path.join(tls_dir, 'cert.pem'), os.path.join(tls_dir, 'key.pem')
    if not (os.path.exists(cert) and os.path.exists(key)):
        os.makedirs(tls_dir, exist_ok=True)
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', key, '-out', cert,
                        '-days', '2', '-subj', '/CN=127.0.0.1',
                        '-addext', 'subjectAltName=IP:127.0.0.1,DNS:localhost',
                        '-addext', 'basicConstraints=critical,CA:TRUE'],
                       check=True, capture_output=True)
    return cert, key


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--port', type=int, default=8151)
    parser.add_argument('--tls-dir', required=True)
    parser.add_argument('--serve-dir', required=True)
    parser.add_argument('--account', required=True)
    parser.add_argument('--license', required=True)
    args = parser.parse_args()

    cert, key = ensure_cert(args.tls_dir)
    server = ThreadingHTTPServer(('127.0.0.1', args.port), Handler)
    server.serve_dir = args.serve_dir
    server.expected_auth = 'Basic ' + base64.b64encode(('%s:%s' % (args.account, args.license)).encode()).decode()
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(cert, key)
    server.socket = context.wrap_socket(server.socket, server_side=True)
    print('READY %d' % server.server_address[1], flush=True)
    server.serve_forever()


if __name__ == '__main__':
    main()
