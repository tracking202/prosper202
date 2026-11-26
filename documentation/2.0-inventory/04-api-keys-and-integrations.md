# API keys and integrations

This area covers the pages where an account connects Prosper202 to other systems: Account › 3rd Party API Integrations (`202-account/api-integrations.php`, with its AJAX endpoint `202-account/ajax/dni.php` and its own `?cb_status=1` fragment), the REST API keys panel of Personal Settings (`202-account/account.php#api-keys`), and the AI-assistant approval page that mints an API key for Tracking202's MCP server (`202-account/connect-ai.php`). Everything below was read from the code at the 1.9.81 branch. The rest of Personal Settings (profile, click filtering, money-loss alerts, currency, the Prosper202 customer API key, ClickServer key, password) and the licence-key pages (`api-key-required.php`, `app-key-required.php`) are not in this file. They are mentioned only where this area depends on them.

| Page | Path | Permission |
|---|---|---|
| API integrations (menu: My Account › 3rd Party API Integrations) | `/202-account/api-integrations.php` | Signed in (`AUTH::require_user()`) and `access_to_api_integrations` |
| Personal settings › API keys (panel) | `/202-account/account.php#api-keys` | Signed in, and `access_to_personal_settings` to see or use the panel |
| Connect an AI assistant | `/202-account/connect-ai.php` | Signed in, and `access_to_personal_settings` to approve |

In the seeded roles (`202-config/Database/DataSeeder.php`), `access_to_api_integrations` (permission 19) is held by role 1 "Super user" and role 2 "Admin" only. Roles 3 to 5 (Campaign manager, Campaign optimizer, Campaign viewer) do not hold it.

---

### API integrations: `/202-account/api-integrations.php`

- **Where / who**
  - URL `202-account/api-integrations.php`. Menu: My Account dropdown › "3rd Party API Integrations" (`template.php`, id `3rdPartyAPIPage`). The entry is shown only when the user has `access_to_api_integrations`.
  - The page calls `AUTH::require_user()`: someone who is not signed in is sent to sign in.
  - A signed-in user without `access_to_api_integrations` gets `Location: 202-account/` and the script exits. Nothing is shown.
  - Inside the page, links to Setup › Inbound Log are rendered only when the user also has `access_to_setup_section`: the readiness text, the Shopify status lines and the currency-mismatch warning. Without that permission the sentences render without the links.
  - Page title `API Integrations`; header "API integrations", icon `bi-plug`. Header text: "Connect Prosper202 to your affiliate networks and tools. Everything here is optional; connect only what you use."
- **Purpose**: one page of cards, each of which configures how a third party talks to this install. Three kinds of card: inbound postback/IPN/webhook verification (postback authentication, ClickBank, Zaxaa, JVZoo, PayKickstart, Shopify); outbound services (Slack, IPQualityScore, Direct Network Integration, Landing Page Optimizer); and a replay of held payment notifications.
- **Layout (in render order)**: Postback authentication (full width, `#postback-auth`); Direct Network Integration (full width, `#dni`); then half-width cards: Landing Page Optimizer (`#lpo`), IPQualityScore (`#ipqs`), ClickBank (`#clickbank`), Zaxaa (`#zaxaa`), Slack (`#slack`), JVZoo (`#jvzoo`), PayKickstart (`#paykickstart`), Shopify (`#shopify`). Each card is a `section.p202-panel` with the anchor id shown. Every write redirects back to `…#<anchor>` (HTTP 303 via `p202_account_redirect()`). Flashes render in a `[data-p202-flashes]` block, which `p202-ui.js` moves into the panel the fragment names.

#### Data read on every load
- `202_users` row of `$_SESSION['user_own_id']`: `user_name`, `install_hash`, `p202_customer_api_key`. A failed query goes to `record_mysql_error()`, the error page, which exits.
- `SELECT * FROM 202_users LEFT JOIN 202_users_pref USING (user_id) WHERE user_id = $_SESSION['user_id']`. This is the row every card reads. Columns used: `cb_key`, `cb_verified`, `user_slack_incoming_webhook`, `zaxaa_api_signature`, `ipqs_api_key`, `jvzoo_ipn_secret_key`, `jvzoo_roleless_role`, `paykickstart_ipn_secret_keys`, `lpo_status`, `lpo_site_key`, `lpo_bridge_config`, `lpo_ctx_kw`, `p202_customer_api_key`, `install_hash`, `user_account_currency`. `AUTH::begin_user_session()` sets `user_id` and `user_own_id` to the same value at sign-in.
- DNI network catalogue: `getAllDniNetworks(install_hash)` makes an outbound GET to `https://my.tracking202.com/api/v2/dni/<install_hash>/networks/all`, validated by `dniNetworkList()` (a list whose items have `networkId`, `networkType`, `name`). Anything else counts as unavailable.
- The account's DNI rows: `Prosper202\Setup\DniNetworks::listFor()` reads `202_dni_networks` with `user_id = session user`, ordered by id. Keys are never selected: only `CHAR_LENGTH(apiKey)` and `RIGHT(apiKey,4)`, which become a hint. A failure goes to `record_mysql_error()`.
- Slack: `Slack::forAccount($db, user_id)`, used to push change notices.
- Postback authentication: `PostbackAuthStore::ensureCredentials(user_id, now)`. This mints the token and signing secret on first view, as every place that hands out postback URLs does, and writes `202_postback_auth`. Then `PostbackAuthReadiness::forAccount()`, which reads `202_inbound_log` for the last 7 days.
- Payment-card state (`p202_payment_card_state()`): `HeldNotifications::counts()` (table `202_held_payment_notifications` via `TableRegistry::HELD_PAYMENT_NOTIFICATIONS`) and `RolelessTraffic::recent()` over the inbound log (30-day window). Each read is logged and left empty if it fails.
- IPQS note: `p202_ipqs_card_note()`, which calls `IpqsScoring::status()` and `FraudVerdictUndo::lastDay()`.
- Shopify: `IntegrationSecretStore::open()` / `marker()` on `202_integration_secrets` (name `shopify_webhook_secret`). When connected, also `WebhookStatus::of()` (inbound log) and `RefundsToSettle::list()`.
- A per-session secret `$_SESSION['paykickstart_key_secret']`, 64 hex characters, is created if missing. It is used to sign PayKickstart removal tokens.

#### Card: Postback authentication (`#postback-auth`)
- **Pill**: "Unreadable" (the store could not be read), "Not required" (mode `off`), "Token or signature" (`token`), or "Signed only" (`signed`).
- **Text**: explains `p202_token` in URLs, and `p202_ts` + `p202_sig` HMAC signing with the signing secret. Notes that a pixel in a browser cannot sign.
- **Recovery notice**: `$pbAuth->recoveryNotice()` is shown as a warning alert when credentials had to be re-minted.
- **"Who would be refused" (readiness, last `WINDOW_DAYS` = 7 days)**:
  - Readiness unreadable: red text "This could not be checked just now…".
  - `requests === 0`: "No postback or pixel has arrived in that time…" (`data-postback-ready="unknown"`).
  - All carried credentials: pill "Safe to require the token" with the request count (`data-postback-ready="yes"`).
  - None without credentials but some unread: an "unknown" sentence giving `unread` of `requests`.
  - Otherwise (`data-postback-ready="no"`): pill "Not yet safe to require the token" (mode off) or "Being refused" (mode on), with `without_credentials` of `requests`, and a link to Setup › Postback / Pixel (`tracking202/setup/get_postback.php`). With `access_to_setup_section` and mode on, also a link to `tracking202/setup/inbound_log.php?outcome=unauthenticated`.
  - Senders table (`#postback-auth-senders`), at most `MAX_SENDERS` = 50 rows. Columns:
    - Endpoint: pb → "Postback (pb.php)", gpb → "Global postback (gpb.php)", px → "Pixel (px.php)", gpx → "Global pixel (gpx.php)", upx → "Universal pixel (upx.php)".
    - Campaign: the campaign name plus its network name in brackets. When unknown: "not known", or "not known; subid …" with up to 3 subids.
    - Carried: none → "nothing", invalid → "a wrong token or signature", token → "the token", signed → "a signature".
    - Requests; Conversions recorded; Last one (`date('M j, g:i a')`).
    - A row whose sender carried nothing or a wrong credential gets class `table-warning`.
  - `more_senders > 0`: "N more with fewer requests are not listed", plus an Inbound Log link with `access_to_setup_section`.
  - Shown when `token_only + pixels > 0` and the mode is not signed: a note that Signed-only would also refuse them.
  - Shown when `covers_since > since`: a note on how far back the log reaches.
- **Form 1, mode** (POST, `data-p202-confirm`). Confirm text: "Change what your postbacks and pixels must carry? …", with " In the last 7 days N came without your token." added when the mode is off and N > 0.
  - Hidden fields: `token`, `p202_shown[postback_auth_mode]` (version of `{mode}`), `postback_auth_action=mode`.
  - Radio `mode`: `off` "Not required", `token` "Token or signature", `signed` "Signed postbacks only", each with a help line. The current mode is checked.
  - Button "Save".
- **Forms 2 and 3, rotate** (one each for `token` and `signing_secret`, each behind `data-p202-confirm` "Replace your token? / signing secret? Everything that uses the old one must move…").
  - The current value is shown in clear in a `<code data-postback-token>` / `<code data-postback-signing-secret>`, with "made <date>".
  - When a replaced one still works: "The one it replaced keeps working until <date>".
  - Hidden fields: `token`, `postback_auth_action=rotate`, `credential` (`token` | `signing_secret`).
  - Select `grace_hours` labelled "The old one keeps working": `168` "for 7 days" (default selected), `24` "for 1 day", `720` "for 30 days", `0` "not at all (it leaked)". Button "Rotate".
- **Server validation**:
  - Mode not one of off, token, signed → flash bad "Choose what your postbacks and pixels must carry."
  - Rotate with an unknown credential, or `grace_hours` not one of `0`, `24`, `168`, `720` → flash bad "Choose how long the old one keeps working: not at all, a day, a week or 30 days."
  - The mode save first runs `p202_account_require_shown()`; the rotate does not.
- **Writes**:
  - `PostbackAuthStore::setMode()`: `UPDATE 202_postback_auth SET auth_mode, updated_at` and re-ensures credentials, in one transaction.
  - `PostbackAuthStore::rotate()`: writes the new token or secret (sealed, aes-256-gcm under a key from `202_deployment_secrets` `postback_token`). The previous value is kept with `previous_*_expires_at = now + grace` when grace > 0 and the old value was readable. At most two work at once.
- **Flashes**:
  - Mode: off → "Every postback and pixel for this account is accepted again, with or without credentials." token → "Postbacks and pixels without your token or a signature are now refused." signed → "Only signed postbacks are accepted now: …".
  - Rotate: "Your postback token was replaced; the old one stopped working. Copy your postback URLs and pixels again from Setup › Postback / Pixel." or "…signing secret was replaced; … Give the new one to everything that signs." With grace: "…the old one keeps working for N day(s) while you move to the new one."
  - A recovery notice is added as a bad flash. On any throwable: "This could not be saved just now; nothing changed. Try again."

#### Card: Direct Network Integration (`#dni`)
- **Header**: help link (`showHelpUrl('dni')`, a click202.com redirect, new tab). Pill "1 network connected" / "N networks connected", or "Not connected".
- **Text**: "Search, apply to and set up offers from your affiliate networks without leaving Prosper202."
- **Errors**: `$error['dni_network']` and `$error['dni_network_auth']` are shown as bad flashes inside the card. They render in place: no redirect.
- **Networks table** (when the account has rows). Columns:
  - Network: favicon 16×16 from `favIcon` when set; `name (type)` with `shortDescription` as its title.
  - While `processed = 0`, a "Processing offers…" progress bar `[data-dni-progress=<id>]` inside `#network-<id>`.
  - API key: the hint, e.g. "Saved, ends in XXXX", or "Saved" for a key shorter than 12 characters.
  - Affiliate ID.
  - Actions: "Edit" link (`?edit_dni_network=<id>#dni`) and a "Remove…" POST form. Hidden `delete_dni_network=<id>` and `token`; confirm "Remove <name>? Its offers stay in your campaigns but stop updating, and its DNI affiliate network is retired."
- **Catalogue unreachable**: warning "The list of DNI networks could not be loaded from the Prosper202 service just now, so a network cannot be added. Reload the page to try again." No form.
- **Add/Edit form** (`data-dni-form`). Action `#dni`, or `?edit_dni_network=<id>#dni` when editing.
  - Hidden fields: `token`; `dni_network_type`; `dni_network_name`. The page's JS fills type and name from the selected option: type from `data-type`, name from the option text "Name (Type)".
  - When editing, also: `p202_shown[dni_network]` (version of `{network: row}`), `editing_dni_network=1`, `editing_dni_network_id=<id>`.
  - `dni_network` select, required. Label "Add a network" / "Network". First option "Select network" (value ''), then one per catalogue network (`networkId`, text `name (networkType)`). Preselected from POST, then the edited row, then `?add_dni_network=` from the query string.
  - `dni_network_api_key` text. Label "API key" (required when adding) or "New API key" (when editing; placeholder "Key <hint>; leave empty to keep it"). Never prefilled; autocomplete off.
  - `dni_network_affiliate_id` text "Affiliate ID". Shown and required only when the type is `Cake`. For other types the JS hides it, unrequires it and sets the value to the literal `null`.
  - Buttons "Cancel" (when editing) and "Add network" / "Save changes". Help text: "HasOffers networks need only the API key; Cake networks also ask for your affiliate ID." Editing adds the keep-key sentence.
- **Server validation (POST with `dni_network`)**:
  - Required: `dni_network`, `dni_network_type`, `dni_network_name`, `dni_network_affiliate_id`, and `dni_network_api_key` when adding (trimmed non-empty). Otherwise "Make sure all fields are selected and filled out!"
  - An edit id is read with `apiint_dni_id()`: `^[1-9][0-9]{0,9}$`, at most 4294967295. Not valid → "That network was not found; nothing was changed."
  - An edit of an owned network first runs `p202_account_require_shown()`. If the network's row changed since the page loaded: warn flash `P202_ACCOUNT_SHOWN_STALE` and a reload.
  - An edit with an empty key keeps the stored one (`DniNetworks::keptKey()`). It is refused with "A different network needs its own API key: paste it to save the change." when the network changed, "That network was not found…", or "The network could not be saved, and nothing changed; try again."
  - Then `authDniNetworks(install_hash, network, key, affId)`: outbound POST `https://my.tracking202.com/api/v2/dni/<hash>/auth/<network>` with `api_key`, `affiliate_id`, `host` (`base64(p202TrackingBaseUrl())`). A non-200 → `$error['dni_network_auth']` "Can't authenticate with provided credentials. Try again!"
- **Writes**:
  - **Add**, in one `Connection::transaction()`:
    - `INSERT INTO 202_dni_networks SET user_id, networkId, name` (text before " (" of the posted name), `type, apiKey, time, processed` (from the auth answer), `shortDescription, favIcon` (from the catalogue). `affiliateId` is set only for `Cake`.
    - Then `INSERT INTO 202_aff_networks SET dni_network_id = <insert id>, user_id, aff_network_name = '<name> (DNI)', aff_network_time`.
    - Success flash: "<name> network configured. API processing can take up to 5 minutes." Failure: "The network could not be saved, and nothing was added; try again."
  - **Edit**, in one transaction:
    - `UPDATE 202_dni_networks SET networkId, name, type, apiKey, time[, affiliateId] WHERE id AND user_id`. Zero affected rows is "not found".
    - Then `UPDATE 202_aff_networks SET aff_network_name = '<name> (DNI)', aff_network_time WHERE dni_network_id AND user_id`.
    - Flash: "DNI network updated. API processing can take up to 5 minutes."
  - **Remove** (POST `delete_dni_network`, read with an `(int)` cast):
    - `DELETE FROM 202_dni_networks WHERE id AND user_id`. If a row went, `UPDATE 202_aff_networks SET aff_network_deleted = '1', aff_network_time WHERE dni_network_id AND user_id`.
    - Flashes: ok "The network is removed. Its offers stay in your campaigns; they stop updating."; bad "The network could not be removed; try again."; bad "The network is removed, but its campaign category could not be retired…"; warn "That network was not found; nothing was removed."
  - The retired GET `?delete_dni_network=…` writes nothing. It flashes warn "Removing a network now asks first. Use remove beside the network below." and redirects.
  - `?edit_dni_network=<id>` for a network that is not the account's shows warn "That DNI network is not one of yours, or it was removed."
- **AJAX: `202-account/ajax/dni.php`** (`AUTH::require_user()` and `AUTH::require_permissions('access_to_api_integrations')`).
  - Poll: the page's JS runs it only when a progress bar exists, once at load and then every 3000 ms. Request: `POST ajax/dni.php?getProgress=true`, no body.
    - The server builds the request from the account's own rows (`DniNetworks::progressRequest()`: install hash plus networks with `processed = 0`, keys included).
    - It calls `getDNICacheProgress()`, a POST to `https://my.tracking202.com/api/v2/dni/<hash>/cache/progress` (JSON). With `CURLOPT_RETURNTRANSFER => false`, the service's body is streamed straight into the response.
    - Answers: no importing networks → `{"data":[]}`; account not found → 404; DB failure → 500; service unreachable → 502.
    - The JS reads `json.data[]` items `{id, progress}`, sets each bar's width, text and `aria-valuenow`.
  - Mark processed: when an item's progress is `100`, `POST ajax/dni.php?updateStatus=true&dni=<id>` with body `token=<session token>`.
    - The server checks POST plus `AUTH::check_csrf_token()`; otherwise 403 "Invalid token." Then `UPDATE 202_dni_networks SET processed = '1' WHERE id AND user_id`, 500 on failure.
    - The JS removes `#network-<id>` when the response is ok.

#### Card: Landing Page Optimizer (`#lpo`)
- **Pill**: "Connected" (`lpo_status='active'`, webhook receiving); "Not receiving events" (webhook dead, off or gone); "Status unknown"; "Upgrade needed" (no `lpo_status` column); "Subscription required"; "Action needed" (an error); "Not connected".
- **Text**: "Run hosted A/B experiments on your landing pages. Connecting registers a signed conversion webhook; nothing else changes on this install."
- **When connected**:
  - Status from `PairingWebhook::state()`: the `webhook_id` in `lpo_bridge_config` JSON, read from `202_ltv_webhooks`. `PairingWebhook::notice()` text is shown as a flash, e.g. "The optimizer is not receiving events: its webhook (#N) was stopped after a delivery to it failed 6 times. Fix the endpoint, then revive the webhook in LTV Settings."
  - "Site key" with Copy (the `lpo_site_key` value, in clear).
  - Buttons: "LTV Settings" (`tracking202/analyze/ltv.php?view=settings`, only when the webhook is dead); "Manage experiments" (`<saas>/api/customers/experiments`, new tab); "Disconnect…" (POST `lpo_action=disconnect`, confirm "Disconnect the Landing Page Optimizer? The pairing webhook will be removed.").
  - An Advanced disclosure (`data-p202-remember="account-lpo-privacy"`, open state kept in localStorage) holds the keyword-privacy form:
    - Hidden `lpo_ctx_kw_save=1`, `token`, `p202_shown[lpo_ctx_kw]`.
    - Checkbox `lpo_ctx_kw` value `1`, "Include keyword text in optimizer context tokens", checked unless the stored value is `'0'`. Button "Save".
  - Footer: "Bridge v<EventBridge::BRIDGE_VERSION> · <capability events>, wildcard subscribe, …".
- **Not connected**:
  - With a customer API key (`202_users.p202_customer_api_key`): a "Connect" button (POST `lpo_action=connect`).
  - Without one: empty state "A customer API key comes first" with "Get your API key" (`<saas>/api/customers/login?redirect=get-api`).
  - After a subscription error: a warning flash and "Get Landing Page Optimizer" (external), plus "I’ve subscribed, connect" (POST `lpo_action=connect`, `lpo_retry=1`).
  - The SaaS base is `LPO_SAAS_BASE_URL` when defined, else `https://my.tracking202.com`.
- **Connect**, in order:
  - Refusals, each shown as `$error['lpo']` in place: no `lpo_status` column → "Run the Prosper202 upgrade first…"; already active → "Already connected. Disconnect first to re-pair."; empty customer key → "A Prosper202 Customer API key is required to connect — use the button below to get yours."; empty install hash → "This account has no install hash, so the install cannot pair. Log in as the account owner (user 1) to connect."
  - `PairingClient::pairInit()`: POST `<saas>/api/v2/lpo/pair/init` with `customers_api_key`, `install_hash`, `install_url` (from `TrackingBaseUrl::forRequest`), `bridge_version`, `p202_version`, `capabilities`. It needs `site_key` and `hook_url` back.
  - `MysqlWebhookRepository::create(user, hook_url, ['*'], false)` inserts a wildcard row into `202_ltv_webhooks` with a hex secret (not `whsec_`).
  - `pairComplete()`: POST `/api/v2/lpo/pair/complete` with `p202_webhook_id` and `webhook_secret`. If it fails, the webhook is deleted again.
  - Then `202_users_pref` gets `lpo_site_key`, `lpo_status='active'`, and `lpo_bridge_config` = JSON `{webhook_id, ctx_key: hex(CtxToken::deriveKey(secret))}`.
  - The memcache key for rtr.php's `SELECT lpo_status, lpo_bridge_config, lpo_ctx_kw …` is busted, and `Analytics::event('lpo_paired', {via: ui})` is sent.
  - Redirect to `?lpo=connected#lpo`, which flashes "Landing Page Optimizer connected."
  - A `PairingRequestException` whose user message contains "subscription" switches the card to the subscription state. Its warning reads "Landing Page Optimizer runs on a Landing Page Optimizer plan…" or, after the retry button, "We still don’t see an active … subscription…". Any other failure message becomes `$error['lpo']`.
- **Disconnect**:
  - Deletes the `202_ltv_webhooks` row named in `lpo_bridge_config`; an already-missing row is ignored.
  - `pairDisconnect()`: POST `/api/v2/lpo/pair/disconnect`, best effort and only logged on failure.
  - Clears `lpo_site_key`, `lpo_status` and `lpo_bridge_config` to `''`, busts the memcache key, and redirects to `?lpo=disconnected#lpo` ("Landing Page Optimizer disconnected.").
- **Keyword privacy save**:
  - Runs only when the `lpo_ctx_kw` column exists. Runs `p202_account_require_shown()`.
  - When the value changes, writes `lpo_ctx_kw` `'1'` / `'0'` and busts memcache.
  - Flash "Context token preference saved." Failure: "The context token preference could not be saved; try again."

#### Cards: IPQualityScore, ClickBank, Zaxaa, Slack (`#ipqs`, `#clickbank`, `#zaxaa`, `#slack`): one secret, one Save
All four share one renderer and one handler (`processApiKeyUpdate()`).

| Card | Icon | Help link | Endpoint shown | POST flag | Field name and column (`202_users_pref`) | Label | Empty-field message |
|---|---|---|---|---|---|---|---|
| IPQualityScore | ipqs.png | none | none | `change_ipqs_api_key` | `ipqs_api_key` (varchar 250) | IPQS API key | "The IPQualityScore API Key can't be empty!" |
| ClickBank | clickbank.png | `showHelpUrl('clickbank')` | "INS URL" `<trackingBase>tracking202/static/cb202.php` | `change_cb_key` | `cb_key` (varchar 250) | ClickBank secret key | "Clickbank Secret Key can't be empty!" |
| Zaxaa | zaxaa.png | none (`showHelpUrl('zaxaa')` has no case and returns '') | "ZPN URL" `<trackingBase>tracking202/static/zpn.php` | `change_zaxaa_api_signature` | `zaxaa_api_signature` (varchar 250) | Zaxaa API signature | "Zaxaa API signature can't be empty!" |
| Slack | slack.png | `showHelpUrl('slack')` | none | `change_user_slack_incoming_webhook` | `user_slack_incoming_webhook` (text) | Slack incoming webhook URL | "Slack Incoming Webhook URL can't be empty!" |

- **Base URL**: `<trackingBase>` is `p202TrackingBaseForResponse()`, the stored tracking domain with its saved scheme, else the request's. Each endpoint URL renders in a `p202-code` block with a Copy button (`data-p202-copy`).
- **Descriptions**:
  - IPQS: `p202_ipqs_card_desc()` (fraud threshold `IpqsScoring::FRAUD_THRESHOLD`) and an external link "Get a free API key".
  - ClickBank: "put tid=[[subid]] on your HopLink."
  - Zaxaa: "put custom=[[subid]] on your Zaxaa order link and paste the ZPN URL into the product’s Notification Handler URL…"
  - Slack: "Send Prosper202 notifications into a Slack channel through a Slack incoming webhook."
- **Pill**: ClickBank shows "Unavailable" when `openssl_decrypt` is missing. Otherwise "Not connected" while no value is saved. When saved: ClickBank shows "Verified" or "Unverified" (from `cb_verified`, in `[data-cb-status]`); the others show "Connected".
- **ClickBank without OpenSSL**: a bad flash "The OpenSSL PHP extension, which decrypts ClickBank’s notifications, is required…". The URL and the form are hidden.
- **IPQS extra note** (`p202_ipqs_card_note()`):
  - A privacy paragraph, always shown: what is sent to ipqualityscore.com and the EU/EEA/UK/CH exclusions.
  - Once a key is saved: "In the last 24 hours: N addresses scored, N clicks filtered for the score." (`FraudVerdictUndo::lastDay`).
  - Then one of: the last lookup failure ("The last lookup failed (<time>): <error> Scoring tries again after <time>."), "Last lookup: <time>.", or "No click has been looked up yet: scoring starts with the next clicks."
  - Read failures become warn flashes "The scoring status could not be read." and "The scoring counts could not be read."
- **Zaxaa extra**: the currency-mismatch notice `p202_currency_mismatch_notice('zaxaa', 'Zaxaa')`, described under "Held notifications" below.
- **Saved value line**: "<label>: Saved, ends in XXXX" / "Saved" / "Not saved" (`SavedSecret::hint`, ending shown only from 12 characters).
- **Form** (inside a `details` that is open after an error). Summary "Connect" / "Update".
  - Hidden fields: the flag `=1`, `token`, `p202_shown[<field>]`.
  - Text field `<field>`: label "<label>" or "New <label>". Required when nothing is saved; placeholder "Leave empty to keep the saved one" when saved. Never prefilled. Button "Save".
  - ClickBank, when saved: a button "Check verification again" (`data-cb-check`), described below.
- **Handler**:
  - Runs `p202_account_require_shown(<field>)`.
  - An empty or non-string posted value with a saved value: flash ok "Nothing changed: your saved <label> is kept."
  - An empty value with nothing saved: the empty-field message above, rendered under the field. The test is PHP `empty()`, so `"0"` counts as empty.
  - Slack only: `SlackWebhook::accepted()` (trim plus `WebhookGuard::webhookRefusal()`). A refusal is shown under the field.
  - The other three are stored exactly as posted, not trimmed, with no format or length check in the page. The column width is 250.
  - When the value changed: `UPDATE 202_users_pref SET <field>`. For `cb_key` also `cb_verified = '0'`. Failures: "This could not be saved just now; the old value is still in place. Try again." or "The key is saved, but it still shows as verified; save it again."
  - After a save (also when the value was unchanged): Slack push `<field>_updated` when the value changed (`cb_key_updated`, `user_slack_incoming_webhook_updated`, `zaxaa_api_signature_updated`, `ipqs_api_key_updated`); `Analytics::event('integration_configured', {via: ui, integration: <anchor>})`; flash "Your <label> was saved."
- **ClickBank verification check**: `GET api-integrations.php?cb_status=1`.
  - It runs before any POST handling and needs no token, only the page's sign-in and permission. It reads `cb_verified` and answers only an HTML pill (`Verified` / `Unverified`). A failed read answers HTTP 503 `<span class="p202-pill p202-pill--bad">Could not check</span>`.
  - The JS fetches it (same-origin, GET), disables the button while waiting, and replaces `[data-cb-status]` with the answer, or with "Could not check" on a network error.
  - `cb_verified = 1` is written by `ClickBankAdapter` when a ClickBank test notification verifies.

#### Card: JVZoo (`#jvzoo`)
- **Pill**: "Connected" (the key is saved and readable by `JVZooKey`), "Key unreadable", or "Not connected". There is no help link: `showHelpUrl('jvzoo')` has no case.
- **Body**: two instruction blocks, each with its URL and Copy.
  - Affiliate: "Affiliate IPN URL" `<trackingBase>tracking202/static/jvzoo.php?role=affiliate`; offer URL with `?tid=[[subid]]`.
  - Vendor: "Vendor JVZIPN URL" `…jvzoo.php?role=vendor`; buy link with `?subid=[[subid]]`, JVZIPN v1.
  - A note on "Add them up" for rebills, and the saved-key hint "JVZIPN secret key: …".
- **Roleless notice** (`p202_jvzoo_roleless_notice()`):
  - Shown when JVZoo still sends to the 1.9.56 URL (`jvzoo.php` without `?role=`) and there is a conflict, or the URL is read as affiliate but the traffic looks like a vendor's. Counts come from the last 30 days.
  - With a one-click form "Read the 1.9.56 URL as a vendor’s": `change_jvzoo_roleless_role=1`, `jvzoo_roleless_role=vendor`, `token`, `p202_shown[jvzoo_roleless_role]`.
- **Key form** (in a disclosure):
  - Hidden `change_jvzoo_secret_key=1`, `token`, `p202_shown[jvzoo_key]`.
  - Text `jvzoo_ipn_secret_key`: label "JVZIPN secret key" or "New JVZIPN secret key". Required when nothing is saved; placeholder "Leave empty to keep the saved one". Button "Save".
- **Key validation** (`JVZooKey::parse`): trimmed. At most 250 bytes ("The key is N characters long; at most 250."). Must be valid UTF-8 with no control characters ("The key has a character that is not text…").
- **Key outcomes**:
  - Empty with a valid saved key: "Nothing changed: your saved JVZoo secret key is kept."
  - Empty with nothing usable: "[The saved key cannot be used. ]Paste your JVZIPN secret key: JVZoo shows it under My Account › Account Details › Seller Settings."
  - Changed: `UPDATE 202_users_pref SET jvzoo_ipn_secret_key`, then Slack `jvzoo_secret_key_updated` (when changed), `Analytics integration_configured jvzoo`, and flash "Your JVZoo secret key was saved."
  - DB failure: "This could not be saved just now; the old value is still in place. Try again."
- **"Upgraded from Prosper202 1.9.56?" disclosure**:
  - Open when the inbound-log read failed, the 1.9.56 URL is in use, or the stored role is vendor.
  - Form: `change_jvzoo_roleless_role=1`, `token`, `p202_shown[jvzoo_roleless_role]`, radio `jvzoo_roleless_role`: `affiliate` "I promote JVZoo products (affiliate)", `vendor` "I sell my own product on JVZoo (vendor)". Default is affiliate. Button "Save".
  - Server: a value other than affiliate or vendor → "Choose affiliate or vendor for the 1.9.56 JVZIPN URL." Otherwise `require_shown`, `UPDATE 202_users_pref SET jvzoo_roleless_role`, and flash "Saved: the 1.9.56 JVZIPN URL reads your notifications as a vendor's." / "…an affiliate's." DB failure: "This could not be saved just now; the old choice is still in place. Try again."

#### Card: PayKickstart (`#paykickstart`)
- **Pill**: "1 key stored" / "N keys stored", or "Not connected". There is no help link.
- **Body**:
  - Instructions: `subid=[[subid]]` on the checkout link; paste the URL into each product's IPN Integration tab; store each PayKickstart campaign's IPN secret key.
  - "IPN URL" `<trackingBase>tracking202/static/paykickstart.php` with Copy. The currency-mismatch notice for `paykickstart`.
  - A closing note that PayKickstart's Affiliate IPN cannot be used.
- **Stored keys list**, one row per readable key (`PayKickstartKeys::stored`, column `paykickstart_ipn_secret_keys`, newline-separated):
  - Name: "Key ending …XXXX" for keys of 12 or more characters, else "Key N".
  - A "remove" form, confirm "Remove this key? PayKickstart IPNs signed with it are refused until it is stored again."
  - Its fields: `token`, `p202_shown[paykickstart]`, `paykickstart_key_action=remove`, `paykickstart_key_place=<1-based index>`, `paykickstart_key_token=<HMAC-SHA256('paykickstart-ipn-key-removal|'+key, session secret)>`.
- **Add form**: summary "Connect" / "Add a key".
  - Fields: `paykickstart_key_action=add`, `token`, `p202_shown[paykickstart]`, required text `paykickstart_ipn_secret_key` "IPN secret key". Button "Save".
- **Validation** (`PayKickstartKeys`):
  - The pasted text is split on whitespace and commas, so several keys can be pasted at once.
  - Each key must match `[\x21-\x2B\x2D-\x7E]+` (printable ASCII except comma) and be 8 to 128 characters. Errors read "The key / Key N has a character…" or "…is N characters long; a PayKickstart IPN secret key is 8 to 128."
  - At most 20 keys in total. Duplicates are dropped.
  - Empty paste: "Paste the IPN secret key of a PayKickstart campaign." All pasted keys already stored: "That key is stored already."
  - Remove with a stale place or token: "The stored keys changed since this page was loaded, so nothing was removed. Look again and remove it from the list below." (Shown at the top of the card.)
- **Writes**:
  - Runs `require_shown('paykickstart')`, which re-reads the list under the settings lock, then `UPDATE 202_users_pref SET paykickstart_ipn_secret_keys = <list joined by \n>`.
  - Slack `paykickstart_ipn_keys_updated` when the list changed; `Analytics integration_configured paykickstart` on add.
  - Flash "Your PayKickstart IPN secret key was saved; N keys are stored." / "The key was removed; N keys are stored." DB failure: "This could not be saved just now; the stored keys are as they were. Try again."

#### Card: Shopify (`#shopify`)
- **Pill**: "Unverified webhook seen"; "Receiving webhooks"; "No webhook yet"; "Secret saved" (status unread); "Secret unreadable" (the store could not open it, or it fails `ShopifyWebhookSecret` rules); "Not connected".
- **Status list** (`#shopify-status`, when connected and readable):
  - A webhook arrived (`WebhookStatus::ago`) that no saved secret verifies. These are inbound-log rows under `user_id = 0`, `outcome = 'unverified'`. With a link to `inbound_log.php?endpoint=shopify&outcome=unverified` (`access_to_setup_section` only).
  - "No webhook signed with your secret has arrived yet…", or "Last webhook: <topic> <ago>, <outcome>." and "Last order recorded <ago>." / "No order has been recorded yet…".
  - "N[+] refunds are not recorded and still counted as revenue." with a link "Record or close them" to `tracking202/setup/inbound_log.php#shopify-refunds`.
  - Status unreadable: "Whether webhooks are arriving could not be read just now."
- **Instructions**:
  - End the tracker URL with `?t202subid=[[subid]]` (`ThemeSnippet::URL_SUFFIX`).
  - Create JSON webhooks for Order payment, Refund create and Transaction creation.
  - "Webhook URL" `<trackingBase>tracking202/static/shopify.php` with Copy.
  - A "Theme snippet" disclosure showing `ThemeSnippet::html()` with Copy.
  - A note that webhooks sent to `gpb.php` are handled the same way.
  - "Webhook secret: <hint>", or "Could not be read (the server's error log says why); saving it again replaces it". When an overlap is running: "The secret it replaced keeps verifying until <Y-m-d H:i> UTC."
- **Form**:
  - Hidden `change_shopify_webhook_secret=1`, `token`, `p202_shown[shopify]` (the version from `IntegrationSecretStore::version()`).
  - Text `shopify_webhook_secret`: "Webhook secret" / "New webhook secret". Required when not connected; placeholder "Leave empty to keep the saved one".
  - When connected, a checkbox `shopify_keep_old` value `1` "Keep the old secret verifying for 7 days". Button "Save".
- **Validation**:
  - The secret must be a single value ("The secret arrived as a list; paste it as one value.").
  - `shopify_keep_old` must be absent or `1` ("…arrived as something other than on or off; save again.").
  - `ShopifyWebhookSecret::parse`: trimmed, `[\x21-\x7E]+`, 16 to 250 characters.
  - Empty with a valid saved secret: "Nothing changed: your saved Shopify webhook secret is kept." Empty with none usable: "[The saved secret cannot be used. ]Paste the secret your Shopify webhooks are signed with: Shopify shows it under Settings › Notifications › Webhooks."
- **Writes**:
  - `IntegrationSecretStore::put(user, 'shopify_webhook_secret', secret, grace, actor = user, via 'page', now)`. This seals into `202_integration_secrets` and records an event in `202_integration_secret_events`.
  - Grace is `OVERLAP_SECONDS` (7 days) when `shopify_keep_old=1` and the old secret was valid, else 0.
  - Slack `shopify_webhook_secret_updated`.
  - Flash "Your Shopify webhook secret was saved." plus " The old one keeps verifying webhooks for 7 days, while your store moves to the new one." when an overlap is stored. DB failure: "This could not be saved just now; the old value is still in place. Try again."

#### Held notifications (Zaxaa, PayKickstart, Shopify cards)
- **Notice** (`p202_currency_mismatch_notice()`): built from `HeldNotifications::counts()` and `CurrencyMismatch::held()`.
  - When notifications in the account's current currency are held: an info box "N notifications in <CUR> are held…" with a form `record_held_notifications=<source>` plus `token`, button "Record it/them".
  - When some are held in other currencies: a warning "N notifications from <provider> in <CUR…> recorded nothing…", with a link to `202-account/account.php#currency`. With `access_to_setup_section`, also "See them in the Inbound Log" (`inbound_log.php?outcome=currency_mismatch&endpoint=<source>`).
- **Handler**:
  - The source must be one of `paykickstart`, `shopify`, `zaxaa` (`HeldReplay::provider`), else "No payment integration holds notifications under that name."
  - `HeldReplay::replay()` records up to 200 a press through the payment `Processor` (conversion ledger writes). It claims, finishes or releases rows in the held table.
  - Flash: "<Provider>: recorded N held notification(s). [N already on file.] [N refused …; the first said: …] [N could not be recorded just now and stay held.] [N more are held: press Record again.]" The flash is warn when anything was refused or failed.
  - Throwable: "The held notifications could not be recorded just now. Try again: one that did get recorded is not recorded twice."

#### Page-wide rules and edge cases
- **CSRF**: every POST is refused before any write when `AUTH::check_csrf_token()` fails. Flash `P202_ACCOUNT_TOKEN_REFUSED` ("This form expired or did not come from this page, so nothing was saved. Reload the page and try again.") and a redirect to the page.
- **Stale-page guard**:
  - Covers the key cards, JVZoo key, JVZoo roleless role, PayKickstart, LPO keyword privacy, Shopify, postback mode and DNI edit (not postback rotate or held replay).
  - Each form carries `p202_shown[<form>]`. The save takes `GET_LOCK('p202_settings_<user>')` (`ShownSettings::hold`, 10-second wait), re-reads, and refuses when the values changed: warn `P202_ACCOUNT_SHOWN_STALE`.
  - Lock busy: "Another save of these settings is still running, so nothing was saved. Try again in a moment."
- **Secrets on the page**: saved secrets are never echoed, except the postback token, the postback signing secret and the LPO site key, which are shown in clear.
- **Key cards**: none of them has a "remove" action. A secret can only be replaced.
- **Confirmations**: forms with `data-p202-confirm` use `window.confirm` (`p202-ui.js`). Copy buttons use `data-p202-copy`.
- **Analytics**: `Analytics::event()` calls are made in the `analytics` tier. Per the code comment on the LPO call they self-gate on consent and swallow failures.
- **Slack**: notices go through `Slack::push()`, which queues them (`SlackNoticeQueue`) or sends them at once when the queue table is missing.

#### v3 coverage
| Page read or action | v3 route | Status |
|---|---|---|
| Postback auth state (mode, token, secret, created/valid-until) | `GET /conversions/postback-auth` | Covered (scope `conversions:write` + `access_to_api_integrations`) |
| Readiness ("who would be refused") | `GET /conversions/postback-auth/readiness` | Covered (returns `mode` + `PostbackAuthReadiness::forAccount()`) |
| Set mode | `PUT /conversions/postback-auth` `{mode}` | PARTIAL: no stale-page version check |
| Rotate token/secret | `POST /conversions/postback-auth/rotate` `{credential, grace_hours}` | Covered (any whole hours 0–720; default 168) |
| Integration URLs and whether each secret is stored, ClickBank verified, held counts, JVZoo 1.9.56 traffic | `GET /system/integrations` | PARTIAL: also asks `requireAdmin()` (Admin/Super user role names); has no "ends in XXXX" hint; has no IPQS or Slack entry |
| ClickBank "Check verification again" | `GET /system/integrations` (`verified` on the clickbank entry) | PARTIAL: admin-only, as above |
| Save ClickBank / Zaxaa / IPQS / Slack / JVZoo key / Shopify secret / PayKickstart list | `PUT /users/{id}/preferences` (`cb_key`, `zaxaa_api_signature`, `ipqs_api_key`, `user_slack_incoming_webhook`, `jvzoo_ipn_secret_key`, `shopify_webhook_secret`, `paykickstart_ipn_secret_keys`) | PARTIAL, see below |
| Read whether each secret is saved | `GET /users/{id}/preferences` (secrets as `{"stored": bool}`) | PARTIAL: no hint, no PayKickstart per-key list |
| JVZoo 1.9.56 role save | none (`jvzoo_roleless_role` is served by GET preferences but is not in `PreferenceRules::columns()`) | **MISSING** |
| Record held notifications | `POST /system/integrations/record-held` `{integration}` | PARTIAL: admin-only |
| Shopify refunds to settle (count on card) | `GET /conversions/shopify-refunds` | Covered (gated by `RefundAccess`) |
| Shopify webhook status (last webhook, last order, unverified seen) | `GET /conversions/inbound-log?endpoint=shopify` | PARTIAL: needs `access_to_setup_section`; no summary; caller must derive it |
| IPQS scoring status / last-24h counts / last lookup error | none | **MISSING** |
| DNI catalogue, list, add, edit, remove, progress, mark processed | none | **MISSING** |
| LPO connect, disconnect, status, site key, keyword-privacy write | none (`lpo_status`, `lpo_ctx_kw` readable via GET preferences; `lpo_site_key` only as `{stored}`) | **MISSING** |

Notes on the `PUT /users/{id}/preferences` partial:
- **Permission**: it is gated by `requirePersonalSettingsOf()` (`access_to_personal_settings`, or managing another user), not by `access_to_api_integrations`.
- **Rules**: `cb_key`, `zaxaa_api_signature` and `ipqs_api_key` are TEXT ≤250. Slack uses `SlackWebhook::accepted`, JVZoo `JVZooKey::parse`, Shopify `ShopifyWebhookSecret::parse`, PayKickstart the whole list via `PayKickstartKeys::parse`.
- **Clearing**: `''` clears a secret, which the page cannot do.
- **Shopify**: always stored with grace 0. There is no "keep the old secret verifying for 7 days".
- **PayKickstart**: the API replaces the whole list and never returns the keys, so one key cannot be added or removed without resending every other key. No add/remove-one operation, no per-key hint.
- **ClickBank**: changing `cb_key` resets `cb_verified`, as the page does.
- **Side effects**: no Slack `*_updated` notice and no `integration_configured` analytics event.

---

### Personal settings › API keys: `/202-account/account.php#api-keys`

- **Where / who**
  - Menu: My Account › Personal Settings, then the "API keys" tab link (`#api-keys`). Only this panel is in scope here.
  - Signed in (`AUTH::require_user()`). The panel, its tab link and its data are rendered only when the user has `access_to_personal_settings` (`$canPersonal`).
  - A POST of `add_rest_api_key`, `remove_rest_api_key`, `replace_rest_api_key` or `stop_ai_watcher` from a user without it gets HTTP 403 "You do not have permission to change these settings." before any write.
- **Purpose**: create, list, replace and revoke the user's own REST API keys (v3 API, p202 CLI, agents). For keys minted by an AI connection, also list and stop that connection's watchers on Tracking202's MCP server.
- **Data shown**
  - Pill "1 key" / "N keys". Note: "For the REST API, the p202 command line and agents. Make one key per integration, so revoking one stops only that one." When use columns exist: " A key's last use is recorded from version 1.9.80 on, to within five minutes."
  - Keys come from `p202_account_api_keys()`: `202_api_keys WHERE user_id = session user_id`, newest first by `created_at`. Replaced keys that ended more than `ENDED_KEY_KEPT` (7 days) ago are excluded. The columns read depend on which schema columns exist (scope, label, use columns).
  - A read failure flashes bad "Your API keys could not be read just now. Reload the page to see them." and shows the empty list.
  - Each key row (`data-api-key-row`):
    - Label in bold when set. "Created <M j, Y>".
    - A scope pill, from `Auth::parseScopes`: "full access"; "scope unreadable · refused everywhere" (`MALFORMED_SCOPE`, bad tone); or the comma-joined scope tokens.
    - An ending pill: "Replaced; works until <M j, Y H:i>" (warn) or "Replaced; ended …" (bad).
    - A last-use line: "Last used <M j, Y H:i>[ on API <api>][, by a client calling itself <UA>]" or "No use recorded".
    - The key masked as its 8-character prefix plus 20 bullets. The full key is never shown again; keys are stored hashed.
  - The new key, once: after create or replace, `$_SESSION['p202_new_api_key']` is shown once in a `p202-code` block with Copy ("Your new key. Copy it now…") and then unset.
  - Empty state: "No API keys yet / Generate one above to connect the command line, an agent or your own scripts."
  - AI watchers, only for keys whose label starts with `AI: `:
    - `p202_account_ai_watchers()` calls `AiWatchers::list(watcher_token)`: GET `<relayOrigin>/api/watchers`, timeouts 3 s / 3 s. The relay origin is `P202_MCP_RELAY_ORIGIN` or `https://mcp.tracking202.com`.
    - Campaign names come from `202_aff_campaigns` of this user, with " (deleted)" after deleted ones.
    - Each watcher shows: campaign name (or "Campaign #id"); status pill (running good, cancelled warn, failed bad; title = status reason); "ends/ended/was to end <date>"; a rule sentence (`p202_account_watcher_rule`); "N alerts · latest: …". A Stop form is shown for running ones.
    - "Couldn't load watchers right now…" when the list fails; "No watchers." when empty.
- **Inputs**
  - Create form: hidden `add_rest_api_key=1`, `token`. Text `rest_api_key_label` "New key", maxlength 100, optional, placeholder "Name it for what will use it, e.g. Nightly store sync (optional)". Button "Generate key".
    - A legacy posted `rest_api_key` is still accepted when it matches `^[A-Za-z0-9]{32,128}$`. Otherwise: "An API key is 32 to 128 letters and digits. Leave it out and one is generated for you."
  - There is no scope input: keys made here are always full access.
- **Actions and writes**
  - **Generate**:
    - Label rule (`ApiKeyStore::labelFrom`): UTF-8, trimmed, ≤100 characters, no control or bidi characters, must not start with "AI:". Refusal: "A key's name is up to 100 characters on one line, and does not start with "AI:". Nothing was created."
    - Key: `bin2hex(random_bytes(32))`, 64 hex characters.
    - `ApiKeyStore::insert()` writes `202_api_keys`: `user_id`, `api_key` (placeholder), `key_hash`, `key_prefix`, `watcher_token` (null), `scope` (null = full), `label`, `created_at`. On installs without the hash column the key is stored in `api_key` whole.
    - Failure: "The API key could not be saved. Nothing was created; try again.", or `UNSTORABLE_NAME` for a name the column cannot hold.
    - Slack `user_added_app_api_key`. Flash ok "API key created. It has full access. Copy it now: it is not shown again."
  - **Replace…** (only when `ends_at` is null, the key is not an AI key, and use columns exist):
    - Confirm: "Replace this API key? A new key with the same access is made and shown once. This key keeps working for 24 hours, so you can switch without an outage."
    - Fields: hidden `replace_rest_api_key=1`, `api_key_ref` (the 64-hex SHA-256 ref), `token`.
    - `ApiKeyStore::replace()` inserts a new key with the same scope and label, and sets the old key's `ends_at = now + 86400`.
    - Refusals as warn flashes plus " Nothing was replaced.": "Replacing a key needs the 1.9.80 database upgrade, which has not run yet." / "An AI app's key is replaced by connecting the app again." / "That key was already replaced."
    - Not found: "That API key was not found on this account; nothing was replaced."
    - Flash ok "Replacement key created, with the same access. Copy it now: it is not shown again. The old key keeps working until <date>, so you have time to switch; revoke it to end it sooner." Slack `user_added_app_api_key`.
  - **Revoke…**:
    - Confirm "Revoke this API key? Anything still using it is refused from the next request. Your other keys keep working." Fields: hidden `remove_rest_api_key=1`, `api_key_ref`, `token`.
    - Stops the key's AI watchers first (`AiWatchers::stopBeforeDelete`, POST `/api/watchers/stop {all:true}`, best effort), then `DELETE FROM 202_api_keys WHERE user_id = ? AND <ref match>`.
    - Flash ok "API key revoked. Anything still using it is refused from now on." Plus warn "Tracking202's MCP server did not confirm that this key's watchers stopped; without the key they can read nothing more." when the stop was not confirmed.
    - Other outcomes: "That API key was not found on this account; nothing was revoked." / bad "The API key could not be revoked. It still works; try again." Slack `user_removed_app_api_key`.
  - **Stop (watcher)**:
    - Confirm "Stop this watcher? It reads nothing more and sends no more alerts." Fields: hidden `stop_ai_watcher=1`, `api_key_ref`, `watcher_id` (`^wch_[0-9a-f]{24}$`), `token`.
    - Outbound POST `<relay>/api/watchers/stop {watcherId}` with the key's watcher token.
    - Flashes: "Watcher stopped. It reads nothing more with this key." / "Tracking202's MCP server has no such watcher for this key; it may have ended already." / "…could not be reached to stop the watcher; try again, or revoke the key to cut it off." / "That watcher was not recognised; nothing was stopped." / "That AI connection key was not found on this account; nothing was stopped."
  - Every action checks `AUTH::check_csrf_token()` first (`P202_ACCOUNT_TOKEN_REFUSED`) and redirects to `account.php#api-keys`.
- **Edge cases**: the scope column, label column and use columns are each probed first. An install before 1.9.75, 1.9.78 or 1.9.80 shows keys without scope, label or use, and offers no Replace. An unreadable scope probe throws rather than reading as "no column".
- **v3 coverage**
  - List: `GET /users/{id}/api-keys`. PARTIAL: returns a masked `api_key`, `scope`, `label`, `created_at` and use columns, but no `ref` and no order. It has no AI watcher information.
  - Create: `POST /users/{id}/api-keys` `{scope?, label?}`. Covered, and broader: a scope can be chosen.
  - Replace: `POST /users/{id}/api-keys/replace` `{api_key}`. PARTIAL: needs the full key (`ApiKeyStore::matchKey`), which a client that only listed keys does not hold. The page names the key by its ref.
  - Revoke: `DELETE /users/{id}/api-keys/{keyId}`. PARTIAL: `{keyId}` is matched as the full key (`matchKey`), not a ref, so a key cannot be revoked from the list alone. Stops watchers as the page does.
  - AI watchers list and stop: **MISSING**.
  - Slack notices `user_added_app_api_key` / `user_removed_app_api_key`: not sent by v3.

---

### Connect an AI assistant: `/202-account/connect-ai.php`

- **Where / who**
  - Not in any menu. An AI app sends the person here from Tracking202's MCP server (`mcp.tracking202.com`), with the approval request in the query string (GET) or the form (POST).
  - Signed in (`AUTH::require_user()`). Standalone shell (`info_top`), title "Connect an AI assistant - Prosper202 ClickServer".
  - Headers: `X-Frame-Options: DENY`, `Content-Security-Policy: frame-ancestors 'none'`, `Referrer-Policy: no-referrer`, `Cache-Control: no-store`.
  - Without `access_to_personal_settings`: the page shows the error "Connecting an AI assistant creates an API key, which your role cannot do. Ask an administrator of this Prosper202 to connect it, or to give you that permission." and no form.
- **Purpose**: the consent step of an OAuth-like PKCE flow. On Allow, this install issues a one-time code and redirects to the MCP server's fixed callback. The MCP server then exchanges the code for a new API key of this user (`POST /api/v3/connect/token`).
- **Inputs (request parameters, `AiConnect::readRequest`)**
  - `redirect_uri` must equal `<relayOrigin>/callback`, else "This approval link does not come from Tracking202's MCP server (<origin>), so it cannot be used."
  - `state`: two 43-character base64url parts joined by `.`.
  - `code_challenge_method` = `S256` and `code_challenge` (43-character base64url).
  - `client_name`: 1–100 characters, no control characters.
  - `client_domain`: optional hostname.
  - `redirect_host`: 1–255 printable ASCII characters.
  - `install`: `https?://host[:port]/path/`. It must be the same install as the request's own base (`AiConnect::sameInstall`: host, port and path compared; scheme not compared), else "This approval link was made for <install>, not for this install (<self>), so nothing can be approved here…"
  - Each other failure: "The approval link is incomplete (…). Start connecting again from your AI app." (or similar). A bad link renders a card "This link cannot be used" with the reason.
  - The form re-posts every request field as hidden inputs, plus `token`.
  - Radio `access`: `read` "Read only (recommended)" (default), `propose` "Read, and propose changes", `write` "Read and change", each with a help paragraph.
  - Buttons `decision=allow` "Allow" and `decision=deny` "Cancel".
- **Data shown**: the client's name and domain as the link states them (with a note that the page cannot check them), the signed-in user name, the relay host and the redirect host. Warnings that whoever started the connection gets the access, and that the key cannot touch users, API keys or settings. A data-collection note about what the MCP server records.
- **Actions and writes**
  - POST without a valid token: error `P202_ACCOUNT_TOKEN_REFUSED`.
  - **Allow**:
    - `AiConnect::scopeFor(access)` builds the scope. `read`: every READ_AREA `:read`. `propose`: those plus `:stage` for the WRITE_AREAS. `write`: `:write` for the WRITE_AREAS and `:read` for the rest. An invalid access: "Choose what the assistant may do."
    - `AiConnect::approve()` stores `sha256(code)` with `{user_id: user_own_id, scope, code_challenge, client_name}` in `ServerStateStore::forInstall($db)`, TTL 300 s.
    - 303 to `<relayOrigin>/callback?code=…&state=…&iss=<self>`.
    - No API key is written by this page. The key (label `AI: <client>`) is written later, when the MCP server calls `POST /connect/token`.
  - **Cancel**: 303 to `<relayOrigin>/callback?error=access_denied&state=…&iss=…`. Any other decision: "Choose Allow or Cancel."
- **v3 coverage**
  - `GET /connect` (the install advertises the feature), `POST /connect/token` (the code exchange that mints the key) and `POST /connect/revoke` exist. The consent decision itself, which issues the code for a signed-in user, has **no** v3 route: **MISSING** for a v3-only client.
  - The resulting key is listed and revoked through the API-keys routes above, with the same partial gaps.

---

## Missing v3 endpoints
| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| API integrations | Lists the DNI network catalogue from my.tracking202.com | none | No route proxies the DNI catalogue |
| API integrations | Lists the account's DNI networks (name, type, key hint, affiliate id, processed, favicon, description) | none | No read of `202_dni_networks` |
| API integrations | Adds a DNI network (authenticates with the DNI service, inserts it plus a "(DNI)" affiliate network in one transaction) | none | No create |
| API integrations | Edits a DNI network (keeps the stored key when left empty; renames its affiliate network) | none | No update |
| API integrations | Removes a DNI network and retires its affiliate network | none (`DELETE /aff-networks/{}` handles only the category) | No delete of the integration |
| API integrations (ajax/dni.php) | Polls DNI import progress and marks a network processed | none | No progress or processed routes |
| API integrations | Connects the Landing Page Optimizer (pair/init, creates a wildcard `202_ltv_webhooks` row, pair/complete, writes `lpo_*` prefs, busts the rtr.php cache) | `POST /ltv/webhooks` covers only the webhook row | No pairing route |
| API integrations | Disconnects the LPO (deletes the webhook, pair/disconnect, clears prefs) | none | No unpair route |
| API integrations | Shows the LPO connection state, site key and webhook health | `GET /users/{id}/preferences` (`lpo_status` only; `lpo_site_key` as `{stored}`) | No status, site key or webhook-health read |
| API integrations | Saves LPO keyword privacy (`lpo_ctx_kw`) with the cache bust | `GET /users/{id}/preferences` reads it | Not writable (absent from `PreferenceRules::columns()`) |
| API integrations | Saves the JVZoo 1.9.56 roleless role (`jvzoo_roleless_role`) | `GET /users/{id}/preferences` reads it | Not writable |
| API integrations | Shows IPQS scoring status, last-24h scored and filtered counts, last lookup error and retry time | none | No IPQS status read |
| Personal settings › API keys | Lists an AI connection key's watchers from the MCP server | none | No watcher list |
| Personal settings › API keys | Stops one watcher | none | No watcher stop (only all of them, as part of the key delete) |
| Connect an AI assistant | Records the signed-in user's consent and issues the one-time code | none (`POST /connect/token` is the later exchange) | No consent / code-issue route |

## Partial v3 coverage
| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| API integrations | Shows integration URLs, which secrets are stored, ClickBank verified, held counts, JVZoo 1.9.56 traffic | `GET /system/integrations` | Also requires an Admin/Super user role (`requireAdmin`), beyond `access_to_api_integrations`. No "Saved, ends in XXXX" hint. No IPQS or Slack entries. |
| API integrations | "Check verification again" (ClickBank) | `GET /system/integrations` | Admin-only, as above |
| API integrations | Records held notifications | `POST /system/integrations/record-held` | Admin-only, as above |
| API integrations | Saves the ClickBank, Zaxaa, IPQS and Slack secrets, the JVZoo key, the Shopify secret and the PayKickstart keys | `PUT /users/{id}/preferences` | Gated by `access_to_personal_settings` / user management, not `access_to_api_integrations`. No stale-page version check. No Slack `*_updated` notice or `integration_configured` analytics. |
| API integrations | Saves the Shopify secret, optionally keeping the old one verifying for 7 days | `PUT /users/{id}/preferences` `shopify_webhook_secret` | Always grace 0. No keep-old option. |
| API integrations | Adds one PayKickstart key, or removes one by its place and HMAC token | `PUT /users/{id}/preferences` `paykickstart_ipn_secret_keys` | Whole-list replace only. The keys are never returned, so adding or removing one needs every other key resent. No per-key hint list (`GET /system/integrations` gives only `keys_stored`). |
| API integrations | Shows a saved-secret hint ("Saved, ends in XXXX") for each secret and DNI key | `GET /users/{id}/preferences` | Only `{"stored": true/false}` |
| API integrations | Shows Shopify webhook status (last webhook and outcome, last order recorded, unverified webhook seen) | `GET /conversions/inbound-log?endpoint=shopify` | Needs `access_to_setup_section`. No summary: the client must derive the last webhook, last order and unverified-seen itself. |
| API integrations | Saves the postback authentication mode under the stale-page guard | `PUT /conversions/postback-auth` | No shown-version / stale check |
| Personal settings › API keys | Lists keys newest first, each with a ref the page acts on | `GET /users/{id}/api-keys` | No `ref` (or any stable key id) and no ordering |
| Personal settings › API keys | Revokes a key named by its ref | `DELETE /users/{id}/api-keys/{keyId}` | `{keyId}` is matched as the full key (`ApiKeyStore::matchKey`), so a listed (masked) key cannot be revoked. No Slack notice. |
| Personal settings › API keys | Replaces a key named by its ref (old one works 24 h) | `POST /users/{id}/api-keys/replace` `{api_key}` | Needs the full key, not a ref. No Slack notice. |
| Personal settings › API keys | Creates a key with an optional name | `POST /users/{id}/api-keys` | Covered, and broader (scope). No Slack notice `user_added_app_api_key`. |

## Not determined from code
- Whether anything sets `$_SESSION['user_id']` and `$_SESSION['user_own_id']` apart after sign-in. `api-integrations.php` reads the DNI install hash from the `user_own_id` row and everything else from the `user_id` row; `AUTH::begin_user_session()` sets both to the same value. No other writer of `user_id` was searched for.
- What the DNI service returns from `/cache/progress`: the page streams it through unchanged, and the JS reads only `data[].id` and `data[].progress`.
- The full response shape of the LPO SaaS (`pair/init` must include `site_key` and `hook_url`; nothing else is read) and the exact text of `PairingRequestException::userMessage()`, which comes from the SaaS.
- Under strict SQL mode, what a non-numeric Cake affiliate ID does: the `202_dni_networks.affiliateId` column is `int(11) unsigned` and the page does not check that the value is a number. The session's `sql_mode` at that insert was not traced.
