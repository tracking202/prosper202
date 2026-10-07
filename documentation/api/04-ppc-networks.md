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
| `GET` | `/ppc-networks/{id}/variables` | The source's custom variables ([Setup API](27-setup.md)) |
| `POST` | `/ppc-networks/{id}/variables` | Add one |
| `PUT` | `/ppc-networks/{id}/variables/{variableId}` | Change one |
| `DELETE` | `/ppc-networks/{id}/variables/{variableId}` | Retire one (`?dry_run=1` previews) |

## Fields

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `ppc_network_name` | string | Yes | Network name (max 50) |

A traffic source's custom variables are the extra `parameter=placeholder`
pairs its tracking links carry; they are edited under
`/ppc-networks/{id}/variables`, held to Setup › Traffic Sources' rules (see
the [Setup API](27-setup.md)).

## Example

```bash
curl -X POST https://your-domain.com/api/v3/ppc-networks \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{ "ppc_network_name": "Google Ads" }'
```
