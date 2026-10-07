# Users API

Manage users, roles, API keys, and preferences.

## User Endpoints

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| `GET` | `/users` | Admin | List all users |
| `GET` | `/users/{id}` | Self or Admin | Get user details |
| `POST` | `/users` | Admin | Create a user |
| `PUT` | `/users/{id}` | Self, or a user you may manage | Update a user |
| `DELETE` | `/users/{id}` | Super user | Soft-delete a user (never user 1 or yourself) |

### Who may act on whom

The API keeps the rules of **Account › User management**. *Admin* means the
Admin or Super user role; only the Super user role carries the
`add_edit_delete_admin` permission.

- **Your own account** — profile, password, API keys, signing key and
  preferences — is always yours to change. Changing your own password needs
  `current_password` as well (see [User Fields](#user-fields)).
- **Another user's account** needs Admin, and:
  - only user 1 acts on user 1 (the Super user account the installer
    created), whether to read its keys and preferences or to change them;
  - an account holding the Admin or Super user role is acted on only by a
    Super user — an Admin cannot change another Admin, nor its own roles.
- **Roles**: Super user (role 1) is never granted; Admin (role 2) is granted
  only by a Super user; user 1's roles are fixed.
- **Removing a user** needs the Super user role, and never removes user 1 or
  the caller.

A refusal is `403` with the rule in `message`. Before these rules, an Admin
key could grant itself Super user, mint a full-access key for user 1 and set
user 1's password; `tests/Api/V3/UserManagementRulesInstanceTest.php` drives
each refusal against a running instance.

## Role Endpoints

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| `GET` | `/users/roles` | Any Authenticated | List all available roles |
| `POST` | `/users/{id}/roles` | Admin, under the [rules above](#who-may-act-on-whom) | Assign a role to a user (`role_id`: a positive whole number; 2 needs a Super user, 1 is never granted) |
| `DELETE` | `/users/{id}/roles/{roleId}` | Admin, under the [rules above](#who-may-act-on-whom) | Remove a role from a user |

## API Key Endpoints

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| `GET` | `/users/{id}/api-keys` | Self, or a user you may manage | List API keys (masked) |
| `POST` | `/users/{id}/api-keys` | Self, or a user you may manage | Generate a new API key |
| `DELETE` | `/users/{id}/api-keys/{keyId}` | Self, or a user you may manage | Delete an API key |

API keys are masked after the first 8 characters in list responses. The full key is only returned once, at creation time.

## Preference Endpoints

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| `GET` | `/users/{id}/preferences` | Self, or a user you may manage | Get user preferences (they include the account's integration secrets) |
| `PUT` | `/users/{id}/preferences` | Self, or a user you may manage | Update preferences |

## User Fields

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `user_name` | string | Yes | Username (unique) |
| `user_email` | string | Yes | Email address (validated) |
| `user_pass` | string | Yes (create) | Password, 8-72 characters (bcrypt reads only the first 72 bytes), hashed server-side |
| `current_password` | string | When you change your **own** `user_pass` | Your present password, as Personal settings asks for it: a key that is not the password must not be enough to take over the sign-in. Missing or wrong is `422` naming `current_password`. A Super user or Admin resetting another user's password does not send it |
| `user_fname` | string | No | First name |
| `user_lname` | string | No | Last name |
| `user_timezone` | string | No | Timezone (default: UTC) |
| `user_active` | integer | No | Active status (1 = active, 0 = inactive, default: 1) |

## Preference Fields

| Field | Type | Description |
| ----- | ---- | ----------- |
| `user_tracking_domain` | string | Custom tracking domain |
| `user_account_currency` | string | 3-letter currency code |
| `user_slack_incoming_webhook` | string | Slack webhook URL for notifications |
| `user_daily_email` | string | Daily email digest (`on` or `off`) |
| `ipqs_api_key` | string | IPQualityScore API key for fraud detection |

## Examples

### Create User

```bash
curl -X POST https://your-domain.com/api/v3/users \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "user_name": "jdoe",
    "user_email": "jdoe@example.com",
    "user_pass": "securepassword123",
    "user_fname": "John",
    "user_lname": "Doe",
    "user_timezone": "America/New_York"
  }'
```

### Generate API Key

```bash
curl -X POST https://your-domain.com/api/v3/users/1/api-keys \
  -H "Authorization: Bearer YOUR_API_KEY"
```

Response (full key shown only once):
```json
{
  "_status": 201,
  "data": {
    "api_key": "p202_a1b2c3d4e5f6g7h8i9j0...",
    "user_id": 1,
    "scope": "*",
    "created_at": 1709942400
  }
}
```

### Generate a Scoped API Key

Pass `scope` (a comma-separated string or an array of tokens) to attenuate
the key — see [API Key Scopes](00-api-integrations.md#api-key-scopes) for
the grammar and enforcement rules. A read-only key for a reporting agent:

```bash
curl -X POST https://your-domain.com/api/v3/users/1/api-keys \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"scope": "read"}'
```

Rules enforced at creation:

- Unknown tokens are rejected with `422` (a typo must not mint a key that
  silently denies everything).
- A scoped key cannot mint a key broader than itself (`403`), and must name
  an explicit scope for any key it creates (`422` otherwise) — defaulting to
  full access would be a silent escalation.
- On a pre-1.9.75 schema without the `scope` column, requesting a scope
  returns `409` pointing at the upgrade.

`GET /users/{id}/api-keys` returns each key's `scope` alongside the masked
key (keys without one show `*`).

### Assign a Role

Make user 7 a Campaign manager (role 3; `GET /users/roles` lists them):

```bash
curl -X POST https://your-domain.com/api/v3/users/7/roles \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{ "role_id": 3 }'
```

### Change Your Own Password

```bash
curl -X PUT https://your-domain.com/api/v3/users/1 \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{ "user_pass": "a-new-password", "current_password": "the-old-one" }'
```

`p202 user update <id> --current-password --set-password` reads both without
echo, at a terminal or as two lines of piped stdin (current first).
