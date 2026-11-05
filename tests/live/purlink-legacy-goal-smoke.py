#!/usr/bin/env python3
"""Exercise a legacy PurLink goal on a pre-seeded disposable instance."""
import argparse
import json
import secrets
import subprocess
import urllib.parse
import urllib.request


def positive(value):
    if not value.isascii() or not value.isdecimal() or value.startswith("0"):
        raise argparse.ArgumentTypeError("must be a canonical positive integer")
    return int(value)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", required=True)
    parser.add_argument("--database", required=True)
    parser.add_argument("--socket", required=True)
    parser.add_argument("--click", required=True, type=positive)
    parser.add_argument("--campaign-public", required=True, type=positive)
    parser.add_argument("--campaign-id", required=True, type=positive)
    parser.add_argument("--ack-disposable-database", action="store_true")
    args = parser.parse_args()
    if not args.ack_disposable_database:
        parser.error("explicit disposable database acknowledgement is required")
    # The fixture must have a goal that pays 3.00 on 'signup', and a fresh click.
    event_id = "pur-goal-" + secrets.token_hex(8)
    fields = {"subid": f"{args.click}-{args.campaign_public}", "event": "signup", "event_id": event_id}
    url = args.base.rstrip("/") + "/tracking202/static/gpb.php?" + urllib.parse.urlencode(fields)
    for duplicate in (False, True):
        with urllib.request.urlopen(url, timeout=15) as response:
            result = json.load(response)
            if response.status != 200 or result.get("duplicate") is not duplicate:
                raise AssertionError("unexpected goal postback result: " + str(result))
    sql = ("SELECT COUNT(*) FROM 202_goal_events WHERE subject_type='click' "
           f"AND subject_id={args.click} AND event_id='{event_id}' AND name='signup'; "
           "SELECT COUNT(*) FROM 202_conversion_logs WHERE "
           f"click_id={args.click} AND campaign_id={args.campaign_id} "
           "AND source='goal' AND event_name='signup' AND click_payout=3")
    result = subprocess.check_output(["mysql", "--protocol=socket", "--socket=" + args.socket,
                                      "-uroot", "--batch", "--skip-column-names", args.database, "-e", sql], text=True)
    if result.splitlines() != ["1", "1"]:
        raise AssertionError("expected exactly one stored event and goal conversion: " + result)
    print("Legacy PurLink goal HTTP postback and idempotent retry passed")


if __name__ == "__main__":
    main()
