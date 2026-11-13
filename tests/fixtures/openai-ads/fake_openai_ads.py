#!/usr/bin/env python3
"""A local stand-in for OpenAI's Advertiser API (https://api.ads.openai.com/v1), over TLS.

No OpenAI account is reachable from tests or a live pass, so the install is
pointed at this (P202_OPENAI_ADS_ENDPOINT=https://127.0.0.1:<port>,
P202_OPENAI_ADS_CA_FILE=<tls-dir>/cert.pem). Answers follow the shapes of
https://developers.openai.com/ads/openapi.json (2.3.0) and the examples in
/ads/llms-full.txt; state is kept in memory, so what setup creates is listed
afterwards and a pause changes the campaign's status.

  GET  /v1/ad_account
  GET  /v1/conversions/pixels           POST /v1/conversions/pixels
  POST /v1/conversions/api_keys
  GET  /v1/conversions/event_settings   POST /v1/conversions/event_settings
  GET  /v1/campaigns | ad_groups | ads   (?campaign_id, ?ad_group_id, ?limit, ?after)
  GET  /v1/<level>/<id>                 POST /v1/<level>/<id> (an update)
  POST /v1/<level>/<id>/pause | activate
  GET  /v1/ad_account/insights          (aggregation_level=campaign, time_granularity=none)
  GET  /v1/conversions/events?pid=      the events a pixel received (server events as server_to_server)
  POST /v1/events?pid=                  the Conversions API host's events (Bearer a capi- key; validate_only saves nothing)

A request without `Authorization: Bearer <--api-key>` is 401. A create with
an Idempotency-Key it has seen answers what that key made.

Control (for whoever drives it):

  POST /control/reset                   back to the seeded account
  POST /control/disable                 {"pixels": true, "api_keys": true, "events": true}: those creates (and the
                                        pixel list) answer 404 "Not found", as for an account
                                        without the feature
  POST /control/account                 {"id": "adacct_999"}: the key now reaches that ad account, until the reset
  GET  /control/requests                every request seen, in order (keys masked)

Usage: fake_openai_ads.py --port 8242 --tls-dir DIR [--api-key KEY]
It writes DIR/cert.pem (self-signed for 127.0.0.1, localhost and bzr.openai.com: the CA the
install is given) and DIR/key.pem if missing, then prints "READY <port>".
"""
import argparse
import copy
import json
import os
import re
import ssl
import subprocess
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlsplit

SEED = {
    'account': {
        'id': 'adacct_123', 'name': 'Northwind Outfitters', 'url': 'https://shop.example.com', 'preview_url': None,
        'status': 'active', 'timezone': 'America/New_York', 'currency_code': 'USD', 'review': {'status': 'approved'},
    },
    'campaigns': [
        {'id': 'cmpn_101', 'created_at': 1735689600, 'updated_at': 1735862400, 'name': 'Spring sale', 'description': None,
         'status': 'active', 'product_feed_id': None, 'start_time': 1735689600, 'end_time': None,
         'budget': {'daily_spend_limit_micros': 25000000}, 'bidding_type': 'impressions',
         'conversion_event_setting_ids': [], 'landing_page_configuration': None, 'serving_issues': []},
        {'id': 'cmpn_102', 'created_at': 1735689600, 'updated_at': 1735862400, 'name': 'Trial sign-ups', 'description': None,
         'status': 'paused', 'product_feed_id': None, 'start_time': None, 'end_time': None,
         'budget': {'lifetime_spend_limit_micros': 500000000}, 'bidding_type': 'conversions',
         'conversion_event_setting_ids': [], 'landing_page_configuration': None,
         'serving_issues': [{'code': 'campaign_not_active'}]},
    ],
    'ad_groups': [
        {'id': 'adgrp_201', 'campaign_id': 'cmpn_101', 'created_at': 1735689600, 'updated_at': 1735862400, 'name': 'Running shoes',
         'description': None, 'context_hints': [], 'status': 'active', 'bidding_config': {}, 'landing_page_configuration': None,
         'serving_issues': []},
    ],
    'ads': [
        {'id': 'ad_301', 'ad_group_id': 'adgrp_201', 'name': 'Trail runner', 'created_at': 1735689600, 'updated_at': 1735862400,
         'creative': {}, 'status': 'active', 'review_status': 'approved', 'review': {'status': 'approved'},
         'landing_page_configuration': None, 'serving_issues': []},
        {'id': 'ad_302', 'ad_group_id': 'adgrp_201', 'name': 'Road runner', 'created_at': 1735689600, 'updated_at': 1735862400,
         'creative': {}, 'status': 'active', 'review_status': 'rejected',
         'review': {'status': 'rejected', 'reason': 'landing_page_unavailable'},
         'landing_page_configuration': None, 'serving_issues': [{'code': 'review_not_approved'}]},
    ],
    'insights': {'cmpn_101': (1200, 36, 18.42), 'cmpn_102': (980, 29, 14.86)},
}
LEVELS = {'campaigns', 'ad_groups', 'ads'}


class State:
    def __init__(self):
        self.lock = threading.Lock()
        self.reset()

    def reset(self):
        self.data = copy.deepcopy(SEED)
        self.pixels, self.event_settings, self.api_keys = [], [], []
        self.idempotent, self.requests, self.disabled, self.events = {}, [], set(), []
        self.counter = 900


STATE = State()


def page(rows, query):
    limit = int((query.get('limit') or ['100'])[0])
    after = (query.get('after') or [None])[0]
    if after is not None:
        ids = [r['id'] for r in rows]
        rows = rows[ids.index(after) + 1:] if after in ids else []
    chunk = rows[:limit]
    return {'object': 'list', 'data': chunk, 'first_id': chunk[0]['id'] if chunk else None,
            'last_id': chunk[-1]['id'] if chunk else None, 'has_more': len(rows) > limit}


class Handler(BaseHTTPRequestHandler):
    api_key = 'sk-ads-live-demo-key-0001'

    def log_message(self, fmt, *args):
        pass

    def answer(self, status, payload):
        body = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def body(self):
        length = int(self.headers.get('Content-Length') or 0)
        raw = self.rfile.read(length) if length else b''
        try:
            return json.loads(raw or b'{}')
        except ValueError:
            return None

    def do_GET(self):
        self.route('GET')

    def do_POST(self):
        self.route('POST')

    def route(self, method):
        url = urlsplit(self.path)
        query = parse_qs(url.query)
        path = url.path
        with STATE.lock:
            if path.startswith('/control/'):
                return self.control(method, path)
            body = self.body() if method == 'POST' else {}
            auth = self.headers.get('Authorization', '')
            if path == '/v1/events' and method == 'POST':
                return self.events(query, body, auth)
            STATE.requests.append({'method': method, 'path': path, 'query': url.query, 'body': body,
                                   'idempotency_key': self.headers.get('Idempotency-Key'),
                                   'authorized': auth == 'Bearer ' + self.api_key})
            if auth != 'Bearer ' + self.api_key:
                return self.answer(401, {'error': {'message': 'Invalid API key', 'type': 'invalid_request_error'}})
            if body is None:
                return self.answer(400, {'error': {'message': 'Body is not JSON'}})
            key = self.headers.get('Idempotency-Key')
            if method == 'POST' and key and key in STATE.idempotent:
                return self.answer(200, STATE.idempotent[key])
            status, payload = self.dispatch(method, path, query, body)
            if method == 'POST' and key and status == 200:
                STATE.idempotent[key] = payload
            return self.answer(status, payload)

    def dispatch(self, method, path, query, body):
        d = STATE.data
        not_found = (404, {'error': {'message': 'Not found'}})
        if path == '/v1/ad_account' and method == 'GET':
            return 200, d['account']
        if path == '/v1/conversions/pixels':
            if 'pixels' in STATE.disabled:
                return not_found
            if method == 'GET':
                return 200, page(STATE.pixels, query)
            if not isinstance(body.get('name'), str) or len(body['name']) < 3 or body.get('client_type') != 'web':
                return 400, {'error': {'message': 'name (3 to 1000 characters) and client_type web are required'}}
            STATE.counter += 1
            pixel = {'id': 'clidsrc_%d' % STATE.counter, 'name': body['name'], 'client_type': 'web', 'pixel_id': '1345%05d' % STATE.counter}
            STATE.pixels.append(pixel)
            return 200, pixel
        if path == '/v1/conversions/api_keys' and method == 'POST':
            if 'api_keys' in STATE.disabled:
                return not_found
            STATE.counter += 1
            made = {'name': body.get('name', ''), 'api_key': 'capi-demo-%d-Xq7' % STATE.counter}
            STATE.api_keys.append(made)
            return 200, made
        if path == '/v1/conversions/event_settings':
            if method == 'GET':
                return 200, page(STATE.event_settings, query)
            sources = body.get('source_ids')
            if not isinstance(sources, list) or len(sources) != 1:
                return 400, {'error': {'message': 'source_ids must hold exactly one source'}}
            if sources[0] not in [p['id'] for p in STATE.pixels]:
                return 400, {'error': {'message': 'Client data source not found'}}
            STATE.counter += 1
            setting = {'id': 'ces_%d' % STATE.counter, 'name': body.get('name', ''), 'event_type': body.get('event_type'),
                       'custom_event_name': body.get('custom_event_name'), 'attribution_window_days': body.get('attribution_window_days'),
                       'ad_account_id': d['account']['id'], 'source_ids': sources,
                       'sources': [{'id': sources[0], 'name': None}], 'campaigns': [], 'archived': False, 'version': 1}
            STATE.event_settings.append(setting)
            return 200, setting
        if path == '/v1/conversions/events' and method == 'GET':
            if 'events' in STATE.disabled:
                return not_found
            pid = (query.get('pid') or [''])[0]
            rows = [e for e in STATE.events if e['pixel_id'] == pid][-50:]
            return 200, {'object': 'list', 'data': [{k: v for k, v in e.items() if k != 'pixel_id'} for e in reversed(rows)]}
        if path == '/v1/ad_account/insights' and method == 'GET':
            rows = [{'id': 'entity_id=' + cid, 'start_time': 0, 'end_time': 0, 'campaign_id': cid,
                     'campaign_name': next(c['name'] for c in d['campaigns'] if c['id'] == cid),
                     'impressions': imp, 'clicks': clicks, 'spend': spend}
                    for cid, (imp, clicks, spend) in d['insights'].items()]
            return 200, {'object': 'list', 'data': rows, 'count': len(rows), 'first_id': None, 'last_id': None, 'has_more': False}
        match = re.fullmatch(r'/v1/(campaigns|ad_groups|ads)(?:/([a-z]+_[A-Za-z0-9-]+)(?:/(pause|activate))?)?', path)
        if not match:
            return not_found
        level, oid, action = match.groups()
        rows = d[level]
        if oid is None:
            if method != 'GET':
                return 405, {'error': {'message': 'Creating is not part of this stand-in'}}
            parent = {'ad_groups': 'campaign_id', 'ads': 'ad_group_id'}.get(level)
            if parent and query.get(parent):
                rows = [r for r in rows if r.get(parent) == query[parent][0]]
            return 200, page(rows, query)
        row = next((r for r in rows if r['id'] == oid), None)
        if row is None:
            return not_found
        if method == 'GET':
            return 200, row
        if action is not None:
            row['status'] = 'paused' if action == 'pause' else 'active'
            return 200, row
        for field in ('name', 'status', 'conversion_event_setting_ids', 'landing_page_configuration'):
            if field in body:
                if field == 'landing_page_configuration':
                    template = (body[field] or {}).get('query_string_template')
                    if template is not None and len(template) > 2048:
                        return 400, {'error': {'message': 'query_string_template is longer than 2048'}}
                row[field] = body[field]
        return 200, row

    def events(self, query, body, auth):
        """POST /v1/events?pid= on the Conversions API host: Bearer a Conversions API key this stand-in
        issued (or one starting capi-, as a key from Ads Manager); validate_only saves nothing."""
        key = auth[len('Bearer '):] if auth.startswith('Bearer ') else ''
        STATE.requests.append({'method': 'POST', 'path': '/v1/events', 'query': urlsplit(self.path).query, 'body': body,
                               'idempotency_key': None, 'authorized': key.startswith('capi-')})
        if not key.startswith('capi-'):
            return self.answer(401, {'error': {'message': 'Invalid Conversions API key'}})
        pid = (query.get('pid') or [''])[0]
        events = (body or {}).get('events')
        if not pid or not isinstance(events, list) or not events:
            return self.answer(400, {'error': {'message': 'pid and events are required'}})
        if not body.get('validate_only'):
            for event in events:
                STATE.events.append({'pixel_id': pid, 'action_source': event.get('action_source'), 'api_channel': 'server_to_server',
                                     'custom_event_name': event.get('custom_event_name'), 'data_source_id': 'cds_1',
                                     'event_data_json': json.dumps(event.get('data', {})), 'event_timestamp_ms': event.get('timestamp_ms'),
                                     'event_type': event.get('type'), 'received_at_ms': event.get('timestamp_ms')})
        return self.answer(200, {'object': 'list', 'data': []})

    def control(self, method, path):
        if path == '/control/reset' and method == 'POST':
            STATE.reset()
            return self.answer(200, {'ok': True})
        if path == '/control/disable' and method == 'POST':
            body = self.body() or {}
            STATE.disabled = {name for name, on in body.items() if on}
            return self.answer(200, {'disabled': sorted(STATE.disabled)})
        if path == '/control/account' and method == 'POST':
            body = self.body() or {}
            if not isinstance(body.get('id'), str) or body['id'] == '':
                return self.answer(400, {'error': {'message': 'id is required'}})
            STATE.data['account']['id'] = body['id']
            return self.answer(200, {'id': body['id']})
        if path == '/control/requests' and method == 'GET':
            return self.answer(200, {'requests': STATE.requests})
        return self.answer(404, {'error': {'message': 'no such control'}})


def ensure_cert(tls_dir):
    cert, key = os.path.join(tls_dir, 'cert.pem'), os.path.join(tls_dir, 'key.pem')
    if not (os.path.exists(cert) and os.path.exists(key)):
        os.makedirs(tls_dir, exist_ok=True)
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', key, '-out', cert,
                        '-days', '2', '-subj', '/CN=127.0.0.1',
                        '-addext', 'subjectAltName=IP:127.0.0.1,DNS:localhost,DNS:bzr.openai.com',
                        '-addext', 'basicConstraints=critical,CA:TRUE'],
                       check=True, capture_output=True)
    return cert, key


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--port', type=int, default=8242)
    parser.add_argument('--tls-dir', required=True)
    parser.add_argument('--api-key', default=Handler.api_key)
    args = parser.parse_args()
    Handler.api_key = args.api_key
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
