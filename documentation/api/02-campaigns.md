# Campaigns API

Manage campaigns.

## Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/campaigns` | List campaigns (paginated) |
| `GET` | `/campaigns/{id}` | Get a single campaign |
| `POST` | `/campaigns` | Create a campaign |
| `PUT` | `/campaigns/{id}` | Update a campaign |
| `DELETE` | `/campaigns/{id}` | Delete a campaign |
| `POST` | `/campaigns/bulk-upsert` | Bulk create/update campaigns |

## Fields

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `aff_campaign_name` | string | Yes | Campaign name (max 255) |
| `aff_campaign_url` | string | Yes | Primary destination URL (max 2048) |
| `aff_campaign_url_2` | string | No | Alternate URL 2 (max 2048) |
| `aff_campaign_url_3` | string | No | Alternate URL 3 (max 2048) |
| `aff_campaign_url_4` | string | No | Alternate URL 4 (max 2048) |
| `aff_campaign_url_5` | string | No | Alternate URL 5 (max 2048) |
| `aff_campaign_payout` | decimal | Yes | Default payout amount |
| `aff_campaign_currency` | string | No | Currency code (max 5) |
| `aff_campaign_foreign_payout` | decimal | No | Foreign currency payout (default 0) |
| `aff_network_id` | integer | Yes | Associated network ID |
| `aff_campaign_cloaking` | integer | No | Cloaking enabled (0/1) |
| `aff_campaign_rotate` | integer | No | Rotation enabled (0/1) |
| `attribution_model_id` | integer | No | The attribution model its conversions are credited with, overriding the account default: one of your models (`GET /attribution/models`); `null` or `0` returns it to the default. Anything else, including another account's model, is a `422` |

Auto-generated on create: `aff_campaign_time` (unix timestamp) and `aff_campaign_id_public`,
the id advanced landing-page code and `go.php?acip=` carry: a random digit, the
campaign id, a random digit, as the setup page makes it, so no two campaigns
share one. It is returned with the campaign and can be filtered on. A campaign
that has none (`NULL`, or `0`, which is no id — a setup-page save whose second
write failed, or a row from before the column was filled) is given one the
next time the API reads the account's campaigns (`GET /campaigns`,
`GET /campaigns/{id}`), as the landing-page code endpoint gives one to a
campaign it names; a campaign that has an id keeps it.

**Changing `aff_campaign_url`.** Links redirect to the new URL within three
minutes (with memcached running, the redirect caches a link's row that long).
While MySQL is down, direct links (`dl.php`), landing-page links (`lp.php`)
and offer links (`off.php`) fall back to a URL kept in memcached; the first
click that sees the new URL rewrites it, so an outage sends visitors to the
current primary URL, not the one the link had at its first click, and a
cleared URL disables the fallback (an outage then answers with an error
page). A link that gets no click between the change and an outage still
falls back to its old URL.

## Example: Create Campaign

```bash
curl -X POST https://your-domain.com/api/v3/campaigns \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "aff_campaign_name": "Summer Promo",
    "aff_campaign_url": "https://example.com/go?sid=[[subid]]",
    "aff_campaign_payout": 2.50,
    "aff_network_id": 3
  }'
```

## Bulk Upsert

`POST /campaigns/bulk-upsert` accepts an array of campaign objects. Requires an `Idempotency-Key` header.

The key identifies one batch. Retrying it with the same rows replays the
recorded response instead of re-applying them; reusing a key for anything
else — different rows, or a different endpoint — is refused with `422`, so
mint a new key for a new batch.

Rows without an `aff_campaign_id` (or `id`) are created and must include all
required fields. Rows with an existing ID are updated and may send only the
fields being changed.

```bash
curl -X POST https://your-domain.com/api/v3/campaigns/bulk-upsert \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Idempotency-Key: unique-request-id" \
  -H "Content-Type: application/json" \
  -d '[
    { "aff_campaign_name": "Campaign A", "aff_campaign_url": "https://...", "aff_campaign_payout": 1.50, "aff_network_id": 3 },
    { "aff_campaign_name": "Campaign B", "aff_campaign_url": "https://...", "aff_campaign_payout": 2.00, "aff_network_id": 3 }
  ]'
```
