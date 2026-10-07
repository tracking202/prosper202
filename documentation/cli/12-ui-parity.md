# What the UI does, through the API and `p202`

Every page of the web UI, the REST API route that does the same thing, and
the `p202` command for it. Use it to find the command for something you know
how to do on a page, and to see what still needs the browser.

| Mark | Meaning |
| ---- | ------- |
| ✅ | Everything the page does |
| ◐ | Most of it; the note says what is missing |
| ✗ | Not available through the API or CLI |
| — | Not applicable: the page shows content or talks to a hosted Prosper202 service, not this install |

Permissions follow the pages: a key acts as its user, and a route asks for
the role permission its page asks for (see
[Role Permissions](../api/00-api-integrations.md#role-permissions)). A Setup
record links only to the caller's own records
([Linked Records](../api/00-api-integrations.md#linked-records)).

## Setup

| Page | API | CLI | |
| ---- | --- | --- | - |
| Traffic Sources: sources and accounts | `/ppc-networks`, `/ppc-accounts` | `p202 ppc-network …`, `p202 ppc-account …` | ✅ |
| Traffic Sources: an account's pixels, a source's custom variables | — | — | ✗ the variables are read into tracking links (`tracker get-url`) but cannot be edited |
| Categories | `/aff-networks` | `p202 aff-network …` | ◐ DNI networks (a hosted service) are not offered |
| Campaigns | `/campaigns` (+ `attribution_model_id`, `app_registration_id`) | `p202 campaign …`, `campaign clone`, `campaign check-urls`, `campaign replace-url` | ✅ |
| Landing Pages | `/landing-pages` | `p202 landing-page …` | ✅ |
| Text Ads | `/text-ads` | `p202 text-ad …` | ✅ |
| Redirector: redirectors, rules, criteria | `/rotators`, `/rotators/{id}/rules` | `p202 rotator …`, `rotator rule-create/update/delete`, `rotator criteria-values`, `rotator test`, `rotator trace` | ◐ criteria values that come from a hosted list for some types |
| Mobile Apps (incl. goals, SKAN encodings, integrity) | `/apps/…`, `/goals/…` | `p202 app …`, `p202 goal …` | ✅ |
| Get LP Code | — | — | ✗ |
| Get Links | `POST /trackers`, `GET /trackers/{id}/url` (`t202kw`, `c1`–`c4`, `utm_*`, the source's custom variables) | `p202 tracker create`, `tracker create-with-url`, `tracker get-url`, `tracker bulk-urls` | ✅ |
| Postback/Pixel | — | `p202 conversion postback-url` | ◐ the postback URL only; not the pixel snippets |

## Overview

| Page | API | CLI | |
| ---- | --- | --- | - |
| Campaign Overview | `/reports/summary`, `/reports/breakdown?breakdown=campaign` | `p202 report summary`, `report breakdown`, `p202 dashboard` | ✅ |
| Breakdown Analysis | `/reports/timeseries`, `/reports/breakdown` | `p202 report timeseries`, `report breakdown` | ✅ |
| Day Parting / Week Parting | `/reports/daypart`, `/reports/weekpart` | `p202 report daypart`, `report weekpart` | ✅ |
| Group Overview (up to four groupings, nested, with subtotals) | — | `p202 report crosstab` pivots one metric across two | ◐ no nested report with every metric |
| Rotator breakdown | — | — | ✗ |

## Analyze

| Page | API | CLI | |
| ---- | --- | --- | - |
| Keywords, Text Ads, Countries, Cities, Regions, ISP/Carrier, Landing Pages, Devices, Browsers, Platforms | `/reports/breakdown?breakdown=…` | `p202 report breakdown --breakdown …`, `p202 analytics --group-by …` | ✅ |
| Referers, IPs, Custom Variables (`c1`–`c4`, UTM) | — | — | ✗ |
| The pages' filters (text ad, region, ISP, browser, platform, device type, keyword, IP, referer, "show") | — | — | ◐ campaign, category, traffic source, account, landing page and country only |
| Customer LTV (customers, companies, subscriptions, products, fields, webhooks, integrations, merges) | `/ltv/…` | `p202 ltv …` | ✅ |
| Mobile Apps | `/apps/report`, `/apps/postbacks` | `p202 app report`, `app postbacks` | ✅ |
| CSV downloads | every list and report as JSON | `--csv` on any list or report, `--all` for every page | ✅ |

## Visitors and Spy

| Page | API | CLI | |
| ---- | --- | --- | - |
| Visitors: each click with its campaign, source, keyword, IP, location, device, referrer and landing URLs | `/clicks`, `/clicks/{id}`, `/clicks/{id}/conversions` | `p202 click list`, `click get`, `click conversions` | ◐ filters by campaign, account, landing page, lead and bot only |
| Spy: the newest clicks, then each new one | `/clicks` polled | `p202 click list --follow` | ✅ |

## Update

| Page | API | CLI | |
| ---- | --- | --- | - |
| Update Subids | `POST /conversions/subids` | `p202 conversion mark-subids` | ✅ |
| Update CPC | `POST /clicks/cpc` | `p202 click update-cpc` | ✅ |
| Reset Campaign Subids | `POST /conversions/subids/reset` | `p202 conversion reset-subids` | ✅ |
| Delete Subids | `POST /conversions/subids/delete` | `p202 conversion delete-subids` | ✅ |
| Upload Revenue Reports | `POST /conversions/uploads` | `p202 conversion upload-revenue` | ✅ |

Every Update write previews with `?dry_run=1` (`--dry-run`).

## Account

| Page | API | CLI | |
| ---- | --- | --- | - |
| Personal Settings: profile, password, time zone, currency, report and privacy preferences | `/users/{id}`, `/users/{id}/preferences` | `p202 user update`, `p202 user prefs get/update` | ✅ |
| Personal Settings: REST API keys | `/users/{id}/api-keys` | `p202 user apikey create/list/rotate/delete`, `p202 whoami` | ✅ |
| Personal Settings: the my.tracking202.com customer key, LPO pairing | — | — | — hosted service |
| Settings: versions, cron, DataEngine, memcache, database size | `/system/info`, `/system/cron`, `/system/dataengine`, `/system/db-stats`, `/system/metrics` | `p202 system info`, `system cron`, `system dataengine`, `system db-stats`, `system metrics` | ✅ |
| Settings: login log | `/system/login-log` | `p202 system login-log` | ✅ |
| Settings: click-data retention, delete before a date | `/system/retention`, `/system/retention/delete-before` | `p202 system retention show/set/delete-before` | ✅ |
| Settings: ISP lookup | `/system/isp-lookup` | `p202 system isp-lookup show/enable/disable` | ✅ |
| Settings: AutoCron, "update available" | — | — | — hosted service |
| 3rd Party API Integrations: secrets (IPQS, ClickBank, JVZoo, Zaxaa, Slack) | `/users/{id}/preferences` | `p202 user prefs update` | ✅ |
| 3rd Party API Integrations: the URLs to paste into each network | `/system/integrations` | `p202 system integrations` | ✅ |
| 3rd Party API Integrations: DNI networks | — | — | — hosted service |
| Attribution: models, reports, journeys, exports | `/attribution/…` | `p202 attribution …` | ✅ |
| User Management: users, roles, keys | `/users`, `/users/{id}/roles`, `/users/{id}/api-keys` | `p202 user …`, `user role …`, `user apikey …` | ✅ |
| 1-click upgrade | — | — | ✗ run the upgrade from the page; `p202 system info` says when one is needed |
| ClickServers, VIP Perks, App Store, Hot Deals, TV202, Help | — | — | — hosted services and content |

## Not on any page

What the CLI and API add that the UI has no page for: staged changes
(`--staged`, `p202 change …`), dry-run previews of every delete and Update
write, idempotent creates, `p202 sync`/`diff`/`export`/`import` across
servers, forecasts and forecast events, goals evaluation, the change feed,
`p202 search` and `p202 commands --json` for discovering commands, and
`p202 eval` for agent evaluation cases.
