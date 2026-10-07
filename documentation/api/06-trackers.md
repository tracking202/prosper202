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

`GET /trackers/{id}/url` returns the link to put in your traffic source, built
the way **Setup → Get Links** builds it:

```json
{
  "data": {
    "tracker_id": 42,
    "tracker_id_public": 8837291,
    "direct_url": "https://track.example.com/tracking202/redirect/dl.php?t202id=8837291&adid={ad_id}&c1={placement}&t202kw={keyword}",
    "tracking_params": "?t202id=8837291&adid={ad_id}&c1={placement}&t202kw={keyword}"
  }
}
```

**Which link.** A tracker with a landing page links to the landing page's own
URL with `t202id` added to its query (the parameters go before any `#fragment`,
where a browser sends them). A rotator tracker links to
`tracking202/redirect/rtr.php`, any other to `tracking202/redirect/dl.php`.

**The base.** The tracking domain set in **Settings → Personal** (user 1's, as
every page uses), or this server's own name and port when none is set, followed
by the directory Prosper202 is installed in. A domain stored with `http://` or
`https://` keeps that scheme; otherwise the link uses the scheme this request
arrived on.

**The variables.** After `t202id` come the traffic source's custom variables
(set on the traffic source in Setup → Traffic Sources), each as
`parameter=placeholder`, then the built-in tokens. A built-in token is written
only when it has a value, except `t202kw`, which is always present (empty when
nothing fills it). A traffic-source variable named after a built-in token gives
that token its default.

To fill a built-in token, pass it as a query parameter — the value is written
into the link as given, so pass the traffic source's macro:

```
GET /trackers/42/url?t202kw={keyword}&c1=fb&utm_source={source}
```

| Parameter | |
| --------- | - |
| `c1` `c2` `c3` `c4` | Custom tracking values |
| `utm_source` `utm_medium` `utm_campaign` `utm_term` `utm_content` | UTM values |
| `t202ref` | The referring page, when the source passes it |
| `t202b` | The bid, when the source passes it |
| `t202kw` | The keyword |

A value may not contain `&`, `#`, `?`, whitespace or control characters (it
would end the parameter or the URL), and is at most 255 bytes. Any other
parameter is refused with `422` naming it — a misspelled token is not silently
left out of the link.

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
