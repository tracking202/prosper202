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
| `POST` | `/landing-pages/{id}/check` | Fetch the page's saved URL and say whether it carries that code ([Setup API](27-setup.md)) |

## Fields

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `landing_page_url` | string | Yes | Landing page URL (max 255), starting with `http://` or `https://`. A host sent without its scheme (`lp.example.com/x`, `//lp.example.com/x`) is stored with `https://`, as Setup › Landing Pages stores it, and the answer says so: `meta.normalized.landing_page_url` holds `sent` and `stored`. Any other value is a `422`. Sync (`p202 sync`, `POST /sync`) compares landing pages on the stored form, so a source that still holds the bare host matches the page it made |
| `aff_campaign_id` | integer | Yes | Campaign this page belongs to; `0` for an advanced page (`landing_page_type: 1`), which has none |
| `landing_page_nickname` | string | Yes | Friendly name (max 50) |
| `leave_behind_page_url` | string | No | Leave-behind URL (max 255; `null` clears it). A host sent without its scheme is stored with `https://` and named in `meta.normalized` |
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

## Making an advanced page simple

`PUT /landing-pages/{id}` with `landing_page_type` 0 does not make an advanced
page simple while text ads of `text_ad_type` 1 or trackers name it: Get Links
offers those ads only on an advanced page, and the trackers' links were built
for one. The answer is `422`, with the refusal under `landing_page_type`
naming each ad's `text_ad_id` and each tracker's `tracker_id`, and nothing in
the request is written. Point each ad at another advanced page or make it a
campaign ad, and move each tracker to another advanced page or delete it, then
change the type. Setup › Landing Pages refuses the same change.

## Deleting a page

`DELETE /landing-pages/{id}` removes the page (it is marked deleted). An
advanced page is not removed while text ads of `text_ad_type` 1 or trackers
name it, since each would be left pointing at a page that is gone: the answer
is `409`, its message names them, and `details` lists their ids:

```json
{"error": true, "message": "Landing page 12 is advanced and 1 text ad of text_ad_type 1 (text_ad_id 4) and 2 trackers (tracker_id 7, 9) name it: ...", "status": 409, "details": {"text_ad_ids": [4], "tracker_ids": [7, 9]}}
```

Point each text ad at another advanced page or make it a campaign ad, and move
or delete each tracker, then delete the page. `dry_run=1` answers the same
refusal. A simple page is removed as before. Setup › Landing Pages refuses the
same delete.

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
