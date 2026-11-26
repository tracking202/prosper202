# Setup: traffic sources, categories and campaigns

This area covers the Setup pages where an account records where its traffic comes from and where it sends it. That means traffic sources, the accounts on each source, a source's custom variables, an account's conversion pixels and correction URLs, the ChatGPT Ads preset and the ChatGPT Ads spend sync, campaign categories, and campaigns. Campaigns include the offer URL, payout and currency, rotation, cloaking, the attribution model, the goals panel and the offer-network (DNI) browser. The Setup index is a redirect into this area.

All the Setup list pages share the same list behaviour:

- **Search.** A client-side filter (`data-p202-filter-list`, in `202-js/p202-setup.js`) appears only when a list has more than 5 items. It matches a case-insensitive substring of each row's text, and a parent row stays visible while any of its children matches. Nothing is sent to the server.
- **Sort.** There is no sort control. The server orders every list by name, ascending.
- **Edit-in-place.** A `?edit_*_id=` link reloads the same page with the form filled in from the row. A save posts the form and redirects back with a flash query key (post-redirect-get).
- **Clone.** Only campaigns have one, through `?copy_aff_campaign_id=`.
- **Delete.** Every "remove" control is a GET form built by `p202_setup_remove_form()` that carries `?token=`. It asks `confirm()` (`form[data-p202-confirm]`) and nothing else: **no page in this area checks where a record is used before removing it.**
  - Each remove needs its own `remove_*` permission. Super user and Admin hold all of them; Campaign manager holds `access_to_setup_section` but none of them.
  - The remove is a soft delete through `Prosper202\Setup\SetupRemoval::mark()`. It sets `*_deleted = 1` and `*_time = now()` where the id and `user_id` match and the row is not already deleted. It reads the id with `PostedId::read()`, so an id like `12abc` names nothing.
  - The result is answered with a flash: `?deleted=1`, or an error sentence when no row was marked.
- **Disclosures.** A disclosure marked `data-p202-remember` keeps its open or closed state per browser in `localStorage`, under the key `p202-disclosure:<key>`.

`tracking202/setup/_base/SetupController.php` is the abstract base class for a Setup page built as a controller. It requires a login and `access_to_setup_section`, validates a `csrf_token` field with `AUTH::csrf_token_matches()`, re-renders with the message on an `InvalidArgumentException`, and redirects to `tracking202/setup/` on any other exception. Only `MobileAppsController` extends it (Setup › Mobile Apps, another area). **No page in this area uses it.** The pages here call `AUTH::require_user()`, check `$userObj->hasPermission('access_to_setup_section')` and redirect to `tracking202/` when it is missing. They check the hidden `token` field with `AUTH::check_csrf_token()` on POST, and `?token=` with `AUTH::csrf_token_matches()` on a remove.

| Page | Path | Permission |
|---|---|---|
| Setup (index) | `tracking202/setup/index.php` | signed in (the page it redirects to checks `access_to_setup_section`) |
| Traffic Sources | `tracking202/setup/ppc_accounts.php` | `access_to_setup_section`; remove a source and edit variables: `remove_traffic_source`; remove an account: `remove_traffic_source_account` |
| Campaign Categories | `tracking202/setup/aff_networks.php` | `access_to_setup_section`; remove: `remove_campaign_category` |
| Campaigns | `tracking202/setup/aff_campaigns.php` | `access_to_setup_section`; remove: `remove_campaign` |

### Setup (index): `tracking202/setup/index.php`

- **Where / who:** the Setup menu's root URL. It requires a signed-in user and makes no permission check of its own.
- **Purpose:** sends the user to the first Setup page.
- **Data shown:** none.
- **Inputs:** none.
- **Actions:** redirects to `tracking202/setup/ppc_accounts.php`. A user without `access_to_setup_section` is then redirected again by that page, to `tracking202/`.
- **Writes and side effects:** none.
- **Edge cases and states:** none beyond the login redirect.
- **v3 coverage:** not applicable (navigation only).

### Traffic Sources: `tracking202/setup/ppc_accounts.php`

- **Where / who:** Setup › Traffic Sources (page title "Traffic Sources"). It needs `access_to_setup_section`. Removing a source and the "variables" button need `remove_traffic_source`. Removing an account needs `remove_traffic_source_account`. `hasPermission()` has no super-user bypass.
- **Purpose:** add, rename and remove traffic sources (`202_ppc_networks`) and the accounts on them (`202_ppc_accounts`). Edit a source's custom variables. Set an account's conversion pixels and correction URLs. Add the ChatGPT Ads preset. Turn ChatGPT Ads spend sync on or off.
- **Data shown:**
  - Sources: the user's live sources (`user_id`, not deleted) by name.
    - Each row shows the source name, a "ChatGPT Ads" pill when `ppc_network_platform = 'chatgpt_ads'`, an account count and "edit".
    - With `remove_traffic_source`, it also shows "variables" and "remove".
  - Accounts: each source's accounts are nested under it, with "edit", plus "remove" with `remove_traffic_source_account`. A source with no accounts shows "No accounts yet".
  - The list header shows a count pill ("N sources"). With no sources, the empty state reads "No traffic sources yet".
  - Variables (for the dialog): `202_ppc_network_variables` joined to the source, `deleted = 0`, ordered by id. Pixel types come from `202_pixel_types`: 1 Image, 2 Iframe, 3 Javascript, 4 Postback, 5 Raw, 6 Bot202 Facebook Pixel Assistant, 7 ChatGPT Ads Pixel.
  - Flash messages from the query string, through `p202_setup_query_flashes`:
    - Saves and removes: `source_added`, `source_saved`, `account_added`, `account_saved`, `deleted` ("Removed. Clicks already tracked keep their history.") and `variables_saved`.
    - ChatGPT Ads: `chatgpt_added`, `chatgpt_adopted`, `chatgpt_completed` and `chatgpt_exists`. After any of these, a warning names any unmarked source that has an `ad_id` variable.
    - Spend sync: `spend_sync=on|off|missing|invalid|token`.
  - Field errors are flashed for `ppc_network_name`, `ppc_network_id`, `ppc_account_name` and `ppc_network_chatgpt`, through `p202_setup_error_flashes`, which opens with "Nothing was saved. One field needs attention; …".
  - The ChatGPT Ads spend card (`_includes/spend_sync_card.php`) appears only when ChatGPT Ads connections exist (`SpendSync::status`).
    - It shows the heading "ChatGPT Ads spend", how far back spend data reaches (`SpendLedger::reaches`) and the last 7 days of platform conversions (`PlatformConversions::read`).
    - Each connection shows its `account_ref`, a "Pulling spend hourly" or "Off" pill, a turn-on/turn-off form, the last run time and rows, and `last_error` and `left_out`.
    - When the status cannot be read, it shows "ChatGPT Ads spend sync could not be read just now; reload the page to try again."
- **Inputs:**
  - Source form:
    - `ppc_network_name`: maxlength 50, required.
    - When a source is being edited (`?edit_ppc_network_id=&edit_ppc_network_name=`), there is an Advanced disclosure (remember key `setup-traffic-sources-chatgpt`). It holds the checkbox `ppc_network_chatgpt=1` with the hidden `ppc_network_platform_shown=1`.
  - "Add ChatGPT Ads" form (`add_chatgpt_ads=1`): shown when no source is being edited.
  - Account form: shown only when sources exist and no source is being edited. Editing an account uses `?edit_ppc_account_id=`, and the form carries `do_edit_ppc_account=1`.
    - `ppc_network_id`: a select, chosen for you when there is only one source.
    - "Account username" `ppc_account_name`: maxlength 50.
    - Under Advanced (remember key `setup-traffic-sources-pixels`), repeating pixel rows built from a template. Each row has `pixel_type_id[]` (a select, "None" by default), `pixel_code[]` (a textarea), `pixel_correction_url[]` (a text input) and a hidden `pixel_id[]`. Rows after the first have "Remove this pixel", and "Add a pixel" adds a row.
  - Variables dialog (`#variables-modal`): one row per variable with Name in reports, Parameter and Placeholder, all required.
  - Spend card: `connection_id` and `enabled` (`0` or `1`).
- **Actions:**
  - **Add ChatGPT Ads** (POST `add_chatgpt_ads=1`) → `ChatGptAdsSource::add()`. Under a lock on the user's `202_users` row:
    - It uses the existing source whose platform is `chatgpt_ads`. Failing that, it adopts the single unmarked live source named "ChatGPT Ads" by setting its platform. Failing that, it inserts the source "ChatGPT Ads".
    - It adds the account "ChatGPT Ads account" if the source has no account.
    - It inserts any missing variables: Campaign ID `campaign_id` `{campaign_id}`, and Ad ID `ad_id` `{ad_id}`.
    - It redirects with `chatgpt_added`, `chatgpt_adopted`, `chatgpt_completed` or `chatgpt_exists`.
  - **Add or rename a source** (POST `ppc_network_name`):
    - Refusals: an empty name → "Type in the name the traffic source." A name too long → "… can be at most 50 characters; this one has N." (counted in characters).
    - A rename locks the row (`SELECT … FOR UPDATE` on id, `user_id`, not deleted) inside a transaction, then runs `UPDATE … ppc_network_name, ppc_network_platform = COALESCE(?, ppc_network_platform), ppc_network_time`.
    - The ChatGPT choice is read only when `ppc_network_platform_shown=1` is posted. Any value other than unset or `1` → "The ChatGPT Ads choice was not one the form offers. Nothing was saved." A source that is not the user's → "That traffic source was not found on this account. Nothing was saved."
    - An add runs `INSERT 202_ppc_networks SET user_id, ppc_network_name, ppc_network_time`.
  - **Add or edit an account** (POST with `ppc_network_id`):
    - Refusals for the name and source: an empty name → "What is the username for this account?". A name longer than 50 characters is refused. No source → "What traffic source is this account attached to?". A source that is not the user's → "You are not authorized to add an account to another user's traffic source". An edited account must be the user's.
    - Pixels are read by `AccountPixels::fromForm()` from the parallel arrays:
      - A code without a type → "A pixel code needs its pixel type; …".
      - Type 6 needs a Meta Pixel ID, and type 7 needs an OpenAI pixel id.
      - Codes are CRLF-normalized and trimmed.
    - Postback codes and correction URLs are checked by `PostbackSender::refusalAtSave()`, which refuses private hosts and the like.
    - Correction URLs are allowed only on a Postback pixel (type 4), otherwise a sentence refuses them. `CorrectionUrls::problem()` also applies: http(s) only, at most 2048 characters, and no more URLs than the pixel code has.
    - The write runs inside `TrafficSourceAccountWrite::run()`, which locks the account and its source(s) and re-checks `ownsLive`.
  - **Remove a source** (GET `delete_ppc_network_id`, `delete_ppc_network_name`, `token`):
    - A bad token, or a user without `remove_traffic_source`, is redirected with nothing done.
    - Otherwise `SetupRemoval::mark('traffic_source')` runs. Failure → "That traffic source was not found on this account. Nothing was removed."
  - **Remove an account** (GET `delete_ppc_account_id`, `token`):
    - It requires `remove_traffic_source_account`.
    - `SetupRemoval::mark('traffic_source_account')` runs inside `TrafficSourceAccountWrite::run()`. Failure → "That traffic source account was not found on this account. Nothing was removed."
  - **Save variables:** the dialog POSTs to `tracking202/ajax/custom_variables.php`, an AJAX endpoint, not a page.
    - Access: `AUTH::require_permissions('access_to_setup_section', 'remove_traffic_source')`. A refused user gets 403 "This account's role does not have the '…' permission."
    - Method and token: a non-POST gets 405 "POST only". A bad token gets 403 "Invalid token, please reload the page and try again."
    - The source must be the user's and not deleted, otherwise 404 "That traffic source is not yours, or it was removed."
    - `post_vars=1&vars[]=…` inserts or updates each listed variable's name, parameter and placeholder, then soft-deletes (`deleted = 1`) the source's live variables that are not listed. Any empty field → 400 "VALIDATION FAILD!".
    - `delete_vars=1`, which the dialog sends when no rows remain, **hard-deletes** the source's live variables (`DELETE FROM 202_ppc_network_variables WHERE ppc_network_id = ? AND deleted = 0`).
    - Answers: "DONE!" on success; any other action gets 400 "Unknown action."
    - In the browser, "DONE!" reloads with `?variables_saved=1`. Anything else shows "The variables were not saved: …". If the variables JSON on the page cannot be read, saving is disabled and a message is shown.
  - **Turn spend sync on or off:** the card's form POSTs to `tracking202/setup/spend_sync.php`, a handler, not a page.
    - It requires a login and `access_to_setup_section`. A GET redirects to `#chatgpt-spend`.
    - It checks the token, reads `connection_id` with `PostedId` and `enabled` (only `0` or `1`), and calls `SpendSync::setEnabled()`.
    - It redirects with `?spend_sync=on|off|missing|invalid|token#chatgpt-spend`.
- **Writes and side effects:**
  - Database: `202_ppc_networks`, `202_ppc_accounts`, `202_ppc_account_pixels` and `202_notification_correction_urls`.
    - `AccountPixels::save()` leaves exactly the listed pixels: it updates, inserts or deletes rows.
    - `CorrectionUrls::saveForAccount()` writes the correction URLs and prunes orphans.
  - The variables endpoint writes `202_ppc_network_variables`, and the spend sync handler writes its state.
  - `DimensionSync::markDirty()` runs after source and account saves, removes and ChatGPT Ads adds that changed anything. It sets `dims_dirty` only while LPO is active. The variables endpoint does not call it.
  - An account moved to another source → `RollupRefresh::account()`, which queues the account's clicks' report rows in `202_dirty_hours`.
  - Slack (`Slack::forAccount`):
    - Sources: `traffic_source_created`, `traffic_source_name_changed`, `traffic_source_deleted`.
    - Accounts: `traffic_source_account_created`, `traffic_source_account_deleted`, the account name change, and pixel added or pixel type/code changed.
  - Analytics `traffic_source_added` `{via: ui}` fires on a new source, including one made by the ChatGPT Ads preset. Analytics fires on create only.
- **Edge cases and states:**
  - Editing a source hides the account form.
  - The variables button is hidden without `remove_traffic_source`, and its endpoint refuses such a user.
  - The variables endpoint enforces no length limit and does not check a parameter against the tracking link's own parameters. v3 does both (see below).
  - Removing the last variable row hard-deletes the variables, while removing some of them soft-deletes those.
  - No "where it is used" check is made before removing a source or account; trackers and ad connections that name one are not mentioned.
- **v3 coverage:**
  - Sources:
    - `GET /ppc-networks` (server-side `q` search on the name, `sort`, `filter[…]`, `limit`, `offset`, `cursor`, `with=usage`) and `GET /ppc-networks/{id}`.
    - `POST /ppc-networks` and `PUT /ppc-networks/{id}`: name required, max 50; `ppc_network_platform` `''` or `chatgpt_ads`.
    - `DELETE /ppc-networks/{id}`: needs `remove_traffic_source`; `?dry_run=1` lists `in_use_by`.
    - `GET /ppc-networks/{id}/uses` (accounts).
  - Accounts:
    - `GET/POST /ppc-accounts`, `GET/PUT /ppc-accounts/{id}`. Writes go through `TrafficSourceAccountWrite::run()`, and a move to another source queues `RollupRefresh::account()`.
    - `DELETE /ppc-accounts/{id}`: needs `remove_traffic_source_account`.
    - `GET /ppc-accounts/{id}/uses` (trackers, ad connections).
  - Pixels: `GET/POST /ppc-accounts/{id}/pixels` and `PUT/DELETE /ppc-accounts/{id}/pixels/{pixelId}`. These apply the form's checks: type list, type 6 and type 7 ids, `refusalAtSave`, and correction URL on type 4 only.
  - Variables: `GET/POST /ppc-networks/{id}/variables` and `PUT/DELETE /ppc-networks/{id}/variables/{variableId}`, needing `remove_traffic_source`.
  - ChatGPT Ads preset: `POST /ppc-networks/chatgpt-ads`.
  - Spend sync: `GET /spend-ledger/sync`, `PUT /spend-ledger/sync/{id}` `{enabled: bool}` and `POST /spend-ledger/sync/{id}/run`.
  - `bulk-upsert` exists for sources and accounts. `markDirty` runs in the base write.
  - **PARTIAL:**
    - The account form saves the account, all its pixels and their correction URLs in one save, replacing the set. v3 takes one pixel per call.
    - The variables dialog replaces the whole set in one POST. v3 is per variable.
    - v3 sends no Slack and no Analytics event.
    - v3's delete does not stamp `*_time`.

### Campaign Categories: `tracking202/setup/aff_networks.php`

- **Where / who:** Setup › Categories (page title "Campaign Category Setup", heading "Campaign Categories"). It needs `access_to_setup_section`, and "remove" needs `remove_campaign_category`.
- **Purpose:** add, rename and remove campaign categories (`202_aff_networks`).
- **Data shown:**
  - The user's live categories, by name. A "network integration" pill shows when `dni_network_id` is set.
  - A count pill. With no categories, the empty state reads "No categories yet".
  - Flashes: `added` "Category added. Add its campaigns next.", `saved` "Category renamed." and `deleted` "Category removed. Its campaigns keep their history."
- **Inputs:** `aff_network_name` (maxlength 50, required). Editing a category uses `?edit_aff_network_id=`.
- **Actions:**
  - **Add or rename** (POST `aff_network_name`):
    - Refusals: a bad token → "Invalid token, please reload the page and try again." An empty name → "Type in the name of your campaign's category." A name longer than 50 characters is refused. Editing a category that is not the user's → "You are not authorized to edit another users network".
    - It then inserts or updates `202_aff_networks` (`user_id`, `aff_network_name`, `aff_network_time`).
  - **Remove** (GET `delete_aff_network_id`, `delete_aff_network_name`, `token`):
    - It requires `remove_campaign_category`, and asks `confirm()` with 'Remove the category "X"? Its campaigns keep their clicks and history.'
    - `SetupRemoval::mark('campaign_category')` runs. Failure → "That campaign category was not found on this account. Nothing was removed."
- **Writes and side effects:**
  - Database: `202_aff_networks`.
  - `DimensionSync::markDirty()` runs after a save or a remove.
  - Slack: `campaign_category_created`, `campaign_category_name_changed`, `campaign_category_deleted`.
  - Analytics `aff_network_added` fires on create.
- **Edge cases and states:**
  - On GET, a `?edit_aff_network_id=` naming another account's category falls back to the add form.
  - A refused POST runs `array_map('htmlentities', $_POST)`, so a posted array value (`aff_network_name[]=`) would throw a TypeError instead of showing the error.
  - Removing a category does not check for or remove its campaigns.
- **v3 coverage:**
  - `GET /aff-networks` (`q`, `sort`, `filter`, `with=usage`) and `GET /aff-networks/{id}`.
  - `POST /aff-networks` and `PUT /aff-networks/{id}`: `aff_network_name` required, max 50; `dni_network_id`.
  - `DELETE /aff-networks/{id}`: needs `remove_campaign_category`; the dry run lists `in_use_by`.
  - `GET /aff-networks/{id}/uses` (campaigns) and `POST /aff-networks/bulk-upsert`.
  - **PARTIAL:** v3 sends no Slack and no Analytics event, and its delete does not stamp `aff_network_time`.

### Campaigns: `tracking202/setup/aff_campaigns.php`

- **Where / who:** Setup › Campaigns (page title "Affiliate Campaigns Setup", heading "Campaigns"). It needs `access_to_setup_section`, and "remove" needs `remove_campaign`. The goals panel runs through `GoalsController`. App-scope goals also need `manage_attribution_models`, but this page makes only campaign-scope goals.
- **Purpose:** add, edit, copy and remove campaigns (`202_aff_campaigns`), manage a campaign's goals, and browse and set up offers from an integrated offer network (DNI).
- **Data shown:**
  - Category rows come from `202_aff_networks` LEFT JOIN `202_dni_networks` (tied on `user_id`), for the favicon and the processed flag.
    - Each shows its campaign count.
    - A category with an integration shows "offers loading" while it is not processed, and a "search offers" button once it is.
  - Campaign rows sit under their categories:
    - Name, and a payout pill: `dollar_format` in the account currency, plus the foreign amount when the campaign is paid in another currency.
    - A "rotates URLs" mark.
    - Links: one that opens the campaign URL in a new tab, "edit" and "copy", plus "remove" with `remove_campaign`.
  - A total count pill. With no categories, the empty state reads "Add a category first" and links to `aff_networks.php`.
  - The account currency comes from `202_users_pref.user_account_currency`, through `normalizeCurrency`.
  - Flashes:
    - `added` "Campaign added. Get its tracking link from Get Links."
    - `saved`
    - `deleted` "Campaign removed. Its clicks and conversions keep their history."
    - `goal_saved` and `goal_archived`
  - Field errors are flashed for `aff_network_id`, `name`, `url`, `payout`, `currency`, `url_2`–`url_5` and `payout_mode`. `attribution_model_id` is not in that list, so its error shows both at the top and under the field.
  - Goals panel (`_includes/campaign_goals_panel.php`, `campaign_goals.php`, anchor `#campaign-goals`): shown only when an existing campaign is being edited.
    - It reads goals with `GoalsController::list(campaign_id, include_archived=1, limit 500)` and `get()` for each. Goals attached from another scope come from `MysqlGoalRepository::campaignTerms()`.
    - Each row shows the name, a pill (archived, payout or tracked), "edit" (only when `p202_goal_form_fits`) and an archive form with a confirm. Its meta line gives the event, the worth, whether the traffic source is told, "reached N times", and "edit with p202 goal update ID" when the form cannot show the goal. Attached goals say "shared <scope> goal … paid here".
    - A warning shows when more than one paid goal exists and `payout_mode` is not `accumulate`. With no goals: "No goals yet".
- **Inputs:**
  - Offer fields (`_includes/campaign_form/offer.php`):
    - `aff_network_id`: a select, required. With a single category it is chosen for you, with the note "Your only category, so it is chosen for you."
    - `aff_campaign_name`: maxlength 50, required.
    - `aff_campaign_url`: a textarea, required, with the Link Assistant and a placeholder disclosure (remember key `setup-campaigns-placeholders`) that inserts a token at the cursor: `[[subid]]`, `[[c1]]`–`[[c4]]`, `[[random]]`, `[[referer]]`, `[[gclid]]`, `[[gbraid]]`, `[[wbraid]]`, `[[ttclid]]`, `[[msclkid]]`, `[[fbclid]]`, `[[oppref]]`, `[[utm_source]]`, `[[utm_medium]]`, `[[utm_campaign]]`, `[[utm_term]]`, `[[utm_content]]`, `[[payout]]`, `[[cpc]]`, `[[cpc2]]` and `[[timestamp]]`.
    - Currency: a select offering the supported currencies, the currency the edited campaign already holds, and the account currency labelled "(your account)".
    - Payout: a text input, `inputmode` decimal, required.
  - Advanced (`_includes/campaign_form/advanced.php`, remember key `setup-campaigns-advanced`). It opens by itself when a setting is away from its default or was refused.
    - `payout_mode`: `replace` "Keep the latest" or `accumulate` "Add them up".
    - `aff_campaign_cloaking`: 0 "Off by default" or 1 "On by default".
    - Attribution model (`attribution_model_field.php`): "Account default (name)" or one of the user's models, listed as "name — type (status)", with a "Manage models" link to `202-account/attribution.php`. If the models cannot be loaded, a note shows instead.
    - Rotation is offered only when the account already has a campaign with `aff_campaign_rotate = 1`: the radio `aff_campaign_rotate` (0 or 1), and `aff_campaign_url_2`–`url_5`, shown only while rotate is 1.
  - Hidden `dni_id` and `dni_offer_id`, filled in by the offer browser.
  - Goal form:
    - `goal_name`: maxlength 100, required.
    - `goal_event`: maxlength 64, pattern `[A-Za-z0-9_][A-Za-z0-9_.:\-]{0,63}`, required.
    - `goal_value`: `fixed`, `property` or `none`, with `goal_amount` when fixed.
    - Under Advanced (remember key `setup-campaign-goal-advanced`):
      - `goal_count` (1–10000).
      - `goal_repeat` (`once` or `each`), with `goal_repeat_max`.
      - `goal_within_days` (1–3650).
      - `goal_after`: one of the campaign's other live goals.
      - `goal_where_prop`, with `_op` (eq, neq, gt, gte, lt, lte), `_value` and `_type` (auto, text, number, bool).
      - `goal_payout`.
      - `goal_notify`: a checkbox, on by default, with a hidden 0.
  - Query modes: `?edit_aff_campaign_id=`, `?copy_aff_campaign_id=`, `?edit_goal_id=`, `?dl_dni&dl_offer_id=` (opens the offer browser on an offer) and `?ddlci=`.
  - The Link Assistant (`p202-link-assistant.js`, fed by `LinkAssistant::networks()`) suggests where `[[subid]]` goes in the network's URL, with Add, Replace and "No thanks". It runs only in the browser.
- **Actions:**
  - **Add or edit a campaign** (POST). The checks run in this order:
    1. Token.
    2. "Select a category." when `aff_network_id` is empty.
    3. `attribution_model_id` must be one of the user's models (`ModelRepository::row`), otherwise "Choose one of your attribution models, or leave it on the account default."
    4. Name: "What is the name of this campaign." when empty; at most 50 characters.
    5. URL: "What is your affiliate link? Make sure subids can be added to it." when empty. A URL not starting `http://` or `https://` → "Your Landing Page URL must start with http:// or https://".
    6. `payout_mode`, when posted, must be `replace` or `accumulate`, otherwise "Choose keep the latest or add them up."
    7. A non-numeric payout → "Please enter in a numeric number for the payout."
    8. The currency must be in `UsersController::SUPPORTED_CURRENCIES` or be the one the edited campaign holds, otherwise "Choose a currency from the list."
    9. Category ownership → "You are not authorized to add an campaign to another users network". Campaign ownership is checked on `$_POST['aff_campaign_id']` → "You are not authorized to modify another users campaign".
    10. A payout in a currency other than the account's is converted by `CampaignCurrency::store()` through `ExchangeRates::convert()`, an outbound call. A payout of 0 needs no rate. A foreign payout must be above 0 and below 1,000,000. With no rate: "… Nothing was saved. Try again, or enter the payout in X."
  - The campaign write then inserts or updates `202_aff_campaigns`: category, name, URL, `url_2`–`url_5`, rotate, payout, currency, foreign payout, cloaking, `attribution_model_id` (NULL for the default), `payout_mode` (only when posted) and `aff_campaign_time`. Rotate, cloaking and `url_2`–`url_5` are not validated.
  - **Copy** (`?copy_aff_campaign_id=`): the form is filled from the row with " (Copy)" appended to the name and the attribution model cleared. Saving creates a new campaign. Goals are not copied.
  - **Remove** (GET `delete_aff_campaign_id`, `token`): it requires `remove_campaign`. `SetupRemoval::mark('campaign')` runs. Failure → "That campaign was not found on this account. Nothing was removed."
  - **Goals** (POST `goal_action=save|archive` to `?edit_aff_campaign_id=`):
    - Checks: the token, and that the campaign is the user's and live, otherwise "Goals belong to a campaign of yours: open the campaign to edit its goals."
    - A new goal → `GoalsController::create({scope: campaign, scope_id, definition, payable, notify_traffic_source, payout})`.
    - An edit → `update(definition)`, then `attachCampaign()` or `detachCampaign()`.
    - Archive → `GoalsController::delete()`.
    - Other refusals: "Unknown goal action.", "That goal is not one of this campaign's own goals." and "This goal has settings the form cannot show. Edit it with p202 goal update ID."
    - It redirects with `goal_saved` or `goal_archived` and `#campaign-goals`.
  - **Offer browser** (`#dni-offers-modal`), using the AJAX endpoint `tracking202/ajax/dni_get_offers.php`. It requires `access_to_setup_section`. The calls that act need POST and the token (403 otherwise). An id not matching `[A-Za-z0-9_-]{1,64}` is refused with 400.
    - GET `?all_offers&dni&offset&limit&column[&filter[0]=offer][&filter[1]=name]` lists offers. The limit is capped at 1–100, and the table pages 25 at a time with Previous and Next. Its columns are ID, Name, Payout, Type, Preview and Status.
    - GET `get_offer&offer_id` shows one offer.
    - POST `request_offer_access&type` and POST `submit_offer_questions` ask the network for access.
    - POST `setup_offer&ddlci` answers JSON that fills `dni_id`, `dni_offer_id`, the category, name, URL and payout. A category without the integration gets 409, and bad data from the network gets 502.
    - Every call goes to `https://my.tracking202.com/api/v2/dni/…`.
- **Writes and side effects:**
  - Database: `202_aff_campaigns`, and the goal tables through `GoalsController`.
  - On create:
    - `aff_campaign_id_public` is set to a random digit, then the id, then a random digit.
    - If `dni_id` and `dni_offer_id` were posted, `setupDniOfferTrack()` calls `POST https://my.tracking202.com/api/v2/dni/{hash}/offers/{network}/setup/track/[dl/]{id}`.
    - Slack `campaign_created` and Analytics `aff_campaign_added`.
  - On edit, Slack events fire per change: `campaign_name_changed`, `campaign_category_changed`, `campaign_category_rotation_changed`, `campaign_url_changed`, `campaign_payout_changed` and `campaign_cloaking_changed`.
  - A foreign currency → `ExchangeRateCache::refreshQuietly()`.
  - A category change → `RollupRefresh::campaign()`.
  - `DimensionSync::markDirty()` runs after a save or a remove.
  - A remove sends no Slack event.
- **Edge cases and states:**
  - Rotation cannot be turned on from this page until some campaign already rotates.
  - The edit flag comes from the query string, while the ownership check reads `$_POST['aff_campaign_id']`.
  - Copying does not copy goals or the attribution model.
  - No "where it is used" check is made before a remove (trackers, rotator rules and landing pages that name the campaign are not mentioned).
  - The goals panel is absent on a new or copied campaign until it is saved.
- **v3 coverage:**
  - `GET /campaigns` (`q`, `sort`, `filter`, `with=usage`) and `GET /campaigns/{id}`.
  - `POST /campaigns` and `PUT /campaigns/{id}`:
    - Name required, max 50; URL required, max 2048; `url_2`–`url_5`; payout required; currency (max 3, defaulted to the account currency on create); `aff_campaign_foreign_payout`; cloaking; rotate.
    - `payout_mode` (`replace` or `accumulate`) and `attribution_model_id` (checked to be the user's).
    - Also `identity_signals` and `app_registration_id`, which the page does not offer.
    - The public id is set on create. A category move queues `RollupRefresh::campaign()`.
  - `DELETE /campaigns/{id}`: needs `remove_campaign`; the dry run lists `in_use_by`.
  - `GET /campaigns/{id}/uses` and `POST /campaigns/bulk-upsert`.
  - Goals: `GET/POST /goals`, `GET/PUT/DELETE /goals/{id}`, `GET /goals/{id}/campaigns` and `PUT/DELETE /goals/{id}/campaigns/{campaignId}`.
  - Attribution models: `GET /attribution/models`.
  - **MISSING:**
    - No copy or clone route. `p202 campaign clone` reads `GET /campaigns/{id}` and posts the fields to `POST /campaigns` on the client, naming the copy "… (Clone)".
    - No route for the offer-network browser, its access requests or offer setup, and nothing calls `setupDniOfferTrack` on an API create.
  - **PARTIAL:**
    - v3 does not convert a foreign payout: the caller sends both payout columns, and no exchange-rate route exists.
    - v3 checks the currency only for length, not against the supported list.
    - v3 does not require the URL to start with `http(s)://`.
    - v3 sends no Slack and no Analytics event, and its delete does not stamp `aff_campaign_time`.

## Missing v3 endpoints

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Campaigns | Copies a campaign (`?copy_aff_campaign_id=`), filling the form with " (Copy)" | `GET /campaigns/{id}` + `POST /campaigns` | No clone route; the copy is assembled by the client (`p202 campaign clone` does this) |
| Campaigns | Browses an integrated offer network's offers, requests access, answers its questions and sets an offer up (`dni_get_offers.php`) | none | No v3 route for the offer browser or its actions; `dni_networks` (favicon, processed) is not exposed |
| Campaigns | Registers a new campaign's tracking with the offer network (`setupDniOfferTrack` to my.tracking202.com) when made from an offer | `POST /campaigns` | The API create never calls it |
| Traffic Sources, Categories, Campaigns | Sends Slack events for creates, renames, changes and removes, and Analytics milestone events on create | none | No v3 write sends either |

## Partial v3 coverage

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Traffic Sources | Saves an account with its whole set of pixels and correction URLs in one save, removing pixels left out | `PUT /ppc-accounts/{id}` + `POST/PUT/DELETE /ppc-accounts/{id}/pixels[/{pixelId}]` | One pixel per call; no set replace |
| Traffic Sources | Replaces a source's whole variable list in one POST (soft-deleting the rest, or hard-deleting all when none remain) | `POST/PUT/DELETE /ppc-networks/{id}/variables[/{variableId}]` | Per variable only; v3 always soft-deletes. v3 is stricter: length limit, tracking-link checks, no `=`, no `t202id` |
| Campaigns | Converts a payout in a foreign currency to the account currency at save, through a live exchange rate | `POST/PUT /campaigns` | The caller supplies `aff_campaign_payout` and `aff_campaign_foreign_payout`; no conversion and no rate route |
| Campaigns | Refuses a currency not in the supported list, and a URL not starting `http(s)://` | `POST/PUT /campaigns` | v3 checks currency length (3) and URL length (2048) only |
| Traffic Sources, Categories, Campaigns | Remove stamps `*_time` with the removal time (`SetupRemoval::mark`) | `DELETE /ppc-networks/{id}`, `/ppc-accounts/{id}`, `/aff-networks/{id}`, `/campaigns/{id}` | The v3 base delete sets only `*_deleted = 1` |
| All list pages | Client-side substring filter shown above 5 rows, name order | `GET` lists with `q`, `sort`, `filter[…]` | v3 searches the name on the server and sorts by any listed field; equivalent result, different mechanism (no gap in capability) |

## Not determined from code

- Whether a Slack event is sent for every pixel change on the account form: the page sends pixel-added and pixel type/code-changed events. Whether removing a pixel sends one was not settled from the code read.
- How `PlatformConversions::read` scopes the spend card's 7-day conversion count to ChatGPT Ads: the platform argument was not confirmed.
