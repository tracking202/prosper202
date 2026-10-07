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
| `user_name` | string | Yes | Username, 1-50 characters; another account's (in any case) is `409`. Changing it on `PUT` renames the account, as the Users page does: it needs a user you may manage, even when that is you |
| `user_email` | string | Yes | Email address, at most 100 characters; another account's is `422` |
| `user_pass` | string | Yes (create) | Password, 8-72 characters (bcrypt reads only the first 72 bytes), hashed server-side |
| `current_password` | string | When you change your **own** `user_pass` | Your present password, as Personal settings asks for it: a key that is not the password must not be enough to take over the sign-in. Missing or wrong is `422` naming `current_password`. A Super user or Admin resetting another user's password does not send it |
| `user_fname` | string | No | First name, at most 50 characters |
| `user_lname` | string | No | Last name, at most 50 characters |
| `user_timezone` | string | No | A PHP time zone name such as `America/New_York`, as Personal settings offers them (default: UTC); anything else is `422` |
| `user_active` | integer | No | `1` can sign in (default), `0` cannot; any other value is `422` rather than read as `0` |

## Preference Fields

`PUT /users/{id}/preferences` takes any subset of these. Each value is held to
the rule of the settings page that owns it, before anything is written; a key
not in this table is refused with `422` naming it rather than dropped, and a
free-text value given as `""` clears it. `GET` returns the whole preferences
row.

| Field | Values | Set on |
| ----- | ------ | ------ |
| `user_tracking_domain` | host such as `track.example.com`; `""` (what the installer leaves) builds a page's links on the address the page was opened on, and what is sent elsewhere on the server's own name ([FAQ](../tutorials-and-guides/11-frequently-asked-questions-faq.md#does-the-installer-set-my-tracking-domain)) | Personal settings |
| `user_daily_email` | hour `00`-`23` in your time zone, or `""` for never | Personal settings |
| `user_keyword_searched_or_bidded` | `searched`, `bidded` | Personal settings |
| `user_pref_referer_data` | `browser`, `t202ref` | Personal settings |
| `user_pref_dynamic_bid` | `0` (cost from the tracker), `1` (from the `t202b` parameter) | Personal settings |
| `user_pref_privacy` | `disabled`, `eu`, `all` (see [Privacy](#privacy-user_pref_privacy) below) | Personal settings |
| `user_pref_cloak_referer` | `origin`, `never` | Personal settings |
| `user_pref_ad_settings` | `show_all`, `hide_login`, `hide_all` | Personal settings |
| `user_account_currency` | a supported 3-letter code | Personal settings: **re-prices every campaign's payout** into it through the exchange-rate service, in the same transaction as the rest of the request; `502` and nothing written when the service gives no rate |
| `user_pref_time_predefined` | `today`, `yesterday`, `last7`, `last14`, `last30`, `thismonth`, `lastmonth`, `thisyear`, `lastyear`, `alltime` | report pages |
| `user_pref_limit` | `10`, `25`, `50`, `75`, `100`, `150`, `200` | report pages |
| `user_cpc_or_cpv` | `cpc`, `cpv` | report pages |
| `chart_time_range` | `hours`, `days` | Overview chart (kept in `202_charts`, where the chart reads it) |
| `user_slack_incoming_webhook` | an `https://` URL, or `""` | Integrations |
| `ipqs_api_key`, `cb_key`, `zaxaa_api_signature`, `jvzoo_ipn_secret_key` | up to 250 characters, or `""`; changing `cb_key` resets `cb_verified` | Integrations |
| `user_ltv_customer_cparam` | `0` (off), `1`-`4` (c1-c4) | LTV › Settings |
| `user_ltv_personalization_fields` | comma list of `first_name`, `last_name`, `company`, `city`, `country`, `cf:<field_key>`, `rec:next_offer` | LTV › Settings |
| `user_ltv_score_weights` | `volume:N,time:N,scroll:N,video:N,recency:N` summing to 100, or `""` for the defaults | LTV › Settings |
| `user_ltv_rec_fatigue` | `times,days` such as `3,21`, `0` for off, `""` for the defaults | LTV › Settings |

Not covered here: the daily email's send time is registered with the hosted
mail scheduler when Personal settings saves it; a change made here is stored
but not re-registered, so set the hour on that page if the email must move.

### Privacy (`user_pref_privacy`)

Which visitors are held back: no tracking cookies, and their address stored
masked — an IPv4 address keeps its /24 (`203.0.113.0`), an IPv6 one its /48.
That covers every row that keeps a visitor's address: the click, the
conversion's `ip`, the app intakes' `remote_ip` (Android installs and Apple
postbacks), and the error log the click path writes. And every cookie the
tracking links set: the click cookies on the tracker's site, the ones the
landing-page script sets on your page (`tracking202subid`,
`tracking202outbound`, `tracking202pci` and the personalization token), and
the `p202vid` visitor cookie, which a visitor held back is neither given nor
read for (see [Visitor identity](../features/visitor-identity.md)).

- `disabled`: nobody.
- `all`: every visitor.
- `eu` ("Enabled for European Traffic"): every visitor GeoIP does not place
  outside Europe and outside the European Union. That is the EU, the EEA
  (Norway, Iceland, Liechtenstein), Switzerland, the United Kingdom and the
  rest of Europe, France's overseas departments — and anyone GeoIP cannot
  place at all: a private or reserved address, a block allocated after the
  bundled database was built (it is GeoLite2 of 2018-07-03), or every visitor
  when the GeoIP library is missing. Holding back for more visitors than the
  EU's is deliberate: the GDPR applies across the EEA, the UK and Switzerland
  have laws of the same shape, and masking more costs a report precision,
  never a visitor privacy.

Which setting applies: the stricter of the install's (the first account's,
user 1) and the setting of the account the visitor's click belongs to — the
account that owns the tracker, landing page, campaign or click the request
names, and for the app intakes the account that owns the app (an
unregistered app's postback answers to the install's alone). A value that
cannot be read counts as `all`. Tracking links read the install's alone
before this release, so an account's own setting held back nobody unless the
install's did too.

What the mask changes downstream. The duplicate-click filter remembers
addresses for a day, and under privacy it can only remember the masked one,
so two visitors in one /24 within a day count as one and the second click is
filtered. The "don't count my own clicks" filter compares the address a click
arrived from with the address you signed in from (stored as it arrived; it is
an operator's record, like the sign-in log), so your own clicks are filtered
under every setting and nobody else in your /24 is. Rate limits on the public
endpoints key on the connection's address as it arrived and are not affected.

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
