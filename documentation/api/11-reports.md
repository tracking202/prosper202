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

## Common Query Parameters

All report endpoints accept these filters:

| Parameter | Type | Description |
| --------- | ---- | ----------- |
| `time_from` | integer | Unix timestamp start |
| `time_to` | integer | Unix timestamp end |
| `period` | string | Shortcut: `today`, `yesterday`, `last7`, `last30`, `last90` |
| `aff_campaign_id` | integer | Filter by campaign |
| `aff_network_id` | integer | Filter by affiliate network |
| `ppc_account_id` | integer | Filter by PPC account |
| `ppc_network_id` | integer | Filter by PPC network |
| `landing_page_id` | integer | Filter by landing page |
| `country_id` | integer | Filter by country |

Use either `period` or `time_from`/`time_to`, not both.

## Breakdown Parameters

| Parameter | Type | Default | Description |
| --------- | ---- | ------- | ----------- |
| `breakdown` | string | `campaign` | Dimension to group by (see below) |
| `sort` | string | `total_clicks` | Metric to sort by |
| `sort_dir` | string | `DESC` | Sort direction (`ASC` or `DESC`) |
| `limit` | integer | 50 | Results per page (1-500) |
| `offset` | integer | 0 | Pagination offset |

**Breakdown dimensions:** `campaign`, `aff_network`, `ppc_account`, `ppc_network`, `landing_page`, `keyword`, `country`, `city`, `region`, `browser`, `platform`, `device`, `isp`, `text_ad`.

## Timeseries Parameters

| Parameter | Type | Default | Description |
| --------- | ---- | ------- | ----------- |
| `interval` | string | `day` | Grouping interval: `hour`, `day`, `week`, `month` |

## Daypart / Weekpart Parameters

| Parameter | Type | Default | Description |
| --------- | ---- | ------- | ----------- |
| `sort` | string | `hour_of_day` / `day_of_week` | Metric to sort by |
| `sort_dir` | string | `ASC` | Sort direction |

**Sort options:** `hour_of_day` (or `day_of_week`), `total_clicks`, `total_click_throughs`, `total_leads`, `total_income`, `total_cost`, `total_net`, `epc`, `avg_cpc`, `conv_rate`, `roi`, `cpa`.

## Metrics Returned

All report endpoints return these aggregate metrics:

| Metric | Type | Description |
| ------ | ---- | ----------- |
| `total_clicks` | integer | Total click count |
| `total_click_throughs` | integer | Clicks that reached the offer |
| `total_leads` | integer | Conversion count |
| `total_income` | float | Total revenue |
| `total_cost` | float | Total ad spend |
| `total_net` | float | Net profit (income - cost) |
| `epc` | float | Earnings per click |
| `avg_cpc` | float | Average cost per click |
| `conv_rate` | float | Conversion rate (%) |
| `roi` | float | Return on investment (%) |
| `cpa` | float | Cost per action |

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

### Hourly Timeseries

```bash
curl "https://your-domain.com/api/v3/reports/timeseries?interval=hour&period=today&aff_campaign_id=5" \
  -H "Authorization: Bearer YOUR_API_KEY"
```
