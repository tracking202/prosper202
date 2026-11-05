#!/usr/bin/env python3
"""Test PurLink exit ordering against a disposable pre-seeded instance."""
import argparse
import json
from pathlib import Path
import subprocess
import urllib.error
import urllib.parse
import urllib.request


def positive(v):
    if not v.isascii() or not v.isdecimal() or v.startswith('0'):
        raise argparse.ArgumentTypeError('must be a canonical positive integer')
    return int(v)


def main():
    p = argparse.ArgumentParser(description=__doc__)
    for name in ['base', 'database', 'socket', 'proof-file']:
        p.add_argument('--' + name, required=True)
    for name in ['click', 'first-public', 'second-public', 'second-id', 'foreign-public']:
        p.add_argument('--' + name, required=True, type=positive)
    p.add_argument('--ack-disposable-database', action='store_true')
    a = p.parse_args()
    if not a.ack_disposable_database:
        p.error('explicit disposable database acknowledgement required')
    proof = Path(a.proof_file).read_text().strip()
    url = a.base.rstrip('/') + '/tracking202/static/purlink-exit.php'
    def request(fields=None, method='POST'):
        data = urllib.parse.urlencode(fields).encode() if fields is not None else None
        req = urllib.request.Request(url, data=data, method=method)
        try:
            with urllib.request.urlopen(req, timeout=15) as response:
                return response.status, response.read()
        except urllib.error.HTTPError as error:
            return error.code, error.read()
    reports = [
        ({'proof': proof, 'campaign': a.second_public, 'sequence': '2'}, 200, 'recorded'),
        ({'proof': proof, 'campaign': a.first_public, 'sequence': '1'}, 200, 'stale'),
        ({'proof': proof, 'campaign': a.second_public, 'sequence': '2'}, 200, 'duplicate'),
        ({'proof': proof, 'campaign': a.first_public, 'sequence': '2'}, 409, None),
        ({'proof': proof, 'campaign': a.foreign_public, 'sequence': '3'}, 404, None),
        ({'proof': proof + 'x', 'campaign': a.second_public, 'sequence': '3'}, 404, None),
        ({'proof': proof, 'campaign': a.second_public}, 422, None),
    ]
    for fields, expected, state in reports:
        status, body = request(fields)
        if status != expected or (state is not None and json.loads(body).get('status') != state):
            raise AssertionError(f'exit expected {expected}/{state}, got {status}: {body!r}')
    status, _ = request(method='GET')
    if status != 405:
        raise AssertionError(f'GET expected 405, got {status}')
    sql = (f'SELECT c.aff_campaign_id,r.click_out,o.sequence,o.campaign_id FROM 202_clicks c '
           f'JOIN 202_clicks_record r USING(click_id) JOIN 202_purlink_exit_order o USING(click_id) WHERE c.click_id={a.click}')
    result = subprocess.check_output(['mysql', '--protocol=socket', '--socket=' + a.socket, '-uroot', '--batch',
                                      '--skip-column-names', a.database, '-e', sql], text=True).strip().split('\t')
    if result != [str(a.second_id), '1', '2', str(a.second_id)]:
        raise AssertionError('stored click or ordering state was not the latest accepted exit')
    print('Ordered exit HTTP checks passed, including stale, duplicate and conflict reports')


if __name__ == '__main__':
    main()
