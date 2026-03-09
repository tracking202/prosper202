# PPC Accounts API

Manage pay-per-click advertising accounts within PPC networks.

## Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/ppc-accounts` | List PPC accounts (paginated) |
| `GET` | `/ppc-accounts/{id}` | Get a single account |
| `POST` | `/ppc-accounts` | Create an account |
| `PUT` | `/ppc-accounts/{id}` | Update an account |
| `DELETE` | `/ppc-accounts/{id}` | Delete an account |
| `POST` | `/ppc-accounts/bulk-upsert` | Bulk create/update |

## Fields

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `ppc_account_name` | string | Yes | Account name (max 255) |
| `ppc_network_id` | integer | Yes | Parent PPC network ID |
| `ppc_account_default` | integer | No | Set as default account (0/1) |

## Example

```bash
curl -X POST https://your-domain.com/api/v3/ppc-accounts \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "ppc_account_name": "Main Google Ads",
    "ppc_network_id": 1
  }'
```
