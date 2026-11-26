# Setup: destinations, ad connections, spend sync, offline conversions and inbound log

This area holds the Setup pages that move conversions out of Prosper202 and record what comes in. Setup › Destinations sends each conversion to GA4 (Measurement Protocol), Google Ads (offline click conversions, connected with OAuth) or ChatGPT Ads (OpenAI's Conversions API). The same page holds the ChatGPT Ads ad connections: an Advertiser API key, guided setup, and a live view of campaigns, ad groups and ads with pause, activate, tracking-template and event-settings actions. Each connection's hourly spend pull is switched on a card on Setup › Traffic Sources, through `spend_sync.php`. Setup › Offline Conversions builds the Google Ads and Microsoft Advertising upload CSVs and the fetch URLs those platforms poll. Setup › Inbound Log lists every postback, pixel and payment notification request, with a "Record it now" replay and the Shopify refunds that still need settling. All pages load `202-config/connect.php`, call `AUTH::require_user()`, check a permission, and send a refused user to `tracking202/` with a `location` header (no message). Every POST checks the session token with `AUTH::check_csrf_token()`; the hidden `token` field comes from `p202_setup_token_field()`.

| Page | Path | Permission |
|---|---|---|
| Destinations (includes the delivery log view, the Google Ads picker states and the ChatGPT Ads campaigns view) | `tracking202/setup/destinations.php` | `access_to_setup_section`; the delivery logs and recent-delivery tables also need `access_to_campaign_data` |
| Google Ads OAuth return (callback, no screen of its own) | `tracking202/setup/destination-oauth.php` | `access_to_setup_section` |
| ChatGPT Ads spend sync switch (POST handler for the card on Setup › Traffic Sources) | `tracking202/setup/spend_sync.php` | `access_to_setup_section` |
| Offline Conversions | `tracking202/setup/offline_conversions.php` | `access_to_setup_section`; the download and fetch URLs also need `access_to_campaign_data` |
| Inbound Log | `tracking202/setup/inbound_log.php` | `access_to_api_integrations` OR `access_to_setup_section` (`RefundAccess::maySee`); the log itself needs `access_to_setup_section` |

Menu: Setup sub-menu (`tracking202/_config/sub-menu.php`) entries "Offline Conversions", "Destinations" and "Inbound Log". The spend sync card is on Setup › Traffic Sources (`tracking202/setup/ppc_accounts.php`, anchor `#chatgpt-spend`), rendered by `tracking202/setup/_includes/spend_sync_card.php`.

---

### Destinations: `tracking202/setup/destinations.php`

- **Where / who**: `/tracking202/setup/destinations.php`, Setup › Destinations. Requires a signed-in user with `access_to_setup_section`. A user without it is redirected to `tracking202/` with no message. `access_to_campaign_data` (`$canSeeLog`) decides whether the "Recent deliveries" table, the per-status health links and the full delivery log (`?log=`) are shown. Without it, `?log=<id>` renders the ordinary page. The page title is "Destinations", and dates are shown in the account's zone (`AUTH::set_timezone`).
- **Purpose**: Add, edit, switch on and off, test and delete conversion destinations (GA4, Google Ads, ChatGPT Ads), connect Google Ads with OAuth and pick its account and conversion action, watch and resend deliveries, and backfill past conversions. It also manages ChatGPT Ads ad connections (API key, setup, key check and replacement, deletion) and shows the live campaigns view for a connection.
- **Data shown**:
  - **Destination list** ("Your destinations", with a count pill). Each item shows:
    - The name, and a type label: `GA4 <measurement_id>`, `Google Ads <account name (id)>` or `ChatGPT Ads pixel <pixel_id>`.
    - An on/off pill.
    - A status pill: `needs_authorisation` shows "not connected", `needs_account` shows "pick its account", and unreadable credentials show "credentials unreadable".
    - A meta line: the event name or conversion action, per-campaign mapping counts, "every campaign" or "N campaign(s)", and whether hashed user data is on or off.
  - **Empty state**: "No destinations yet…".
  - **Waiting notice**: conversions held because a destination is not ready (from the store's `waiting` count).
  - **Privacy note**: the account's privacy setting (`PrivacySetting::forAccount`: `disabled`, `eu` or `all`) and what it means for user data sent.
  - **Health, last 7 days** (`p202_destination_health`), per destination:
    - Counts by delivery status, shown as pills that link to the log filtered by that status when `$canSeeLog`.
    - The "waiting" count.
    - Up to 5 failure reasons.
    - Counts of failed and withheld deliveries from the last 90 days that can be resent, each with a "send all again" form.
  - **Recent deliveries**: 10 rows, only with `$canSeeLog`. Columns: When, Conversion (the conversion id, or "test"), Status, Tries, Last answer.
  - **Full log view** (`?log=<id>`, with `$canSeeLog`): 50 rows per page, optional `status` filter (one of `DestinationQueue::STATUSES`), and `page`. Columns:
    - When
    - Conversion, linked to `tracking202/logs/?click_id=`
    - Status
    - Tries
    - Last answer
    - a "send again" button on failed or withheld rows

    Paging links are "‹ newer" and "older ›". A log id that is not the account's shows "That destination is not one of yours, or was deleted."
  - **Google Ads picker** (`_includes/google_ads_picker.php`): shown for a destination in `needs_account` state, or for a ready one with `?choose=<id>` (`&account=1` changes the account).
    - It reads `GoogleAdsAccounts::accounts()` live. With one account, it skips straight to that account's conversion actions.
    - It lists the account's conversion actions of the import-click kind and gives a count of the other actions it left out. A stored action that Google no longer lists is still offered.
    - When a step fails, its hint names `p202 destination update <id> --customer-id … --conversion-action …`.
  - **ChatGPT Ads panel** (`_includes/ad_connection_panel.php`, id `chatgpt-ads`). Each connection shows:
    - The name, the ad account id, a status pill, and buttons: Check key, Delete (with confirm), and a link to its campaigns view.
    - A meta line: currency, time zone, traffic source account, pixel, and whether a Conversions API key is stored.
    - A notice when the ad account's currency differs from the account's, and `last_error` when one is set.
    - The setup results stored in session `p202_ad_setup`, listed under the connection.
  - **ChatGPT Ads campaigns view** (`?ad_campaigns=<connection id>`, `_includes/ad_campaigns_panel.php`). It replaces the rest of the page and is read live from OpenAI.
    - A spend line for yesterday, from `spend()`.
    - A campaign table of up to `OpenAiAdsCampaigns::MAX_PAGE_LIMIT` (500) campaigns. Columns:
      - Campaign, linked with `&campaign=<id>`
      - Status
      - Serving issues
      - Daily budget
      - Spent yesterday
      - Tracking template ("not set", "the generic one" or "its own")
      - Event settings count
      - Actions
    - When there are more campaigns, a note says to use `p202 ad-campaign list <id> --after <the last id>`.
    - With `&campaign=<id>`: up to 20 ad groups, each with up to 50 ads. Each group shows its status with pause/activate. Each group's ads table has the columns Ad, Status, Review, Serving issues, and pause/activate.
    - With `&preview_pause=<campaign id>`, which a money-loss alert links to from `202-config/functions-alerts-ui.php`: a pause preview (a dry run of `setStatus`, nothing changed) with a "Pause it" button that asks to confirm.
    - A connection id that is not the account's shows "That ChatGPT Ads connection is not one of yours, or was deleted."
    - A refusal or outage from OpenAI is shown as an error, never as an empty list.
  - The traffic source choices come from `p202_destination_traffic_sources()`: `202_ppc_accounts` LEFT JOIN `202_ppc_networks`, LIMIT 1000. The campaign choices come from `p202_setup_campaign_options()`.
  - **Flashes from `done=`**: `created` ("Destination added. Send a test to check it."), `created_google_ads`, `connected`, `picked`, `enabled`, `disabled`, `deleted`, `ad_connected`, `ad_checked`, `ad_key_replaced`, `ad_setup`, `ad_deleted`, `updated`, and `queued` (with session notice `p202_destination_queued`, which estimates the send time from `SENT_A_MINUTE` = 200 and `RESEND_BATCH` = 5000). Session flashes: `p202_destination_oauth`, `p202_destination_real_test`, `p202_destination_test`, `p202_ad_setup`, `p202_destination_queued` and `p202_ad_campaign_done`.
- **Inputs** (each form posts `action`, `token`, and `destination_id` and/or `connection_id`):
  - **Add GA4** (`action=create_ga4`):
    - `name`: text, required, at most 100 characters.
    - `measurement_id`: text, required, `G-[A-Z0-9]{4,20}`.
    - `api_secret`: password, required, `[A-Za-z0-9_-]{8,100}`.
    - `event_name`: text, default `purchase`, `[A-Za-z][A-Za-z0-9_]{0,39}`. GA4's reserved names and the prefixes `firebase_`, `ga_`, `google_` and `gtag` are refused.
    - `client_id_source`: select of `click`, `c1`, `c2`, `c3`, `c4`; default `click`.
    - `send_user_data`: checkbox, value 1.
    - `ad_user_data` and `ad_personalization`: selects of `''` (not stated), `granted`, `denied`.
  - **Add Google Ads** (`action=create_google_ads`):
    - `gads_name`: text, required, at most 100 characters.
    - `gads_developer_token`: 10–100 characters.
    - `gads_oauth_client_id`: must end `.apps.googleusercontent.com`.
    - `gads_oauth_client_secret`: password, 10–200 characters.
    - `gads_send_user_data`, `gads_ad_user_data`, `gads_ad_personalization`: as for GA4.
    - Under an Advanced disclosure (remembered as `setup-destinations-gads-ids`): `gads_customer_id` (10 digits), `gads_login_customer_id` and `gads_conversion_action` (both need a customer id).
    - The form shows the OAuth redirect URI to register: `TrackingBaseUrl::forRequest($_SERVER) . 'tracking202/setup/destination-oauth.php'`.
  - **Add ChatGPT Ads** (`action=create_openai_ads`):
    - `oai_name`: placeholder "ChatGPT Ads".
    - `oai_pixel_id`: `[A-Za-z0-9_-]{1,128}`.
    - `oai_conversions_api_key`: password, 16–512 printable characters.
    - Under an Advanced disclosure (remembered as `setup-destinations-openai`):
      - `oai_event_type`: select from `OpenAiAdsDestination::EVENT_TYPES`, including `custom`; default `order_created`.
      - `oai_custom_event_name`: `[a-z0-9]([a-z0-9_-]{0,62}[a-z0-9])?`.
      - `oai_scope`: `oppref`, `source` or `all`; default `oppref`.
      - `oai_ppc_account_id`: placeholder "Only with one traffic source…". Required with scope `source`, refused otherwise.
      - `oai_send_user_data`.
  - **Edit** (`action=update`, `p202_destination_edit` in `_includes/destination_panels.php`):
    - Common: `name`. A credential field left blank keeps the stored value ("Leave blank to keep the stored one.").
    - GA4 fields: `measurement_id`, `api_secret`, `event_name`, `client_id_source`, and `user_id_source` (none, or `customer` = "The customer (p202c.<customer id>)").
    - GA4 parameter rows: `param_name[]` with `param_field[]`, the field chosen from utm_source, utm_medium, utm_campaign, utm_term, utm_content, keyword, c1, c2, c3, c4, campaign_id or click_id. At most 20 rows; `value`, `currency` and `transaction_id` are reserved.
    - Google Ads fields: `customer_id`, `login_customer_id`, `conversion_action`, `developer_token`, `oauth_client_id`, `oauth_client_secret`.
    - Google Ads per-campaign conversion actions: `cca_campaign[]` with `cca_action[]`; at most 500.
    - ChatGPT Ads fields: `pixel_id`, `conversions_api_key`, `event_type`, `scope`, `custom_event_name`, `ppc_account_id`. A stored traffic source account that was deleted shows as "Account N (deleted)".
    - ChatGPT Ads per-campaign event types: `et_campaign[]` with `et_type[]`, standard types only (no `custom`); at most 500.
    - Two blank rows are shown for each list; `DestinationForm::pairs` reads at most 600 rows.
    - Campaigns: `all_campaigns` checkbox, or `campaign_ids[]` multi-select. Each id must be the account's own. If neither is given: "Pick the campaigns it sends, or tick Every campaign."
    - `send_user_data`; `ad_user_data` and `ad_personalization` (not for ChatGPT Ads).
  - **Pick Google Ads account** (`action=pick_google_ads`):
    - `gads_account`: radio, value `\A([0-9]{10})(?::([0-9]{10}))?\z` (customer, or customer:login customer).
    - `gads_conversion_action`.
    - Hidden `gads_names[account][v]` and `gads_names[action][v]`, used as labels.
    - Refusals: "Choose the Google Ads account from the list." and "Choose one conversion action from the list."
  - **Resend one** (`action=resend`): `delivery_id`, `status`. With no delivery named: "The form did not name a delivery; nothing was changed."
  - **Resend all** (`action=resend_all`): `status`, `failed` or `withheld`.
  - **Backfill** (`action=backfill`): `days`, a select of 1, 3, 7, 30, 90 cut to the type's limit (GA4 3, Google Ads 90, ChatGPT Ads 7). With no days: "Pick how many days back to send; nothing was changed." Out of range: "days must be 1 to N for this destination…"
  - **Real test check** (`action=test_real_check`): `sent_at`.
  - **Connect ChatGPT Ads** (`action=ad_connect`):
    - `api_key`: password, 8–512 printable characters, no spaces. Refusal: "api_key is required: the Advertiser API key from Ads Manager › Settings, 8 to 512 characters with no spaces."
    - `ppc_account_id`: select with a "None" choice. A value that names no account: "The form named no traffic source account; pick one or None."
  - **Replace key** (`action=ad_replace_key`): `api_key`. A Replace key disclosure is open when the status is not `connected`.
  - **Run setup** (`action=ad_setup`):
    - `steps[]`: checkboxes `pixel`, `conversions_key`, `destination`, `event_settings`, all ticked by default. Unticked steps are skipped.
    - Optional: `event_types[]` (multi-select of order_created, lead_created, registration_completed, subscription_created, trial_started, appointment_scheduled, checkout_started, items_added, contents_viewed, page_viewed), `pixel_source_id`, `pixel_id`, `source_id`, and `conversions_api_key` (password).
    - Rules: `source_id` needs `pixel_id`; `pixel_source_id` and `pixel_id` cannot both be given.
  - **Campaign actions** (`action=ad_campaign_pause|ad_campaign_activate|ad_campaign_template|ad_campaign_events`): `connection_id`, `level` (`campaigns`, `ad-groups`, `ads`), `object_id`.
- **Actions**:
  - **Destinations**:
    - `create_ga4` and `create_openai_ads` create the destination and redirect to `?done=created`.
    - `create_google_ads` creates the destination, then redirects at once to Google's consent screen (`GoogleAdsOAuth::start` → `authorisation_url`: `accounts.google.com/o/oauth2/v2/auth`, scope `adwords`, signed state valid 600 s). If the start fails, it goes to `?done=created_google_ads`.
    - `connect` starts OAuth again for an existing Google Ads destination.
    - `pick_google_ads` saves the account and action through `GoogleAdsPicker::save` and redirects to `?done=picked`.
    - `enable` and `disable` set `enabled`.
    - `delete` asks to confirm, then soft-deletes.
    - `update` saves through `DestinationForm::patch`, then `store->update(…, keepUnnamedCredentials=true)`. Saving releases waiting conversions.
  - **Tests**:
    - `test` sends a sample conversion to the platform's validation endpoint (`DestinationQueue::testFire`) and records a test delivery.
    - `test_real` sends a real ChatGPT Ads test event (`OpenAiAdsTestEvent::send`). Its confirm says the event is saved and counted by OpenAI. It is offered only when an ad connection reaches the pixel (`p202_ad_connection_reaches`).
    - `test_real_check` reads back whether OpenAI received the event sent at `sent_at`; read only.
  - **Deliveries**:
    - `resend` queues one failed or withheld delivery again. When nothing is queued: "That delivery is not a failed or withheld conversion of this destination (it may have been sent again already); nothing was changed."
    - `resend_all` queues every failed or withheld delivery from the last 90 days. When there is none: "No <status> conversion of the last 90 days was found to send again."
    - `backfill` queues conversions from the last N days.
    - All three redirect to `?done=queued`.
  - **Ad connections**:
    - `ad_connect` → `OpenAiAdsConnections::connect`, then `?done=ad_connected#ac<id>`. Refused when the ad account is already connected or the account already has `AdConnectionStore::MAX_PER_ACCOUNT` (10) connections.
    - `ad_check` → `check()`. A connection not found: "not one of yours"; a stored `last_error` is shown as the check's error.
    - `ad_replace_key` → `replaceKey()`. The new key must reach the same ad account ("That key reaches ad account X, not Y…").
    - `ad_setup` → runs the chosen steps and stores the step results in session `p202_ad_setup`. If OpenAI refuses the key, the connection is marked `key_refused`. It can also fail because the platform is unavailable or answers with something unusable.
    - `ad_delete` asks to confirm, then soft-deletes the connection and erases its credentials.
    - Refusals are prefixed (P202_AD_CONNECTION_REFUSALS): "The check did not pass", "Setup did not run", "The key was not replaced".
  - **Campaigns view**:
    - `ad_campaign_pause` / `ad_campaign_activate` → `setStatus` pause or activate. Pause is shown when the object is active; activate when it is paused and not ended.
    - `ad_campaign_template` asks to confirm, then calls `setTemplate(null)`, which pushes the generic `TrackingTemplate::default()`.
    - `ad_campaign_events` → `attachEventSettings([])`, which attaches the event settings that setup made.
    - Each redirects to `?ad_campaigns=<id>#ad-campaigns` with a flash sentence built from OpenAI's answer.
  - **Errors**:
    - An unknown action: "The form did not say what to do; nothing was changed."
    - A token mismatch: "Your session's security token did not match, so nothing was changed. Reload the page and try again."
    - A generic failure: "That could not be done. Try again; if it keeps failing, the server's error log says why."
  - **JavaScript**: `202-js/p202-setup.js` (`p202_setup_script_tag`); `p202-ui.js` confirm dialogs on `form[data-p202-confirm]`; `details[data-p202-remember]` keeps the open state in localStorage.
  - The page makes no AJAX calls; every action is a full POST and redirect.
- **Writes and side effects**:
  - `202_destinations`: insert, update and soft delete. A delete sets `deleted_at` and `enabled=0`, empties `credentials`, and cancels pending deliveries with the reason "the destination was deleted before this was sent". Credentials are sealed with the `destination_credential` key from `202_deployment_secrets`.
  - `202_destination_deliveries`: test rows, and rows queued for resend and backfill. Deliveries are sent by the queue.
  - `202_ad_connections`: insert, update and soft delete. Credentials are sealed with `ad_connection_credential`.
  - Outbound calls:
    - Google: OAuth, the account and conversion-action lists, and validation sends.
    - OpenAI: key check, setup (it creates a pixel, a Conversions API key, a destination row and event settings on OpenAI's side), campaign reads, status changes, template pushes, event-setting attachments, and real test events (saved and counted by OpenAI).
    - GA4: validation sends.
  - `ad_setup` with step `destination` creates a ChatGPT Ads destination here.
- **Edge cases and states**:
  - Limits: 25 destinations per account, 10 ad connections per account.
  - Destination states: `ready`, `needs_authorisation`, `needs_account`, and credentials unreadable.
  - Google Ads returns no refresh token: handled by the OAuth callback page below.
  - The backfill depth depends on the type.
  - The pause preview reads only; the pause happens only on "Pause it".
  - A campaign page beyond 500 entries is reached only through the CLI.
- **v3 coverage**:
  - Destinations: `GET /destinations`, `GET /destinations/{id}`, `POST /destinations` (type, name, settings, credentials), `PATCH /destinations/{id}` (name, enabled, settings, credentials), `DELETE /destinations/{id}`.
  - Tests: `POST /destinations/{id}/test` (`real`, `confirm_counted`, `check_sent_at`).
  - OAuth and the picker: `POST /destinations/{id}/oauth/start`, `GET /destinations/{id}/google-ads/accounts`, `GET /destinations/{id}/google-ads/conversion-actions`, `POST /destinations/{id}/google-ads/pick`.
  - Deliveries: `POST /destinations/{id}/deliveries/resend` (`delivery_id` or `status`), `POST /destinations/{id}/backfill`, `GET /destinations/{id}/deliveries` (also needs `access_to_campaign_data`).
  - Ad connections: `GET /ad-connections`, `GET /ad-connections/{id}`, `POST /ad-connections/{id}/check`. `POST /ad-connections`, `PATCH /ad-connections/{id}`, `DELETE /ad-connections/{id}` and `POST /ad-connections/{id}/setup` also need a full-access key (`AdConnectionKeyAccess`).
  - Ad campaigns: `GET /ad-connections/{id}/spend`, `GET /ad-connections/{id}/campaigns|ad-groups|ads` and single objects, `POST …/{objectId}/pause|activate` (with `?dry_run=1` for the preview), `POST …/{objectId}/template`, `POST /ad-connections/{id}/campaigns/{objectId}/event-settings`.
  - **PARTIAL**, three gaps:
    - `PATCH /destinations/{id}` does not keep a blank credential as the edit form does; a partial `credentials` object replaces the stored set.
    - `GET /destinations/{id}/deliveries` has no click id per row, no grouped failure reasons, and no counts of resendable deliveries over 90 days or waiting conversions as the health panel shows.
    - The traffic-source account choices for the forms come from the ppc-account routes, not from a route of this area.

### Google Ads OAuth return: `tracking202/setup/destination-oauth.php`

- **Where / who**: `/tracking202/setup/destination-oauth.php`. Not in the menu; Google's consent screen sends the browser here after Connect Google Ads, started either on Setup › Destinations or by `POST /api/v3/destinations/{id}/oauth/start`. It requires a signed-in user with `access_to_setup_section`; others are redirected to `tracking202/`.
- **Purpose**: Finish the Google Ads OAuth grant and report the result on Setup › Destinations.
- **Data shown**: Nothing of its own. It always redirects to `tracking202/setup/destinations.php`. On success it goes to `?done=connected#d<id>`. On failure it sets the session flash `p202_destination_oauth` (`ok`, `text`) and goes to `destinations.php#d<id>` (or without an anchor when no destination is known).
- **Inputs**: Query parameters from Google: `state`, `code`, `error`. They are read by `GoogleAdsOAuthCallback::handle(user, $_GET, $_SERVER, now)`.
- **Actions**:
  - Verifies the signed state (600 s, the same user).
  - Exchanges `code` at `oauth2.googleapis.com` with the redirect URI built from `TrackingBaseUrl::forRequest`.
  - Stores the refresh token (`DestinationStore::authorise`) and releases waiting conversions.
  - Failure messages:
    - Google's `access_denied`
    - "Google gave no refresh token…"
    - On a `RuntimeException`/`JsonException`: "Google Ads could not be connected. Try again; if it keeps failing, the server's error log says why." The error is also logged with `p202UncaughtLogLine`.
- **Writes and side effects**: `202_destinations` credentials and status (to `needs_account` or `ready`); waiting deliveries released; an outbound token request to Google.
- **Edge cases and states**: An expired, forged or other-user state is refused with a sentence. With no refresh token the destination stays not connected.
- **v3 coverage**: `POST /destinations/{id}/oauth/start` starts the flow. The return is this page for API clients too; v3 has no callback route of its own, by design, because the browser must land on a page. Not a gap.

### ChatGPT Ads spend sync switch: `tracking202/setup/spend_sync.php`

- **Where / who**: `/tracking202/setup/spend_sync.php`, POST only. It is posted by the ChatGPT Ads spend card (`_includes/spend_sync_card.php`) on Setup › Traffic Sources (`tracking202/setup/ppc_accounts.php`, `#chatgpt-spend`). Requires `access_to_setup_section`; others are redirected to `tracking202/`. A GET is redirected to `ppc_accounts.php#chatgpt-spend`.
- **Purpose**: Turn one ChatGPT Ads connection's hourly spend pull on or off.
- **Data shown**: None here. The card on Traffic Sources shows:
  - One row per ad connection with its account ref, from `SpendSync::status`: on/off, last run time, rows written by the last run, when the 30-day backfill finished, last error, and hours left out.
  - Whether spend reaches the ledger (`SpendLedger::reaches`).
  - The conversions the platform counted against Prosper202's over the last 7 days (`PlatformConversions::read`).
  - A Turn on / Turn off form per row.
  - The card is not shown when the account has no ad connection. When its reads fail it says it "could not be read just now".
- **Inputs**: `connection_id` (a posted id, read by `PostedId::read`), `enabled` (`0` or `1` exactly), `token`.
- **Actions**: `SpendSync::setEnabled(user, connection, bool)`, then a redirect to `ppc_accounts.php?spend_sync=<state>#chatgpt-spend`. The states and the flashes Traffic Sources shows for them:
  - `on`: "ChatGPT Ads spend is pulled every hour from now on; the first run reads the last 30 days."
  - `off`: "ChatGPT Ads spend is no longer pulled. What is already in the spend ledger stays."
  - `missing`: "That ChatGPT Ads connection is not this account's, or was removed."
  - `invalid`: "The spend sync form was not read; reload the page and try again."
  - `token`: "Invalid or expired form token. Please reload the page and try again."
- **Writes and side effects**: `202_ad_spend_sync` (the enabled flag).
- **Schedule**: The hourly cron tier (`202-cronjobs/index.php`, `SyncAdSpend()`) runs `SpendSync::runDue(now + 120)` for the connections that are on, for up to 2 minutes.
  - The first run reads the last 30 days (`BACKFILL_SECONDS`); later runs read the trailing 72 hours (`TRAILING_SECONDS`).
  - Windows are read a day at a time, at most 500 pages per run.
  - Failures back off from 1 hour up to 1 day (Retry-After up to 7 days).
  - The run writes `202_spend_ledger`.
  - A connection's failure is recorded on its row with one of: `refused`, `page_refused`, `rate_limited`, `key_refused`, `unavailable`, `unreadable_answer`, `request_refused`.
- **Edge cases and states**: Turning off keeps the spend already in the ledger. Hours OpenAI sends unreadably are read again on every run. Hours older than 30 days cannot be read again, and the left-out note says so.
- **v3 coverage**: `GET /spend-ledger/sync` (status for each connection: `enabled`, `complete`, `backfilled_at`, `last_run_at`, `last_success_at`, `last_rows`, `last_error`, `last_note`, `left_out`, `pending_from`, `retry_after`); `PUT /spend-ledger/sync/{id}` `{"enabled": true|false}`; `POST /spend-ledger/sync/{id}/run`, which has no page equivalent. The card's other reads are `GET /spend-ledger` and `GET /spend-ledger/conversions`. Covered.

### Offline Conversions: `tracking202/setup/offline_conversions.php`

- **Where / who**: `/tracking202/setup/offline_conversions.php`, Setup › Offline Conversions. Requires `access_to_setup_section`; others are redirected to `tracking202/`. `access_to_campaign_data` (`$canExport`) is needed for the download, the summary and the fetch URLs. Without it, a warn flash explains, the page shows only the conversion names form, and the fetch URL section says: "Your role does not include campaign data, which every file carries, so it cannot make fetch URLs."
- **Purpose**: Build and download the offline conversion import file for Google Ads (Data Manager or the Uploads template) or Microsoft Advertising. Set the conversion name used per campaign. Make fetch URLs the platforms poll on a schedule. Move or dismiss the schedules of the old Google feed.
- **Data shown**:
  - **Summary** of the file the current settings would make, from `OfflineExport::write` without a handle: rows, conversions, currency and zone. A "Left out" table lists each `LeftOut` reason with its count and explanation.
  - **Fetch URLs** (`#feeds`), one row per feed:
    - Format label, days, the conversion name it uses ("only name"), and consent pills.
    - Meta: when it was made, when its URL was last replaced, when it was last fetched, and rows served.
    - "Replace URL" and "Delete" buttons, each asking to confirm.
    - A newly made or replaced URL is shown once, with a copy button, from session `p202_offline_feed_url`. A warning appears when the tracking base is not https.
  - **Old Google feed** (`#old-feed`): schedules that fetched the pre-feed Google URL (`LegacyGoogleFeedFetches::forAccount`), at most 50 rows and 5 per campaign. They are shown for 35 days after the last fetch and count as idle after 2 days. States: `ready`, `moved`, `gone`.
  - **Flashes from `done=`**: `names`, `deleted`, `hidden`, `moved_name`, and `key`, `moved` or `feed` (each with the URL box).
- **Inputs**:
  - **Download form** (GET, `#download`):
    - `format`: radio or select. Choices:
      - `google`: "Google Ads (Data Manager): gclid, gbraid and wbraid"
      - `google_uploads`: "…Uploads template: gclid only"
      - `microsoft`: "Microsoft Advertising: msclkid"

      Default `google`.
    - `days`: number 1–90, default 90. Refusal: "A whole number, 1 to 90: days of conversions, back from now."
    - Under an Advanced disclosure (remembered as `setup-offline-download-advanced`):
      - `ad_user_data`, `ad_personalization`: selects of not stated (empty), `granted`, `denied`. Refusal: "granted or denied, or leave it out for the column to be empty."
      - `conversion_name`: text, at most `ConversionNames::MAX_LENGTH` (100).

      Microsoft has no consent columns.
    - Buttons: "Count again" (submits and recounts) and "Download" (`download=1`).
    - Format refusal: "One of google, google_uploads, microsoft".
  - **Conversion names form** (POST `action=save_names`, `#names`):
    - Fields `names[google][<campaign id>]` and `names[microsoft][<campaign id>]`; campaign 0 is "Every other campaign".
    - Each name is checked by `ConversionNames::problem`. The checks and their refusals:
      - blank: "A conversion name cannot be blank."
      - not UTF-8: "A conversion name must be UTF-8 text."
      - over 100 characters: "A conversion name is at most 100 characters."
      - a tab or line break: "A conversion name cannot hold a tab or a line break."
      - starting with `=`, `+`, `-` or `@`: "A conversion name cannot start with =, +, - or @, which a spreadsheet would read as a formula."
      - a leading or trailing space: "A conversion name cannot start or end with a space: the platform matches it exactly."
    - Other refusals: "The form named a campaign that is not one of yours…" and "One name per field."
  - **Make fetch URL** (POST `action=create_feed`, with `$canExport`): `format`, `days` (`FeedStore::MIN_DAYS` 2 to 90, default `FeedStore::DEFAULT_DAYS` 7), `conversion_name`, `ad_user_data`, `ad_personalization`. At most `FeedStore::MAX_FEEDS` (20) per account; more is refused (`OverflowException`).
  - **Replace URL** / **Delete** (POST, a feed id, confirm).
  - **Old feed**: `action=move_legacy_feed` with `fetch_id` (it asks to confirm when the stored conversion name differs) and `action=hide_legacy_feed` with `fetch_id`. An unknown fetch: "That schedule is no longer listed. Reload the page."
- **Actions**:
  - Download sends a CSV through `OfflineFile::write`/`send`, named `offline-conversions-<format>-<YYYYMMDD>.csv`.
  - `save_names` → `ConversionNames::save`.
  - `create_feed` → `FeedStore::create`, which returns a key. The URL is `FeedStore::url(p202TrackingBaseUrl(), key)`, which serves `api/v3/offline-conversions/<key>?file=conversions.csv`.
  - Replace → `FeedStore::replaceKey`; the old URL stops answering.
  - Delete → `FeedStore::delete`.
  - `move_legacy_feed` → `LegacyGoogleFeedFetches::move`: it makes a feed in the Uploads format with 90 days, and the result is `done=moved` or `moved_name`.
  - `hide_legacy_feed` → `dismiss` (`done=hidden`).
  - The page makes no AJAX calls.
- **Writes and side effects**: `202_offline_conversion_names`, `202_offline_conversion_feeds`, `202_legacy_google_feed_fetches`. Each fetch of a feed URL records the fetch time and the rows served (`FeedStore::recordFetch`).
- **Edge cases and states**:
  - Every refusal of a feed URL (a bad key, a replaced or deleted feed) answers the same 404.
  - The feed fetch is rate-limited to 60 requests in 60 s.
  - Microsoft files are limited to 100,000 rows (`Format`).
  - A tracking base that is not https gets a warning, because the platforms want https.
- **v3 coverage**:
  - `GET /conversions/offline-export` (the file) and `GET /conversions/offline-export/summary`, both needing `access_to_campaign_data`. The summary also returns the `reasons` explanations. Both take `format`, `days`, consent, `conversion_name` and `campaign_id`.
  - `GET /offline-conversions/{key}`, the public fetch, before authentication.
  - **MISSING** (confirmed: `ConversionNames`, `FeedStore` and `LegacyGoogleFeedFetches` appear in `api/v3` only in `serveFeed`):
    - making, listing, replacing and deleting fetch URLs
    - reading and saving conversion names
    - listing, moving and hiding the old Google feed's schedules

### Inbound Log: `tracking202/setup/inbound_log.php`

- **Where / who**: `/tracking202/setup/inbound_log.php`, Setup › Inbound Log.
  - Entry needs `access_to_api_integrations` or `access_to_setup_section` (`RefundAccess::maySee`); others are redirected to `tracking202/`.
  - The log itself needs `access_to_setup_section` (`$seesLog`). Without it, the page shows only the Shopify refunds, titled "Shopify refunds".
  - The requests no account was found for need `$seesLog` and `add_users`.
  - "Record it now" needs `$seesLog` and `access_to_update_section` (`$mayRecord`).
  - Settling a refund needs `access_to_api_integrations`, or `access_to_setup_section` with `access_to_update_section` (`RefundAccess::maySettle`).
- **Purpose**: Show every request to the conversion intakes and what was done with each, so a refused postback can be found, explained, fixed and recorded. Settle the Shopify refunds that were not taken back automatically.
- **Data shown**:
  - **Header**: what is kept, up to 5,000 requests per account and 5,000 refused (`KEEP_PER_ACCOUNT`, `KEEP_REFUSED`).
  - **Log table**, 100 rows per page; "Older requests" links with `before=<last id>`. Columns:
    - Time
    - Endpoint (with "(account —)" when no account was found)
    - Status
    - Outcome, with the authentication (none, invalid, token, signed)
    - Why: the reason, a fix sentence and link, "recorded later at" when replayed, and the Record it now button
    - Click: a breakdown button
    - Conversion
    - Request: the received fields as JSON inside a details element
  - **Click breakdown**: the `[data-p202-breakdown]` button opens a modal. `202-js/p202-overview.js` fetches `tracking202/ajax/click_conversions.php?click_id=N` (HTML; ClickBreakdown scoped by `DataScope::userId()`).
  - **Info flashes**: admin rows with no account; Shopify webhooks not verified.
  - **Shopify refunds** (`#shopify-refunds`), up to 200 rows (`LIMIT`), with "more" when there are more. Columns:
    - Refunded
    - Order
    - Refund
    - Refund states (amount and currency)
    - Why it is not recorded
    - Settle

    Warnings for stale rows, and for when the transaction webhook was never seen.
- **Inputs**:
  - **Filters** (GET), read by `InboundLogController::filters`:
    - `outcome`: select of `''` Everything, `refused`, `accepted`. Any `[a-z_]{1,32}` outcome name is accepted from links, for example `currency_mismatch` and `unauthenticated`.
    - `endpoint`: select of `pb`, `gpb`, `px`, `gpx`, `upx`, `clickbank`, `jvzoo`, `paykickstart`, `shopify`, `zaxaa`, with labels.
    - `search`: text, at most 255 characters.
    - `click_id`: shows a notice with a "Show every request" link.
    - `before`: the cursor.
  - **Record it now** (POST `inbound_id`, with a confirm from `InboundReplay::question(row, destinations, time, click facts, sale on file)`).
  - **Record refund** (POST `action=record_refund`): `refund_id`, `amount` (text, decimal, required), `click_id` (a select when the order has several clicks), and a hidden `currency` when the refund is in another currency.
  - **Close refund** (POST `action=close_refund`, with confirm): `refund_id`.
- **Actions**:
  - Record it now → `InboundReplay::replay`. The outcomes:
    - `recorded`: shown as ok
    - `duplicate`, `already_recorded`, `in_progress`: shown as info
    - `refused`, `not_recordable`, `not_found`, `failed`: shown as bad

    The token, the role and a bad id each have their own refusal sentence.
  - Record refund → `RefundsToSettle::takeBack`; close → `close`. Both set the session flash `p202_inbound_log_flash` and redirect to `#shopify-refunds`.
  - AJAX: the click breakdown GET above.
- **Writes and side effects**:
  - A replay records the conversion through the same path the original request would have taken, and stamps `recorded_later_at` on the `202_inbound_log` row. Destinations are queued as for any conversion; the confirm names them.
  - Record refund writes the reversal for the order's conversion; close marks the refund settled without one.
- **Edge cases and states**: A request already recorded or recorded by another request is not recorded twice. The refunds section alone is shown to an integrations-only role. When no row matches the filters, the empty state shows.
- **v3 coverage**:
  - `GET /conversions/inbound-log` (Setup; `outcome`, `endpoint`, `search`, `click_id`, `limit` ≤ 500 default 50, `before`; returns `next_before` and `kept_per_account`; `add_users` sees rows with no account).
  - `POST /conversions/inbound-log/{id}/record` (Setup and Update).
  - `GET /conversions/shopify-refunds`.
  - `POST /conversions/shopify-refunds/{id}/record` (`amount`, `click_id`, `currency`) and `POST /conversions/shopify-refunds/{id}/close`.
  - `GET /clicks/{id}/conversions` for the breakdown.
  - **PARTIAL**: the list does not return the replay's confirm question, that is, what recording would do (the destinations it would send to, the click facts, a sale already on file). An API client records blind.

## Missing v3 endpoints

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Offline Conversions | Makes, lists, replaces the key of and deletes fetch URLs (`FeedStore`) | `GET /offline-conversions/{key}` (the public fetch only) | No route to create, list, replace or delete a feed |
| Offline Conversions | Reads and saves the per-campaign conversion names for Google and Microsoft (`ConversionNames`) | `GET /conversions/offline-export` (takes a one-off `conversion_name`) | No route to read or save stored names |
| Offline Conversions | Lists the old Google feed's fetch schedules, moves one to a fetch URL, hides one (`LegacyGoogleFeedFetches`) | none | No list, move or dismiss route |

## Partial v3 coverage

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Destinations | Edit form keeps a credential left blank | `PATCH /destinations/{id}` | A partial `credentials` object replaces the whole stored set; omitting `credentials` keeps them |
| Destinations | Health panel: status counts, grouped failure reasons, resendable counts over 90 days, waiting count; log rows link to the click | `GET /destinations/{id}/deliveries` | `meta.summary` counts only; no reasons grouping, no resendable or waiting counts, no `click_id` per row |
| Inbound Log | "Record it now" confirm says what recording would do (destinations, click facts, sale on file) | `GET /conversions/inbound-log`, `POST /conversions/inbound-log/{id}/record` | The list returns no confirm question or replay facts |

## Not determined from code

- The exact list and labels of `DestinationQueue::STATUSES`, and of the `LeftOut` reasons on Offline Conversions. I used the constants by name and did not open their definitions.
- What `LegacyGoogleFeedFetches::move` does when the account already has `FeedStore::MAX_FEEDS` feeds. I read its signature, not its body.
- Whether `OfflineExport` applies the stored conversion names when the request gives no `conversion_name`, on the page and in the v3 export. I did not open `OfflineExport`.
