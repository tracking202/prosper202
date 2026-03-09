# Landing Pages API

Manage landing pages used between the traffic source and the offer.

## Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/landing-pages` | List landing pages (paginated) |
| `GET` | `/landing-pages/{id}` | Get a single landing page |
| `POST` | `/landing-pages` | Create a landing page |
| `PUT` | `/landing-pages/{id}` | Update a landing page |
| `DELETE` | `/landing-pages/{id}` | Delete a landing page |

## Fields

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `landing_page_url` | string | Yes | Landing page URL (max 2048) |
| `aff_campaign_id` | integer | Yes | Campaign this page belongs to |
| `landing_page_nickname` | string | No | Friendly name (max 255) |
| `leave_behind_page_url` | string | No | Leave-behind URL (max 2048) |
| `landing_page_type` | integer | No | Page type identifier |

## Example

```bash
curl -X POST https://your-domain.com/api/v3/landing-pages \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "landing_page_url": "https://lp.example.com/offer-a",
    "aff_campaign_id": 5,
    "landing_page_nickname": "Offer A - Version 1"
  }'
```
