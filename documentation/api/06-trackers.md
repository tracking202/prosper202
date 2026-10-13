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
| `aff_campaign_id` | integer | Yes | Campaign this tracker is for; `0` for a redirector's link (`rotator_id`) or an advanced landing page's, which have none |
| `ppc_account_id` | integer | No | PPC account for cost tracking |
| `text_ad_id` | integer | No | Associated text ad |
| `landing_page_id` | integer | No | Landing page to send traffic to |
| `rotator_id` | integer | No | Rotator for split testing |
| `click_cpc` | decimal | No | Cost per click, 0 to 99.99999 |
| `click_cpa` | decimal | No | Cost per action, 0 to 99.99999 |
| `click_cloaking` | integer | No | `-1` the campaign's setting (default), `0` off for this link, `1` on for this link |
| `tracker_id_public` | integer | No | Public ID, the `t202id` in the tracking link (a free one is chosen if omitted). One another tracker holds, in any account, is a `422`: the click endpoints find a tracker by this id alone, so two sharing one would split each other's clicks |

A tracker costs per click or per action, as the cost type on Get Links
chooses: the redirects charge any tracker with a `click_cpa` per action, so
one holding both would be charged twice. Send one of `click_cpc` and
`click_cpa`; both in one request is a `422`. On an update, setting one
switches the tracker to it and clears the other:

```bash
# A CPC tracker becomes CPA: click_cpc is cleared
curl -X PUT https://your-domain.com/api/v3/trackers/42 \
  -H "Authorization: Bearer YOUR_API_KEY" -H "Content-Type: application/json" \
  -d '{"click_cpa": 3.00}'
```

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
every page uses), or, when none is set, the host this request arrived at — the
address you called the API on, port included — followed by the directory
Prosper202 is installed in. (It was the server's own name and port, which
behind a reverse proxy or a published container port is an address only the
server can reach.) A domain stored with `http://` or `https://` keeps that
scheme; otherwise the link uses the scheme this request arrived on, as a proxy
reports it (`X-Forwarded-Proto`). Set a tracking domain before handing links
out: the address you reach the API on is not always one a visitor can.

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
