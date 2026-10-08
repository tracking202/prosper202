# Landing Pages API

Manage landing pages used between the traffic source and the destination.

## Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/landing-pages` | List landing pages (paginated) |
| `GET` | `/landing-pages/{id}` | Get a single landing page |
| `POST` | `/landing-pages` | Create a landing page |
| `PUT` | `/landing-pages/{id}` | Update a landing page |
| `DELETE` | `/landing-pages/{id}` | Delete a landing page |
| `GET` | `/landing-pages/{id}/code` | The page's tracking code, as Setup › Get LP Code hands it out ([Setup API](27-setup.md)) |

## Fields

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `landing_page_url` | string | Yes | Landing page URL (max 255) |
| `aff_campaign_id` | integer | Yes | Campaign this page belongs to; `0` for an advanced page (`landing_page_type: 1`), which has none |
| `landing_page_nickname` | string | Yes | Friendly name (max 50) |
| `leave_behind_page_url` | string | No | Leave-behind URL (max 255; `null` clears it) |
| `landing_page_type` | integer | No | Page type identifier (default 0) |

Auto-generated on create: `landing_page_time` (unix timestamp) and
`landing_page_id_public`, the id the landing-page code and the `go.php`/`lp.php`
redirects carry (`lpip=`): a random digit, the page id, a random digit, as the
setup page makes it. Landing pages created through this API before it set one
had none (`NULL`; `0` is none as well), so no code could track them; reading
them through the API gives each one its id.

`landing_page_type` is 0 for a simple page (one campaign) and 1 for an
advanced one (several offers); `GET /landing-pages/{id}/code` makes the code
for either (an advanced page's with `?offers=campaign:<id>,rotator:<id>`),
described in the [Setup API](27-setup.md).

## Example

```bash
curl -X POST https://your-domain.com/api/v3/landing-pages \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "landing_page_url": "https://lp.example.com/page-a",
    "aff_campaign_id": 5,
    "landing_page_nickname": "Page A - Version 1"
  }'
```
