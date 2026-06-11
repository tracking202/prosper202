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
| `text_ad_name` | string | Yes | Ad name (max 100) |
| `text_ad_headline` | string | Yes | Ad headline (max 100) |
| `text_ad_description` | string | Yes | Ad description (max 100) |
| `text_ad_display_url` | string | Yes | Display URL (max 100) |
| `aff_campaign_id` | integer | No | Associated campaign (default 0) |
| `landing_page_id` | integer | No | Associated landing page (default 0) |
| `text_ad_type` | integer | No | Ad type identifier (default 0) |

Auto-generated on create: `text_ad_time` (unix timestamp).

## Example

```bash
curl -X POST https://your-domain.com/api/v3/text-ads \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "text_ad_name": "Summer Sale Ad v2",
    "text_ad_headline": "50% Off This Weekend",
    "text_ad_description": "Limited time offer on all products.",
    "text_ad_display_url": "example.com/summer-sale"
  }'
```
