#!/usr/bin/env python3
"""Exercise opt-in pageview capabilities on a disposable instance."""
import argparse
import json
import re
import urllib.parse
import urllib.request


def positive(value):
    if not value.isascii() or not value.isdecimal() or value.startswith('0'):
        raise argparse.ArgumentTypeError('must be a canonical positive integer')
    return int(value)


def get(base, page, params):
    fields = {'lpip': str(page), **params}
    url = base.rstrip('/') + '/tracking202/static/record.php?' + urllib.parse.urlencode(fields)
    req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
    with urllib.request.urlopen(req, timeout=20) as response:
        return response.read().decode()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base', required=True)
    parser.add_argument('--simple-page', required=True, type=positive)
    parser.add_argument('--advanced-page', required=True, type=positive)
    parser.add_argument('--advanced-campaign', required=True, type=positive)
    parser.add_argument('--ack-disposable-database', action='store_true')
    args = parser.parse_args()
    if not args.ack_disposable_database:
        parser.error('explicit disposable database acknowledgement is required')
    for page, campaign in [(args.simple_page, None), (args.advanced_page, args.advanced_campaign)]:
        for fields in ({}, {'p202_purlink': '1', 'p202_consent': '0'}):
            body = get(args.base, page, fields)
            if 'var subid =' not in body or 'p202PurLinkPageview(' in body:
                raise AssertionError('missing regular click or capability issued without opt-in and consent')
        body = get(args.base, page, {'p202_purlink': '1'})
        click = re.search(r'var subid\s*=\s*"([1-9][0-9]*)"', body)
        match = re.search(r'p202PurLinkPageview\((\{[^\n]*\})\)', body)
        if click is None or match is None:
            raise AssertionError('opted-in recorded click lacks capability')
        proofs = json.loads(match.group(1))
        if not proofs['exitProof'].startswith('exit.' + click.group(1) + '.') or not proofs['pixelProof'].startswith('pixels.' + click.group(1) + '.'):
            raise AssertionError('capabilities do not name the recorded click')
        fields = {'proof': proofs['exitProof']}
        if campaign is not None:
            fields['campaign'] = str(campaign)
        data = urllib.parse.urlencode(fields).encode()
        req = urllib.request.Request(args.base.rstrip('/') + '/tracking202/static/purlink-prepare.php', data=data)
        with urllib.request.urlopen(req, timeout=20) as response:
            subid = json.load(response)['subid']
        if not subid.startswith('p1.' + click.group(1) + '.'):
            raise AssertionError('prepared SubID does not name the recorded click')
    print('Simple and advanced pageviews issue usable scoped proofs only with consent and opt-in')


if __name__ == '__main__':
    main()
