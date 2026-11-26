# Old UI inventory for the 2.0 rebuild

What every user-facing PHP page in Prosper202 1.9.81 does, read from the code, so each screen of the 2.0 React app can be held to a comparison test against the page it replaces. Each area file lists, page by page: where it lives and who may open it, what it shows, every field, filter, sort, button, bulk action, export, modal and AJAX call, what it writes, the edge cases it handles, and which v3 route covers each part (or that none does).

Read from the `1.9.81` branch on 2026-11-26. 97 page files are documented, 114 screens counting the tabs and views that have their own URL (Attribution, Customer LTV, Mobile Apps) and the downloads. Each reader was told to open a route's handler before saying it covers a page; nothing was run against a live instance, so treat a coverage claim as read, not tested. Each file ends with what could not be settled from the code.

## Areas

| File | Area | Page files | Missing | Partial |
|---|---|---|---|---|
| [00](00-shell-and-navigation.md) | Shell: navigation tree, global notices, chrome AJAX | 4 (shared with 01) | 16 | 4 |
| [01](01-sign-in-install-standalone.md) | Sign-in, password reset, install, upgrade, standalone pages | 30 | 17 | 4 |
| [02](02-account-home-and-personal-settings.md) | Home, Personal Settings, Connect AI, ClickServers | 4 | 17 | 13 |
| [03](03-settings-users-upgrades.md) | Settings, Users, 1-click upgrades, roles and permissions | 4 | 7 | 11 |
| [04](04-api-keys-and-integrations.md) | API keys and 3rd-party integrations | 1 (+2 shared with 02) | 15 | 13 |
| [05](05-attribution.md) | Attribution report, journeys, models, exports | 2 | 0 | 3 |
| [06](06-setup-traffic-sources-and-campaigns.md) | Setup: traffic sources, categories, campaigns | 4 | 4 | 6 |
| [07](07-setup-landing-pages-text-ads-rotators.md) | Setup: landing pages, text ads, redirectors | 3 | 6 | 10 |
| [08](08-setup-links-code-postbacks.md) | Setup: Get Links, landing page code, postbacks | 6 | 6 | 7 |
| [09](09-setup-mobile-apps.md) | Setup: Mobile Apps | 1 (4 views) | 6 | 7 |
| [10](10-setup-destinations-spend-offline-inbound.md) | Setup: destinations, spend sync, offline conversions, inbound log | 5 | 3 | 3 |
| [11](11-overview-reports.md) | Overview reports | 8 | 2 | 7 |
| [12](12-analyze-reports.md) | Analyze reports | 15 | 7 | 8 |
| [13](13-analyze-mobile-apps.md) | Analyze: Mobile Apps | 1 (5 tabs) | 1 | 3 |
| [14](14-customer-ltv.md) | Customer LTV | 2 (7 views) | 3 | 7 |
| [15](15-visitors-spy-update-logs.md) | Visitors, Spy, Update, Conversion Logs | 10 | 5 | 6 |
| [99](99-v3-routes.md) | Every route v3 serves (325) | | | |

The Missing and Partial counts are rows in each file's tables; areas share some gaps, so the list below merges them.

Not in scope: the click and conversion endpoints visitors and networks hit (`tracking202/redirect/`, `tracking202/static/`, `.well-known/` receivers), cron jobs, and the APIs and CLIs themselves. They have no screen.

## Missing v3 capabilities, merged

Each line is a thing a page does that no v3 route does. The area file has the detail and the nearest route.

**Session and install (01, 00, 03)**
- Password sign-in, with the page's throttle, login-log write and failed sign-in alert; the remember-me cookie.
- Sign out: ending a session and deleting its remember-me key.
- Lost password (mint a key, send the email) and reset by key.
- Saving and validating the licence (customer API) key.
- Pre-install: database settings, requirements check, licence key hand-off, install and first account. v3 cannot answer before `202-config.php` exists.
- Running the upgrade, its changelog and failure report; checking for and running the free and premium 1-click upgrades; the update banner (notes, price, eligibility) and its snooze.

**Account and Settings (02, 03, 04)**
- Money-loss alerts: settings, test alert, the 30-day log, and the Home list.
- Data and communication consent: record it and run the analytics purge on withdrawal (v3 reads it only).
- Currency: change history, count and record the currency of earlier conversions, the campaign currency notice.
- Customer API key hint, save and remove; ClickServer key; legacy Tracking202 and Stats202 keys (no form renders these).
- AI keys: list and stop an AI key's watchers; the Connect AI consent step that issues the one-time code.
- Settings: AutoCron on or off; MaxMind account save, remove and refresh now; egress proxy read, save, test and remove; restore notices; the trusted-proxies summary; running the cron (the footer ping).

**Integrations and remote services (00, 01, 02, 04, 06, 08)**
- DNI: the network catalogue, networks list, add, edit and remove, import progress and "mark processed", the offer browser with its access requests and offer setup, and the offer-track call a page create makes.
- Landing Page Optimizer: connect, disconnect, status, site key and webhook health; keyword privacy (`lpo_ctx_kw`) write.
- JVZoo 1.9.56 role write; IPQualityScore status, 24-hour counts and last error.
- ClickServer domains: list, licence usage, activate and deactivate.
- Feeds: TV202, Hot Deals, App Store; VIP Perks survey; support chat pass; product analytics events; the bundled documents.

**Setup (06 to 10)**
- Clone a campaign (the CLI does it on the client).
- Landing pages: split tests, Facebook Pixel Assistant, the ChatGPT Ads pixel switch, the optimize link's pairing state.
- Redirector rule value suggestions.
- Publisher credit on links: list grantable and credited publishers, grant, revoke, and `t202pubid` in the link.
- Get Links: the Google Ads final URL and the `{lpurl}` tracking template.
- Mobile Apps: store lookup of name and icon, the `app_icon` field, reading a store link without creating (and opening the account's own registration), the starter SKAN schema, and finding or creating an event goal with its rule in one transaction.
- Offline conversions: fetch URLs (create, list, new key, delete), stored conversion names per campaign, and the old Google feed schedules (list, move, hide, and the notice that counts them).

**Reports and exports (05, 11 to 15)**
- File exports: every Analyze dimension and Publishers, Group Overview, Visitors, Conversion Logs, LTV customers, the Mobile Apps CSV and the attribution breakdown CSV. The pages answer files; v3 answers JSON only.
- Custom Variables report (clicks by variable value).
- Filter menu lists: countries, browsers, platforms, device types, grantable publishers; turning a typed region or ISP into an id.
- Account Overview chart's saved lines.
- Spy's "newer than this click" cursor.
- Conversion Logs: deleted rows and the click-time basis.
- Customer LTV: per-customer engagement score; company search that leaves out the merge target.

**Across every Setup write**: the pages send Slack notifications and analytics events; v3 writes send neither.

## Partial coverage worth planning for

The area files list about 110 rows. The ones that recur:
- **Permissions differ.** Several v3 routes ask for a different permission than the page (`access_to_setup_section` where the page asks `view_attribution_reports`, `access_to_personal_settings` where it asks `access_to_api_integrations`, admin role names where it asks `add_users`, install-wide reads that need `access_to_settings` where the page shows one account's).
- **Reports return less.** `/reports/breakdown` groups by id rather than name, drops the "[no keyword]" and "[direct link]" rows, has no CTR, S/U, average payout, totals or row count, and sorts by clicks. `/reports/groups` lacks three levels and stops at 5,000 groups; `/reports/timeseries` has no year interval.
- **Saved preferences.** `PUT /users/{id}/preferences` saves only the row limit, the time preset and CPC/CPV, not the saved report filters or groupings.
- **Whole-set saves.** Pages save pixels, traffic source variables and PayKickstart keys as one set; v3 works one item at a time.
- **No stale-page check.** Pages refuse a save when the record changed since the form opened (models, settings, profile); v3 has no version or If-Match.
- **Keys.** Replacing or revoking an API key needs the full key, which no list returns.

## Things the reading turned up

Recorded in the area files; listed here so they are not lost. None was changed.
- `api-key-required.php` accepts a new licence key from anyone with a session token, signed in or not, and saves it on user 1 if the key validates (checked: the POST asks only `AUTH::check_csrf_token()`).
- The password reset request and the reset itself do not check whether the user is switched off or deleted (checked: neither query asks `user_active` or `user_deleted`). Sign-in still refuses such a user.
- The Group Overview shows `$` whatever the account currency, and its download writes CPC multiplied by 100; in CPV mode the rotator page shows "1" for the currency sign.
- The Visitors download exports only the first page of rows; Update Subids and Reset stop with an uncaught error when a batch is interrupted.
- User Management says the Campaign optimizer role can update costs and subids; the role lacks `access_to_update_section`. `remove_rotator_criteria` is granted but no file checks it.
- Pages nothing links to: `202-account/auto-upgrade.php`, `tracking202/overview/rotator-breakdown.php`, `tracking202/setup/get_dynamic_smart_component_code.php`, `202-appstore/index.php`.
