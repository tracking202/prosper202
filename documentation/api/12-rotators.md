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
| `GET` | `/rotators/{id}/rules` | List rules for a rotator, in the order they are tried |
| `PUT` | `/rotators/{id}/rules` | Replace the whole rule set, in order, in one transaction ([below](#replace-the-rule-set)) |
| `POST` | `/rotators/{id}/rules` | Create a rule (tried after the others) |
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
to a URL in one request. A body that restates the default the redirector holds
(a GET body sent back with a new name) changes nothing, the auto-monetizer
included, and a campaign or landing page it already names is kept even if it
was deleted since. Sending two is a `422`; so is naming a campaign or landing
page that is not yours or is deleted, and a URL that is not http(s). `null`,
`0` and `""` mean "not this kind".

## Rule Structure

Rules contain criteria (conditions a visit must all match) and redirects
(destinations with weights). Everything in the payload is checked before
anything is written: a refused rule creates nothing, and a refused update
leaves the rule as it was.

The redirects try a redirector's active rules in order, and the first whose
criteria all match sends the visitor; one no rule matches goes to the default.
`GET` answers the rules in that order. A rule created with `POST` is tried
after the others; `PUT /rotators/{id}/rules` sets the order.

## Replace the rule set

`PUT /rotators/{id}/rules` is Setup → Redirectors' save as one call. The body
lists every rule the redirector is to have, in the order they are tried, and
the set is written in one transaction: all of it, or nothing.

```json
{
  "rules": [
    {
      "rule_name": "Office split",
      "splittest": 1,
      "criteria": [{ "type": "ip", "statement": "is", "value": "203.0.113.10" }],
      "redirects": [
        { "redirect_url": "https://a.example.com", "weight": 70, "name": "A" },
        { "redirect_url": "https://b.example.com", "weight": 30, "name": "B" }
      ]
    },
    {
      "id": 12,
      "rule_name": "Everyone else",
      "criteria": [{ "type": "country", "statement": "is", "value": "ALL" }],
      "redirects": [{ "redirect_campaign": 4 }]
    }
  ]
}
```

- **Read, edit, send back.** `GET /rotators/{id}/rules` answers the set in
  this shape (under `data`); a `GET` body sent back as `{"rules": ...}` changes
  nothing, the read-only keys included (they are held to the redirector's own
  values, as on a rule update).
- **A rule sent with its `id` is kept**: it is that rule, changed to what is
  sent, and keeps its id, so its clicks and its report rows stay its own. Its
  criteria and redirects sent with their ids are updated in place, keeping
  theirs (a click's report row names its redirect). An `id` that is not one of
  the redirector's rules, or one listed twice, is a `422`.
- **A rule without an `id` is added**, and **a rule left out is removed**,
  with its criteria and redirects. Removing one needs the role permission
  `remove_rotator_rule`, as the page asks; without it the call is a `403` and
  nothing is written.
- A kept rule sent without `splittest` or `status` keeps its own; a new rule's
  are `0` and `1`.
- **The rules keep the order sent; their criteria and redirects do not.** A
  rule's criteria and redirects come back in id order, the new ones after the
  kept, whatever their order in the body (every criterion has to match, and a
  redirect is drawn by weight, so their order decides nothing). Reordering
  them alone changes nothing.
- As on the page, each rule needs a `rule_name`, at least one criterion (the
  value `ALL` matches every visitor) and at least one redirect; a rule that is
  not a split test has exactly one redirect; each redirect of a split test
  needs a `weight`, and at least one weight above 0. A set has 1 to 200 rules,
  a rule at most 100 criteria and 100 redirects.
- Every problem in the set is refused in one `422`, each named by its place
  (`rules.1.redirects.0.redirect_campaign`), with nothing written.

`?dry_run=1` writes nothing and answers the set as it would be stored (a new
rule, criterion or redirect has `id: null`), in the order the write reads it
back, with what changes. The answer of a write is the same, with the set as
stored:

```json
{
  "data": {
    "dry_run": false,
    "rotator_id": 5,
    "rules": [ "...as GET /rotators/5/rules answers them..." ],
    "changes": {
      "added": 1, "updated": 1, "unchanged": 0, "removed": 1,
      "rules": [
        { "action": "add", "id": null, "rule_name": "Office split", "position": 1 },
        { "action": "update", "id": 12, "rule_name": "Everyone else", "position": 2, "changed": ["position"] },
        { "action": "remove", "id": 9, "rule_name": "Old rule", "position": null }
      ]
    }
  }
}
```

`changed` names what differs: `rule_name`, `splittest`, `status`, `position`
(the rule's place in the set, from 1), `criteria`, `redirects`. The call can
be staged (`?staged=1`). A `PATCH` is a `405`: the set is replaced whole, and
a PATCH naming one rule would remove the others.

Every key is checked, inside each criterion and redirect too: a key a
criterion or redirect does not take is a `422` naming it by its place
(`redirects.0.weigth`, `criteria.1.valeu`), with the keys it does take.
Only the top-level keys used to be checked, so `"weigth": 40` was dropped
and the redirect stored at the default weight of 100.

**A rule read with `GET` can be sent back whole** (`PUT
/rotators/{id}/rules/{ruleId}` with one of `GET /rotators/{id}/rules`): the
rule's `id` and `rotator_id`, and each criterion's `id`, `rotator_id` and
`rule_id` and each redirect's `id`, `rule_id` and `auto_monetizer`, are
accepted with the rule's own values and refused with any other. An entry's
`id` must be one of this rule's: an update writes the criteria and redirects
afresh, so they get new ids, and a body read before an earlier update names
ids that are gone (re-read the rule). A create refuses all of them. A
redirect that Setup → Redirectors made the auto-monetizer (no destination,
`auto_monetizer` set) stays the auto-monetizer when it is sent back that way;
sending a destination beside it is a `422`.

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
| `value` | string | Comma-separated values, compared exactly, and stored as the Redirectors page stores them: each value trimmed, the empty ones dropped (`United States(US), Canada(CA)` was stored with the space, and Canada never matched). `ALL` among the values, in any case (`all`, `All`), matches every visitor whatever the statement, and is stored `ALL`. The redirect links read a value stored before 1.9.79 the same way: each value trimmed, and `ALL` in any case; a landing page's offer link (`offrtr.php`) read only `ALL`, so a rule stored as `all` sent its visitors to the default there. Countries are `Name(CC)`, e.g. `United States(US)` (`p202 rotator criteria-values` lists them); devices are lowercase (`desktop`, `mobile`, `tablet`, `bot`), and a capital is a `422`, since it would never match. An `ip` value lists single IPv4 or IPv6 addresses, compared as addresses (`2001:DB8::1` matches `2001:db8::1`) and stored in canonical form; anything else, a range such as `203.0.113.0/24` included, is a `422` naming it, since it would never match. A value holding `ALL` is taken for any type, since it matches every visitor whatever the type: an `ip` criterion of `ALL` was refused as no address, and a country or device list beside `ALL` is not held to its form |

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
