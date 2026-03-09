# Campaigns API

Manage affiliate campaigns.

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
| `aff_campaign_url` | string | Yes | Primary affiliate URL (max 2048) |
| `aff_campaign_url_2` | string | No | Alternate URL 2 (max 2048) |
| `aff_campaign_url_3` | string | No | Alternate URL 3 (max 2048) |
| `aff_campaign_url_4` | string | No | Alternate URL 4 (max 2048) |
| `aff_campaign_url_5` | string | No | Alternate URL 5 (max 2048) |
| `aff_campaign_payout` | decimal | No | Default payout amount |
| `aff_campaign_currency` | string | No | Currency code (max 5) |
| `aff_campaign_foreign_payout` | decimal | No | Foreign currency payout |
| `aff_network_id` | integer | No | Associated affiliate network ID |
| `aff_campaign_cloaking` | integer | No | Cloaking enabled (0/1) |
| `aff_campaign_rotate` | integer | No | Rotation enabled (0/1) |

Auto-generated on create: `aff_campaign_time` (unix timestamp), `aff_campaign_id_public` (random public ID).

## Example: Create Campaign

```bash
curl -X POST https://your-domain.com/api/v3/campaigns \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "aff_campaign_name": "Summer Promo",
    "aff_campaign_url": "https://offer.example.com/go?sid=[[subid]]",
    "aff_campaign_payout": 2.50,
    "aff_network_id": 3
  }'
```

## Bulk Upsert

`POST /campaigns/bulk-upsert` accepts an array of campaign objects. Requires an `Idempotency-Key` header.

```bash
curl -X POST https://your-domain.com/api/v3/campaigns/bulk-upsert \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Idempotency-Key: unique-request-id" \
  -H "Content-Type: application/json" \
  -d '[
    { "aff_campaign_name": "Campaign A", "aff_campaign_url": "https://..." },
    { "aff_campaign_name": "Campaign B", "aff_campaign_url": "https://..." }
  ]'
```
