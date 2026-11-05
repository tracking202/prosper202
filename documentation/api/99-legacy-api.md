# Legacy API (v1)

> **Deprecated**: The v1 API is maintained for backward compatibility only. New integrations should use [API v3](00-api-integrations.md).

## Endpoint

```
https://[[your-Prosper202-domain]]/api/v1/
```

## Authentication

Pass the API key as a query parameter:

```
?apikey=YOUR_API_KEY
```

> ⚠️ **Security:** Passing the API key in the URL query string exposes it in web-server access logs, browser history, and `Referer` headers. Anyone with access to those can impersonate you against the reporting API. For new integrations, use [API v3](00-api-integrations.md) with `Authorization: Bearer <api_key>` instead. If you must use the legacy v1 query-parameter key, treat it as highly sensitive: restrict access to logs, rotate the key immediately if it may have been exposed, and prefer server-to-server calls over embedding the key in browser-visible URLs.

## Methods

The v1 API's method is `reports`; the setup reads below are kept for clients of paid 1.9.56.

### Reports

| Parameter | Required | Description |
| --------- | -------- | ----------- |
| `type` | Yes | Report type (see below) |
| `apikey` | Yes | API key for authentication (sent in the URL — see the security warning above) |
| `date_from` | No | Start date (mm/dd/yyyy, defaults to start of today) |
| `date_to` | No | End date (mm/dd/yyyy, defaults to end of today) |
| `cid` | No | Campaign ID filter. Absent, empty or `0` is every campaign; anything that is not one of the account's campaign ids, written as the id (`012` is not), answers `Campaign not found` |
| `c1`, `c2`, `c3`, `c4` | No | Custom variable filters. A value of `0` is a value; only an empty one is no filter. A list (`c1[]=x`) answers `c1 takes one value` |

### Report Types

- `keywords` — Keyword report
- `ips` — IP address report
- `text_ads` — Text ad report
- `referers` — Referrer report
- `countries` — Country report
- `cities` — City report
- `carriers` — Carrier and ISP report
- `landing_pages` — Landing page report

A click a redirector link re-clicked counts once, at its latest visit, as in
every report ([Reports](11-reports.md#a-click-a-redirector-link-re-clicked));
it counted at each visit before 1.9.79.

### Setup reads (from paid 1.9.56)

Paid 1.9.56 also served read-only setup lists. They answer again for the
clients written against them, read through API v3:

| Endpoint | Answers under | v3 equivalent |
| -------- | ------------- | ------------- |
| `/api/v1/ads/` | `ads` | `GET /text-ads` |
| `/api/v1/landingPages/` | `landing_pages` (each with `bot202_fb_pixel_assistant`, always empty) | `GET /landing-pages` |
| `/api/v1/trafficSources/` | `traffic_sources`, each with `ppc_accounts`, each with `traffic_source_account_pixels` | `GET /ppc-networks`, `/ppc-accounts`, `/ppc-accounts/{id}/pixels` |
| `/api/v1/redirectors/` | `smart_redirectors`, each with `redirector_rules`, each with `redirector_criteria` and `redirector_redirects` | `GET /rotators/{id}` |
| `/api/v1/trackers/` | `trackers` | `GET /trackers` |
| `/api/v1/campaigns/` | `campaigns` | `GET /campaigns` |
| `/api/v1/categories/` | `categories` | `GET /aff-networks` |
| `/api/v1/version/` | `version`: the installed version | — |

Each takes `?apikey=` and `GET` only. The key is checked as v3 checks it: the
user must be active, a scoped key needs the area's read scope (`read`, or
for example `campaigns:read`; traffic sources need `ppc-networks:read` and
`ppc-accounts:read`), and the role needs Setup access. `version/` takes any
live key. Only the key's own account's records are listed, every value is a
string as before (an empty value is `""`), and an account with none is
answered `null`. Records do not carry their `*_time` column. A refusal is
the old error object under the same key, `{"campaigns": {"msg": …,
"error": true, "status": 401}}`, with that HTTP status.

### Example

```
https://prosper202.com/api/v1/reports/?type=countries&apikey=YOUR_KEY&date_from=03/24/2024&date_to=04/27/2024&cid=1
```

## Migration to v3

The v3 API provides all v1 report functionality through the `/reports/breakdown` endpoint with additional dimensions, sorting, pagination, and filtering. See [Reports API](11-reports.md).

| v1 Report Type | v3 Equivalent |
| -------------- | ------------- |
| `keywords` | `GET /reports/breakdown?breakdown=keyword` |
| `ips` | `GET /clicks` (filter and aggregate) |
| `text_ads` | `GET /reports/breakdown?breakdown=text_ad` |
| `referers` | `GET /clicks` (referrer data in click details) |
| `countries` | `GET /reports/breakdown?breakdown=country` |
| `cities` | `GET /reports/breakdown?breakdown=city` |
| `carriers` | `GET /reports/breakdown?breakdown=isp` |
| `landing_pages` | `GET /reports/breakdown?breakdown=landing_page` |
