# CLI User Commands

Manage users, roles, API keys, and preferences.

## Users

### user:list

List all users.

```bash
./cli/prosper202 user:list
```

### user:get

Get user details with assigned roles.

```bash
./cli/prosper202 user:get <id>
```

### user:create

Create a new user. If `--user_pass` is omitted, the CLI prompts securely for a password (not visible in shell history).

```bash
./cli/prosper202 user:create --user_name "jdoe" --user_email "jdoe@example.com" [options]
```

| Option | Required | Description |
| ------ | -------- | ----------- |
| `--user_name` | Yes | Username (unique) |
| `--user_email` | Yes | Email address |
| `--user_pass` | No | Password (min 8 chars; prompts securely if omitted) |
| `--user_fname` | No | First name |
| `--user_lname` | No | Last name |
| `--user_timezone` | No | Timezone (default: UTC) |

### user:update

Update a user. At least one field must be provided. Use `--user_pass` as a flag without a value to be prompted securely.

```bash
./cli/prosper202 user:update <id> [--user_email "new@example.com"] [--user_pass] [--user_active 0]
```

| Option | Description |
| ------ | ----------- |
| `--user_fname` | First name |
| `--user_lname` | Last name |
| `--user_email` | Email address |
| `--user_pass` | Password (prompts securely if flag given without value) |
| `--user_timezone` | Timezone |
| `--user_active` | 1 = active, 0 = inactive |

### user:delete

Soft-delete a user. Prompts for confirmation.

```bash
./cli/prosper202 user:delete <id> [--force]
```

## Roles

### user:role:list

List all available roles.

```bash
./cli/prosper202 user:role:list
```

### user:role:assign

Assign a role to a user.

```bash
./cli/prosper202 user:role:assign <user_id> --role_id <role_id>
```

### user:role:remove

Remove a role from a user. Prompts for confirmation.

```bash
./cli/prosper202 user:role:remove <user_id> <role_id> [--force]
```

## API Keys

### user:apikey:list

List API keys for a user (keys are masked).

```bash
./cli/prosper202 user:apikey:list <user_id>
```

### user:apikey:create

Generate a new API key for a user. The full key is shown only once.

```bash
./cli/prosper202 user:apikey:create <user_id>
```

### user:apikey:delete

Delete an API key. Prompts for confirmation.

```bash
./cli/prosper202 user:apikey:delete <user_id> <api_key> [--force]
```

## Preferences

### user:prefs:get

Get user preferences.

```bash
./cli/prosper202 user:prefs:get <user_id>
```

### user:prefs:update

Update user preferences. At least one preference must be provided.

```bash
./cli/prosper202 user:prefs:update <user_id> [options]
```

| Option | Description |
| ------ | ----------- |
| `--user_tracking_domain` | Custom tracking domain |
| `--user_account_currency` | 3-letter currency code |
| `--user_slack_incoming_webhook` | Slack webhook URL |
| `--user_daily_email` | Daily email digest (`on`/`off`) |
| `--ipqs_api_key` | IPQualityScore API key |

### Example

```bash
./cli/prosper202 user:prefs:update 1 \
  --user_tracking_domain "track.example.com" \
  --user_account_currency "USD" \
  --user_daily_email on
```
