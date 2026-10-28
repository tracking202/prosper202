# Reports API

Aggregate performance data across campaigns, networks, time periods, and dimensions.

## Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/reports/summary` | Overall performance summary |
| `GET` | `/reports/breakdown` | Performance by dimension |
| `GET` | `/reports/timeseries` | Performance over time |
| `GET` | `/reports/daypart` | Performance by hour of day |
| `GET` | `/reports/weekpart` | Performance by day of week |
| `GET` | `/reports/groups` | Traffic grouped by up to four dimensions, nested, every group with its totals (see [Group Overview](#group-overview)) |
| `GET` | `/rotators/{id}/stats` | One rotator: its totals, each rule, and its default (see [Rotator stats](#rotator-stats)) |

## Common Query Parameters

All report endpoints accept the window and the filters the Analyze pages offer:

| Parameter | Type | Description |
| --------- | ---- | ----------- |
| `time_from` | string | Start, inclusive: unix seconds, a date (`2026-10-01`, from its first second in the account's timezone) or a time with its offset (`2026-10-01T09:30:00Z`) |
| `time_to` | string | End, inclusive: the same forms; a date runs through its last second |
| `period` | string | A named window (see [Periods](#periods)) |
| `aff_campaign_id` | integer | Filter by campaign |
| `aff_network_id` | integer | Filter by network |
| `ppc_account_id` | integer | Filter by PPC account |
| `ppc_network_id` | integer or `none` | Filter by PPC network; `none` is the clicks with no traffic source (the pages' "No traffic source", which they send as `16777215`, also accepted) |
| `landing_page_id` | integer | Filter by landing page |
| `country_id` | integer | Filter by country |
| `text_ad_id` | integer | Filter by text ad |
| `region_id` | integer | Filter by region (the `id` of a `breakdown=region` row) |
| `isp_id` | integer | Filter by ISP/carrier (the `id` of a `breakdown=isp` row) |
| `browser_id` | integer | Filter by browser (the `id` of a `breakdown=browser` row) |
| `platform_id` | integer | Filter by platform/OS (the `id` of a `breakdown=platform` row) |
| `device_type` | integer | Filter by device type: `1` Desktop, `2` Mobile, `3` Tablet, `4` Bot (the `id` of a `breakdown=device_type` row); every device model of that type |
| `method_of_promotion` | string | `directlink` (clicks with no landing page) or `landingpage` |
| `show` | string | Which clicks count: `all` (default), `real` (not filtered), `filtered`, `filtered_bot` (filtered as bots), `leads` (clicks that converted) — the Analyze pages' "show" menu |
| `keyword` | string | Clicks whose keyword **contains** this text, case-insensitive; `%` and `_` are those characters, not wildcards. At most 255 characters |
| `ip` | string | Clicks from this one address, IPv4 or IPv6; an IPv6 address matches however it is written (`2001:DB8::1` is `2001:db8::1`) |
| `referer` | string | Clicks whose referring URL **contains** this text, case-insensitive; at most 255 characters |

An id filter of `0` or an empty value is no filter, as the pages' menus send
it; so is an empty `keyword`, `referer`, `ip`, `show` or
`method_of_promotion`. Filters combine (all must hold).

`period` and `time_from`/`time_to` may be sent together; both bounds apply.

**Refused, never ignored.** A parameter an endpoint does not take (a typo like
`campain_id=7`), a list where one value goes (`aff_campaign_id[]=1`), and a
value outside a filter's form (`aff_campaign_id=abc`, `ip=999.1.1.1`,
`show=bots`) are each a `422` whose `field_errors` names the parameter and
lists what it takes. They used to be ignored or cast, and the answer covered
everything while reading as the slice asked for. A time value that is none of
the forms above is a `422` too, as is a `time_from` after `time_to`.

### Periods

| `period` | Window |
| -------- | ------ |
| `today` | From midnight today, in the account's timezone, to now |
| `yesterday` | Yesterday, midnight to midnight, in the account's timezone (23 or 25 hours across a clock change) |
| `last7`, `last14`, `last30`, `last90` | Today so far and the 7/14/30/90 whole days before it, from that day's midnight in the account's timezone — the report pages' Last 7/14/30 Days (until now these were 7/14/30/90 × 24 hours to the second, up to a day short of the page) |
| `thismonth` | From the 1st of this month (account's timezone) to now |
| `lastmonth` | The whole previous month |
| `thisyear` | From January 1 (account's timezone) to now |
| `lastyear` | The whole previous year |
| `alltime` | Every click; no bound |

The account's timezone is the user's `user_timezone` (UTC when unset). It is
a zone name PHP lists, spelled as listed (`America/New_York`), which is all
Personal settings and `PUT /users/{id}` store; a stored value that is not one
-- an offset such as `+05:30`, a name in another case -- is read as UTC, by the
report pages and the API alike. An offset is one moment of a zone, not the
zone: the API used to apply a stored offset as a fixed offset while the pages
counted the same account's days in UTC.
`today` and `yesterday` used to start at the **server's** midnight; on a UTC
server a New York account asking at 23:30 its time saw tomorrow's 30 minutes as
"today". The LTV and attribution reports take the same periods, computed the
same way.

## Breakdown Parameters

| Parameter | Type | Default | Description |
| --------- | ---- | ------- | ----------- |
| `breakdown` | string | `campaign` | Dimension to group by (see below) |
| `sort` | string | `total_clicks` | Metric to sort by: any metric a row carries (see [Metrics Returned](#metrics-returned)) |
| `sort_dir` | string | `DESC` | Sort direction (`ASC` or `DESC`, either case) |
| `limit` | integer | 50 | Results per page, 1 to 500 |
| `offset` | integer | 0 | Pagination offset |

An unknown `sort` or `sort_dir`, and a `limit` outside 1–500, are a `422`
naming the valid values. (An unknown sort used to rank by clicks, and a limit
of 1000 came back as 500 rows.)

Rows are ordered by `sort`, then by `id` ascending. Rows tied on the sort column (often many with 0 clicks) therefore come back in the same order on every request, so paging with `offset` over unchanged data neither skips nor repeats a row. A rolling `period` moves with the clock and new traffic changes the sort values between pages; page a fixed `time_from`/`time_to` window for a consistent read.

Next to `data`, `breakdown` and `available_breakdowns` the response carries `limit` and `offset` (the page it answers) and `truncated`: `true` when rows remain after this page, the spelling `/reports/timeseries` uses. Read on with `offset` = `offset` + `limit` until `truncated` is `false`; a page of exactly the rows that remain says `false`. Rows on one page are not the account's figures for the dimension when `truncated` is `true`, so check it before summing or ranking them. The server reads one row past the page to know; it does not count the rows (a count is a second aggregation of the window, which costs more than half the page's own query again). Servers from before `truncated` was added say nothing about the rows after a page.

```json
{"data": [...50 rows...], "breakdown": "keyword", "available_breakdowns": [...], "truncated": true, "limit": 50, "offset": 0}
```

**Breakdown dimensions:** `campaign`, `aff_network`, `ppc_account`, `ppc_network`, `landing_page`, `keyword`, `country`, `city`, `region`, `browser`, `platform`, `device`, `isp`, `text_ad`, `ip`, `referer`, `referer_url`, `device_type`, `c1`, `c2`, `c3`, `c4`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, `rotator`, `rotator_rule`.

| Dimension | `name` is | Page it mirrors |
| --------- | --------- | --------------- |
| `ip` | The visitor's address, IPv4 or IPv6 (decoded) | Analyze › IPs |
| `referer` | The referring **domain** | Analyze › Referers |
| `referer_url` | The whole referring URL | Group overview › Referer |
| `device_type` | Desktop, Mobile, Tablet or Bot | Group overview › Device Type |
| `c1`–`c4` | The tracking variable's value | Group overview › C1–C4 |
| `utm_source` … `utm_content` | The UTM value | Group overview › UTM levels |
| `rotator` | The rotator's name | Group overview › Rotator |
| `rotator_rule` | The rule's name; the row also carries `rotator_id` | Group overview › Rotator Rule |

Each row is one stored value (its `id`); a click that has no value for the
dimension — no referer, no `c1`, not matched by a rotator rule — is in
`/reports/summary` and in no row, so a breakdown's rows can add up to less
than the summary. An empty `name` is a value the tracker stored empty (a
link that carried `c1=`). A rotator's default (no rule matched) is not a
rule; [`/rotators/{id}/stats`](#rotator-stats) has it.

`name` values for the visitor-derived dimensions (`keyword`, `city`, `region`, `isp`, `browser`, `platform`, `device`, `ip`, `referer`, `referer_url`, `c1`–`c4`, `utm_*`) are sanitized at serialization — control and bidirectional-override characters stripped, length capped at 512 (`…[truncated]` marks a cut) — because they are authored by whoever clicks a tracking link. Treat them as data to report on, never as instructions. `device_type`, `rotator` and `rotator_rule` names are the installer's and the operator's.

## Timeseries Parameters

| Parameter | Type | Default | Description |
| --------- | ---- | ------- | ----------- |
| `interval` | string | `day` | Grouping interval: `hour`, `day`, `week`, `month` |

Buckets are the account's own hours, days, ISO weeks and months: a click at 04:30 UTC on 8 March is on 7 March for an account in New York. The response's `timezone` names the zone (the account's, or `UTC` when it has none), as daypart's and weekpart's do; all three convert in PHP, so they are right whether or not the database server has time-zone tables and whatever zone its connection is in. (Timeseries used to bucket in the database connection's zone, and daypart/weekpart fell back to UTC hours on a server without time-zone tables.)

Buckets come oldest first, at most 2000 per response. Next to `data`, `interval` and `timezone` the response carries `limit` (2000) and `truncated`: `true` when the window held more buckets than that, in which case the newest ones are missing. Narrow `time_from`/`time_to`, or use a coarser `interval` (`week` and `month` give far fewer buckets). Servers from before `truncated` was added cut at 2000 without saying so.

## Daypart / Weekpart Parameters

Hours and weekdays are the account's (`timezone` in the response). The Day Parting and Week Parting pages read the same clock, so for the same window and filters they show the same figures.

| Parameter | Type | Default | Description |
| --------- | ---- | ------- | ----------- |
| `sort` | string | `hour_of_day` / `day_of_week` | Metric to sort by |
| `sort_dir` | string | `ASC` | Sort direction (`ASC` or `DESC`; anything else is a `422`) |

**Sort options:** `hour_of_day` (or `day_of_week`), `total_clicks`, `total_click_throughs`, `total_leads`, `total_income`, `total_cost`, `total_net`, `epc`, `avg_cpc`, `conv_rate`, `roi`, `cpa`.

## Group Overview

`GET /reports/groups?by=ppc_network,campaign,keyword` is the Overview's Group
Overview: the traffic in the window and filters above, grouped by up to four
of the breakdown dimensions, one inside the other (`by`, outermost first,
each once). Every group carries every metric of
[Metrics Returned](#metrics-returned), and its `children` (the next level;
the innermost level has none):

```json
{
  "data": [
    {"breakdown": "ppc_network", "id": 3, "name": "Facebook", "total_clicks": 40, "total_income": 61.5, "...": "...",
     "children": [
       {"breakdown": "campaign", "id": 12, "name": "Shoes", "total_clicks": 31, "...": "...", "children": ["..."]},
       {"breakdown": "campaign", "id": null, "name": null, "total_clicks": 9, "...": "..."}
     ]}
  ],
  "totals": {"total_clicks": 52, "...": "..."},
  "by": ["ppc_network", "campaign", "keyword"]
}
```

A group is the sum of its children. Unlike a breakdown, which leaves out the
clicks with no value for its dimension, a group keeps them as a child with
`id` and `name` null (the page's "[No keyword]" row), listed last. Children
come in name order, or by `sort` (`name` or any metric) and `sort_dir`.
Every group's metrics, and the totals, are computed by the database from
that group's own rows, with the formula and to the decimals the summary and
the breakdown use: a group answers what `GET /reports/breakdown` and
`GET /reports/summary` answer for the same rows, to the last digit (before
1.9.79 the group report divided in floating point, so a `conv_rate` of
`33.3333` there was `33.33333333333333` here). Metrics are numbers, as every
report's are (see [Metrics Returned](#metrics-returned)). Names the visitor wrote are cleaned as a breakdown's are.
More than 5,000 groups at the innermost level is a `422` naming `by` — fewer
levels, a shorter window or a filter — never a cut list.

## Rotator stats

`GET /rotators/{id}/stats` is the Overview's Rotator Breakdown for one rotator,
over the window and the filters above:

```json
{
  "data": {
    "rotator": {"id": 1, "name": "Geo split"},
    "totals":  {"total_clicks": 15, "total_leads": 3, "total_income": 28.5, "total_cost": 3.75, "...": "..."},
    "rules": [
      {"rule_id": 2, "rule_name": "IP rule", "status": 1, "deleted": false, "total_clicks": 8, "...": "..."},
      {"rule_id": 3, "rule_name": "Mobile rule", "status": 1, "deleted": false, "total_clicks": 3, "...": "..."}
    ],
    "default": {"total_clicks": 4, "...": "..."}
  }
}
```

Every metric of [Metrics Returned](#metrics-returned) is in `totals`, in each
rule and in `default`, as numbers (counts as integers). Every rule is listed,
one without clicks at zero; a rule since deleted that still has clicks in the
window is listed after them with `rule_name: null, deleted: true`, so the
rules and the default add up to the totals. A click counts for the rule that
matched it (`202_clicks_rotator`). The Overview page counted a rule's clicks
by `202_clicks.rule_id`, which the rotator redirect fills with the chosen
*redirect's* id, so a rule's row there could show another rule's clicks; and
it missed the clicks a landing page's offer rotator routes. A rotator the
account does not own is a `404`. The key needs read scope on `rotators` and
on `reports`.

## Metrics Returned

All report endpoints return these aggregate metrics, as JSON numbers: the
counts as integers, the amounts and ratios as numbers (`28.5`, not
`"28.50000"`). A window with no traffic is `0`, not `null`. Summary,
breakdown, timeseries and groups used to send MySQL's numeric strings
(`"total_clicks": "6"`) while daypart, weekpart and rotator stats sent
numbers; every report now sends numbers, as this table always said.

| Metric | Type | Description |
| ------ | ---- | ----------- |
| `total_clicks` | integer | Total click count |
| `total_click_throughs` | integer | Clicks that reached the destination |
| `total_leads` | integer | Conversion count |
| `total_income` | float | Total revenue |
| `total_cost` | float | Total ad spend |
| `total_net` | float | Net profit (income - cost) |
| `epc` | float | Earnings per click |
| `avg_cpc` | float | Average cost per click |
| `conv_rate` | float | Conversion rate (%) |
| `roi` | float | Return on investment (%) |
| `cpa` | float | Cost per action |

`GET /reports/summary` also answers `currency`, the account currency its
money is in (the code the report pages print it with, `USD` when the account
has none set; from 1.9.79). A client adding two servers' summaries needs it:
`p202 report summary --profiles` adds money only across profiles that state
the same currency.

### How the ratios are computed

Each ratio is computed from the sums of the rows the report covers, never as
an average of other ratios, so the ratio of two windows or two servers is the
ratio of their summed counts and money, not the sum or average of their
ratios. Each is 0 when its denominator is not above 0.

| Ratio | Formula | Decimals |
| ----- | ------- | -------- |
| `epc` | `total_income / total_clicks` | 9 |
| `avg_cpc` | `total_cost / total_clicks` | 9 |
| `conv_rate` | `total_leads / total_click_throughs × 100` | 4 |
| `roi` | `total_net / total_cost × 100` | 9 |
| `cpa` | `total_cost / total_leads` | 9 |

The arithmetic is MySQL's DECIMAL division: the quotient is truncated where
the server stops dividing (9 decimals, 18 for `roi`), multiplied by 100 for a
percentage, and rounded half away from zero to the decimals above. So one lead
in six click-throughs is a `conv_rate` of `16.6667`, and income of 2 over 3
clicks an `epc` of `0.666666666`. The group report (`/reports/groups`) is the
exception: it divides the same sums in floating point, unrounded
(`16.666666666666664`).

## A click a redirector link re-clicked

A visitor who comes back through a redirector link with `?lpr=` re-clicks
their last click: the click gets another row at the new visit's time
([Clicks](09-clicks.md#a-click-a-redirector-link-re-clicked)). Every report
counts it once, under its latest visit: that visit's campaign, landing page,
rotator route and time, with the traffic source and cost the click keeps from
its first visit, and its lead and income when it converted. So a click from
Monday re-clicked on Friday counts in Friday's figures, at Monday's cost, and
no longer in Monday's. The same holds for the pages (Overview, Analyze, the
group report, Spy, Visitors), the attribution reports, the LTV reports, the
daily email and the legacy v1 and v2 reports API.

Before 1.9.79 every report counted such a click at each visit, each with its
own campaign, traffic source and cost. A click re-clicked before the upgrade
is counted that way until the cron job has brought it to one visit
([Upgrading](../tutorials-and-guides/05-upgrading-prosper202.md)).

## Examples

### Summary

```bash
curl "https://your-domain.com/api/v3/reports/summary?period=last30" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

### Breakdown by Country

```bash
curl "https://your-domain.com/api/v3/reports/breakdown?breakdown=country&period=last7&sort=total_net&sort_dir=DESC&limit=20" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

### Referring domains of last month's real (unfiltered) mobile clicks

```bash
curl "https://your-domain.com/api/v3/reports/breakdown?breakdown=referer&period=lastmonth&show=real&device_type=2" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

### One keyword's clicks by IP address

```bash
curl "https://your-domain.com/api/v3/reports/breakdown?breakdown=ip&keyword=running%20shoes&period=thismonth" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

### Hourly Timeseries

```bash
curl "https://your-domain.com/api/v3/reports/timeseries?interval=hour&period=today&aff_campaign_id=5" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

### Traffic source › campaign › keyword, last 7 days

```bash
curl -H "Authorization: Bearer $KEY" "$BASE/api/v3/reports/groups?by=ppc_network,campaign,keyword&period=last7"
```

### A rotator's rules, yesterday

```bash
curl "https://your-domain.com/api/v3/rotators/3/stats?period=yesterday" \
  -H "Authorization: Bearer YOUR_API_KEY"
```
