#!/usr/bin/env python3
"""A local stand-in for Google's two Play Integrity endpoints, over TLS.

The sandbox and CI cannot reach Google, so the real client
(api/v3/Apps/Android/Integrity/GooglePlayIntegrityClient.php) is exercised
against this: it speaks the same wire protocol, checks what Google would
check, and records every request so a test can read what the client sent.

  POST /token                              OAuth 2.0 JWT bearer grant
      form: grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer,
            assertion=<RS256 JWT>
      The assertion's signature is verified with `openssl dgst -verify`
      against --sa-public-key, and its claims are checked (iss, scope, aud,
      iat/exp). A bad one is answered 400 invalid_grant, as Google does.
  POST /v1/<package>:decodeIntegrityToken
      Authorization: Bearer <a token /token issued>, body
      {"integrity_token": "..."}. Answered from the scenario registered for
      that integrity token (below); an unknown token is 400
      INVALID_ARGUMENT, as Google answers a token it cannot decrypt.

Control (same server, for the test driving it):

  POST /control/reset                     forget scenarios, requests and issued tokens
  POST /control/scenario                  {"token": T, "responses": [R, ...]}
      Each decode of T consumes the next R; the last one repeats. R is
      {"status": 200, "payload": {...}} (sent as tokenPayloadExternal),
      or {"status": N, "json": {...}} / {"status": N, "body": "raw"},
      optionally with "delay": seconds and "headers": {...}.
  POST /control/token-endpoint            {"responses": [R, ...]} overrides
      what /token answers, the same way (default: a fresh access token).
  GET  /control/requests                  every request seen, in order.

Usage: fake_google.py --port 8241 --tls-dir DIR --sa-public-key PEM
It writes DIR/cert.pem (self-signed for 127.0.0.1 and localhost: the CA
the client is given) and DIR/key.pem if they are missing, then prints
"READY <port>" on stdout once it is listening.
"""
import argparse
import base64
import json
import os
import ssl
import subprocess
import sys
import tempfile
import threading
import time
import urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

TOKEN_URL = 'https://oauth2.googleapis.com/token'
SCOPE = 'https://www.googleapis.com/auth/playintegrity'
GRANT = 'urn:ietf:params:oauth:grant-type:jwt-bearer'

LOCK = threading.Lock()
STATE = {'scenarios': {}, 'token_responses': [], 'requests': [], 'issued': set(), 'counter': 0}


def b64url_decode(part):
    return base64.urlsafe_b64decode(part + '=' * (-len(part) % 4))


def verify_jwt(assertion, public_key):
    """(claims, None) for a valid assertion, (claims-or-None, reason) otherwise."""
    parts = assertion.split('.')
    if len(parts) != 3:
        return None, 'not a JWT'
    try:
        header = json.loads(b64url_decode(parts[0]))
        claims = json.loads(b64url_decode(parts[1]))
        signature = b64url_decode(parts[2])
    except Exception:  # noqa: BLE001 - any decoding failure is a malformed JWT
        return None, 'undecodable JWT'
    if header.get('alg') != 'RS256' or header.get('typ') != 'JWT':
        return claims, 'header is not RS256 JWT'
    with tempfile.TemporaryDirectory() as tmp:
        data = os.path.join(tmp, 'data')
        sig = os.path.join(tmp, 'sig')
        with open(data, 'wb') as f:
            f.write((parts[0] + '.' + parts[1]).encode())
        with open(sig, 'wb') as f:
            f.write(signature)
        ok = subprocess.run(['openssl', 'dgst', '-sha256', '-verify', public_key, '-signature', sig, data],
                            capture_output=True, check=False).returncode == 0
    if not ok:
        return claims, 'Invalid JWT Signature.'
    now = int(time.time())
    if claims.get('aud') != TOKEN_URL:
        return claims, 'aud is not the token endpoint'
    if claims.get('scope') != SCOPE:
        return claims, 'scope is not playintegrity'
    if not isinstance(claims.get('iss'), str) or '@' not in claims['iss']:
        return claims, 'iss is not a service account'
    iat, exp = claims.get('iat'), claims.get('exp')
    if not isinstance(iat, int) or not isinstance(exp, int) or exp - iat > 3600 or exp < now or iat > now + 300:
        return claims, 'iat/exp out of range'
    return claims, None


class Handler(BaseHTTPRequestHandler):
    server_version = 'FakeGoogle/1'
    public_key = None

    def log_message(self, fmt, *args):  # quiet; the requests are recorded instead
        pass

    def _body(self):
        length = int(self.headers.get('Content-Length') or 0)
        return self.rfile.read(length) if length else b''

    def _send(self, status, payload=None, raw=None, headers=None):
        body = raw.encode() if raw is not None else json.dumps(payload if payload is not None else {}).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        for k, v in (headers or {}).items():
            self.send_header(k, v)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        try:
            self.wfile.write(body)
        except (BrokenPipeError, ConnectionResetError):
            pass

    def _reply(self, response, default_payload_key=None):
        delay = response.get('delay', 0)
        if delay:
            time.sleep(delay)
        status = response.get('status', 200)
        if 'payload' in response:
            return self._send(status, {default_payload_key: response['payload']}, headers=response.get('headers'))
        if 'body' in response:
            return self._send(status, raw=response['body'], headers=response.get('headers'))
        return self._send(status, response.get('json', {}), headers=response.get('headers'))

    def _record(self, entry):
        with LOCK:
            STATE['requests'].append(entry)

    def do_GET(self):
        if self.path == '/control/requests':
            with LOCK:
                return self._send(200, {'requests': STATE['requests']})
        return self._send(404, {'error': {'code': 404, 'message': 'not found', 'status': 'NOT_FOUND'}})

    def do_POST(self):
        raw = self._body()
        path = urllib.parse.urlsplit(self.path).path
        if path.startswith('/control/'):
            return self._control(path, raw)
        headers = {k.lower(): v for k, v in self.headers.items()}
        if path == '/token':
            return self._token(raw, headers)
        prefix, suffix = '/v1/', ':decodeIntegrityToken'
        if path.startswith(prefix) and path.endswith(suffix):
            return self._decode(urllib.parse.unquote(path[len(prefix):-len(suffix)]), raw, headers)
        self._record({'path': path, 'unknown': True})
        return self._send(404, {'error': {'code': 404, 'message': 'no such method', 'status': 'NOT_FOUND'}})

    def _control(self, path, raw):
        data = json.loads(raw or b'{}')
        with LOCK:
            if path == '/control/reset':
                STATE['scenarios'].clear()
                STATE['token_responses'] = []
                STATE['requests'] = []
                STATE['issued'] = set()
                STATE['counter'] = 0
            elif path == '/control/scenario':
                STATE['scenarios'][data['token']] = list(data['responses'])
            elif path == '/control/token-endpoint':
                STATE['token_responses'] = list(data['responses'])
            else:
                return self._send(404, {'error': 'unknown control'})
        return self._send(200, {'ok': True})

    def _token(self, raw, headers):
        form = urllib.parse.parse_qs(raw.decode(), keep_blank_values=True)
        grant = form.get('grant_type', [''])[0]
        assertion = form.get('assertion', [''])[0]
        claims, problem = verify_jwt(assertion, self.public_key)
        if grant != GRANT:
            problem = 'unsupported grant_type'
        self._record({'path': '/token', 'content_type': headers.get('content-type'), 'grant_type': grant,
                      'jwt_claims': claims, 'jwt_problem': problem})
        with LOCK:
            override = STATE['token_responses'].pop(0) if len(STATE['token_responses']) > 1 else \
                (STATE['token_responses'][0] if STATE['token_responses'] else None)
        if override is not None:
            return self._reply(override)
        if problem is not None:
            return self._send(400, {'error': 'invalid_grant', 'error_description': problem})
        with LOCK:
            STATE['counter'] += 1
            token = 'fake-access-%d' % STATE['counter']
            STATE['issued'].add(token)
        return self._send(200, {'access_token': token, 'expires_in': 3599, 'token_type': 'Bearer'})

    def _decode(self, package, raw, headers):
        auth = headers.get('authorization', '')
        try:
            integrity_token = json.loads(raw or b'{}').get('integrity_token')
        except ValueError:
            integrity_token = None
        self._record({'path': '/v1/%s:decodeIntegrityToken' % package, 'package': package, 'authorization': auth,
                      'content_type': headers.get('content-type'), 'integrity_token': integrity_token})
        with LOCK:
            issued = auth.startswith('Bearer ') and auth[len('Bearer '):] in STATE['issued']
            queue = STATE['scenarios'].get(integrity_token)
            response = None
            if queue:
                response = queue.pop(0) if len(queue) > 1 else queue[0]
        if not issued:
            return self._send(401, {'error': {'code': 401, 'message': 'Request had invalid authentication credentials.',
                                              'status': 'UNAUTHENTICATED'}})
        if response is None:
            return self._send(400, {'error': {'code': 400, 'message': 'Integrity token cannot be decoded.',
                                              'status': 'INVALID_ARGUMENT'}})
        return self._reply(response, 'tokenPayloadExternal')


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
    parser.add_argument('--port', type=int, default=8241)
    parser.add_argument('--tls-dir', required=True)
    parser.add_argument('--sa-public-key', required=True)
    args = parser.parse_args()
    Handler.public_key = args.sa_public_key
    cert, key = ensure_cert(args.tls_dir)
    server = ThreadingHTTPServer(('127.0.0.1', args.port), Handler)
    server.daemon_threads = True
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(cert, key)
    server.socket = context.wrap_socket(server.socket, server_side=True)
    print('READY %d' % server.server_address[1], flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass


if __name__ == '__main__':
    sys.exit(main())
