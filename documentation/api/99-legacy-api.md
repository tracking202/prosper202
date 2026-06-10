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

The v1 API supports a single `reports` method.

### Reports

| Parameter | Required | Description |
| --------- | -------- | ----------- |
| `type` | Yes | Report type (see below) |
| `apikey` | Yes | API key for authentication (sent in the URL — see the security warning above) |
| `date_from` | No | Start date (mm/dd/yyyy, defaults to start of today) |
| `date_to` | No | End date (mm/dd/yyyy, defaults to end of today) |
| `cid` | No | Campaign ID filter |
| `c1`, `c2`, `c3`, `c4` | No | Custom variable filters |

### Report Types

- `keywords` — Keyword report
- `ips` — IP address report
- `text_ads` — Text ad report
- `referers` — Referrer report
- `countries` — Country report
- `cities` — City report
- `carriers` — Carrier and ISP report
- `landing_pages` — Landing page report

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
