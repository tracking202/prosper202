# Rotators API

Manage traffic rotators with rules, criteria, and weighted redirects for split testing.

## Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/rotators` | List rotators (paginated) |
| `GET` | `/rotators/{id}` | Get rotator with all nested rules |
| `GET` | `/rotators/{id}/stats` | The rotator's totals, each rule and its default over a window (needs `reports:read` too; see [Reports › Rotator stats](11-reports.md#rotator-stats)) |
| `POST` | `/rotators` | Create a rotator |
| `PUT` | `/rotators/{id}` | Update a rotator |
| `DELETE` | `/rotators/{id}` | Delete rotator and all rules (cascading) |
| `GET` | `/rotators/{id}/rules` | List rules for a rotator |
| `POST` | `/rotators/{id}/rules` | Create a rule |
| `PUT` | `/rotators/{id}/rules/{ruleId}` | Update a rule |
| `DELETE` | `/rotators/{id}/rules/{ruleId}` | Delete a rule |

## Rotator Fields

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `name` | string | Yes | Rotator name (1-255 characters) |
| `default_url` | string | No | Default destination: an `http://` or `https://` URL |
| `default_campaign` | integer | No | Default destination: one of your live campaigns |
| `default_lp` | integer | No | Default destination: one of your live landing pages |
| `public_id` | integer | No | Public ID: on create, honoured when no redirector has it (auto-generated otherwise); fixed after, so an update accepts only the redirector's own |
| `auto_monetizer` | string | Read-only | `"true"` when the default is the auto-monetizer (set on the Redirectors page) |

A body may carry only these fields; any other key is a `422` naming it.
`id`, `user_id`, `auto_monetizer` and `rules` (written through
`/rotators/{id}/rules`) are what `GET` answers beside them: an update accepts
them only with the redirector's own values, so a `GET` body can be sent back,
and a create refuses them.

**The default is one destination**, as on Setup → Redirectors: send at most one
of `default_url`, `default_campaign` and `default_lp`. On an update, naming any
of them replaces the whole default and clears the others (and the
auto-monetizer), so a redirector whose default was a campaign can be switched
to a URL in one request. Sending two is a `422`; so is a campaign or landing
page that is not yours or is deleted, and a URL that is not http(s). `null`,
`0` and `""` mean "not this kind".

## Rule Structure

Rules contain criteria (conditions a visit must all match) and redirects
(destinations with weights). Everything in the payload is checked before
anything is written: a refused rule creates nothing, and a refused update
leaves the rule as it was.

### Create/Update Rule Payload

```json
{
  "rule_name": "US Desktop Traffic",
  "splittest": 1,
  "status": 1,
  "criteria": [
    { "type": "country", "statement": "is", "value": "United States(US)" },
    { "type": "device", "statement": "is", "value": "desktop" }
  ],
  "redirects": [
    { "redirect_lp": 10, "weight": 60, "name": "Variant A" },
    { "redirect_url": "https://lp2.example.com", "weight": 40, "name": "Variant B" }
  ]
}
```

### Rule Fields

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `rule_name` | string | Yes | Rule name (1-255 characters) |
| `splittest` | integer | No | `1` splits by the redirects' weights; `0` (default) |
| `status` | integer | No | `1` active (default), `0` paused |

### Criteria Object

| Field | Type | Description |
| ----- | ---- | ----------- |
| `type` | string | `country`, `region`, `city`, `isp`, `ip`, `platform`, `device` or `browser` — the types the redirects evaluate; any other is a `422`, since it would never match |
| `statement` | string | `is` (default) or `is_not` |
| `value` | string | Comma-separated values, compared exactly. Countries are `Name(CC)`, e.g. `United States(US)` (`p202 rotator criteria-values` lists them); devices are lowercase (`desktop`, `mobile`, `tablet`, `bot`) |

### Redirect Object

| Field | Type | Description |
| ----- | ---- | ----------- |
| `redirect_url` | string | Destination: an http(s) URL |
| `redirect_campaign` | integer | Destination: one of your live campaigns |
| `redirect_lp` | integer | Destination: one of your live landing pages |
| `weight` | integer | Traffic weight, 0-100 (default 100) |
| `name` | string | Variant name for reporting |

Each redirect has exactly one destination; the other two are stored as NULL.
Earlier versions stored `0` there, which the redirect read as a campaign with
no campaign: a URL or landing-page redirect made through the API sent the
visitors its rule matched to an empty `Location`. The redirect now treats `0`
as "none", so redirects saved that way work as written.

## Example: Create Rotator with Rules

```bash
# 1. Create the rotator
curl -X POST https://your-domain.com/api/v3/rotators \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{ "name": "Geo Split Test", "default_url": "https://fallback.example.com" }'

# 2. Add a rule (using the returned rotator ID)
curl -X POST https://your-domain.com/api/v3/rotators/1/rules \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "rule_name": "US Traffic",
    "splittest": 1,
    "criteria": [{ "type": "country", "statement": "is", "value": "United States(US)" }],
    "redirects": [
      { "redirect_url": "https://lp-a.example.com", "weight": 50, "name": "A" },
      { "redirect_url": "https://lp-b.example.com", "weight": 50, "name": "B" }
    ]
  }'
```
