# CLI CRUD Entity Commands

Seven entity types have auto-generated CRUD commands following a consistent pattern. Each entity supports `list`, `get`, `create`, `update`, and `delete`.

## Common Patterns

```bash
# List with pagination
./cli/prosper202 {entity}:list [--limit 50] [--offset 0]

# Get by ID
./cli/prosper202 {entity}:get <id>

# Create (required fields vary by entity)
./cli/prosper202 {entity}:create --field1 value1 --field2 value2

# Update (at least one field required)
./cli/prosper202 {entity}:update <id> --field1 new_value

# Delete (prompts for confirmation)
./cli/prosper202 {entity}:delete <id> [--force]
```

All commands support `--json` for raw JSON output.

---

## campaign

Manage affiliate campaigns.

**List filters:** `--filter[aff_network_id]`

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `--aff_campaign_name` | string | Yes | Campaign name |
| `--aff_campaign_url` | string | Yes | Primary affiliate URL |
| `--aff_campaign_url_2` through `_5` | string | No | Alternate URLs |
| `--aff_campaign_payout` | decimal | No | Default payout |
| `--aff_campaign_currency` | string | No | Currency code |
| `--aff_campaign_foreign_payout` | decimal | No | Foreign payout |
| `--aff_network_id` | integer | No | Affiliate network ID |
| `--aff_campaign_cloaking` | integer | No | Cloaking (0/1) |
| `--aff_campaign_rotate` | integer | No | Rotation (0/1) |

```bash
./cli/prosper202 campaign:create --aff_campaign_name "Summer Offer" --aff_campaign_url "https://offer.example.com"
./cli/prosper202 campaign:list --filter[aff_network_id] 3
```

## aff-network

Manage affiliate networks.

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `--aff_network_name` | string | Yes | Network name |
| `--dni_network_id` | integer | No | DNI network ID |

```bash
./cli/prosper202 aff-network:create --aff_network_name "MaxBounty"
```

## ppc-network

Manage PPC/traffic source networks.

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `--ppc_network_name` | string | Yes | Network name |

```bash
./cli/prosper202 ppc-network:create --ppc_network_name "Google Ads"
```

## ppc-account

Manage PPC advertising accounts.

**List filters:** `--filter[ppc_network_id]`

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `--ppc_account_name` | string | Yes | Account name |
| `--ppc_network_id` | integer | Yes | Parent PPC network ID |
| `--ppc_account_default` | integer | No | Default account (0/1) |

```bash
./cli/prosper202 ppc-account:create --ppc_account_name "Main Account" --ppc_network_id 1
```

## tracker

Manage tracking link configurations.

**List filters:** `--filter[aff_campaign_id]`, `--filter[ppc_account_id]`

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `--aff_campaign_id` | integer | Yes | Campaign ID |
| `--ppc_account_id` | integer | No | PPC account ID |
| `--text_ad_id` | integer | No | Text ad ID |
| `--landing_page_id` | integer | No | Landing page ID |
| `--rotator_id` | integer | No | Rotator ID |
| `--click_cpc` | decimal | No | Cost per click |
| `--click_cpa` | decimal | No | Cost per action |
| `--click_cloaking` | integer | No | Cloaking (0/1) |

```bash
./cli/prosper202 tracker:create --aff_campaign_id 5 --ppc_account_id 1 --click_cpc 0.45
```

## landing-page

Manage landing pages.

**List filters:** `--filter[aff_campaign_id]`

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `--landing_page_url` | string | Yes | Landing page URL |
| `--aff_campaign_id` | integer | Yes | Campaign ID |
| `--landing_page_nickname` | string | No | Friendly name |
| `--leave_behind_page_url` | string | No | Leave-behind URL |
| `--landing_page_type` | integer | No | Page type |

```bash
./cli/prosper202 landing-page:create --landing_page_url "https://lp.example.com" --aff_campaign_id 5 --landing_page_nickname "LP v1"
```

## text-ad

Manage text ad creatives.

**List filters:** `--filter[aff_campaign_id]`

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `--text_ad_name` | string | Yes | Ad name |
| `--text_ad_headline` | string | No | Headline |
| `--text_ad_description` | string | No | Description |
| `--text_ad_display_url` | string | No | Display URL |
| `--aff_campaign_id` | integer | No | Campaign ID |
| `--landing_page_id` | integer | No | Landing page ID |
| `--text_ad_type` | integer | No | Ad type |

```bash
./cli/prosper202 text-ad:create --text_ad_name "Ad v2" --text_ad_headline "50% Off Today"
```
