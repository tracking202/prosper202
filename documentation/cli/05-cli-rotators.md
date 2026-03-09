# CLI Rotator Commands

Manage rotators and their rules for split testing and rule-based traffic distribution.

## rotator:list

List all rotators.

```bash
./cli/prosper202 rotator:list [--limit 50] [--offset 0]
```

## rotator:get

Get a rotator with all its rules, criteria, and redirects.

```bash
./cli/prosper202 rotator:get <id>
```

## rotator:create

Create a new rotator.

```bash
./cli/prosper202 rotator:create --name "My Rotator" [options]
```

| Option | Required | Description |
| ------ | -------- | ----------- |
| `--name` | Yes | Rotator name |
| `--default_url` | No | Default redirect URL |
| `--default_campaign` | No | Default campaign ID |
| `--default_lp` | No | Default landing page ID |

## rotator:update

Update a rotator. At least one field must be provided.

```bash
./cli/prosper202 rotator:update <id> [--name "New Name"] [--default_url "..."]
```

## rotator:delete

Delete a rotator and all its rules. Prompts for confirmation.

```bash
./cli/prosper202 rotator:delete <id> [--force]
```

## rotator:rule:create

Add a rule to a rotator. Criteria and redirects are passed as JSON.

```bash
./cli/prosper202 rotator:rule:create <rotator_id> --rule_name "US Traffic" [options]
```

| Option | Required | Description |
| ------ | -------- | ----------- |
| `--rule_name` | Yes | Rule name |
| `--splittest` | No | Enable split testing (0 or 1, default 0) |
| `--criteria_json` | No | JSON array of criteria objects |
| `--redirects_json` | No | JSON array of redirect objects |

### Criteria JSON Format

```json
[
  { "type": "country", "statement": "is", "value": "US" },
  { "type": "device", "statement": "is", "value": "desktop" }
]
```

### Redirects JSON Format

```json
[
  { "redirect_url": "https://lp-a.example.com", "weight": "60", "name": "Variant A" },
  { "redirect_url": "https://lp-b.example.com", "weight": "40", "name": "Variant B" }
]
```

### Example

```bash
./cli/prosper202 rotator:rule:create 1 \
  --rule_name "US Desktop Split" \
  --splittest 1 \
  --criteria_json '[{"type":"country","statement":"is","value":"US"}]' \
  --redirects_json '[{"redirect_url":"https://a.example.com","weight":"50","name":"A"},{"redirect_url":"https://b.example.com","weight":"50","name":"B"}]'
```

## rotator:rule:delete

Delete a rule from a rotator. Prompts for confirmation.

```bash
./cli/prosper202 rotator:rule:delete <rotator_id> <rule_id> [--force]
```
