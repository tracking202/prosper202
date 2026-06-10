# PPC Networks API

Manage pay-per-click ad networks (Google Ads, Bing Ads, Facebook, etc.).

## Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/ppc-networks` | List PPC networks (paginated) |
| `GET` | `/ppc-networks/{id}` | Get a single PPC network |
| `POST` | `/ppc-networks` | Create a PPC network |
| `PUT` | `/ppc-networks/{id}` | Update a PPC network |
| `DELETE` | `/ppc-networks/{id}` | Delete a PPC network |
| `POST` | `/ppc-networks/bulk-upsert` | Bulk create/update |

## Fields

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `ppc_network_name` | string | Yes | Network name (max 255) |

## Example

```bash
curl -X POST https://your-domain.com/api/v3/ppc-networks \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{ "ppc_network_name": "Google Ads" }'
```
