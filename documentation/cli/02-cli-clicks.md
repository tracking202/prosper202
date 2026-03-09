# CLI Click Commands

Inspect click tracking data.

## click:list

List clicks with optional filters.

```bash
./cli/prosper202 click:list [options]
```

| Option | Default | Description |
| ------ | ------- | ----------- |
| `--limit`, `-l` | 50 | Results per page |
| `--offset`, `-o` | 0 | Pagination offset |
| `--time_from` | — | Unix timestamp start |
| `--time_to` | — | Unix timestamp end |
| `--aff_campaign_id` | — | Filter by campaign ID |
| `--ppc_account_id` | — | Filter by PPC account ID |
| `--landing_page_id` | — | Filter by landing page ID |
| `--click_lead` | — | 0 = clicks only, 1 = conversions only |
| `--click_bot` | — | 0 = human traffic, 1 = bot traffic |

### Examples

```bash
# Recent conversions
./cli/prosper202 click:list --click_lead 1 --limit 20

# Clicks for a specific campaign
./cli/prosper202 click:list --aff_campaign_id 5 --time_from 1709856000

# Bot traffic
./cli/prosper202 click:list --click_bot 1
```

## click:get

Get full details of a single click including geo, device, and tracking parameters.

```bash
./cli/prosper202 click:get <id>
```

| Argument | Required | Description |
| -------- | -------- | ----------- |
| `id` | Yes | Click ID |
