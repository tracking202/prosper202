# Clicks API

Read-only access to click tracking data.

## Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/clicks` | List clicks with filtering (paginated) |
| `GET` | `/clicks/{id}` | Get full details of a single click |

## Query Parameters (List)

| Parameter | Type | Default | Description |
| --------- | ---- | ------- | ----------- |
| `limit` | integer | 50 | Results per page (1-500) |
| `offset` | integer | 0 | Pagination offset |
| `time_from` | integer | — | Unix timestamp start filter |
| `time_to` | integer | — | Unix timestamp end filter |
| `aff_campaign_id` | integer | — | Filter by campaign |
| `ppc_account_id` | integer | — | Filter by PPC account |
| `landing_page_id` | integer | — | Filter by landing page |
| `click_lead` | integer | — | 0 = clicks only, 1 = conversions only |
| `click_bot` | integer | — | 0 = human traffic, 1 = bot traffic |

## List Response Fields

`click_id`, `aff_campaign_id`, `ppc_account_id`, `landing_page_id`, `click_cpc`, `click_payout`, `click_lead`, `click_filtered`, `click_bot`, `click_alp`, `click_time`, `rotator_id`, `rule_id`, `click_id_public`, `click_cloaking`, `click_in`, `click_out`, `keyword_id`, `country_id`, `platform_id`, `browser_id`, `device_id`, plus resolved country, platform, and browser names.

## Detail Response (Additional Fields)

The single-click endpoint adds: `text_ad_id`, `region_id`, `city_id`, `ip_id`, `isp_id`, tracking parameters (`c1`–`c4`), and resolved region/city/ISP names.

## Example

```bash
curl "https://your-domain.com/api/v3/clicks?limit=10&click_lead=1&time_from=1709856000" \
  -H "Authorization: Bearer YOUR_API_KEY"
```
