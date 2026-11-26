# Shell, navigation and global notices

This area covers what every signed-in page has in common:
- the page shell drawn by `template_top()` and `template_bottom()` in `202-config/template.php`, including the header, the primary navigation, the My Account menu, the theme switch and sign-out;
- the Prosper202 CS section tabs (`tracking202/_config/top.php`) and the section strips (`tracking202/_config/sub-menu.php`);
- the body classes and assets the shell loads;
- the banners and notices that appear on more than one page, or that the shell draws itself (the update banner, the EU consent prompt, the support chat, the cron ping, the postback-queue, old-Google-feed and restore notices, the money-loss alerts panel, and the payment notices);
- the session, CSRF and token plumbing the shell sets up for every page;
- the AJAX endpoints the shell and these notices call;
- the four pages that exist only to be linked from the chrome: Sign out, TV202, Hot Deals and the App Store.

The standalone shell (`info_top()`) is covered only as far as its chrome goes. Each page's own content is documented in that page's area file.

## Pages

| Page | Path | Permission |
|---|---|---|
| Sign out (confirmation and POST) | `202-account/signout.php` | Signed in. A signed-out visitor is redirected to the base. |
| Watch TV202 | `202-tv/index.php` | `AUTH::require_user()` (signed in and licence-checked); no role permission |
| Hot Deals & Discounts | `202-resources/index.php` | `AUTH::require_user()`; no role permission |
| App Store | `202-appstore/index.php` | `AUTH::require_user()`; no role permission. No menu links to it. |

Redirect-only entry points the navigation links to (these render nothing):
- `tracking202/index.php` → `tracking202/overview/`
- `tracking202/setup/index.php` → `ppc_accounts.php`
- `tracking202/analyze/index.php` → `keywords.php`

AJAX endpoints documented as items in this file: `202-account/ajax/check-for-update.php`, `update-needed.php`, `delay-alert.php`, `new-offers.php`, `dni.php`, `chat-pass.php`, `validate-apikey.php`, `messaging/_auth.php` (a shared gate, not an endpoint), `messaging/consent.php` and `messaging/track.php`. The brief named `202-account/messaging/`; that directory does not exist, and the files are under `202-account/ajax/messaging/`.

---

## 1. Navigation tree

### 1.1 How the shell knows where it is

- `connect.php` matches `REQUEST_URI` against `/\b(api|tracking202|202-\w+).*/`, removes the query string and splits on `/`, which gives `$navigation[1]`, `[2]`, `[3]`. For example, `/tracking202/setup/aff_campaigns.php` gives `tracking202`, `setup`, `aff_campaigns.php`.
- Menu items are marked active from these values; the tables below say how.
- Permissions are read with `$userObj->hasPermission('<name>')`. The permission names live in `202_permissions` and the role grants in `202_role_permission`. Both are editable data, so the role column below shows what the installer and the upgrade **seed**:
  - Super user (role 1): every permission.
  - Admin (2): every permission except `add_edit_delete_admin`.
  - Campaign manager (3): `access_to_campaign_data`, `access_to_setup_section`, `access_to_update_section`, `view_attribution_reports`.
  - Campaign optimizer (4): `access_to_campaign_data` only.
  - Campaign viewer (5) and Publisher (6): none.
- A user who may not act (switched off or removed) is signed out by `connect.php` before any menu is drawn, and `User` loads no roles for them.

### 1.2 Header (every page drawn by `template_top()`)

`p202_chrome_header()` draws three things, in this order:

1. **Logo slot.** An iframe `.advertise-top-left` loading `https://ads.tracking202.com/prosper202-cs-topleft/?t202aid=<$_SESSION['user_cirrus_link']>&theme=auto`. It is drawn whenever `TRACKING202_ADS_URL` is defined, and `connect.php` always defines it.
   - `user_cirrus_link` holds the user's Tracking202 API key. It is put in the session only on a remember-me sign-in (`functions-auth.php` line 1033) and when the key is saved in Personal Settings; otherwise it is empty.
   - `p202-chrome.js` posts `{type:'p202-theme', theme}` to the iframe on load and whenever `data-bs-theme` changes.
2. **Primary navigation** (`nav.p202c-nav`):

| id | Label | URL | Shown when | Active when |
|---|---|---|---|---|
| `HomePage` | Home (house icon) | `202-account/` | always | nav1 = `202-account` and nav2 = '' |
| `ClickServerPage` | Prosper202 CS (heart icon) | `tracking202/` (redirects to `tracking202/overview/`) | always | nav1 = `tracking202` |
| `AttributionPage` | Attribution (graph icon) | `202-account/attribution.php` | `view_attribution_reports` | nav2 = `attribution.php` |
| `Tv202Page` | Watch TV202 ("Watch " is hidden on narrow widths) | `202-tv/` | always | nav1 = `202-tv` |
| `ResourcesPage` | Hot Deals & Discounts (" & Discounts" is hidden on narrow widths) | `202-resources/` | always | nav1 = `202-resources` |

3. **My Account menu.** A `<details id="account-dropdown" class="p202c-menu">` whose summary reads "My Account", with a person icon and a chevron.
   - Badge `#notification` shows "1" when `vip_perks_status` (read from `202_users` by `get_user_data_feedback()`) is non-empty.
   - The summary is marked `is-active` when nav1 = `202-account` and nav2 ≠ ''.

| id | Label | URL | Shown when |
|---|---|---|---|
| `PersonalSettingsPage` | Personal Settings | `202-account/account.php` | always. The menu does **not** check `access_to_personal_settings`; the page reads it (`$canPersonal`, line 23) and limits its own sections and actions by it. |
| `VIPPerksPage` | VIP Perks Profile (badge `notification-perks`) | `202-account/vip-perks.php` | `access_to_vip_perks` |
| `3rdPartyAPIPage` | 3rd Party API Integrations | `202-account/api-integrations.php` | `access_to_api_integrations` |
| `UserManagementPage` | User Management | `202-account/user-management.php` | `add_users` |
| `SettingsPage` | Settings (gear icon) | `202-account/administration.php` | `access_to_settings` |
| `HelpPage` | Help (question icon) | `202-account/help.php` | always |
| — | Theme: Auto / sun icon / moon icon | buttons `data-theme-choice="system\|light\|dark"`, with `aria-pressed` | always |
| `SignoutPage` | Sign out (exit icon) | a POST form to `202-account/signout.php` carrying the session token (`p202_signout_form`, `form.p202c-signout[data-p202-signout]`) | always |

All icons are inline SVG from `p202_chrome_icon()`. `202-config/functions-icons.php` is an empty file (0 bytes); two files include it.

### 1.3 Prosper202 CS section tabs (`tracking202/_config/top.php`)

`template_top()` includes this file when nav1 = `tracking202`. It draws `nav.p202c-tabs` with `ul#second-nav`. The current tab gets `li.active` and `aria-current`.

| id | Label | URL | Shown when | Active when |
|---|---|---|---|---|
| `SetupPage` | Setup | `tracking202/setup` | `access_to_setup_section` | nav2 = `setup` |
| `OverviewPage` | Overview | `tracking202/overview` | always | nav2 = `overview`. A second condition, nav1 = `account` and nav2 = '', can never be true, because nav1 is always `tracking202` here. |
| `AnalyzePage` | Analyze | `tracking202/analyze` | always | nav2 = `analyze` |
| `VisitorsPage` | Visitors | `tracking202/visitors` | always | nav2 = `visitors` |
| `SpyPage` | Spy | `tracking202/spy` | always | nav2 = `spy` |
| `UpdatePage` | Update | `tracking202/update` | `access_to_update_section` | nav2 = `update` |
| `ConversionLogsPage` | Conversion Logs | `tracking202/logs/` | `access_to_setup_section` | nav2 = `logs` |

The tabs only hide links. Each page enforces its own permission.

### 1.4 Section strips (`tracking202/_config/sub-menu.php`)

`template_top()` includes this file when nav2 is `setup`, `bots`, `overview`, `analyze`, `update` or `export`. It draws `nav#sub-menu.p202c-strip`, with `aria-label` set to the strip's label. An item is active when nav3 is in its page list.

**Setup** (label "Setup"). The strip is empty for a role without `access_to_setup_section`; such a role can still reach a Setup page such as the RefundAccess Inbound Log through a link.

| Label | URL (under `tracking202/setup/`) | Active for |
|---|---|---|
| Traffic Sources | `ppc_accounts.php` | `ppc_accounts.php`, the index |
| Categories | `aff_networks.php` | itself |
| Campaigns | `aff_campaigns.php` | itself |
| Landing Pages | `landing_pages.php` | itself |
| Text Ads | `text_ads.php` | itself |
| Redirector | `rotator.php` | itself |
| Mobile Apps | `mobile_apps.php` | itself |
| Get LP Code | `get_landing_code.php` | itself, `get_simple_landing_code.php`, `get_adv_landing_code.php` |
| Get Links | `get_trackers.php` | itself |
| Postback/Pixel | `get_postback.php` | itself |
| Offline Conversions | `offline_conversions.php` | itself |
| Destinations | `destinations.php` | itself |
| Inbound Log | `inbound_log.php` | itself |

Setup pages with no strip entry, reached from links on other pages: `attribution_models.php`, `destination-oauth.php`, `get_dynamic_smart_component_code.php`, `spend_sync.php`.

**Overview** (label "Overview reports"):

| Label | URL | Active for |
|---|---|---|
| Campaign Overview | `tracking202/overview` | `campaign.php`, the index |
| Breakdown Analysis | `overview/breakdown.php` | itself |
| Day Parting | `overview/day-parting.php` | itself |
| Week Parting | `overview/week-parting.php` | itself |
| Group Overview | `overview/group-overview.php` | itself |

`overview/rotator-breakdown.php` has no strip entry.

**Analyze** (label "Analyze reports"), all under `tracking202/analyze/`:

| Label | URL | Shown when |
|---|---|---|
| Keywords | `keywords.php` (also active for the index) | always |
| Text Ads | `text_ads.php` | always |
| Referers | `referers.php` | always |
| IPs | `ips.php` | always |
| Countries | `countries.php` | always |
| Regions | `regions.php` | always |
| Cities | `cities.php` | always |
| ISP/Carrier | `isp.php` | always |
| Landing Pages | `landing_pages.php` | always |
| Devices | `devices.php` | always |
| Browsers | `browsers.php` | always |
| Platforms | `platforms.php` | always |
| Custom Variables | `variables.php` | always |
| Customer LTV | `ltv.php` | `access_to_campaign_data` |
| Publishers | `publishers.php` | `access_to_campaign_data` |
| Mobile Apps | `mobile_apps.php` | `view_attribution_reports` |

**Update** (label "Update tools"), all under `tracking202/update/`:

| Label | URL | Shown when |
|---|---|---|
| Update Subids | `subids.php` (also active for the index) | always |
| Update CPC | `cpc.php` | always |
| Reset Campaign Subids | `clear-subids.php` | always |
| Delete Subids | `delete-subids.php` | `delete_individual_subids` |
| Upload Revenue Reports | `upload.php` | always |

**bots** and **export** include the file, but no strip is defined for them, so nothing is drawn.

### 1.5 Body classes and assets

- **`template_top($title, $options)`** accepts only these options: `meta_description`, `meta_keywords`, `extra_head`, `body_class`, `body_id`, `body_style`.
  - Any other key throws `InvalidArgumentException`. `'ui'` throws with a message of its own, because the classic shell it chose has been removed.
- **Body classes:**
  - `p202-shell-v2` (`P202_SHELL_BODY_CLASS`)
  - `p202-section-<slug of nav1>`
  - `p202-sub-<slug of nav2>`
  - plus any `body_class` option.

  A slug drops `.php` and turns each run of non-alphanumeric characters into `-`. For example, `/tracking202/setup/x.php` gives `p202-section-tracking202 p202-sub-setup`, and `202-account/account.php` gives `p202-sub-account`.
- **Head:**
  - `robots noindex,nofollow`
  - favicon `202-img/favicon.gif`
  - an inline script that reads `localStorage['p202-theme']` before first paint and sets `data-bs-theme`.
- **Assets** (`p202_shell_assets()`; every file is pinned in `202-config/assets.php` with its SHA-384):
  - CSS: `p202-chrome.css`, `bootstrap.css`, `bootstrap-icons.css`, `p202-theme.css`, `p202-components.css`, plus `p202-chat.css` when signed in.
  - Blocking JS: `jquery.js`, `bootstrap.js`.
  - Deferred JS: `highcharts.js` and `202-js/chart.theme.js` (the tracking202 section only), then `tablesort.js`, `p202-ui.js`, `p202-chrome.js`, plus `p202-chat.js` when signed in.
- **Footer** (`template_bottom()`):
  - Links: prosper202.com, Help (`202-account/help.php`), Documentation (support.tracking202.com).
  - A version line, then local time (`DATE_RFC2822`) and "Copyright © Blue Terra LLC".
  - The version line reads "Your Prosper202 ClickServer <v> is out of date. Download New Version" (linking to `https://my.tracking202.com/api/customers/login`) when `$_SESSION['update_needed']` is truthy, and "…is up to date." otherwise.
- **Dead code:** a `window` `message` listener in `template_top()` resizes `#adframe` to the numeric height a message carries. No page in the tree has an `#adframe` element.

### 1.6 Chrome scripts

- **`202-js/p202-chrome.js`:**
  - Closes an open `details` menu on an outside click or Escape.
  - Scrolls the active tab or strip item (`li.active, .is-active`) to the centre when its list overflows. On resize this runs at most once per frame, and only when the width changed.
  - Runs the theme switch: it stores `localStorage['p202-theme']` (removed for "system") and follows `prefers-color-scheme` while set to system.
  - Runs the update-banner fetches (section 2.1).
  - Posts the theme to the logo iframe.
- **`202-js/p202-ui.js`** provides hooks that any page uses by markup:
  - `[data-p202-copy]` copies text and says "Copied", or "Press Ctrl+C" where copying is refused.
  - `[data-p202-reveal]`.
  - `form[data-p202-confirm]` asks `window.confirm`, by default "Are you sure?".
  - `select[data-p202-range]` with `[data-p202-range-field]` and `[data-p202-range-hint]`.
  - Bootstrap tooltips and popovers.
  - `details[data-p202-remember]` keeps its state in `localStorage['p202-disclosure:<key>']` (`open`/`closed`).
  - `table[data-p202-sort]` sorts through Tablesort and keeps `aria-sort` in step.
  - `[data-p202-flashes]` is moved into the panel the URL fragment names.
  - `input[data-p202-suggest-url]` with `%QUERY` fills a datalist from a GET fetch: 250 ms debounce, terms of 2 or more characters, up to 20 suggestions, and it understands comma-separated lists.
  - The script exposes `window.p202ui = {init, initHints, copyText}`.
- **`202-js/chart.theme.js`:** the Highcharts theme, read from CSS variables, with the Lato font.

### 1.7 Standalone shell (`info_top()`, chrome only)

- **Used for:** sign-in, password reset, installer, upgrader, 404, `_die()`, and the sign-out confirmation.
- **Options:** `title`, `wide`, `ads`.
- **Assets:** loads the signed-out asset list.
- **Header:**
  - The brand image `202-img/prosper202.png`.
  - An optional Google Publisher Tag slot `/1006305/P202_CS_Login_Page_288x200`.
  - A `getWallpaper()` link, drawn only when both its image and its URL are http(s).
  - No navigation.
- **Footer:** `info_bottom()` draws "Prosper202 ClickServer · Help".
- **Token:**
  - `p202_standalone_wizard_token()` starts a session with the same cookie flags as `connect.php` and mints a token when none is set.
  - `p202_standalone_wizard_token_ok()` refuses when either side is empty.

---

## 2. Global banners, notices and alerts

### 2.1 Update-available banner (every signed-in page)

- **Where:** `template_top()` draws an empty `#update_needed.p202c-update` when `user_id` is set and `$_SESSION['publisher']` is empty, which is always the case, because nothing writes `publisher`.
  - It carries `data-p202-check` (`ajax/check-for-update.php`), `data-p202-banner` (`ajax/update-needed.php`), `data-p202-snooze` (`ajax/delay-alert.php`) and `data-p202-token`.
- **Flow** (`p202-chrome.js`):
  1. When the browser is idle (`requestIdleCallback`, timeout 2000 ms, or `setTimeout` 1500 ms), it sends a GET to check-for-update.
  2. Then a GET to update-needed, whose HTML becomes the element's `innerHTML`.
  3. A failure at either step is silent.
  4. The fetch sends no `X-Requested-With`, so `connect.php` opens a writable session for it rather than read-and-close.
- **Content** (`p202_update_banner()` in `functions-update-banner.php`):
  - It answers '' unless `show_update_check` is set, and '' unless one of `auto_upgraded_not_possible`, `update_needed` or `premium_update_available` is set.
  - Every banner is `alert-warning p202-flash alert-dismissible[data-p202-update-banner]` with title "A new version of Prosper202 is available." and a close button labelled "Hide for an hour".
  - **Managed deployment** (`auto_upgrade_disabled()`: `P202_DISABLE_AUTO_UPGRADE` truthy, or any `COOLIFY_*` environment variable): explanatory text only, no actions.
  - **Not possible** (202-config not writable, or no zip extension): text naming both, and the action "Download the upgrade files" (`https://my.tracking202.com/`).
  - **Update needed:** "Upgrade in one click, or by hand. The upgrade page lists what is new." Actions: 1-Click Upgrade (`202-account/auto-upgrade-premium.php`) and the manual download.
  - **Premium** (otherwise): the feed's headline and body, plus "Released <release-date>.".
    - No Customer API key: adds "To upgrade, add your Prosper202 Customer API key in Personal Settings.", an optional register button (http/https only; label from `register-button-text`, default "Get a Customer API key") and a Personal Settings button.
    - With a key: a button labelled from `order-button-text` (default "1-Click Upgrade"), with " ($price)" added when `upgrade-price` is numeric, linking to `auto-upgrade-premium.php`.
    - `check_premium_update()` sets `update_needed` and `premium_update_available` together, so this branch is reached only when the session still carries a premium flag from an earlier check.
- **Snooze:** clicking `[data-p202-update-banner] .btn-close` sends a POST of `delay=1&token=…` to delay-alert, which stops the check for an hour. A failure is ignored.
- **Writes:** session keys only (`next_update_check`, `show_update_check`, `update_needed`, `premium_p202_details`, `premium_update_available`, `auto_upgraded_not_possible`). The checks contact `my.tracking202.com`.

### 2.2 Cron ping (every page drawn by `template_bottom()`)

- **What it is:** 3 s after window load, the page sends `navigator.sendBeacon('//<getTrackingDomain()><install path>202-cronjobs/?page_ping=1')`, built by `p202CronBeaconStatement()` in `functions-tracking202.php`.
- **When it is skipped:** when the tracking domain cannot be read (the page carries a comment and the server logs a line), and when `template_bottom(false)` is called, as the database error page calls it.
- **What the ping runs:** the whole cron (seconds job, hourly, daily, AutoOptimizeDatabase, ClearOldClicks) under `CronLock`, with **no authentication**.
- **What it records:** the run is marked as a page ping (`CronRun`) and is not written to `202_cronjob_logs` as a scheduler run, so it does not count as "the cron job ran".

### 2.3 Analytics page view (every signed-in page)

- `template_bottom()` calls `Analytics::event('page_viewed', ['path' => <request path>], 'analytics')`.
- It is gated on the account's analytics consent. When it records, it writes `202_messaging_events` through `MessagingService::recordEvent`.

### 2.4 EU consent prompt (signed-in pages, until answered)

- **Condition:** `ConsentPolicy::needsEuPrompt` is true when the account is in the EU, `analytics_consent = 'unset'`, and the prompt has not been seen.
  - `template_bottom()` then draws `#p202-consent-banner` (`data-base`, `data-token`) and loads `202-js/consent-prompt.js`.
  - Errors are caught and logged with the prefix "[Consent]", and the page renders without the prompt.
- **UI:** a fixed card headed "Get workflow tips & higher-paying offers", with disclosure text and a "Learn more" link to `202-account/disclosure.php`.
- **Actions:**
  - **Accept** and **No thanks** send a POST of `flag=analytics`, `state=granted|denied`, `source=eu_prompt` and the token to `ajax/messaging/consent.php`.
  - The card is removed only on `{ok:true}`. Otherwise it shows "Your choice could not be saved. Please try again."
- **Writes:** see `consent.php` (section 4.9).

### 2.5 Support chat (every signed-in page)

- **Setup:** `template_bottom()` writes `window.P202_CHAT_CONFIG = {base, token, user}` and a `Prosper202Messenger` queue stub; `p202-chat.js` loads deferred.
- **Start:** when the browser is idle after load, it fetches a pass from `ajax/chat-pass.php`.
  - 409 hides the chat.
  - 401 or 403 shuts it down.
  - Any other failure shows "unavailable".
- **Calls to the chat service.** Each sends `Authorization: Bearer <pass>`, in CORS mode with credentials omitted:
  - `GET /v1/customer/state?after=`
  - a WebSocket to `chat_url` + `/v1/customer/socket`, subprotocols `['p202chat.v1', pass]`
    - while the socket is down, it polls every 5 s with the page visible and every 60 s with it hidden, and retries the socket after 300 s
  - `POST /v1/customer/messages {cmid, body, conv}`
  - `POST /v1/customer/read {conv, upto}`
  - `POST /v1/customer/typing`
- **Pass renewal:** a 401 from the chat service renews the pass once, with `refresh=1`.
- **UI:**
  - A launcher button `.p202-chat__launcher` with an unread badge.
  - A panel `role=dialog` with aria-label "Chat with Tracking202 support" and home subtitle "Ask the Tracking202 team anything.". Escape closes it.
  - The composer is capped at 10,000 characters ("A message can be at most 10,000 characters.").
  - Send errors: 429 "Too many messages. Wait a moment, then retry.", 400 shows the service's own message, anything else "Not sent.".
  - The notice "We can't connect to chat right now. We'll keep trying." has a "Try again" button.
- **Messenger API:**
  - `update` and `trackEvent` POST to `ajax/messaging/track.php`. When the answer gives `retry_after_s` (default 20), a `{flush:'1'}` POST follows that many seconds later, plus 0.5 s.
  - `show`, `hide` and `toggle` control the panel.

### 2.6 Postback-queue warning (Home, Account Overview, Settings)

- **Function:** `p202_postback_queue_notice($db, $userId, $base)` in `functions-ui.php`.
- **Data:** `OutboxBacklog::read` for this account takes pending `202_notification_pending` rows more than 900 s past `next_attempt_at` (it counts up to 1000, then answers "more than"), and the scheduler's last run from `202_cronjob_logs` id 1.
- **Condition:** shown when the account has overdue rows and the cron job has never run, or has not run for 900 s or more.
- **Text:** "N conversion postbacks to your traffic sources have not been sent, the oldest waiting <span>. The cron job sends them, and it last ran <span> ago (or: it has never run on this install). Until it runs, your traffic sources are not told about conversions. Set up the cron job, or turn on AutoCron under Account › Settings." It is followed by the link "Open Settings" (`202-account/administration.php#autocron`).
- **Failure:** a failed read is logged and shows nothing.

### 2.7 Old Google feed notice (Home, Overview)

- **Function:** `p202_old_google_feed_notice`.
- **Condition:** `LegacyGoogleFeedFetches` reports one or more schedules still fetching the old Google URL, and the role has both `access_to_setup_section` and `access_to_campaign_data`.
- **Text:** "Your Google Ads conversion uploads have stopped…", with the link "Move it/them over" (`tracking202/setup/offline_conversions.php#old-feed`).
- The move itself is documented in 10-setup-destinations-spend-offline-inbound.md.

### 2.8 Restore notice (Settings only)

- **Function:** `functions-restore-notice-ui.php`, drawn at `administration.php` line 843, which is gated by `access_to_settings`.
- **Data:** `ServerStateStore::forInstall()->restoreNotices()`.
- **Text:** a warning, "This database was restored from an earlier backup.". For each restore it lists:
  - counts of staged changes, Idempotency-Key retry records, sync jobs, sync manifests and change-feed entries;
  - the directory they were kept in;
  - the date they are kept until.
- **Action:** a Dismiss form posts the token and `restore_notice_dismiss[]`.
  - `administration.php` checks the token for every POST (line 57), then calls `dismissRestoreNotices`.
  - On failure it shows the flash "The notice could not be dismissed just now. Try again."
  - Either way it redirects to `administration.php`.
- **Writes:** the server state store's restore-notice records (files, not the database).

### 2.9 Re-click backfill notice (Settings only)

- **Function:** `p202_reclick_backfill_notice`, which draws `ReClickBackfill::stopNotice()`.
- **Content:** when the backfill is stopped at a click that keeps failing, it says since when (in the page's zone) and why it waits. A failed read says so.
- Documented with Settings in 03-settings-users-upgrades.md.

### 2.10 Money-loss alerts panel (Home) and card (Personal Settings)

- **Home panel** (`p202_alerts_home_panel` in `functions-alerts-ui.php`, drawn by `202-account/index.php`):
  - **Data:** `MysqlAlertStore::recent()` for the last 7 days, at most 10. The function also calls `AlertNotifier::rememberWebAddress`.
  - **Header:** title "Money-loss alerts", sub-line "the last 7 days · settings", where "settings" links to `AlertNotifier::SETTINGS_PAGE`.
  - **Columns:** First seen, Alert, How it was sent. An alert whose `last_seen_at` falls within `AlertRun::ACTIVE_SECONDS` shows the pill "Going on"; others show "Earlier".
  - **Delivery text:** "Sent by …", "Never sent, after N tries.", "Not sent yet (try X of MAX)", or "Shown here only: no channel had anywhere to send it."
  - **ChatGPT Ads alerts** carry Pause buttons linking to `tracking202/setup/destinations.php?ad_campaigns=<conn>&preview_pause=<cid>#ad-campaigns`.
  - **Failure:** a failed read shows "Your alerts could not be read just now. Reload the page to see them."
- **Personal Settings card** (`p202_alerts_post/view/card`, anchor `#money-loss-alerts`):
  - Fields: `alerts_enabled`, `alerts_notify_slack`, `alerts_notify_email`, `alerts_notify_webhook`, `alerts_gap_multiple` (maxlength 2), `alerts_min_conversions` (maxlength 4), `alerts_daily_cost_cap` (maxlength 10).
  - A test alert can be sent at most once every 30 s.
  - The card belongs to the Personal Settings page and is documented there.

### 2.11 Payment notices (3rd Party API Integrations only)

- **Function:** `functions-payment-notices.php`. `p202_payment_card_state` reads `HeldNotifications` counts and `RolelessTraffic` for 30 days.
- **Currency notice:** a form "Record it/them" posting `record_held_notifications=<source>`, which runs `HeldReplay`.
  - A waiting warning links to `account.php#currency`.
  - With Setup permission it also links to the Inbound Log filtered on `outcome=currency_mismatch`.
- **JVZoo roleless notice:** a form posting `change_jvzoo_roleless_role=1&jvzoo_roleless_role=vendor`, which runs `updateUserPreference`.

### 2.12 Paid-1.9.56 notes link (Help, upgrade pages)

`p202_came_from_paid_1956` and `p202_paid_1956_notes_link` (`functions-ui.php`) add a notes link on `help.php`, `upgrade.php` and the auto-upgrade pages. They belong to those pages.

---

## 3. Session, CSRF and token plumbing

- **Session start** (`connect.php`):
  - Cookie settings: `httponly`, `SameSite=Lax`, `secure` when the request is HTTPS, `use_strict_mode`, `gc_maxlifetime` 50000, `cookie_lifetime` 0.
  - A request carrying `X-Requested-With: XMLHttpRequest` opens the session `read_and_close`; code that writes later calls `withWritableSession()`.
- **Signed in** means `user_name`, `user_id` and `session_fingerprint` are set, and the last request was less than 50000 s ago.
- **Who may act:** before any page runs, `connect.php` reads the user's standing (`UserStanding`) and ends the session when the user is switched off or removed, or when the stored session generation differs from the one the session holds. A password change, a switch-off or a sign-out counts the generation up.
- **Remember-me:** the cookie is tried in `connect.php` before the session's user is read, so the first request after a closed browser already has the user.
- **CSRF token:**
  - `connect.php` seeds `$_SESSION['token']` with `AUTH::mintCsrfToken()` (`bin2hex(random_bytes(16))`) whenever it is not a non-empty string.
  - `AUTH::check_csrf_token()` compares `$_POST['token']` through `AUTH::csrf_token_matches()` (`hash_equals`). It refuses an empty or non-string value on either side.
- **Token delivery:**
  - **jQuery:** `template_top()` installs `jQuery.ajaxPrefilter`, which adds `token=` to same-origin jQuery POSTs. It skips `FormData`, string bodies that start with `{` or `[`, and data that already carries a token.
  - **fetch():** the prefilter does not see it, so each fetch caller posts the token itself:
    - the update snooze, from `data-p202-token`
    - the chat and the tracker, from `P202_CHAT_CONFIG.token`
    - the consent prompt, from `data-token`
  - **Sign-out:** a POST form carrying the token.
- **`AUTH::require_user()`**:
  - **Signed out:**
    - A script request (`X-Requested-With`, or `Sec-Fetch-Dest: empty`) gets 401 with the text "You are signed out. Sign in again, then reload the page.".
    - Any other request is answered by `202-access-denied.php`, which clears the session's sign-in keys and redirects to `202-login.php?redirect=…` (or to `202-Toolbar/`).
  - **Signed in:** it sets the account's time zone, then checks the licence. A failed check redirects to `api-key-required.php`, with `?licence=unconfirmed` when the licence service gave no answer.
- **`AUTH::require_permissions($perm)`** answers 403 "This account's role does not have the '<perm>' permission."
- **Messaging gate** (`ajax/messaging/_auth.php`): uses `logged_in_with_remember`, with no licence gate. It answers JSON 401 "authentication required", or 403 when the `AUTH` class is missing.
- **The same session in v3:** `Api\V3\SessionSignIn` signs a request in by the session cookie when it carries no `Authorization` header. A write must send the session token in `X-P202-CSRF`. `GET /me` returns `auth.method` (`session` or `api_key`) and `auth.csrf_token`.

---

## 4. AJAX endpoints

### 4.1 `202-account/ajax/check-for-update.php`
- **Where / who:** called by `p202-chrome.js` on every signed-in page. `AUTH::require_user()`.
- **Purpose:** decides whether to ask the premium feed for a newer version.
- **Inputs:** none.
- **Actions:** if `next_update_check` is unset or past, it sets `show_update_check = true` and `update_needed = check_premium_update()`; otherwise it sets `show_update_check = false`.
  - `check_premium_update()` (`functions.php` line 470) calls `getData('https://my.tracking202.com/api/v2/premium-p202/version', 5, 3)`.
  - When the feed's version is newer than `$version`, it sets `premium_p202_details` and `premium_update_available = 1`, sets `auto_upgraded_not_possible` when 202-config is not writable or the `zip_*` functions are missing, and returns 1. Otherwise it returns 0.
  - It never clears the premium flags.
- **Writes:** session only. One outbound request.
- **Response:** an empty body.
- **v3 coverage:** **MISSING**. `GET /system/version` and `GET /system/info` report the installed version and whether the database needs an upgrade; neither asks the premium feed for a newer release.

### 4.2 `202-account/ajax/update-needed.php`
- **Who:** `AUTH::require_user()`.
- **Purpose:** returns the banner HTML (section 2.1).
- **Reads:** when `premium_update_available` is set and `update_needed` is empty, it reads `202_users.p202_customer_api_key` for `user_own_id`. Otherwise it reads the session flags only.
- **Response:** HTML from `p202_update_banner()`, or ''.
- **v3 coverage:** **MISSING** (see 4.1).

### 4.3 `202-account/ajax/delay-alert.php`
- **Who:** `AUTH::require_user()`.
- **Inputs:** `delay`, `token` (POST).
- **Actions:** when `$_POST['delay'] == true`, it runs `check_csrf_token()` (failure: 403 "Invalid token"), then sets `next_update_check = time() + 3600` and `show_update_check = false`.
- **Writes:** session only.
- **v3 coverage:** **MISSING**.

### 4.4 `202-account/ajax/new-offers.php`
- **Who:** `AUTH::require_user()`.
- **Response:** echoes " (N)" from `$_SESSION['new_offers']`.
- **Edge cases:** nothing in the tree sets `new_offers` and nothing calls this endpoint, so it is orphaned.
- **v3 coverage:** **MISSING**. There is nothing to cover, because no data exists behind it.

### 4.5 `202-account/ajax/dni.php`
- **Who:** `AUTH::require_user()` and `require_permissions('access_to_api_integrations')`.
- **Caller:** only the inline script on `api-integrations.php`, which POSTs every 3 s.
- **`?getProgress`:** `DniNetworks::progressRequest` reads `202_users.install_hash` and the unprocessed `202_dni_networks` rows (`id, networkId, apiKey, type`, `processed = 0`).
  - 500 "The account could not be read."
  - 404 "The account could not be found."
  - `{"data":[]}` when there are no networks.
  - Otherwise `getDNICacheProgress()` POSTs JSON to `https://my.tracking202.com/api/v2/dni/<install_hash>/cache/progress` with `RETURNTRANSFER = false`, so the remote body is **streamed straight to the browser**.
  - 502 "The DNI service could not be reached." when that call fails.
- **`?updateStatus&dni=<id>`:** requires POST plus the token (403 "Invalid token.").
  - Runs `UPDATE 202_dni_networks SET processed = '1' WHERE id = … AND user_id = …`.
  - 500 "The network could not be updated." on failure.
  - The caller then removes the row `#network-<id>`.
- **v3 coverage:** **MISSING**. No DNI route.

### 4.6 `202-account/ajax/chat-pass.php`
- **Who:**
  - Must be a POST: anything else gets 405 with `Allow: POST`.
  - Then the `_auth.php` gate, then the token check (403).
- **Inputs:** `token`; `refresh` must be '0' or '1' (400 otherwise).
- **Actions:**
  1. `MessagingService::identityFor` resolves the account's chat identity: 500 `internal` on failure, 403 `forbidden` when there is none, 409 `no_api_key` when the API key or `install_hash` is empty.
  2. The install URL comes from `p202TrackingBaseUrl()`. It is blanked when it is over 300 characters or not http(s).
  3. A pass is kept in `$_SESSION['p202_chat_pass']` per fingerprint until 1 h before it expires (`ChatPassClient::keptPass`). `refresh=1` forces a new one from `CHAT_PASS_URL`.
  4. When the portal gives no pass, it answers 502 `chat_unavailable` and clears the kept pass.
- **Response:** 200 `{ok, pass, chat_url, expires_at_s, user:{name}}`, with `Cache-Control: no-store` and `nosniff`.
- **Writes:** session. One outbound request to the pass service.
- **v3 coverage:** **MISSING**.

### 4.7 `202-account/ajax/validate-apikey.php`
- **Who:** `AUTH::require_user()`.
- **Inputs:** `apikey` (POST).
- **Response:** when `apikey` is posted, it echoes `api_key_validate(trim(apikey))`, which asks the licence service through `ClickServerKeyValidator`. When that check cannot be made, it answers `p202_status_body(503, 'The key could not be checked.')` (JSON `{code, msg}`).
- **Edge cases:** no caller exists in the served tree. `get_apikey.php` says it cannot call this endpoint, because its user is not signed in.
- **v3 coverage:** **MISSING**.

### 4.8 `202-account/ajax/messaging/_auth.php`
The shared gate for chat-pass, consent and track (section 3). It is not an endpoint itself.

### 4.9 `202-account/ajax/messaging/consent.php`
- **Who:** the `_auth.php` gate and the token (403).
- **Inputs:** `flag` ∈ {`analytics`, `email_marketing`}, `state` ∈ {`granted`, `denied`}, `source` ∈ {`settings`, `eu_prompt`} (default `settings`). Anything else is 400 `bad_args`.
- **Callers:** the EU prompt (section 2.4) and the Personal Settings consent switches.
- **Writes** (`ConsentPolicy::record`):
  1. `INSERT IGNORE` into `202_users_pref` (`user_id`).
  2. **Analytics:** updates `analytics_revoked_version` (on a denial), `consent_version`, `analytics_consent`, `analytics_consent_at`, `analytics_consent_source` and `eu_consent_prompt_seen = 1`. A denial also runs `MessagingService::purgeAnalyticsData`.
  3. **Email marketing:** updates `consent_version`, `email_marketing_consent` and `email_marketing_consent_at`.
- **Response:** `{ok:true}`, or 503 `{ok:false, error:"not_saved"}` when nothing was saved.
- **v3 coverage:** **PARTIAL**. `GET /users/{id}/preferences` serves the consent columns read-only, and no v3 route writes consent.

### 4.10 `202-account/ajax/messaging/track.php`
- **Who:** the `_auth.php` gate and the token. Answers carry `no-store`.
- **Account:** `MessagingService::forUser` answers 500 on failure and 409 "messaging unavailable for this account" when there is no messaging identity.
- **Inputs:**
  - `update`: a JSON object (400 "update must be a JSON object").
  - `event_name` with `metadata`: a JSON object (400 otherwise).
  - `flush`: must be '1' (400 otherwise).
  - None of them: 400 "nothing to track".
- **Consent** (`ConsentPolicy::captureFence`): without analytics consent, and with no `flush`, it answers `{ok, recorded:false, reason:"analytics_not_consented"}`, or 503 `consent_unavailable` when consent could not be read.
- **Writes:**
  1. `updateAttributes` (`202_messaging_attributes`) and `recordEvent` (`202_messaging_events`). A failure is 500 "not recorded".
  2. Then `syncExclusive` (under `CronLock` `ANALYTICS_DELIVERY_FILE`) delivers to the portal's `/track`.
- **Response:** `{ok:true, delivery}`, with `retry_after_s` when delivery is busy, throttled, or has more left.
- **v3 coverage:** **MISSING**.

---

## 5. Pages

### 5.1 Sign out — `202-account/signout.php`
- **Where / who:** the Sign out button in the My Account menu. Any signed-in user.
- **Purpose:** ends the sign-in.
- **Data shown:** when no valid POST arrives, a standalone page "Sign out of Prosper202?", with a Sign out button and a "Stay signed in" link to `202-account/`.
- **Inputs:** `token` (POST).
- **Actions:**
  - A POST with a valid token runs `AUTH::signOut()`, then redirects to the base (or `202-Mobile/` for the toolbar).
  - A POST without a valid token gets 403 with the flash "That sign-out did not come from a page of yours…" and the confirmation page.
  - A visitor who is not signed in is redirected.
- **Writes and side effects:** `AUTH::signOut()` deletes the remember-me key its cookie names, counts the user's session generation up (which ends every other session of theirs), and clears the cookie and the session.
- **v3 coverage:** **MISSING**. No route ends a session or revokes remember-me keys for the caller.

### 5.2 Watch TV202 — `202-tv/index.php`
- **Where / who:** the primary navigation. `AUTH::require_user()`.
- **Purpose:** shows Tracking202's video feed.
- **Data shown:**
  - Feed: `getData('https://my.tracking202.com/api/feeds/tv202?us=<$_SESSION['user_hash']>&t202aid=<user_cirrus_link>')`. Nothing puts `user_hash` in the session, so `us=` is sent empty.
  - `p202_tv_modules()` draws each module in a `col-xl-6` grid: title, a 16x9 iframe (YouTube embeds only) and description.
- **Inputs:** none.
- **Actions:** none.
- **Writes and side effects:** none. One outbound request, which carries the user's Tracking202 API key when the session holds one.
- **Edge cases and states:** an empty or failed feed shows "TV202 is not available right now" with a Try again button.
- **v3 coverage:** **MISSING**. No feed route.

### 5.3 Hot Deals & Discounts — `202-resources/index.php`
- **Where / who:** the primary navigation. `AUTH::require_user()`.
- **Purpose:** lists partner deals.
- **Data shown:**
  - Feed: curl to `https://my.tracking202.com/feed/resources/`, following redirects, accepting 2xx only.
  - `p202_resource_deals()` draws deal cards from `deal-url`/`url`, `title`, `deal-img`, `deal-description` and `deal-coupon`, each with a "Get the deal" or "Visit site" button.
- **Inputs:** none.
- **Actions:** outbound links only.
- **Writes and side effects:** none.
- **Edge cases and states:** an empty or failed feed shows "Deals are temporarily unavailable".
- **v3 coverage:** **MISSING**.

### 5.4 App Store — `202-appstore/index.php`
- **Where / who:** no menu links to it. `AUTH::require_user()`.
- **Purpose:** lists Tracking202 apps, and stores the ClickServer API key the apps use.
- **Data shown:**
  - Feed: `https://my.tracking202.com/feed/appstore/`, drawn by `p202_appstore_apps()`. Icons may be `/202-img/…` paths.
  - Each app shows a pill: installed → Installed, coming-soon → Coming soon, un-installed → Install now; any other status shows its own text or "Learn more". It may also show Popular, a price, and a "Find out more" link.
  - The stored key is shown masked: 22 `*` followed by `substr(key, 22)`.
- **Inputs:** `clickserver_api_key`, `token` (POST).
- **Actions:**
  1. A bad token is refused with `P202_ACCOUNT_TOKEN_REFUSED`.
  2. A key containing `*` changes nothing (info flash "Nothing was changed: the key shown is masked…").
  3. Otherwise `p202_check_clickserver_key` checks the key with the licence service (`my.tracking202.com/api/v1/auth`). Errors: "This API Key appears invalid." or `P202_ACCOUNT_KEY_UNCHECKED`.
  4. Then `UPDATE 202_users SET clickserver_api_key`, a flash of "ClickServer API key removed." or "…saved.", and a redirect.
- **Writes and side effects:** `202_users.clickserver_api_key`. Outbound requests to the feed and the licence service.
- **Edge cases and states:** an empty or failed feed shows "The App Store is not available right now".
- **v3 coverage:** **MISSING** for both the feed and `clickserver_api_key`. Neither `GET /me` nor `/users` exposes the key.

### 5.5 The shell itself (every signed-in page)
- **Data the shell reads:** `get_user_data_feedback()` on every page (`user_email`, `user_time_register`, `p202_customer_api_key`, `install_hash`, `user_hash`, `modal_status`, `vip_perks_status` from `202_users`). A failed read throws.
- **v3 coverage:** **PARTIAL**.
  - `GET /me` returns the user, the account (time zone, currency), roles, the raw `permissions` list (enough to rebuild every menu condition above), `flags`, `scopes`, `switches` (`licence`, `sponsored_ads`), `install` (`version`, `schema_version`, `api_version`), `features` and `auth {method, csrf_token}`.
  - `/me`'s convenience flags do not match the menu: `can_view_settings` needs admin, `access_to_settings` and the `system:read` scope, while the menu needs `access_to_settings` only; `can_manage_users` needs admin, `add_users` and `users:write`, while the menu needs `add_users` only. A client copying the menu should read `permissions`.
  - Not served: `vip_perks_status` (the menu badge), and the Tracking202 API key (`user_cirrus_link`) that the logo iframe and TV202 send.
  - `GET /capabilities`, `GET /versions` and the unauthenticated `GET /system/health` also exist.
- **Postback-queue warning:** **PARTIAL**. `GET /system/cron` returns `traffic_source_postbacks {stuck, overdue, oldest_due_at, warning}` with the same text, but for **every** account and only to an admin with `access_to_settings`. No per-account read exists.
- **Re-click backfill notice:** covered by `GET /system/dataengine` (`reclick_backfill`).
- **Payment notices:** `GET /system/integrations` (`held_notifications`, the JVZoo `no_role_url`) and `POST /system/integrations/record-held` cover the Record button. `jvzoo_roleless_role` is served by `GET /users/{id}/preferences` but cannot be written through `PUT` (it is not in `PreferenceRules`).

---

## Missing v3 endpoints

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Shell (`ajax/check-for-update.php`, `update-needed.php`) | Asks the premium feed for a newer release and draws the update banner | `GET /system/version`, `GET /system/info` | No route reports an available upgrade, its notes or price, or whether one-click upgrade is possible |
| Shell (`ajax/delay-alert.php`) | Hides the update banner for an hour | none | No snooze (session state) |
| Shell (`ajax/new-offers.php`) | Echoes a session count of new offers | none | Orphaned; nothing sets it. No route needed. |
| 3rd Party API Integrations (`ajax/dni.php`) | DNI cache progress (proxied to my.tracking202.com) and marking a network processed | none | No DNI progress read or `processed` write |
| Shell (`ajax/chat-pass.php`) | Issues a support-chat pass | none | No chat pass route |
| Shell (`ajax/messaging/track.php`) | Records messaging attributes and events and delivers them to the portal | none | No analytics or event intake route |
| `get_apikey.php` (no caller) (`ajax/validate-apikey.php`) | Checks a Tracking202 licence key | none | No key-validation route |
| Shell (cron ping) | Runs the cron from a page beacon | `GET /system/cron` (read only) | No route runs the cron |
| Sign out | Ends the session, deletes the remember-me key, counts the session generation up | none | No sign-out or session-revoke route |
| Home | Money-loss alerts panel (last 7 days, delivery state) | none | No alerts list route |
| Home, Overview | Old Google feed notice | none | No count or list of legacy feed schedules (also listed in 10-setup-destinations-spend-offline-inbound.md) |
| Settings | Restore notice and its Dismiss | none | No read or dismiss of restore notices |
| Watch TV202 | Video feed | none | No feed route |
| Hot Deals & Discounts | Deals feed | none | No feed route |
| App Store | App feed | none | No feed route |
| App Store | Saves `clickserver_api_key` after a licence check | none | Key not readable or writable in v3 |

## Partial v3 coverage

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Shell (menus) | Shows or hides each menu item by role permission | `GET /me` | `permissions` is enough; `vip_perks_status` (badge) and the Tracking202 API key (logo iframe, TV202) are not served; `/me`'s `can_*` flags add admin and scope conditions the menu does not apply |
| Shell (EU consent prompt) and Personal Settings (`ajax/messaging/consent.php`) | Records analytics or email consent and purges analytics on a denial | `GET /users/{id}/preferences` | Read only; no consent write and no prompt-seen state |
| Home, Account Overview, Settings | Postback-queue warning for this account | `GET /system/cron` | Every account's backlog, admin and `access_to_settings` only; no per-account read |
| 3rd Party API Integrations | JVZoo roleless role switch | `GET /users/{id}/preferences` | `jvzoo_roleless_role` cannot be written through `PUT /users/{id}/preferences` |

## Not determined from code

- **The remote feeds' content and field set** (premium version feed, TV202, resources, app store, DNI progress). Only the fields the pages read are listed; the services are outside the repository.
- **The chat service's API beyond the calls `p202-chat.js` makes**, and what `CHAT_PASS_URL` answers beyond the fields `chat-pass.php` reads.
- **Which roles see which menu items on a given install.** Role grants are rows in `202_role_permission` and can be edited; the table above is the seeded set.
