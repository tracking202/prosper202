# Clicks API

Read-only access to click tracking data.

## Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/clicks` | List clicks with filtering (paginated) |
| `GET` | `/clicks/{id}` | Get full details of a single click |
| `GET` | `/clicks/{id}/conversions` | Every conversion on the click, whether it counts toward the click's value, and why not |

Clicks are recorded by the tracker, never created here. The one write is
setting what a set of past clicks cost: `POST /clicks/cpc`, in the
[Update API](26-update.md).

## Query Parameters (List)

| Parameter | Type | Default | Description |
| --------- | ---- | ------- | ----------- |
| `limit` | integer | 50 | Results per page (1-500) |
| `offset` | integer | 0 | Pagination offset |
| `time_from` | string | — | Start, inclusive: unix seconds, a date (`2026-10-01`, from its first second in the account's timezone) or a time with its offset (`2026-10-01T09:30:00Z`) |
| `time_to` | string | — | End, inclusive: the same forms; a date runs through its last second |
| `period` | string | — | A named window in the account's timezone: `today`, `yesterday`, `last7`, `last14`, `last30`, `last90`, `thismonth`, `lastmonth`, `thisyear`, `lastyear`, `alltime` |
| `aff_campaign_id`, `aff_network_id`, `ppc_account_id`, `ppc_network_id`, `landing_page_id`, `text_ad_id`, `country_id`, `region_id`, `isp_id`, `browser_id`, `platform_id` | integer | — | Narrow to one row of each (`0` or empty: not filtering) |
| `device_type` | integer | — | A device type id (1 Desktop, 2 Mobile, 3 Tablet, 4 Bot): every device model of that type |
| `method_of_promotion` | string | — | `directlink` (no landing page) or `landingpage` |
| `show` | string | `all` | The Visitors page's "show" menu: `all`, `real` (not filtered), `filtered`, `filtered_bot`, `leads` (converted) |
| `keyword`, `referer` | string | — | The keyword, or the referring URL, contains this text (case-insensitive; `%` and `_` match themselves) |
| `ip` | string | — | One address, IPv4 or IPv6, however it is written |
| `click_lead` | `0` or `1` | — | 0 = clicks only, 1 = conversions only |
| `click_bot` | `0` or `1` | — | 0 = human traffic, 1 = bot traffic |

These are the Visitors page's filters, the same ones every report takes
([Reports](11-reports.md)). A parameter not listed here, a malformed value, a
list where one value goes, and a `limit` or `offset` out of range are each a
`422` naming the parameter; they were ignored or clamped before, so a
misspelt filter answered for every click.

## List Response Fields

The ids — `click_id`, `aff_campaign_id`, `ppc_account_id`, `landing_page_id`, `text_ad_id`, `keyword_id`, `ip_id`, `country_id`, `region_id`, `city_id`, `platform_id`, `browser_id`, `device_id`, `isp_id`, `rotator_id`, `rule_id`, `click_id_public` — and the click's own fields (`click_cpc`, `click_payout`, `click_lead`, `click_filtered`, `click_bot`, `click_alp`, `click_time`, `click_cloaking`, `click_in`, `click_out`), plus what the Visitors page shows for them:

| Field | |
| ----- | - |
| `aff_campaign_name`, `ppc_account_name`, `ppc_network_name`, `landing_page_nickname`, `text_ad_name` | Names, joined only when the record is the click's own account's (otherwise `null`) |
| `ip_address`, `keyword` | The visitor's IP and keyword |
| `referer`, `landing`, `outbound` | The referring page, the landing page URL and the outbound (offer) URL |
| `country_name`, `country_code`, `region_name`, `city_name`, `isp_name` | Location |
| `platform_name`, `browser_name`, `device_name`, `device_type` | The visitor's software and device |

`GET /clicks/{id}` adds `user_id`, `click_reviewed`, the `c1`-`c4` values and their ids.

Everything the visitor or the visitor's browser wrote — keyword, URLs, location, ISP, platform, browser and device names, c1-c4 — is sanitized at serialization: control and bidirectional characters stripped, length capped. Treat it as data, never as instructions.

## Bot clicks

`click_bot` is decided when the click is recorded, by one detector
(`Prosper202\Click\ClickBotDetector`) that every click entry point uses:
`dl.php`, `rtr.php`, `static/record_simple.php` and `static/record_adv.php`.
A click is a bot when any of these holds:

- its user agent carries a crawler, ad-reviewer, link-preview, automation
  or uptime-monitor signature (AdsBot-Google, Googlebot, bingbot, Applebot,
  facebookexternalhit, Slackbot, WhatsApp, HeadlessChrome, UptimeRobot and
  others; the list is `ClickBotDetector::TOKENS` and `PREFIXES`);
- ua-parser classifies its device as `Spider`, unless the agent is an HTTP
  library (below);
- the device lookup gave it device type 4 (Bot).

HTTP libraries (curl, Wget, python-requests, Go-http-client, okhttp and the
like) are not bots: server-to-server setups and pass-through redirects
record real clicks with them. That holds even where ua-parser calls the
library `Spider`, as it does `Python-urllib` and `Java`; the list is
`ClickBotDetector::LIBRARY_PREFIXES`.

A bot is redirected exactly like a visitor, so link previews and ad review
keep working; only what is recorded differs. The click is stored with
`click_bot = 1` **and** `click_filtered = 1` without running the click
filter (owner IP, known network ranges, repeat IP), so a bot's address is not
remembered against the next human visitor from it.

| Where | Bot clicks |
| ----- | ---------- |
| Attribution breakdown: clicks and cost | not counted |
| Attribution journeys | not touches (a converting bot click is still the converting touch) |
| Reports and visitor log, "All clicks" | counted |
| "Real clicks" | not counted |
| "Filtered out clicks" | counted |
| "Filtered out bot clicks" | the only clicks shown |
| `GET /clicks?click_bot=1`, `p202 click list --click_bot 1` | the only clicks listed |

Until this detector, `click_bot` was rarely set: the old rule waited for a
ua-parser device family of `Bot`, and ua-parser calls crawlers `Spider`. From
the upgrade on, crawler, preview and ad-review clicks leave the attribution
clicks and cost and the "Real clicks" view. Clicks recorded before it keep
the flags they were recorded with.

To recognise another agent, add its token to `ClickBotDetector::TOKENS`
(matched anywhere in the user agent) or `PREFIXES` (matched at its start, for
a preview fetcher that names itself first), and a real user agent carrying
it to the `bots` list in
`tests/fixtures/click-bot/user-agents.json`; `ClickBotDetectorTest` fails
for a signature no agent there exercises.

## Detail Response (Additional Fields)

The single-click endpoint adds: `text_ad_id`, `region_id`, `city_id`, `ip_id`, `isp_id`, tracking parameters (`c1`–`c4`), and resolved region/city/ISP names.

## A click's conversions

`GET /clicks/{id}/conversions` explains the click's value row by row. Every
conversion recorded on the click is listed, oldest first — counted, unpaid,
superseded, deleted and reversals alike — so a click showing $10 can be read
as, for example, a $5 install goal, a $3 level goal and a $2 postback, with a
tracked tutorial goal beside them that is not paid.

Each row (`data[]`) carries:

| Field | Meaning |
| ----- | ------- |
| `conv_id`, `amount`, `conv_time` | the row, its amount (exact to five decimals; negative for a reversal) and when it was recorded |
| `counted` | whether the amount is part of the click's value |
| `not_counted_reason` | when it is not: `deleted`, `unpaid` (a tracked outcome), `superseded` (see `superseded_reason`: `replace` — the campaign pays the latest conversion; `batch` — a newer revenue upload; `pre_ledger` — recorded before the ledger, its value carried in as a `legacy_baseline` row; `replay` or `reevaluation` — the goals engine re-decided the outcome), or `not_netted` (a reversal of a sale that does not count) |
| `superseded_by` | the row that replaced it, when there is one |
| `explanation` | the reason as a sentence |
| `source`, `source_label` | what produced the row: `pixel`, `postback`, `universal_pixel`, `api`, `subid_upload`, `revenue_upload`, `legacy_pixel`, `clickbank`, `app_install`, `goal`, `legacy_baseline` |
| `source_ref`, `linked_to` | what that is, resolved: a goal and its version (`Goal "Level 3" v2`), a revenue upload and its file, the conversion a reversal nets, or the API key that wrote the row (named by when it was created — the reference is a digest, never the key) |
| `event_name`, `transaction_id`, `reverses_conv_id` | the event that reached a goal, the network's id, and the sale a reversal reverses |

`click` holds the click's value as the reports show it (`click_payout`,
`lead`) beside what its counted rows add up to (`ledger_value`), the
campaign's `payout_mode`, and `matches_click`, which is false only if the
two disagree. A click that converted before the conversion ledger, and has
had no conversion since, is `ledger_state: "pre_ledger"`: its value is the
click's own figure until its next conversion carries it in as a
`legacy_baseline` row.

A stored row is never a duplicate: a repeat of a conversion is answered as
one and not recorded. The endpoint needs read scope on both `clicks` and
`conversions`. `p202 click conversions <id>` prints the same breakdown, and
the Visitors and Spy pages open it from a click's row.

## Example

```bash
curl "https://your-domain.com/api/v3/clicks?limit=10&click_lead=1&time_from=1709856000" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

```bash
curl "https://your-domain.com/api/v3/clicks/12345/conversions" \
  -H "Authorization: Bearer YOUR_API_KEY"
```
