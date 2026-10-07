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
| `GET` | `/ppc-accounts/{id}/pixels` | The pixels the account fires on a conversion ([Setup API](27-setup.md)) |
| `POST` | `/ppc-accounts/{id}/pixels` | Add one |
| `PUT` | `/ppc-accounts/{id}/pixels/{pixelId}` | Change one |
| `DELETE` | `/ppc-accounts/{id}/pixels/{pixelId}` | Remove one, with its correction URL (`?dry_run=1` previews) |

## Fields

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `ppc_account_name` | string | Yes | Account name (max 50) |
| `ppc_network_id` | integer | Yes | Parent PPC network ID |
| `ppc_account_default` | integer | No | Set as default account (0/1) |

An account's pixels — image, iframe, script, server-to-server postback (with
an optional correction URL) or raw markup, fired when one of its clicks
converts — are edited under `/ppc-accounts/{id}/pixels`, held to the account
form's rules (see the [Setup API](27-setup.md)).

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
