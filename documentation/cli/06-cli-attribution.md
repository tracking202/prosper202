# CLI Attribution Commands

Manage attribution models, view snapshots, and schedule exports.

## Attribution Models

### attribution:model:list

List attribution models with optional type filter.

```bash
./cli/prosper202 attribution:model:list [--type time_decay]
```

| Option | Description |
| ------ | ----------- |
| `--type`, `-t` | Filter by type: `first_touch`, `last_touch`, `linear`, `time_decay`, `position_based`, `algorithmic` |

### attribution:model:get

Get details of a single attribution model.

```bash
./cli/prosper202 attribution:model:get <id>
```

### attribution:model:create

Create a new attribution model.

```bash
./cli/prosper202 attribution:model:create --model_name "..." --model_type "..." [options]
```

| Option | Required | Description |
| ------ | -------- | ----------- |
| `--model_name` | Yes | Model name |
| `--model_type` | Yes | One of: `first_touch`, `last_touch`, `linear`, `time_decay`, `position_based`, `algorithmic` |
| `--weighting_config` | No | JSON configuration for the model type |
| `--is_active` | No | Active status (default: 1) |
| `--is_default` | No | Set as default model (default: 0) |

The `--weighting_config` option accepts a JSON string and is validated before submission.

### Example

```bash
./cli/prosper202 attribution:model:create \
  --model_name "Position Based 40/20/40" \
  --model_type position_based \
  --weighting_config '{"first": 0.4, "last": 0.4, "middle": 0.2}'
```

### attribution:model:update

Update an attribution model. At least one field must be provided.

```bash
./cli/prosper202 attribution:model:update <id> [--model_name "..."] [--is_active 0]
```

### attribution:model:delete

Delete an attribution model and all related data (snapshots, exports). Prompts for confirmation.

```bash
./cli/prosper202 attribution:model:delete <id> [--force]
```

## Snapshots

### attribution:snapshot:list

List snapshots for a model.

```bash
./cli/prosper202 attribution:snapshot:list <model_id> [options]
```

| Option | Default | Description |
| ------ | ------- | ----------- |
| `--scope_type` | — | Filter by scope: `global`, `campaign`, `landing_page` |
| `--limit`, `-l` | 100 | Results per page |
| `--offset`, `-o` | 0 | Pagination offset |

## Exports

### attribution:export:list

List exports for a model.

```bash
./cli/prosper202 attribution:export:list <model_id>
```

### attribution:export:schedule

Schedule an attribution data export.

```bash
./cli/prosper202 attribution:export:schedule <model_id> [options]
```

| Option | Default | Description |
| ------ | ------- | ----------- |
| `--scope_type` | `global` | Export scope: `global`, `campaign`, `landing_page` |
| `--scope_id` | 0 | Entity ID for the scope |
| `--start_hour` | — | Start timestamp |
| `--end_hour` | — | End timestamp |
| `--format` | `csv` | Export format: `csv` or `json` |
| `--webhook_url` | — | URL to notify when export completes |

### Example

```bash
./cli/prosper202 attribution:export:schedule 1 \
  --scope_type campaign \
  --scope_id 5 \
  --start_hour 1709856000 \
  --end_hour 1709942400 \
  --format csv \
  --webhook_url "https://hooks.example.com/export-ready"
```
