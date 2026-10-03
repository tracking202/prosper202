# Clicks API

Read-only access to click tracking data.

## Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/clicks` | List clicks with filtering (paginated) |
| `GET` | `/clicks/{id}` | Get full details of a single click |
| `GET` | `/clicks/{id}/conversions` | Every conversion on the click, whether it counts toward the click's value, and why not |

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

`click_id`, `aff_campaign_id`, `ppc_account_id`, `landing_page_id`, `click_cpc`, `click_payout`, `click_lead`, `click_filtered`, `click_bot`, `click_alp`, `click_time`, `rotator_id`, `rule_id`, `click_id_public`, `click_cloaking`, `click_in`, `click_out`, `keyword_id`, `country_id`, `platform_id`, `browser_id`, `device_id`, plus resolved country, platform, and browser names. Resolved name fields derive from the visitor (user agent, IP) and are sanitized at serialization: control/bidirectional characters stripped, length capped.

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
