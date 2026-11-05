#!/usr/bin/env python3
"""Record synthetic conversions against a disposable test instance only."""
import argparse
import secrets
import subprocess
import urllib.error
import urllib.parse
import urllib.request


def positive(value):
    if not value.isascii() or not value.isdecimal() or value.startswith('0'):
        raise argparse.ArgumentTypeError('must be a canonical positive integer')
    return int(value)


def http(base, params):
    url = base.rstrip('/') + '/tracking202/static/gpb.php?' + urllib.parse.urlencode(params)
    try:
        with urllib.request.urlopen(url, timeout=15) as response:
            return response.status
    except urllib.error.HTTPError as error:
        return error.code


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base', required=True)
    parser.add_argument('--database', required=True)
    parser.add_argument('--socket', required=True)
    parser.add_argument('--click', required=True, type=positive)
    parser.add_argument('--numeric-click', required=True, type=positive)
    parser.add_argument('--campaign-public', required=True, type=positive)
    parser.add_argument('--other-campaign-public', required=True, type=positive)
    parser.add_argument('--campaign-id', required=True, type=positive)
    parser.add_argument('--ack-disposable-database', action='store_true')
    args = parser.parse_args()
    if not args.ack_disposable_database or args.click == args.numeric_click:
        parser.error('explicit disposable database acknowledgement and distinct synthetic clicks are required')
    token = secrets.token_hex(8)
    tx = 'purlink-test-' + token
    numeric_tx = 'purlink-numeric-' + token
    legacy = f'{args.click}-{args.campaign_public}'
    good = {'subid': legacy, 'amount': '4.25', 'txid': tx}
    checks = [
        (good, 200),
        (good, 200),
        ({'subid': f'{args.click}-{args.other_campaign_public}', 'txid': 'wrong-' + token}, 404),
        ({'subid': legacy + 'x', 'txid': 'bad-' + token}, 404),
        ({'subid': str(args.numeric_click), 'amount': '2', 'txid': numeric_tx}, 200),
    ]
    for fields, expected in checks:
        status = http(args.base, fields)
        if status != expected:
            raise AssertionError(f'postback expected HTTP {expected}, got {status}')
    sql = ("SELECT click_id,campaign_id,click_payout,transaction_id FROM 202_conversion_logs "
           f"WHERE transaction_id IN ('{tx}','{numeric_tx}') ORDER BY click_id")
    output = subprocess.check_output([
        'mysql', '--protocol=socket', '--socket=' + args.socket, '-uroot', '--batch',
        '--skip-column-names', args.database, '-e', sql,
    ], text=True)
    rows = [line.split('\t') for line in output.splitlines()]
    expected_rows = sorted([
        [str(args.click), str(args.campaign_id), '4.25000', tx],
        [str(args.numeric_click), str(args.campaign_id), '2.00000', numeric_tx],
    ], key=lambda row: int(row[0]))
    if rows != expected_rows:
        raise AssertionError('conversion readback differed from expected synthetic rows')
    print('Legacy PurLink and numeric postback HTTP checks passed')


if __name__ == '__main__':
    main()
