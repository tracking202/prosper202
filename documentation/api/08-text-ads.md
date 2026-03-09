# Text Ads API

Manage text ad creatives for tracking copy performance.

## Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/text-ads` | List text ads (paginated) |
| `GET` | `/text-ads/{id}` | Get a single text ad |
| `POST` | `/text-ads` | Create a text ad |
| `PUT` | `/text-ads/{id}` | Update a text ad |
| `DELETE` | `/text-ads/{id}` | Delete a text ad |

## Fields

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `text_ad_name` | string | Yes | Ad name (max 255) |
| `text_ad_headline` | string | No | Ad headline (max 500) |
| `text_ad_description` | string | No | Ad description (max 2000) |
| `text_ad_display_url` | string | No | Display URL (max 2048) |
| `aff_campaign_id` | integer | No | Associated campaign |
| `landing_page_id` | integer | No | Associated landing page |
| `text_ad_type` | integer | No | Ad type identifier |

## Example

```bash
curl -X POST https://your-domain.com/api/v3/text-ads \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "text_ad_name": "Summer Sale Ad v2",
    "text_ad_headline": "50% Off This Weekend",
    "text_ad_description": "Limited time offer on all products."
  }'
```
