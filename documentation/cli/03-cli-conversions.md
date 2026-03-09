# CLI Conversion Commands

List, inspect, create, and delete conversions.

## conversion:list

List conversions with optional filters.

```bash
./cli/prosper202 conversion:list [options]
```

| Option | Default | Description |
| ------ | ------- | ----------- |
| `--limit`, `-l` | 50 | Results per page |
| `--offset`, `-o` | 0 | Pagination offset |
| `--campaign_id` | — | Filter by campaign ID |
| `--time_from` | — | Unix timestamp start |
| `--time_to` | — | Unix timestamp end |

## conversion:get

Get details of a single conversion.

```bash
./cli/prosper202 conversion:get <id>
```

## conversion:create

Manually log a conversion.

```bash
./cli/prosper202 conversion:create [options]
```

| Option | Required | Description |
| ------ | -------- | ----------- |
| `--click_id` | Yes | The click to attribute this conversion to |
| `--payout` | No | Override payout amount |
| `--transaction_id` | No | Deduplication key |

### Example

```bash
./cli/prosper202 conversion:create --click_id 12345 --payout 4.50 --transaction_id "txn-abc"
```

## conversion:delete

Delete a conversion. Prompts for confirmation unless `--force` is used.

```bash
./cli/prosper202 conversion:delete <id> [--force]
```

| Argument | Required | Description |
| -------- | -------- | ----------- |
| `id` | Yes | Conversion ID |

| Option | Description |
| ------ | ----------- |
| `--force`, `-f` | Skip confirmation prompt |
