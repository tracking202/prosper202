# Rotators API

Manage traffic rotators with rules, criteria, and weighted redirects for split testing.

## Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/rotators` | List rotators (paginated) |
| `GET` | `/rotators/{id}` | Get rotator with all nested rules |
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
| `name` | string | Yes | Rotator name |
| `default_url` | string | No | Default redirect URL |
| `default_campaign` | integer | No | Default campaign ID |
| `default_lp` | integer | No | Default landing page ID |
| `public_id` | integer | No | Public ID (auto-generated) |

## Rule Structure

Rules contain criteria (conditions to match) and redirects (destinations with weights).

### Create/Update Rule Payload

```json
{
  "rule_name": "US Desktop Traffic",
  "splittest": 1,
  "status": 1,
  "criteria": [
    { "type": "country", "statement": "is", "value": "US" },
    { "type": "device", "statement": "is", "value": "desktop" }
  ],
  "redirects": [
    {
      "redirect_url": "https://lp1.example.com",
      "redirect_campaign": 5,
      "redirect_lp": 10,
      "weight": 60,
      "name": "Variant A"
    },
    {
      "redirect_url": "https://lp2.example.com",
      "redirect_campaign": 5,
      "redirect_lp": 11,
      "weight": 40,
      "name": "Variant B"
    }
  ]
}
```

### Rule Fields

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `rule_name` | string | Yes | Rule name |
| `splittest` | integer | No | Enable split testing (0/1, default 0) |
| `status` | integer | No | Rule active status |

### Criteria Object

| Field | Type | Description |
| ----- | ---- | ----------- |
| `type` | string | Criterion type (e.g., `country`, `device`, `browser`, `platform`) |
| `statement` | string | Comparison operator (e.g., `is`, `is_not`) |
| `value` | string | Value to match against |

### Redirect Object

| Field | Type | Description |
| ----- | ---- | ----------- |
| `redirect_url` | string | Destination URL |
| `redirect_campaign` | integer | Campaign ID |
| `redirect_lp` | integer | Landing page ID |
| `weight` | integer | Traffic weight (percentage) |
| `name` | string | Variant name for reporting |

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
    "criteria": [{ "type": "country", "statement": "is", "value": "US" }],
    "redirects": [
      { "redirect_url": "https://lp-a.example.com", "weight": 50, "name": "A" },
      { "redirect_url": "https://lp-b.example.com", "weight": 50, "name": "B" }
    ]
  }'
```
