# Trackers API

Manage tracking links that tie campaigns, landing pages, PPC accounts, and rotators together.

## Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/trackers` | List trackers (paginated) |
| `GET` | `/trackers/{id}` | Get a single tracker |
| `POST` | `/trackers` | Create a tracker |
| `PUT` | `/trackers/{id}` | Update a tracker |
| `DELETE` | `/trackers/{id}` | Delete a tracker |
| `POST` | `/trackers/bulk-upsert` | Bulk create/update |
| `GET` | `/trackers/{id}/url` | Get the tracking URL for a tracker |

## Fields

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `aff_campaign_id` | integer | Yes | Campaign this tracker is for |
| `ppc_account_id` | integer | No | PPC account for cost tracking |
| `text_ad_id` | integer | No | Associated text ad |
| `landing_page_id` | integer | No | Landing page to send traffic to |
| `rotator_id` | integer | No | Rotator for split testing |
| `click_cpc` | decimal | No | Cost per click |
| `click_cpa` | decimal | No | Cost per action |
| `click_cloaking` | integer | No | Cloaking enabled (0/1) |
| `tracker_id_public` | integer | No | Public ID (auto-generated if omitted) |

## Get Tracking URL

`GET /trackers/{id}/url` returns the ready-to-use tracking link:

```json
{
  "data": {
    "tracker_id": 42,
    "tracker_id_public": 8837291,
    "direct_url": "https://your-domain.com/tracking202/redirect/go.php?t202id=8837291",
    "tracking_params": "?t202id=8837291&t202kw={keyword}&c1={c1}&c2={c2}&c3={c3}&c4={c4}"
  }
}
```

## Example: Create Tracker

```bash
curl -X POST https://your-domain.com/api/v3/trackers \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "aff_campaign_id": 5,
    "ppc_account_id": 1,
    "landing_page_id": 3,
    "click_cpc": 0.45
  }'
```
