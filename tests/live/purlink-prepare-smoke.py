#!/usr/bin/env python3
"""Exercise PurLink preparation over HTTP against a disposable instance.

The proof file must contain a current exit proof for an existing test click.
The campaign is its public offer ID. Do not use a real visitor's proof.
"""
import argparse
import json
import urllib.error
import urllib.parse
import urllib.request


def request(url, method, fields=None, body=None):
    if fields is not None:
        body = urllib.parse.urlencode(fields).encode('utf-8')
    req = urllib.request.Request(url, data=body, method=method)
    try:
        with urllib.request.urlopen(req, timeout=10) as response:
            return response.status, response.headers, response.read()
    except urllib.error.HTTPError as error:
        return error.code, error.headers, error.read()


def expect(url, method, expected, fields=None, body=None):
    status, headers, payload = request(url, method, fields, body)
    if status != expected:
        raise AssertionError(f'{method}: expected HTTP {expected}, got {status}: {payload[:200]!r}')
    return headers, payload


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base', required=True, help='base URL of a disposable Prosper202 instance')
    parser.add_argument('--proof-file', required=True, help='file holding a synthetic exit proof')
    parser.add_argument('--campaign', required=True, help='public campaign ID belonging to the proof')
    parser.add_argument('--other-campaign', required=True, help='public campaign ID that must be refused')
    parser.add_argument('--simple', action='store_true', help='the fixture is a simple landing page')
    args = parser.parse_args()
    with open(args.proof_file, encoding='utf-8') as proof_file:
        proof = proof_file.read().strip()
    if not proof or args.campaign == args.other_campaign:
        parser.error('a nonempty synthetic proof and distinct campaign IDs are required')
    url = args.base.rstrip('/') + '/tracking202/static/purlink-prepare.php'
    headers, payload = expect(url, 'POST', 200, {'proof': proof, 'campaign': args.campaign})
    data = json.loads(payload)
    assert isinstance(data.get('subid'), str) and data['subid'], 'response must contain a SubID'
    assert headers.get('Cache-Control') == 'no-store'
    assert headers.get('Access-Control-Allow-Origin') == '*'
    if args.simple:
        expect(url, 'POST', 200, {'proof': proof})
    else:
        expect(url, 'POST', 404, {'proof': proof})
    expect(url, 'POST', 404, {'proof': proof, 'campaign': args.other_campaign})
    expect(url, 'POST', 404, {'proof': proof + 'x', 'campaign': args.campaign})
    expect(url, 'POST', 404, {'proof': proof, 'campaign': args.campaign + 'x'})
    expect(url, 'POST', 422, {'proof[]': proof})
    expect(url, 'POST', 422, {'proof': proof, 'campaign[]': args.campaign})
    expect(url, 'POST', 422, {'proof': proof, 'extra': 'x'})
    headers, _ = expect(url, 'OPTIONS', 204)
    assert headers.get('Access-Control-Allow-Origin') == '*'
    headers, _ = expect(url, 'GET', 405)
    assert 'POST' in headers.get('Allow', '')
    expect(url, 'POST', 413, body=b'x=' + b'a' * 2048)
    print('PurLink preparation HTTP checks passed')


if __name__ == '__main__':
    main()
