# Users API

Manage users, roles, API keys, and preferences.

## User Endpoints

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| `GET` | `/users` | Admin | List all users |
| `GET` | `/users/{id}` | Self or Admin | Get user details |
| `POST` | `/users` | Admin | Create a user |
| `PUT` | `/users/{id}` | Self or Admin | Update a user |
| `DELETE` | `/users/{id}` | Admin | Soft-delete a user |

## Role Endpoints

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| `GET` | `/users/roles` | None | List all available roles |
| `POST` | `/users/{id}/roles` | Admin | Assign a role to a user |
| `DELETE` | `/users/{id}/roles/{roleId}` | Admin | Remove a role from a user |

## API Key Endpoints

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| `GET` | `/users/{id}/api-keys` | Self or Admin | List API keys (masked) |
| `POST` | `/users/{id}/api-keys` | Self or Admin | Generate a new API key |
| `DELETE` | `/users/{id}/api-keys/{keyId}` | Self or Admin | Delete an API key |

API keys are masked after the first 8 characters in list responses. The full key is only returned once, at creation time.

## Preference Endpoints

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| `GET` | `/users/{id}/preferences` | Self or Admin | Get user preferences |
| `PUT` | `/users/{id}/preferences` | Self or Admin | Update preferences |

## User Fields

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `user_name` | string | Yes | Username (unique) |
| `user_email` | string | Yes | Email address (validated) |
| `user_pass` | string | Yes (create) | Password (min 8 characters, hashed server-side) |
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
    "created_at": 1709942400
  }
}
```

### Assign Admin Role

```bash
curl -X POST https://your-domain.com/api/v3/users/1/roles \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{ "role_id": 1 }'
```
