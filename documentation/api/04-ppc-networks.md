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
| `POST` | `/ppc-networks/chatgpt-ads` | Add ChatGPT Ads: the source, an account and its variables (below) |
| `GET` | `/ppc-networks/{id}/variables` | The source's custom variables ([Setup API](27-setup.md)) |
| `POST` | `/ppc-networks/{id}/variables` | Add one |
| `PUT` | `/ppc-networks/{id}/variables/{variableId}` | Change one |
| `DELETE` | `/ppc-networks/{id}/variables/{variableId}` | Retire one (`?dry_run=1` previews) |

## Fields

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `ppc_network_name` | string | Yes | Network name (max 50) |
| `ppc_network_platform` | string | No | `chatgpt_ads` for the ChatGPT Ads source, else `''` (the default). Set up the source with [Add ChatGPT Ads](#add-chatgpt-ads), or mark one you set up yourself here or on its edit form in Setup › Traffic Sources (under Advanced). The field is writable so `p202 sync` and `p202 import` carry it, and a source on no platform is sent without it. It also decides cost: once a source of the account is marked, ChatGPT Ads spend goes only to marked sources' clicks ([Cost from the spend ledger](11-reports.md#cost-from-the-spend-ledger)) |

A traffic source's custom variables are the extra `parameter=placeholder`
pairs its tracking links carry; they are edited under
`/ppc-networks/{id}/variables`, held to Setup › Traffic Sources' rules (see
the [Setup API](27-setup.md)).

## Add ChatGPT Ads

`POST /ppc-networks/chatgpt-ads`, with no body, is Setup › Traffic Sources'
**Add ChatGPT Ads** button. It makes the traffic source *ChatGPT Ads*, an
account on it, and the custom variables `campaign_id={campaign_id}` and
`ad_id={ad_id}`, which Ads Manager fills in. It is safe to call again: an
account that already has the ChatGPT Ads source gets no second one, only what
that source lacks (a live account, either variable), and nothing you changed
there (its name, a placeholder) is touched. `201` when the source was made,
`200` when it was already there:

```json
{
  "data": {
    "ppc_network_id": 12,
    "ppc_account_id": 30,
    "source_created": true,
    "source_adopted": false,
    "account_created": true,
    "variables_added": ["campaign_id", "ad_id"]
  }
}
```

The source is recognised by what this call stored on it, not by its name, so
renaming it afterwards is fine. A source you named "ChatGPT Ads" yourself is
not it until you call this: then, when it is the only live source of that name,
it becomes the ChatGPT Ads source (`source_adopted: true`; its name, accounts
and links are kept) instead of gaining a second source of the same name. The
source and account it makes are in the change feed (`GET /changes/ppc-networks`,
`/changes/ppc-accounts`) as a POST's are. It makes an account, so a scoped key
needs `ppc-accounts:write` as well as `ppc-networks:write`.
`GET /trackers/{id}/url` answers `chatgpt_ads` for a tracker on its account
([Trackers](06-trackers.md#get-tracking-url)). The CLI is
`p202 ppc-network add-chatgpt-ads`.

## Example

```bash
curl -X POST https://your-domain.com/api/v3/ppc-networks \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{ "ppc_network_name": "Google Ads" }'
```
