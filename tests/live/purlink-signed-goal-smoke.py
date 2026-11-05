#!/usr/bin/env python3
"""Exercise signed per-offer goals on a disposable pre-seeded instance."""
import argparse
import json
from pathlib import Path
import secrets
import subprocess
import urllib.error
import urllib.parse
import urllib.request


def positive(value):
    if not value.isascii() or not value.isdecimal() or value.startswith('0'):
        raise argparse.ArgumentTypeError('must be a canonical positive integer')
    return int(value)


def request(url, fields, post=False):
    data = urllib.parse.urlencode(fields).encode()
    req = urllib.request.Request(url if post else url + '?' + data.decode(), data=data if post else None)
    try:
        with urllib.request.urlopen(req, timeout=15) as response:
            return response.status, json.loads(response.read() or b'{}')
    except urllib.error.HTTPError as error:
        return error.code, json.loads(error.read() or b'{}')


def main():
    p = argparse.ArgumentParser(description=__doc__)
    for name in ['base', 'database', 'socket', 'proof-file']:
        p.add_argument('--' + name, required=True)
    for name in ['click', 'first-public', 'second-public', 'first-id', 'second-id', 'plain-public', 'plain-id']:
        p.add_argument('--' + name, required=True, type=positive)
    p.add_argument('--ack-disposable-database', action='store_true')
    a = p.parse_args()
    if not a.ack_disposable_database:
        p.error('explicit disposable database acknowledgement required')
    base = a.base.rstrip('/')
    proof = Path(a.proof_file).read_text().strip()
    def prepare(campaign):
        status, body = request(base + '/tracking202/static/purlink-prepare.php', {'proof': proof, 'campaign': campaign}, True)
        if status != 200:
            raise AssertionError(f'preparation returned {status}')
        return body['subid']
    first, second, plain = [prepare(x) for x in [a.first_public, a.second_public, a.plain_public]]
    event_id = 'purlink-goal-' + secrets.token_hex(8)
    def send(subid, **extra):
        return request(base + '/tracking202/static/gpb.php', {'subid': subid, **extra})
    status, _ = send(first, event='signup', event_props='{')
    if status != 422:
        raise AssertionError(f'invalid event expected 422, got {status}')
    before = subprocess.check_output(['mysql', '--protocol=socket', '--socket=' + a.socket, '-uroot', '--batch',
                                      '--skip-column-names', a.database, '-e',
                                      f'SELECT aff_campaign_id FROM 202_clicks WHERE click_id={a.click}'], text=True).strip()
    if before != str(a.plain_id):
        raise AssertionError('invalid event changed the click route')
    for subid, duplicate in [(first, False), (second, False), (first, True)]:
        status, body = send(subid, event='signup', event_id=event_id)
        if status != 200 or body.get('duplicate') is not duplicate:
            raise AssertionError(f'goal result {status}: {body}')
    status, _ = send(first, event='signup', event_id=event_id, event_props='{"color":"blue"}')
    if status != 409:
        raise AssertionError(f'conflicting event expected 409, got {status}')
    status, _ = send(second, event='signup', event_id=event_id, status='reversed', txid='anything')
    if status != 422:
        raise AssertionError(f'event reversal expected 422, got {status}')
    tx = 'plain-event-' + secrets.token_hex(8)
    status, _ = send(plain, event='signup', txid=tx, amount='4.25')
    if status != 200:
        raise AssertionError(f'plain conversion with event expected 200, got {status}')
    sql = ("SELECT campaign_id,click_payout,source,event_name FROM 202_conversion_logs "
           f"WHERE click_id={a.click} ORDER BY conv_id; "
           f"SELECT COUNT(*) FROM 202_goal_events e JOIN 202_purlink_goal_subjects s ON s.subject_id=e.subject_id "
           f"WHERE e.subject_type='purlink' AND s.click_id={a.click} AND e.event_id='{event_id}'")
    out = subprocess.check_output(['mysql', '--protocol=socket', '--socket=' + a.socket, '-uroot', '--batch',
                                   '--skip-column-names', a.database, '-e', sql], text=True).splitlines()
    expected = [f'{a.first_id}\t3.00000\tgoal\tsignup', f'{a.second_id}\t3.00000\tgoal\tsignup',
                f'{a.plain_id}\t4.25000\tpostback\tsignup', '2']
    if out != expected:
        raise AssertionError('goal event or plain conversion readback did not match: ' + repr(out))
    print('Signed per-offer goals, retries and no-goal fallback passed over HTTP')


if __name__ == '__main__':
    main()
