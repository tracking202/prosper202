---
name: onboard-prosper202
description: >-
  Onboard a freshly installed Prosper202 ClickServer. Use when the user wants to
  "onboard Prosper202", "set up Prosper202", "create my first tracking link",
  "fill in basic settings", or finish post-install configuration. Interviews the
  user, then drives the Go CLI (go-cli/p202) to set preferences and build a first
  working tracker. Requires a completed install and the user's REST API key.
allowed-tools: Bash(.claude/skills/onboard-prosper202/scripts/find-cli.sh), Bash(make -C go-cli build), Bash(go-cli/p202 *), Bash(go-cli/dist/*/p202 *), Read, Write
---

# Onboard Prosper202

Guide a new user from a finished install to their first working tracking link by
driving the Go CLI. Be conversational: ask for what you need, confirm each entity
you create, and end by handing them a tracking URL.

## 0. Locate the CLI

Run the helper to get a usable `p202` binary path (it prefers the release-bundled
binary, falls back to a local build, and builds from source if Go is present):

```bash
.claude/skills/onboard-prosper202/scripts/find-cli.sh
```

Use the printed path as `P202` in the steps below. If it fails, tell the user the
CLI binary is missing and Go isn't installed to build it, then stop.

## 1. Connect

Ask the user for:
- **Install URL** (default `http://localhost:8000`).
- **REST API key** — shown once on the install success screen, or generated under
  **Account → REST API Keys**. This is the local `202_api_keys` Bearer key, NOT the
  my.tracking202 install key.

```bash
$P202 config set-url "<url>"
$P202 config set-key "<api-key>"
$P202 config test          # hits /api/v3/system/health — must succeed before continuing
```

If `config test` fails, recheck the URL/key with the user and retry. Do not proceed
until it passes.

## 2. Basic settings

Ask for their tracking domain, currency, and whether they want the daily email,
then apply them (you need their numeric **user id**, shown on the success screen;
otherwise ask):

```bash
$P202 user prefs update <user_id> \
  --user_tracking_domain="<domain>" \
  --user_account_currency="<USD|EUR|...>" \
  --user_daily_email="<on|off>"
```

## 3. First working tracker

Create the entities in order, parsing the `--json` output to capture each new ID
for the next call. See `cli-guide.md` in this skill for the full flag reference.

```bash
$P202 ppc-network  create --ppc_network_name="Google Ads" --json
$P202 ppc-account  create --ppc_account_name="<acct>" --ppc_network_id=<id> --json
$P202 aff-network  create --aff_network_name="<network>" --json
$P202 campaign     create --aff_campaign_name="<offer>" --aff_campaign_url="<offer url>" --aff_network_id=<id> --json
# optional pre-sell page:
$P202 landing-page create --landing_page_url="<lp url>" --aff_campaign_id=<id> --json
$P202 tracker      create --aff_campaign_id=<id> --ppc_account_id=<id> [--landing_page_id=<id>] --json
$P202 tracker      get-url <tracker_id>
```

`tracker create-with-url` does the create + URL fetch in one call if you prefer.

## 4. Confirm

```bash
$P202 dashboard
```

Present the tracking URL (and landing-page code, if any) and tell the user to put
the tracking URL in their traffic source. Keep a short summary of every ID you
created.

## Notes
- Always pass `--json` on create/read so you can parse IDs reliably; never guess an
  ID — read it back from the response.
- If a flag name is uncertain, run `$P202 <resource> create --help` to confirm
  before calling it.
- If `config test` works but a create fails with an auth error, the key likely
  lacks scope — have the user generate a fresh key under Account → REST API Keys.
