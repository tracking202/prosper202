#!/usr/bin/env python3
"""Exercise signed PurLink postbacks against a pre-seeded disposable instance."""
import argparse
import json
import secrets
import subprocess
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path


def positive(value):
    if not value.isascii() or not value.isdecimal() or value.startswith('0'):
        raise argparse.ArgumentTypeError('must be a canonical positive integer')
    return int(value)


def request(url, params, post=False):
    data = urllib.parse.urlencode(params).encode()
    req = urllib.request.Request(url if post else url + '?' + data.decode(), data=data if post else None)
    try:
        with urllib.request.urlopen(req, timeout=15) as response:
            return response.status, response.read()
    except urllib.error.HTTPError as error:
        return error.code, error.read()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base', required=True)
    parser.add_argument('--database', required=True)
    parser.add_argument('--socket', required=True)
    parser.add_argument('--proof-file', required=True)
    parser.add_argument('--click', required=True, type=positive)
    parser.add_argument('--campaign-public', required=True, type=positive)
    parser.add_argument('--campaign-id', required=True, type=positive)
    parser.add_argument('--other-campaign-public', required=True, type=positive)
    parser.add_argument('--second-campaign-public', required=True, type=positive)
    parser.add_argument('--second-campaign-id', required=True, type=positive)
    parser.add_argument('--expected-payout', required=True)
    parser.add_argument('--ack-disposable-database', action='store_true')
    args = parser.parse_args()
    if not args.ack_disposable_database:
        parser.error('explicit disposable database acknowledgement is required')
    proof = Path(args.proof_file).read_text().strip()
    prep = args.base.rstrip('/') + '/tracking202/static/purlink-prepare.php'
    status, body = request(prep, {'proof': proof, 'campaign': args.campaign_public}, True)
    if status != 200:
        raise AssertionError(f'preparation expected 200, got {status}')
    signed = json.loads(body)['subid']
    status, _ = request(prep, {'proof': proof, 'campaign': args.other_campaign_public}, True)
    if status != 404:
        raise AssertionError(f'foreign offer expected 404, got {status}')
    pb = args.base.rstrip('/') + '/tracking202/static/gpb.php'
    tx = 'signed-pur-' + secrets.token_hex(8)
    valid = {'subid': signed, 'txid': tx}
    for fields, expected in [(valid, 200), (valid, 200),
                             ({'subid': signed[:-1] + ('0' if signed[-1] != '0' else '1'), 'txid': tx + 'bad'}, 404),
                             ({'subid': signed, 'event': 'signup'}, 200)]:
        status, _ = request(pb, fields)
        if status != expected:
            raise AssertionError(f'postback expected {expected}, got {status}')
    sql = ("SELECT click_id,campaign_id,click_payout,transaction_id FROM 202_conversion_logs "
           f"WHERE transaction_id='{tx}'; "
           f"SELECT c.aff_campaign_id,r.click_out FROM 202_clicks c JOIN 202_clicks_record r USING (click_id) WHERE c.click_id={args.click}")
    output = subprocess.check_output(['mysql', '--protocol=socket', '--socket=' + args.socket, '-uroot',
                                      '--batch', '--skip-column-names', args.database, '-e', sql], text=True)
    expected = [f'{args.click}\t{args.campaign_id}\t{args.expected_payout}\t{tx}', f'{args.campaign_id}\t1']
    if output.splitlines() != expected:
        raise AssertionError('ledger or click route did not match: ' + output)
    # A second offer can convert after the first without changing the first
    # conversion's campaign, and the original amount remains reversible.
    status, body = request(prep, {'proof': proof, 'campaign': args.second_campaign_public}, True)
    if status != 200:
        raise AssertionError(f'second offer preparation expected 200, got {status}')
    second = json.loads(body)['subid']
    second_tx = tx + '-second'
    status, _ = request(pb, {'subid': second, 'txid': second_tx, 'amount': '3.25'})
    if status != 200:
        raise AssertionError(f'second offer postback expected 200, got {status}')
    for _ in range(2):
        status, _ = request(pb, {'subid': signed, 'txid': tx, 'status': 'reversed'})
        if status != 200:
            raise AssertionError(f'reversal expected 200, got {status}')
    sql = ("SELECT conv_id,campaign_id,click_payout,COALESCE(reverses_conv_id,0) FROM 202_conversion_logs "
           f"WHERE transaction_id IN ('{tx}','{second_tx}') ORDER BY conv_id")
    output = subprocess.check_output(['mysql', '--protocol=socket', '--socket=' + args.socket, '-uroot',
                                      '--batch', '--skip-column-names', args.database, '-e', sql], text=True)
    rows = [line.split('\t') for line in output.splitlines()]
    if len(rows) != 3 or rows[0][1:] != [str(args.campaign_id), args.expected_payout, '0']:
        raise AssertionError('first offer conversion changed: ' + output)
    if rows[1][1:] != [str(args.second_campaign_id), '3.25000', '0']:
        raise AssertionError('second offer was not credited correctly: ' + output)
    if rows[2][1:] != [str(args.campaign_id), '-' + args.expected_payout, rows[0][0]]:
        raise AssertionError('first offer reversal was not recorded correctly: ' + output)
    print('Signed PurLink HTTP postbacks, two offers, reversal, tamper refusal and retry passed')


if __name__ == '__main__':
    main()
