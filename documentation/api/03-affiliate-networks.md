# Networks API

Manage networks.

## Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/aff-networks` | List networks (paginated) |
| `GET` | `/aff-networks/{id}` | Get a single network |
| `POST` | `/aff-networks` | Create a network |
| `PUT` | `/aff-networks/{id}` | Update a network |
| `DELETE` | `/aff-networks/{id}` | Delete a network |
| `POST` | `/aff-networks/bulk-upsert` | Bulk create/update |

## Fields

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `aff_network_name` | string | Yes | Network name (max 255) |
| `dni_network_id` | integer | No | Direct Network Integration ID |

## Example: Create Network

```bash
curl -X POST https://your-domain.com/api/v3/aff-networks \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{ "aff_network_name": "MaxBounty" }'
```
