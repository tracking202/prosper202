# Account home and personal settings

This area covers the signed-in home page (`202-account/index.php`), Personal Settings (`202-account/account.php`) with every card and form in it (profile and advanced tracking preferences, data and communication consent, click filtering, money-loss alerts, account currency, REST API keys and the AI watchers behind them, the Prosper202 customer API key, the password), the consent page an AI assistant's connection sends the owner to (`202-account/connect-ai.php`), and ClickServer Management (`202-account/clickservers.php`) with its AJAX endpoint `202-config/clickserver_api_management.php`. The include files `202-config/functions-account-ui.php`, `202-config/functions-click-filter-ui.php`, `202-config/functions-alerts-ui.php` and the notices in `202-config/functions-ui.php` are described inside the pages that use them. Version read: 1.9.81.

Shared behaviour of the pages in this area:

- **Sign-in gate.** Each page calls `AUTH::require_user()`. A request with no signed-in session that is a script request (an XHR, or `Sec-Fetch-Dest: empty`) gets HTTP 401 with the text "You are signed out. Sign in again, then reload the page."; any other request includes `202-access-denied.php`, which clears the session's sign-in keys and redirects to `202-login.php?redirect=<requested URI>`. The same call then runs `AUTH::require_valid_api_key()`: an install without a valid licence key is redirected to `api-key-required.php` (with `?licence=unconfirmed` when ClickServer gave no answer).
- **Session user.** In every valid session `user_id` equals `user_own_id` (the sign-in sets both and the session check refuses a session where they differ), so "the user" and "the account" are the same row of `202_users` here.
- **CSRF.** Every form carries a hidden `token` from `p202_account_token_field()`, checked with `AUTH::check_csrf_token()`. A refusal flashes `P202_ACCOUNT_TOKEN_REFUSED`: "This form expired or did not come from this page, so nothing was saved. Reload the page and try again." and redirects.
- **Post-redirect-get.** A handled POST stores its message with `p202_account_flash()` and answers `p202_account_redirect()` (HTTP 303 back to the page and the card's fragment). `p202_account_render_flashes()` prints a `[data-p202-flashes]` block, which `202-js/p202-ui.js` moves into the panel the URL fragment names, so the message shows beside the card that was saved.
- **Concurrent-edit guard (ShownSettings).** Forms that save stored settings carry a hidden `p202_shown[<form>]` holding an HMAC of the values the page showed. `p202_account_require_shown()` takes the MySQL lock `GET_LOCK('p202_settings_…', 10)`, reads the values again and compares. Refusals: lock busy, "Another save of these settings is still running, so nothing was saved. Try again in a moment."; lock or read failure, "Your settings could not be saved just now; nothing changed. Try again."; values changed since the page opened (`P202_ACCOUNT_SHOWN_STALE`), "These settings were changed in another window or session after this page opened, so nothing was saved. The page now shows what is saved: make your change again."
- **p202-ui.js behaviours used here.** `form[data-p202-confirm]` asks `window.confirm()` with the attribute's text before submitting; `[data-p202-copy]` is a copy-to-clipboard button; `details[data-p202-remember]` keeps its open or closed state in `localStorage` under `p202-disclosure:<key>`.
- **Slack notices.** `Slack::forAccount($db, user_id)` answers null when the account has no Slack webhook; otherwise `push()` queues the notice in `202_slack_notices` (SlackNoticeQueue) or sends it. Several saves below push a named notice.

| Page | Path | Permission |
|---|---|---|
| Home | `/202-account/index.php` (also `/202-account/`) | signed in; the old Google Ads feed notice needs both `access_to_setup_section` and `access_to_campaign_data` |
| Personal Settings | `/202-account/account.php` | signed in; every card except Email, Data & communication and Password needs `access_to_personal_settings` |
| Connect an AI assistant | `/202-account/connect-ai.php` | signed in; the Allow form needs `access_to_personal_settings` |
| ClickServer Management | `/202-account/clickservers.php` | signed in + `access_to_clickservers` |

### Home: `/202-account/index.php`

- **Where / who**: `/202-account/` or `/202-account/index.php`. Header navigation item "Home" (href `202-account/`). Login required (`AUTH::require_user()`, see the shared gate above); no permission check for the page itself. The document title is "Prosper202 ClickServer" (`template_top('Prosper202 ClickServer')`). The page header is "Home" with the sub-line "Your applications, the next step in setting up, and where to get help.", and a button "Open the ClickServer" linking to `tracking202/`.
- **Purpose**: the landing page after sign-in: a setup checklist for a new account, money-loss alerts and queue warnings, sponsored offers, and links to the apps and outside resources.
- **Data shown**:
  - **Get Started checklist** (panel `#p202-getting-started`). The user id is `$_SESSION['user_own_id']`, falling back to `user_id`. Three counts are read with `memcache_mysql_fetch_assoc($sql, 0)` (no cache time):
    1. "Add a traffic source" (tail text "where your clicks come from"), linking to `tracking202/setup/ppc_accounts.php`: count of `202_ppc_accounts` joined to `202_ppc_networks` of the same user, with `ppc_account_deleted='0'` and `ppc_network_deleted='0'`.
    2. "Create a campaign" ("the offer you promote"), linking to `tracking202/setup/aff_campaigns.php`: count of `202_aff_campaigns` joined to `202_aff_networks` of the same user, neither deleted.
    3. "Generate a tracking link" ("and put it in your traffic source"), linking to `tracking202/setup/get_trackers.php`: count of `202_trackers` for the user (the table has no deleted column).
    Each step is a disabled checkbox: a done step is checked and struck through, an undone step is a link. A "next step" button (btn-secondary) links to the first undone step. The footer line reads: Prefer hands-free? Ask Claude to "onboard Prosper202".
  - **Money-loss alerts panel**, from `p202_alerts_home_panel($db, user_id, $base, time())` in `202-config/functions-alerts-ui.php`. It reads `MysqlAlertStore::recent(user, now − 7 days, 10)` from `202_alert_log` and shows the same alert list as the Personal Settings card (described there), headed "the last 7 days · settings", where "settings" links to `AlertNotifier::SETTINGS_PAGE` (`202-account/account.php#money-loss-alerts`).
  - **Postback queue notice**, from `p202_postback_queue_notice()` (`202-config/functions-ui.php`). `OutboxBacklog::read()` for the user reads `202_notification_pending` and `202_cronjob_logs`; when traffic source postbacks are waiting with no cron job to send them, a warning is shown with a button "Open Settings" linking to `202-account/administration.php#autocron`.
  - **Old Google Ads feed notice**, from `p202_old_google_feed_notice()` (`202-config/functions-ui.php`), shown only when the user has both `access_to_setup_section` and `access_to_campaign_data`. `LegacyGoogleFeedFetches::waiting()` reads `202_legacy_google_feed_fetches`. Text: "Your Google Ads conversion uploads have stopped. Google Ads is still fetching a scheduled feed" (or "N scheduled feeds") "from before your upgrade…", with a link "Move it over" / "Move them over" to `tracking202/setup/offline_conversions.php#old-feed`.
  - **Special offers panel**, shown when `$_SESSION['user_pref_ad_settings']` is set and is not `hide_all`. It holds a "hide these" link to `202-account/account.php#profile` and an iframe (height 420) loading `TRACKING202_ADS_URL . '/prosper202-home/?t202aid=' . $_SESSION['user_cirrus_link']`. `user_cirrus_link` is set at sign-in from the user's `user_api_key` (`functions-auth.php`).
  - **My applications**: ClickServer → `tracking202/` (icon `202-img/new/icons/shield.svg`), TV202 → `202-tv/` (`video.svg`), Resources202 → `202-resources/` (`basket.svg`).
  - **Extra resources** (each opens in a new tab): Blog `https://blog.tracking202.com/`, Twitter `https://twitter.tracking202.com/`, Newsletter `https://newsletter.tracking202.com/`, Community support `https://support.tracking202.com/`, Developers `https://developers.tracking202.com/`.
- **Inputs**: none. The checklist's dismissal is a saved per-browser preference: `localStorage` key `p202_getting_started_dismissed`.
- **Actions**:
  - The checklist's close button `#p202-gs-dismiss` (btn-close) sets `localStorage.p202_getting_started_dismissed = '1'` and hides the panel. The panel renders hidden; inline JS shows it unless that key is `'1'`.
  - Every other control is a link.
- **Writes and side effects**:
  - `p202_alerts_home_panel()` calls `AlertNotifier::rememberWebAddress()`, which writes a `202_alert_state` row (user 0, kind `ADDRESS_KIND`, `incident_key` = whether the request was HTTPS, `detail` = the install path) when the install's web address differs from the one stored. Alert e-mails and Slack messages use this address for their links.
  - A 1x1 tracking image is requested from `https://my.tracking202.com/api/v2/dni/deeplink/cookie/set/<base64(p202TrackingBaseUrl())>` (outbound, by the browser).
  - The ads iframe loads from `TRACKING202_ADS_URL` (by the browser).
- **Edge cases and states**:
  - The checklist panel is omitted entirely when all three steps are done; it stays hidden in a browser that dismissed it.
  - The alerts panel shows "Your alerts could not be read just now. Reload the page to see them." on a read failure, and nothing at all when there are no alerts in the last 7 days.
  - A failed read of the postback backlog or of the old feed fetches shows no notice (the failure is not reported on this page).
  - The special offers panel is hidden for `hide_all` and when the session has no ad preference.
- **v3 coverage**:
  - Checklist counts: **PARTIAL**. `GET /ppc-accounts`, `GET /campaigns` and `GET /trackers` return `pagination.total`, but they do not exclude accounts whose traffic source is deleted or campaigns whose affiliate network is deleted, so the counts can differ from the page's joins.
  - Money-loss alerts panel: **MISSING**. No v3 route reads `202_alert_log`.
  - Postback queue notice: **PARTIAL**. `GET /system/cron` reports a `traffic_source_postbacks` backlog, but it needs `access_to_settings` and is install-wide, not per account.
  - Old Google Ads feed notice: **MISSING**. No route reads `202_legacy_google_feed_fetches`; `GET /offline-conversions/{}` and `GET /conversions/offline-export` are the nearest.
  - Special offers: **PARTIAL**. `GET /me` returns `switches.sponsored_ads` (the `user_pref_ad_settings` value), but no route returns the `t202aid` value (the user's `user_api_key`) the iframe URL needs.
  - Deeplink cookie image: needs the install's tracking base URL; see Not determined.
  - App links and resource links: static, nothing to cover.

### Personal Settings: `/202-account/account.php`

- **Where / who**: `/202-account/account.php`. Menu: My Account › Personal Settings (always shown to a signed-in user). Login required. The document title is "Personal Settings" and the page header "Personal settings". The page checks `$canPersonal = $userObj->hasPermission('access_to_personal_settings')`.
  - With `access_to_personal_settings` the page shows tabs: Profile `#profile`, Click filtering `#click-filtering`, Money-loss alerts `#money-loss-alerts`, Currency `#currency`, API keys `#api-keys`, Customer key `#customer-key`, Password `#password`.
  - Without it the page shows only the Email field, the Data & communication fieldset and the Change password card, and reads only `SELECT user_email` for `user_own_id`.
  - Without it, a POST that names any of `add_rest_api_key`, `remove_rest_api_key`, `replace_rest_api_key`, `stop_ai_watcher`, `update_account_currency`, `record_earlier_currency`, `campaign_currency_notice`, `update_clickserver_api_key`, `change_user_api_key`, `change_user_stats202_app_key`, `update_p202_customer_api_key`, `update_click_filter`, `add_click_filter_entry`, `remove_click_filter_entry`, `update_money_loss_alerts` or `send_test_money_loss_alert`, or a GET with a non-empty `customers_api_key`, `remove_user_stats202_app_key`, `remove_user_api_key` or `test_click_filter`, is answered HTTP 403 "You do not have permission to change these settings."
- **Purpose**: the signed-in user's own settings: e-mail, time zone and tracking preferences, consent choices, click filtering, money-loss alerts, account currency, API keys, the Prosper202 customer key and the password.
- **Data shown** (initial reads): `SELECT user_name` (used in Slack messages); `Slack::forAccount()`; with `access_to_personal_settings`, `SELECT * FROM 202_users LEFT JOIN 202_users_pref USING (user_id)` for the user. After the POST handlers run the row is read again, so the page shows what was saved. Each card below lists its own reads.
- The page's cards follow, each with its inputs, actions, writes, states and v3 coverage.

#### Profile card (`#profile`)

- **Form**: POST `account.php`, `update_profile=1`, `token`, `p202_shown[profile]`. With `access_to_personal_settings` the shown-values HMAC covers `user_email`, `user_timezone`, `user_keyword_searched_or_bidded`, `user_pref_referer_data`, `user_pref_dynamic_bid`, `user_pref_privacy`, `user_pref_same_ip_fallback`, `user_pref_click_id_params`, `user_pref_cloak_referer`, `user_pref_ad_settings`, `user_tracking_domain`; without it, `user_email` only. Button "Save settings".
- **Inputs**:
  - `user_email`: label Email, `type=email`, required, help "Password resets go here.". Refusals: `check_email_address()` fails, "Please enter a valid email address."; taken by another user, "That email address is already being used."; the uniqueness read fails, "Your email could not be checked; try again.". With `access_to_personal_settings` the taken check counts only users with `user_deleted != 1`; without it the check counts deleted users too.
  - `user_timezone` (only with `access_to_personal_settings`): a select over `DateTimeZone::listIdentifiers()`, with the account's own stored backward-compatible name (for example `Europe/Kiev`) added first when it is not in the list, so it stays selected; an invalid stored value shows UTC selected. Option labels are "<zone> [<abbreviation> ±<offset>]". Help: "Every report's days start and end in this zone.". Refusal when `AccountZone::isZone()` fails: "Choose a time zone from the list."
  - **Advanced** disclosure (`<details data-p202-remember="account-profile-advanced">`, hint "keyword, cost, referer, privacy, IP matching, ad click ids, ads, tracking domain"; it renders open when one of its fields has an error). Only with `access_to_personal_settings`:
    - `user_keyword_searched_or_bidded`: `searched` "Pickup Searched Keyword", `bidded` "Pickup Bidded Keyword". Refusal: "You must select your keyword preference."
    - `user_bid` (stored as `user_pref_dynamic_bid`): `0` "Pickup Bid from setup data", `1` "Pickup Bid dynamically from t202b variable". Refusal: "You must select your cost data preference."
    - `user_referer` (stored as `user_pref_referer_data`): `browser`, `t202ref`. Refusal: "You must select your referer preference."
    - `user_pref_privacy`, label "GDPR & privacy": `disabled` "Disabled", `eu` "Enabled for European Traffic", `all` "Enabled for All Traffic". Refusal: "You must select your privacy setting."
    - `user_pref_same_ip_fallback`, label "Match conversions by IP address": `0` "Off: only a subid or a cookie finds the click", `1` "On: guess the click from the IP address". Refusal: "Choose whether a pixel may guess the click from the IP address."
    - `user_pref_click_id_params`, label "Match conversions by ad click id": text, maxlength 255, placeholder `aff_sub3`. Validated only when posted, by `ClickIdParameters::accepted()`: at most 5 names, comma-separated; each matches `[a-z0-9][a-z0-9_-]{0,39}`; none may be one of the ad click ids already read without being listed (`gclid`, `gbraid`, `wbraid`, `ttclid`, `msclkid`, `fbclid`, `oppref`); none may be a reserved postback parameter (`subid`, `sid`, `cid`, `acip`, `amount`, `status`, `event`, `event_id`, `event_props`, `reversal_id`, `txid`, `transaction_id`, `transactionid`, `order_id`, `orderid`, `oid`, `t202txid`, `cust`, `customer_ref`, `cust_type`, `customer_ref_type`, `cust_sig`, `sku`, `product_id`, `external_product_id`, `product_name`, `qty`, `quantity`, `unit_price`, `currency`, `p202_token`, `p202_ts`, `p202_sig`, `p202_consent`). Stored normalised as a comma list. Its refusal sentence is shown at the field and repeated in the top flash.
    - `cloak_referer` (stored as `user_pref_cloak_referer`): `origin` "Show Prosper202 Domain", `never` "Show Blank Referer". Refusal: "You must select how cloaked links show the referer."
    - `user_pref_ad_settings`: `show_all` "Show All Ads", `hide_login` "Hide Ads On Login Screen", `hide_all` "Hide All Ads". Refusal: "You must select where ads are shown."
    - `user_tracking_domain`: text, placeholder = the request's host. No validation on the page; the value is stored trimmed (column `varchar(255)`). Help: leave it empty to use this install's domain; an `https://` or `http://` prefix chooses the scheme of the links.
    - `user_cached_reports` (stored as `cache_time`): read when posted, but no form on the page sends it.
- **Data & communication fieldset** (every user, also without `access_to_personal_settings`): hidden `consent_form=1`, `analytics_consent_shown`, `email_marketing_consent_shown` (the states shown); checkboxes `analytics_consent` "Product analytics & personalized help" (with a link to `202-account/disclosure.php`) and `email_marketing_consent` "Money-making offers & tips by email". The states shown come from `ConsentPolicy::analyticsAllowed()` and `ConsentPolicy::emailMarketingAllowed()`.
- **Writes and side effects**:
  - Consent: only a box whose state differs from the shown state is recorded, with `ConsentPolicy::record($db, user_id, flag, granted|denied, 'settings')`. It runs `INSERT IGNORE` of the `202_users_pref` row, then writes `analytics_consent`, `analytics_consent_at`, `analytics_consent_source`, `consent_version`, `analytics_revoked_version` (on a denial) and `eu_consent_prompt_seen=1`, or `email_marketing_consent` and `email_marketing_consent_at`. A denial of analytics also runs `MessagingService::purgeAnalyticsData()`. Consent is recorded before validation and before the stale check, so it is saved even when the rest of the form is refused.
  - Save with `access_to_personal_settings`: `p202_account_save_profile()` runs one transaction: `UPDATE 202_users SET user_email, user_timezone`; `INSERT IGNORE INTO 202_users_pref`; `UPDATE 202_users_pref SET user_keyword_searched_or_bidded, user_pref_referer_data, user_tracking_domain, [cache_time], user_pref_cloak_referer, user_pref_dynamic_bid (int), user_pref_ad_settings, user_pref_privacy, user_pref_same_ip_fallback (1/0), [user_pref_click_id_params]`.
  - After a save the session's `user_pref_ad_settings` and `user_timezone` are updated. When memcache is working, `setCache()` (TTL 0) stores `user_id`, `user_timezone`, `user_keyword_searched_or_bidded`, `user_referer`, `cloak_referer` and `user_pref_dynamic_bid` under `md5(<name>_<uid> . systemHash())` for the click path.
  - Slack notices: `user_time_zone_changed`, `user_keyword_preference_changed`, `user_referer_changed`, `user_pref_cloak_referer_changed`, `user_email_changed` (each when that value changed).
  - Save without `access_to_personal_settings`: `UPDATE 202_users SET user_email` for `user_own_id`; Slack `user_email_changed`.
- **Edge cases and states**:
  - Success: "Your settings are saved.", or "Your other settings are saved, but your Data & communication choice could not be. Try again." when the consent write failed.
  - Save failure: "Your settings could not be saved. Nothing you see below has changed[ apart from your Data & communication choice]; try again."
  - Stale: the stale sentence, worded to say whether the consent choice was saved.
  - Refused fields: the form renders in place (no redirect) with the error at each field and the typed values kept, and the top flash "Your settings were not saved. The fields below say why." (or the variant that repeats the click-id refusal).
  - Without `access_to_personal_settings`: "Your email could not be saved; try again." and "Your email is saved, but your Data & communication choice could not be. Try again."
- **v3 coverage**:
  - Read: **PARTIAL**. `GET /users/{}` (e-mail, timezone) plus `GET /users/{}/preferences` (the preference columns, including `user_pref_click_id_params` and `user_tracking_domain`). `GET /users/{}/preferences` needs `access_to_personal_settings` and answers 404 "User preferences not found" when the user has no `202_users_pref` row; the page creates the row.
  - Save: **PARTIAL**. It takes two calls, `PUT /users/{}` (`user_email` up to 100 characters with `FILTER_VALIDATE_EMAIL`; `user_timezone` with `AccountZone::isZone()`, which needs `access_to_personal_settings`) and `PUT /users/{}/preferences` (PreferenceRules). Differences: not one transaction; no ShownSettings stale check; `user_tracking_domain` is validated by the API (at most 255 characters and it must normalise to a host) but not by the page; the API's e-mail uniqueness counts deleted users (the page with the permission does not); no prefs row is created; no Slack notices; the session and memcache refresh is the page's own.
  - Consent read and record: **MISSING**. No v3 route reads or writes the consent columns, and nothing runs `purgeAnalyticsData()`.

#### Click filtering card (`#click-filtering`)

Rendered by `202-config/functions-click-filter-ui.php`, only with `access_to_personal_settings`. Forms post to `account.php#click-filtering`.

- **Data shown**: `MysqlClickFilterStore` settings (`202_click_filter_settings`) and entries (`202_click_filter_entries` ORDER BY `entry_id`). Copy text: "Every click is recorded and redirected…" and "All three are on by default. Bot detection… always applies." On a read failure the card is hidden and the flash says "Your click filtering settings could not be read just now. Reload the page to see them."
- **Settings form** (`update_click_filter=1`, `token`, `p202_shown[click_filter]`, button "Save click filtering"):
  - Switches `sign_in_addresses` "Your own clicks", `crawler_ranges` "Search engine crawlers", `repeat_visits` "Repeat visits" (an unticked switch posts `0`).
  - Select `repeat_window`: `1h` "1 hour", `6h` "6 hours", `24h` "24 hours (the default)", `7d` "7 days".
  - Rules (`ClickFilterRules::settings()`): a flag must be 1, 0, true or false, else "must be true or false (1 or 0)"; the window "must be one of 1h, 6h, 24h, 7d". Refusal flash: "Click filtering was not saved: <field> <why>…".
  - Write: `INSERT … ON DUPLICATE KEY UPDATE 202_click_filter_settings` (`sign_in_addresses`, `crawler_ranges`, `repeat_visits`, `repeat_window` stored in seconds, `updated_at`). When the window is 24h, the same save runs `DELETE FROM 202_last_ips WHERE user_id = ? AND time < now − 86400`.
  - Messages: "Click filtering is saved: every built-in check is on, as it is by default." or "Click filtering is saved. It applies to clicks from now on; clicks already recorded keep how they were counted."; database failure "Your click filtering settings could not be saved. Nothing changed; try again."
  - `Analytics::event('click_filter_changed', {via: 'ui', change: 'settings'})`, sent only with analytics consent.
- **Entries table** (shown when there are entries): columns Address (code), "Clicks from it are" (pill "always counted" or "always filtered"), Note, Added (`M j, Y`). Each row has a "Remove…" form (`remove_click_filter_entry=1`, `entry_id`) with the confirm "Remove <address> from the list? Its clicks are filtered by the other rules from then on."
  - `PostedId::read()` failing: "That entry could not be read; nothing was removed."
  - Write: `DELETE FROM 202_click_filter_entries` for the user and id. Messages: "The entry is removed: clicks from its addresses are filtered by the other rules from now on.", "That entry was not on your list; nothing was removed.", "The entry could not be removed. It still applies; try again."
- **Add form** (`add_click_filter_entry=1`, button "Add to the list"):
  - `filter_address` (placeholder `203.0.113.0/24`), required: "is required: an IP address (203.0.113.7) or a CIDR block (203.0.113.0/24)".
  - `filter_action`: `filter` "Always filtered" (default), `count` "Always counted"; required.
  - `filter_note` (maxlength 255, placeholder "Client QA office"): text, valid UTF-8, one line with no control characters, at most 255 characters.
  - Limits: at most 500 entries, "the list is full: it holds 500 entries at most; remove one first"; a duplicate range, "<range> is on the list already, as entry N: change that entry instead". Errors are labelled "The address", "Count or filter", "The note".
  - Write: INSERT into `202_click_filter_entries`. Success: "<range> is always counted from now on." or "… always filtered from now on.", plus the analytics event with `change: entry_added`. Database failure: "The address could not be added. Nothing changed; try again."
- **Test form**: GET `test_click_filter=1`, `test_ip`, button "Test". `ClickFilterRules::testAddress()` refuses an empty value ("is required: the IPv4 or IPv6 address to test…") or a non-address ("\"x\" is not an IPv4 or IPv6 address (test one address, not a block)"). `ClickFilterTester::test()` answers a flash (warn or ok) "<ip>: <explanation>"; a failure says "The address could not be tested just now; try again." Nothing is written.
- **v3 coverage**: covered. `GET /users/{}/click-filter`, `PUT /users/{}/click-filter`, `POST /users/{}/click-filter/entries`, `PUT /users/{}/click-filter/entries/{}`, `DELETE /users/{}/click-filter/entries/{}`, `GET /users/{}/click-filter/test?ip=` (ClickFilterController, the same ClickFilterRules). **PARTIAL** only in that `PUT /users/{}/click-filter` has no ShownSettings stale check and the page's analytics event is the page's own.

#### Money-loss alerts card (`#money-loss-alerts`)

Rendered by `202-config/functions-alerts-ui.php`, only with `access_to_personal_settings`.

- **Data shown**:
  - `MysqlAlertStore` settings (`202_alert_settings`); the alert contact from `AlertNotifier` (the user's e-mail, Slack webhook and tracking domain, read from `202_users`); `AlertNotifier::rememberWebAddress()` (writes `202_alert_state`, as on Home); whether links can be built (`settingsUrl() !== null`); the alerts of the last 30 days, at most 20 (`202_alert_log`).
  - A read failure hides the card and flashes "Your money-loss alert settings could not be read just now. Reload the page to see them."
  - A note appears when the install has no tracking domain stored, saying that alerts will carry no links.
- **Settings form** (`update_money_loss_alerts=1`, `token`, `p202_shown[money_loss_alerts]`):
  - Switches: `alerts_enabled` "Alerts on" (help "On by default…"); `alerts_notify_slack` (help: when the account has no webhook, add one on API integrations, `202-account/api-integrations.php#slack`); `alerts_notify_email` (help "To <email>, sent by this server's mail()", or a note that there is no valid e-mail); `alerts_notify_webhook` (webhooks subscribed to `alert.conversions_stopped`, `alert.spend_without_conversions`, `alert.postback_refusals_spiked`, or to every event).
  - `alerts_gap_multiple` "Times the usual gap": text, maxlength 2, whole number 2 to 20, default 5.
  - `alerts_min_conversions` "Converting hours a week to be steady": text, maxlength 4, whole number 3 to 1000, default 10 (the lookback is 7 days).
  - `alerts_daily_cost_cap` "Daily cost cap per campaign": text, maxlength 10, matches `\A[0-9]{1,7}(?:\.[0-9]{1,2})?\z`, at most 9999999.99; empty is 0, meaning no cap.
  - Errors: a switch "must be on or off"; a number "must be a whole number from X to Y"; the cap "must be an amount from 0 to 9999999.99 with at most two decimals (0 is no cap)". Labels: Alerts, Slack, Email, Webhooks, "The multiple of the usual gap", "The converting hours a week", "The daily cost cap". Refusal flash: "Money-loss alerts were not saved; see the fields marked below."
  - Write: `INSERT … ON DUPLICATE KEY UPDATE 202_alert_settings` (`enabled`, `notify_slack`, `notify_email`, `notify_webhook`, `gap_multiple`, `min_conversions`, `daily_cost_cap`, `updated_at`).
  - Messages: off, "Money-loss alerts are off for your account…"; on with no channel, "…you will see them only on Home and on this card. The cron job checks your campaigns every hour."; otherwise "Money-loss alerts are on. The cron job checks your campaigns every hour."; database failure "Your money-loss alerts could not be saved. Nothing changed; try again."
- **Test form** (`send_test_money_loss_alert=1`, button "Send a test alert"):
  - Refused with a warning when alerts are off, or when no channel is on.
  - Throttle: `claimTurn(user, 'test', 30, now)` on `202_alert_state` (`aff_campaign_id` 0, `alert_kind` `test`): "one was sent less than 30 seconds ago. Send the next in N seconds."
  - `AlertNotifier::deliver()` of a test alert (`AlertKind::Test`, `alert.test`): Slack webhook (outbound HTTP), PHP `mail()`, and the webhook queue (event `alert.test`). The result flash lists each channel as taken, failed or skipped; nothing taken is shown as an error.
- **Alert list** "Your alerts, the last 30 days" (also used, for 7 days, on Home): columns First seen (`Y-m-d H:i`); Alert (a pill "Going on" when `last_seen` is within `AlertRun::ACTIVE_SECONDS` = 2 h 10 min, else "Earlier"; the kind's title, "Conversions stopped", "Spend with no conversions", "Postback refusals jumped" or "Test alert"; the message; a link to the kind's page: `tracking202/setup/inbound_log.php`, `tracking202/setup/inbound_log.php?outcome=refused`, or `tracking202/setup/aff_campaigns.php?edit_aff_campaign_id=<id>`; for ChatGPT Ads campaigns, "Pause <id> in ChatGPT Ads" buttons linking to `tracking202/setup/destinations.php?ad_campaigns=<connection>&preview_pause=<id>#ad-campaigns`); How it was sent ("Sent by …"; "Never sent, after N tries." or "Not sent yet (try N of 3)." for a failed delivery, `AlertRun::MAX_ATTEMPTS` = 3; "Shown here only…" when no channel was on). Empty state: "None. An alert is listed here and on Home when it is raised…"; read failure "Your alerts could not be read just now…".
- **v3 coverage**: **MISSING** for all of it. No v3 route reads or writes `202_alert_settings`, lists `202_alert_log`, or sends a test alert.

#### Account currency card (`#currency`)

Only with `access_to_personal_settings`.

- **Currency form** (`update_account_currency=1`, `token`, `p202_shown[account_currency]`, button "Save currency"): select `account_currency` with 27 hard-coded options (USD, AUD, BRL, CAD, CZK, DKK, EUR, HKD, HUF, ILS, JPY, MYR, MXN, NOK, NZD, PHP, PLN, GBP, SGD, SEK, CHF, TWD, THB, TRY, CNY, INR, RUB), the stored currency selected. Validated against `UsersController::SUPPORTED_CURRENCIES`: "Choose a currency from the list."
  - Write: `p202_account_save_currency()` builds `CurrencyChange::plan()` (exchange rates through `getForeignPayout()`, outbound, before the transaction), then in one transaction `INSERT IGNORE 202_users_pref`, `UPDATE 202_users_pref SET user_account_currency`, `CurrencyChange::apply()` (re-prices the account's `202_aff_campaigns` payouts) and `CurrencyChange::record()` (`202_currency_changes`). On a change it runs `ExchangeRateCache::refreshQuietly()` (`202_exchange_rates`).
  - Messages: "Account currency saved." or "…New conversions are recorded in X… (Y until now), unconverted…"; failure "The account currency could not be saved, and no campaign was re-priced; try again."
- **Campaign currency notice**: `CampaignCurrencyNotice::listed()` reads the account's `202_aff_campaigns` with `aff_campaign_currency_notice=1`, up to 20 named plus "N more". Two forms with `campaign_currency_notice`:
  - `switch` (button "Their networks report in X: switch it" or "switch them"): `UPDATE 202_aff_campaigns SET aff_campaign_currency = <account currency>, aff_campaign_foreign_payout = '0.00'` for the flagged, non-deleted campaigns, then clears the notice flag. Message "N campaigns are now paid in X…".
  - `keep` (button "Keep converting"): clears the flag only. Message "Those campaigns keep their currency…".
  - Any other value: "Choose whether to switch…"; failure "Those campaigns could not be updated; nothing was changed. Try again."
- **History line**: "Changes of currency: <Y-m-d> from A to B; …" from `CurrencyChange::history()` (`202_currency_changes`, at most 20), dates in the account's zone.
- **Record earlier currency form** (`record_earlier_currency=1`, button "Record"). Its label reads "N earlier conversions have no currency recorded" (`RowCurrency::unrecorded()` on `202_conversion_logs`) or "Conversions recorded before 1.9.80".
  - `earlier_before`: date, required, "Enter the date of the change, as YYYY-MM-DD."
  - `earlier_currency`: select, default the disabled option "Choose a currency", choices `SUPPORTED_CURRENCIES`; "Choose a currency from the list."
  - Under Advanced, `earlier_since`: date, optional; "Enter a date as YYYY-MM-DD, or leave it empty." and "This date must be before the date of the change."
  - Dates are read as midnight in the account's zone. Write: `RowCurrency::recordBefore()` (`202_conversion_logs`, `202_currency_changes`; batches of 20,000 rows, at most 200 batches per request), then `DestinationQueue::releaseHeldForCurrency()` moves destination deliveries held for `NO_CURRENCY` back to pending.
  - Messages: "No conversion changed…" or "N earlier conversions are now recorded in X.", with " N are still to do: save again to continue." when the limit was reached; failure "The earlier conversions could not be updated; try again."
- **v3 coverage**:
  - Currency read and save: **PARTIAL**. `GET /users/{}/preferences` / `PUT /users/{}/preferences` with `user_account_currency` re-prices, records the change and refreshes rates (502 when rates cannot be fetched); there is no stale check. `GET /me` also returns the currency.
  - Currency change history: **MISSING**.
  - Unrecorded-conversion count and "record earlier currency": **MISSING**.
  - Campaign currency notice: **MISSING** for the list and for "keep"; "switch" is possible one campaign at a time with `PUT /campaigns/{}` (`aff_campaign_currency`, `aff_campaign_foreign_payout`), but no route reads or clears `aff_campaign_currency_notice`.

#### API keys card (`#api-keys`)

Only with `access_to_personal_settings`.

- **Data shown**: header pill "N keys"; intro text, plus "A key's last use is recorded from version 1.9.80 on, to within five minutes." when the use columns exist. `p202_account_api_keys()` reads `202_api_keys` for the user: a ref (`key_hash`), `key_prefix`, `created_at`, `scope`, `label`, `watcher_token` (for labels starting "AI: "), `last_used_at`, last API version, last client, `ends_at`; keys whose `ends_at` passed are still listed for 7 days; ordered `created_at DESC`. A read failure shows "Your API keys could not be read just now. Reload the page to see them."
  - Each row: label · "Created <M j, Y>" · scope pill ("full access", "scope unreadable · refused everywhere", or the scope list) · ending pill ("Replaced; works until …" or "Replaced; ended") · "Last used … on API X, by a client calling itself Y" or "No use recorded" · the key prefix followed by 20 bullets.
  - A new key is shown once, from session `p202_new_api_key`, with a Copy button (`data-p202-copy`).
- **Create form** (`add_rest_api_key=1`, button "Generate key"): `rest_api_key_label`, maxlength 100, placeholder "Name it for what will use it, e.g. Nightly store sync (optional)". `ApiKeyStore::labelFrom()`: at most 100 characters, no control or bidirectional characters, not starting "AI:". Refusal: "A key's name is up to 100 characters on one line, and does not start with "AI:". Nothing was created." A legacy posted `rest_api_key` (32 to 128 letters and digits) is still accepted: "An API key is 32 to 128 letters and digits…".
  - Write: key = `bin2hex(random_bytes(32))`; `ApiKeyStore::insert()` stores a placeholder `api_key`, `key_hash`, `key_prefix`, `watcher_token`, `scope` (null = full access), `label`, `created_at`. Slack `user_added_app_api_key`.
  - Messages: "API key created. It has full access. Copy it now: it is not shown again."; "The API key could not be saved. Nothing was created; try again."
- **Replace…** (shown only when the use columns exist, the key is not already ending and it is not an AI key): form `replace_rest_api_key=1`, `api_key_ref`, confirm "Replace this API key? A new key with the same access is made and shown once. This key keeps working for 24 hours…".
  - Write: `ApiKeyStore::replace()` in a transaction inserts a new key with the same scope and label and sets the old key's `ends_at` = now + 86400. Slack `user_added_app_api_key`.
  - Refusals: "Replacing a key needs the 1.9.80 database upgrade…", "An AI app's key is replaced by connecting the app again.", "That key was already replaced."
  - Success: "Replacement key created, with the same access… The old key keeps working until <date>…".
- **Revoke…**: form `remove_rest_api_key=1`, `api_key_ref`, confirm "Revoke this API key? Anything still using it is refused from the next request. Your other keys keep working."
  - First `AiWatchers::stopBeforeDelete()` stops the key's AI watchers on the MCP relay (outbound), then `DELETE FROM 202_api_keys WHERE user_id = ? AND <ref matches>`. Slack `user_removed_app_api_key`.
  - Messages: "API key revoked. Anything still using it is refused from now on.", plus a warning when the watchers could not be confirmed stopped; "That API key was not found on this account; nothing was revoked."
- **AI watchers** (under each AI key): `AiWatchers::list(token)` is `GET https://mcp.tracking202.com/api/watchers` with the key's watcher token as Bearer (`P202_MCP_RELAY_ORIGIN` overrides the host). Each watcher shows the campaign's name (`p202_account_campaign_names()`, the account's own campaigns, with "(deleted)" appended), a status pill, the end or ended date, the rule as a sentence (`p202_account_watcher_rule()`), the alert count and the latest alert. Unreadable: "Couldn't load watchers right now…"; none: "No watchers."
  - "Stop" form: `stop_ai_watcher=1`, `api_key_ref`, `watcher_id` (must match `^wch_[0-9a-f]{24}$`), confirm "Stop this watcher? It reads nothing more and sends no more alerts." It sends `POST /api/watchers/stop {watcherId}` to the relay. Messages: "Watcher stopped…", "…has no such watcher…", "…could not be reached…".
- **v3 coverage**:
  - List: **PARTIAL**. `GET /users/{}/api-keys` returns a masked key, scope, label, `created_at` and the use columns, but no ref and no watchers.
  - Create: **PARTIAL**. `POST /users/{}/api-keys {scope?, label?}`; no Slack notice, no legacy posted key. (The API can set a scope; the page cannot.)
  - Replace: **PARTIAL**. `POST /users/{}/api-keys/replace {api_key}` needs the full key, which a list cannot give.
  - Revoke: **PARTIAL**. `DELETE /users/{}/api-keys/{}` needs the full key; watchers are not stopped first.
  - AI watcher list and stop: **MISSING**.

#### Prosper202 customer API key card (`#customer-key`)

Only with `access_to_personal_settings`.

- **Data shown**: "This account's key:" with `SavedSecret::hint()`: "Not saved", "Saved" or "Saved, ends in XXXX". A link to the dashboard `https://my.tracking202.com/api/customers/register`.
- **Hand-back from my.tracking202.com**: a GET `customers_api_key` (base64) is decoded but not saved; the page flashes "Your customer API key from my.tracking202.com is ready… Press Save this key…" and shows a form with the key in a hidden field and a button "Save this key". A malformed value: "That API key link was malformed. Copy your key from my.tracking202.com and paste it in the field below."
- **Main form** (`update_p202_customer_api_key=1`, `token`, `p202_shown[p202_customer_api_key]`): text `p202_customer_api_key` (always rendered empty), button "Save key"; when a key is saved, a second button "Remove key" (`remove_p202_customer_api_key=1`, `formnovalidate`). An empty field keeps the stored key: "Enter a customer API key to save. A saved key is kept until you replace it or press Remove key."
  - Validation: `ClickServerKeyValidator::check()` POSTs to `https://my.tracking202.com/api/v2/validate-customers-key` and records the answer in `202_licence_answers` (ShellAccessCache). Refusals: "API key is not valid. Check your key and try again!"; no answer (`P202_ACCOUNT_KEY_UNCHECKED`), "The key could not be checked with Tracking202 just now, so nothing was saved. Try again in a minute."
  - Write: `UPDATE 202_users SET p202_customer_api_key` (`char(60)`) for `user_own_id`. Messages: "…is saved.", "…was removed.", "…could not be saved; try again."
- **v3 coverage**: **MISSING**. `GET /capabilities` only reads whether the key is set; no route saves, validates or removes it, or returns its hint.

#### Change password card (`#password`)

Every signed-in user.

- **Form** (`change_user_pass=1`, `token`): `user_pass` current password, required; `new_user_pass` new password, required, `minlength` 8, help "8 to 72 characters."; `retype_new_user_pass`.
- **Refusals**: "You must type in your desired password."; "Your password must be between 8 and 72 characters long." (counted in bytes); "You must type your new password again to verify it."; "Your password did not match, please try again."; "You must enter your current password."; "Unable to verify your current password at this time."; "Your old password was typed incorrectly."
- **Lockout**: each wrong current password increments session `pw_change_fails`; at `AUTH::MAX_PASSWORD_REAUTH_FAILS` (5) the page runs `AUTH::signOut()` and redirects to `202-login.php`.
- **Write**: `PasswordChange::store()` updates `202_users.user_pass`, deletes every remember-me key of the user and counts up the session generation, in one transaction. Then `AUTH::keepSessionAfterPasswordChange(<generation>)` keeps this browser signed in, and the remember-me cookie is forgotten. Message: "Your password is changed. Every other browser signed in as you is signed out…"; failure "Your password could not be changed. Your old password still works; try again."
- **v3 coverage**: **PARTIAL**. `PUT /users/{}` with `user_pass` and `current_password` (8 to 72, current password required for oneself) revokes the remember-me keys and counts up the generation, but has no failure counter and no forced sign-out, does not keep the calling browser's session (the new generation ends it), and has no retype field (a client-side check).

#### Handlers with no form on this page

- `update_clickserver_api_key` is posted from ClickServer Management (`return_to=clickservers`, see that page). It requires `p202_shown[clickserver_api_key]` and is skipped when the value contains `*`. `p202_check_clickserver_key()` runs `clickserver_api_key_validate()` (`GET https://my.tracking202.com/api/v1/auth/?apiKey=…&clickserverId=<base64(host)>`); an empty value removes the key without a check. Write: `UPDATE 202_users SET clickserver_api_key`; Slack `user_updated_clickserver_api_key`. Messages: "You have updated your Prosper202 ClickServer API Key.", "This API Key appears invalid.", or the unchecked sentence. **MISSING** in v3.
- `change_user_api_key` (the Tracking202 key, `202_users.user_api_key`): validated through `ClickServerKeyValidator`; on success it also sets session `user_api_key` and `user_cirrus_link`. **MISSING** in v3.
- `change_user_stats202_app_key`: needs at least 10 characters and a Tracking202 key in the session, else "This Tracking202 API Key & Stats202 App Key combination appears invalid." **MISSING** in v3.
- GET `remove_user_stats202_app_key` and GET `remove_user_api_key` with `token` (a wrong token is answered 403 "Invalid token.") clear those columns. **MISSING** in v3.
- No form or link anywhere in the tree renders `change_user_api_key`, `change_user_stats202_app_key` or the two GET removals (checked with grep); they are reachable only by a hand-made request.

### Connect an AI assistant: `/202-account/connect-ai.php`

- **Where / who**: `/202-account/connect-ai.php?…`. Not in any menu; the MCP relay (`https://mcp.tracking202.com`, or `P202_MCP_RELAY_ORIGIN`) sends the owner here with the connection's parameters. Login required (`AUTH::require_user()`; a signed-out owner signs in and comes back). The Allow form needs `access_to_personal_settings`; without it the page shows "Connecting an AI assistant creates an API key, which your role cannot do. Ask an administrator…" and no form. Response headers: `X-Frame-Options: DENY`, `Content-Security-Policy: frame-ancestors 'none'`, `Referrer-Policy: no-referrer`, `Cache-Control: no-store`. Standalone shell (`info_top()`), title "Connect an AI assistant - Prosper202 ClickServer".
- **Purpose**: the owner's consent to an AI assistant (an MCP client) getting an API key to this install, with a chosen level of access.
- **Data shown**: the client's name and domain as the link gives them, the signed-in user's name, the relay's host and the `redirect_host`; a warning that the link's words are not verified (anyone can start a connection and send the link); privacy text on the MCP relay's telemetry; "This creates an API key… Remove it there to disconnect" (the key is listed on Personal Settings, API keys).
- **Inputs**:
  - The link's parameters, read on GET and again on POST by `AiConnect::readRequest()`, each with its own refusal sentence: `redirect_uri` must equal `<relay origin>/callback`; `state` is two 43-character base64url parts; `code_challenge_method` is `S256` and `code_challenge` 43 characters; `client_name` 1 to 100 characters with no control characters; `client_domain` a hostname or empty; `redirect_host` printable, 1 to 255 characters; `install` matches the install URL pattern. Then `AiConnect::sameInstall(install, TrackingBaseUrl::forRequest())`: "This approval link was made for X, not for this install (Y)…". Any failure shows the card "This link cannot be used" with the reason and no form.
  - The form re-posts the link's values as hidden fields (`token`, `state`, `code_challenge`, `code_challenge_method=S256`, `client_name`, `client_domain`, `redirect_host`, `install`, `redirect_uri`).
  - Radio `access`: `read` "Read only (recommended)" (default), `propose` "Read, and propose changes", `write` "Read and change", each with help text. Scopes (`AiConnect::scopeFor()`): `read` gives `<area>:read` for reports, attribution, campaigns, trackers, landing-pages, ppc-networks, ppc-accounts, aff-networks, text-ads, rotators, clicks, conversions, staged-changes, ad-connections; `propose` adds `<area>:stage` for campaigns, trackers, landing-pages, ppc-networks, ppc-accounts, aff-networks, text-ads, rotators, ad-connections; `write` adds `<area>:write` for the same areas instead.
- **Actions**: buttons `decision=allow` "Allow" and `decision=deny` "Cancel".
  - Token failure: the token-refused sentence.
  - Allow: a bad `access` gives "Choose what the assistant may do."; otherwise `AiConnect::approve(ServerStateStore::forInstall($db), user_own_id, scope, code_challenge, client_name)` issues a one-time code (its SHA-256 stored in the install's server state store, TTL 300 seconds) and the page answers 303 to `https://mcp.tracking202.com/callback?code=…&state=…&iss=<this install>`.
  - Cancel: 303 to the callback with `error=access_denied` and the state.
  - Any other decision: "Choose Allow or Cancel."
- **Writes and side effects**: the approval code file in the server state store. The API key itself is made later, when the MCP server exchanges the code at `POST /api/v3/connect/token`; its label is "AI: <client> via <host>" and it gets a watcher token.
- **Edge cases and states**: link invalid or made for another install; role without `access_to_personal_settings`; code expiry after 300 seconds (the exchange fails, nothing is created); the page cannot be framed.
- **v3 coverage**: **PARTIAL**. `GET /connect` (probe), `POST /connect/token` (exchanges the code and mints the key) and `POST /connect/revoke` exist, but no route validates the approval link or issues the approval code: the consent step itself is **MISSING** from v3.

### ClickServer Management: `/202-account/clickservers.php`

- **Where / who**: `/202-account/clickservers.php`. Not linked from the menu (checked `202-config/template.php`). Login required; then a user without `access_to_clickservers` is redirected to `202-account/`. Page title "ClickServers" (ClickServer Management).
- **Purpose**: switch this account's licensed ClickServer domains on and off and see licence usage.
- **Data shown**: `SELECT clickserver_api_key FROM 202_users` for the user (a read failure goes to `record_mysql_error()`). With a key:
  - `clickserver_api_domain_list()`: `GET https://my.tracking202.com/api/v1/list/?apiKey=…`. Panel "Domains" with a pill "N domains"; each domain has an "Active" switch (status `'1'` is checked).
  - `clickserver_api_license()`: `GET https://my.tracking202.com/api/v1/license/?apiKey=…` (`domainsUsed`, `domainsAvail`). Tile "Licence used": the percentage and "X of Y activated domains", or "The licence could not be read just now."
- **Inputs**: with no key stored, an empty state "No ClickServer API key on file" with a form posting to `account.php` (`token`, `update_clickserver_api_key=1`, `return_to=clickservers`, `p202_shown[clickserver_api_key]`, text `clickserver_api_key` required, button "Save key"); the save is handled by Personal Settings (see "Handlers with no form on this page") and returns here.
- **Actions**: changing a domain's switch runs `fetch` POST `202-config/clickserver_api_management.php` with `clickserver_id=<domain>`, `method=activate|deactivate`, `token`.
  - The endpoint checks, in its order: the token (403); `access_to_clickservers`, 403 "You do not have access to ClickServers."; the method (400); a stored key (409); a domain (400); the domain list reachable (502); the domain on the account's list, 404 "That domain is not activated with this account's key." (`p202_clickserver_switch_refusal()`). Then `clickserver_api_domain_act_deact()` calls `GET https://my.tracking202.com/api/v1/<method>/?apiKey=…&clickserverId=<base64(domain)>` and the endpoint echoes `1`.
  - The page: an empty or failed answer reverts the switch and says "There was a problem changing <domain>; it is unchanged…". When the domain equals the page's own `HTTP_HOST`, any successful change submits `form[data-p202-signout]` (signs out) or reloads. Otherwise it updates the usage counters and says "<domain> is active." or "<domain> is switched off."
- **Writes and side effects**: outbound calls to my.tracking202.com only; nothing in the local database (except through the key save on Personal Settings).
- **Edge cases and states**: no key; "The ClickServer service could not be reached…" when the list is null; "No domains activated yet" for an empty list; licence unreadable.
- **v3 coverage**: **MISSING**. No route stores the ClickServer key, lists the domains, reads the licence or activates/deactivates a domain.

## Missing v3 endpoints

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Home | Money-loss alerts of the last 7 days | none | No route lists `202_alert_log`. |
| Home | Old Google Ads feed notice | `GET /conversions/offline-export`, `GET /offline-conversions/{}` | No route reads `202_legacy_google_feed_fetches` (waiting legacy feed fetches). |
| Personal Settings | Data & communication consent (analytics, e-mail marketing) read and record | none | No route reads or records consent, and none runs the analytics purge on a denial. |
| Personal Settings | Money-loss alert settings read and save | none | No route reads or writes `202_alert_settings`. |
| Personal Settings | Send a test alert | none | No route delivers a test alert or applies the 30-second throttle. |
| Personal Settings | Alerts of the last 30 days with delivery state | none | No route lists `202_alert_log`. |
| Personal Settings | Currency change history | none | No route reads `202_currency_changes`. |
| Personal Settings | Count of conversions with no currency recorded | none | No route answers `RowCurrency::unrecorded()`. |
| Personal Settings | Record the currency of earlier conversions | none | No route runs `RowCurrency::recordBefore()` or releases held destination deliveries. |
| Personal Settings | Campaign currency notice: list flagged campaigns, keep their currency | `PUT /campaigns/{}` | No route reads or clears `aff_campaign_currency_notice`; switching works only one campaign at a time. |
| Personal Settings | AI watchers of an AI key: list and stop | none | No route proxies the MCP relay's watcher list or stop. |
| Personal Settings | Prosper202 customer API key: hint, save with validation, remove | `GET /capabilities` (reads only whether it is set) | No route writes or validates `p202_customer_api_key` or returns its hint. |
| Personal Settings / ClickServer Management | Save the ClickServer API key with validation | none | No route writes `clickserver_api_key`. |
| Personal Settings | Tracking202 key and Stats202 app key handlers (legacy, no form) | none | No route writes `user_api_key` or `user_stats202_app_key`. |
| Connect an AI assistant | Validate the approval link and issue the one-time approval code | `GET /connect`, `POST /connect/token` | No route performs the consent step that issues the code. |
| ClickServer Management | List the account's ClickServer domains and licence usage | none | No route reads the my.tracking202.com domain list or licence. |
| ClickServer Management | Activate or deactivate a domain | none | No route proxies activate/deactivate. |

## Partial v3 coverage

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Home | Get Started counts | `GET /ppc-accounts`, `GET /campaigns`, `GET /trackers` (`pagination.total`) | The lists do not exclude records whose parent (traffic source, affiliate network) is deleted, so counts can differ. |
| Home | Postback queue notice | `GET /system/cron` | Needs `access_to_settings` and reports an install-wide backlog, not the user's. |
| Home | Special offers iframe | `GET /me` (`switches.sponsored_ads`) | The `t202aid` value (the user's `user_api_key`) is not exposed. |
| Personal Settings | Profile read | `GET /users/{}`, `GET /users/{}/preferences` | Preferences answer 404 when the user has no `202_users_pref` row; the page creates the row. |
| Personal Settings | Profile save | `PUT /users/{}`, `PUT /users/{}/preferences` | Two calls, not one transaction; no stale check; tracking domain validated by the API but not by the page; e-mail uniqueness counts deleted users in the API; no Slack notices. |
| Personal Settings | Account currency save | `PUT /users/{}/preferences` (`user_account_currency`) | No stale check; otherwise the same re-pricing and record. |
| Personal Settings | Click filtering settings save | `PUT /users/{}/click-filter` | No stale check; the page's analytics event is not sent. |
| Personal Settings | API key list | `GET /users/{}/api-keys` | No ref to name a key by, and no watchers. |
| Personal Settings | API key create | `POST /users/{}/api-keys` | No Slack notice; the legacy posted key is not accepted. |
| Personal Settings | API key replace | `POST /users/{}/api-keys/replace` | Needs the full key, which no list returns. |
| Personal Settings | API key revoke | `DELETE /users/{}/api-keys/{}` | Needs the full key; AI watchers are not stopped first. |
| Personal Settings | Password change | `PUT /users/{}` (`user_pass`, `current_password`) | No failure counter or forced sign-out after 5 wrong tries; the calling browser session is not kept; no retype field. |
| Connect an AI assistant | Key minting after consent | `POST /connect/token` | Covers the exchange only; the consent step is missing (above). |

## Not determined from code

- Whether a non-admin can read the install's tracking base URL through v3 (needed for Home's deeplink cookie image): `GET /system/info` was not opened for its permission rule or fields.
- The exact sentences `ClickFilterTester::test()` produces for a tested address: the class was not read.
- The exact per-channel skip reasons `AlertNotifier::deliver()` puts in the test-alert flash: not read.
- Whether ClickServer Management signing out (or reloading) on any change to the page's own domain is intended: the code's comment speaks of switching that domain off, but the code acts on activation too.
