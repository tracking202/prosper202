# Settings, users and upgrades

This area covers the pages under the My Account menu that run the install rather than one campaign: Settings (`202-account/administration.php`), which holds system facts, click-data retention, AutoCron, ISP lookup, the egress proxy, the GeoIP refresh, traffic source owner repairs and the sign-in log; User Management (`202-account/user-management.php`); and the two 1-click upgrade pages (`202-account/auto-upgrade.php`, `202-account/auto-upgrade-premium.php`). It also lists every role and permission string the legacy role system defines and which file checks each one. The include files `202-config/functions-traffic-source-owners-ui.php` and `202-config/Role.class.php` are described inside the pages that use them. `202-config/functions-click-filter-ui.php` is not used by any page in this area: only Personal Settings (`202-account/account.php`, card `#click-filtering`) includes it, so it is described briefly under "Roles and permissions" notes below and belongs to the Personal Settings inventory. Version read: 1.9.81.

| Page | Path | Permission |
|---|---|---|
| Settings | `/202-account/administration.php` | signed in + `access_to_settings` (embedded traffic source owners panel also needs a v3 admin role name) |
| Users (User Management) | `/202-account/user-management.php` | signed in + `add_users`; removing users and Admin role work needs `add_edit_delete_admin` |
| 1-click upgrade | `/202-account/auto-upgrade.php` | signed in + `access_to_settings`; refused on deployments that disable auto-upgrade |
| Premium 1-click upgrade | `/202-account/auto-upgrade-premium.php` | signed in + `access_to_settings`; refused on deployments that disable auto-upgrade |

## Roles and permissions

How the legacy check works: `User.class.php` loads the user's roles from `202_user_role` joined to `202_roles`; `Role::getRole()` loads each role's `permission_description` strings through `202_role_permission` and `202_permissions`. `User::hasPermission($p)` is true when any of the user's roles has the string. A user whose standing (`UserStanding`) says they may not act holds no permissions. `AUTH::require_permissions(...)` (functions-auth.php) answers 403 "This account's role does not have the '<perm>' permission."

The v3 API is different in one important way: `Auth::isAdmin()` / `requireAdmin()` test role **names** ('admin', 'administrator', 'super user', 'superuser'), not permissions; `requirePermission()` tests permission strings as the pages do. `Auth::MANAGE_ADMINS` is `add_edit_delete_admin`.

### Roles (seeded by `202-config/Database/DataSeeder.php`; upgrades in `functions-upgrade.php`)

| role_id | Name | Permissions granted (install seed) |
|---|---|---|
| 1 | Super user | all, 1–23 |
| 2 | Admin | all except `add_edit_delete_admin` (1, 3–23) |
| 3 | Campaign manager | `access_to_campaign_data`, `access_to_setup_section`, `access_to_update_section`, `view_attribution_reports` |
| 4 | Campaign optimizer | `access_to_campaign_data` only |
| 5 | Campaign viewer | none |
| 6 | Publisher | none (added by the 1.9.47 rung on upgrades) |

Upgrade note: the old rung seeded permissions 1–21 with role 3 = 12, 14, 15; the attribution rung added 22 and 23 and granted (1,22), (1,23), (2,22), (2,23), (3,22). So upgraded and fresh installs agree.

Note: User Management describes role 4 as able to update costs and subids, but the seed gives it only `access_to_campaign_data`; the Update pages need `access_to_update_section`, which role 4 lacks.

### Permission strings and the files that check them

| id | Permission | Checked in |
|---|---|---|
| 1 | `add_users` | `202-account/user-management.php`; menu item User Management (`202-config/template.php`); `202-cronjobs/health.php`; `tracking202/setup/inbound_log.php` |
| 2 | `add_edit_delete_admin` | `202-account/user-management.php` (remove users, assign/edit Admin); v3 `Auth::MANAGE_ADMINS` (`requireMayDeleteUser`, `requireMayChangeRoles`, `requireMayManageUser`) |
| 3 | `remove_traffic_source` | `tracking202/setup/ppc_accounts.php` |
| 4 | `remove_traffic_source_account` | `tracking202/setup/ppc_accounts.php` |
| 5 | `remove_campaign_category` | `tracking202/setup/aff_networks.php` |
| 6 | `remove_campaign` | `tracking202/setup/aff_campaigns.php` |
| 7 | `remove_landing_page` | `tracking202/setup/landing_pages.php` |
| 8 | `remove_text_ad` | `tracking202/setup/text_ads.php` |
| 9 | `remove_rotator` | `tracking202/setup/rotator.php` |
| 10 | `remove_rotator_criteria` | seeded only; no file checks it (grep found none) |
| 11 | `remove_rotator_rule` | `tracking202/setup/rotator.php`; `tracking202/ajax/rotator.php` |
| 12 | `access_to_campaign_data` | `202-account/index.php`; `202-config/Report/CampaignDataMask.php`; `tracking202/_config/sub-menu.php`; `tracking202/ajax/account_overview*`, charts; `tracking202/overview/index.php`; `tracking202/setup/destinations.php`, `offline_conversions.php`; v3 routes |
| 13 | `delete_individual_subids` | `tracking202/_config/sub-menu.php`; `tracking202/update/clear-subids.php`, `delete-subids.php` |
| 14 | `access_to_setup_section` | `202-account/index.php`, `api-integrations.php`; `api/v1/LegacySetup.php`; `tracking202/_config/top.php`, `sub-menu.php`; `tracking202/overview/index.php`; `tracking202/setup/_base/SetupController` and every setup page (get_* code pages, spend_sync, inbound_log, …); v3 routes |
| 15 | `access_to_update_section` | `tracking202/_config/top.php`; `tracking202/update/*` (cpc, subids, upload, clear-subids, delete-subids); `tracking202/setup/inbound_log.php`; v3 routes |
| 16 | `access_to_personal_settings` | `202-account/account.php`, `connect-ai.php`; `tracking202/ajax/click_history_row*`, `click_not_fraud*`; v3 `requirePersonalSettingsOf` |
| 17 | `access_to_vip_perks` | `202-account/vip-perks.php`; menu item VIP Perks Profile |
| 18 | `access_to_clickservers` | `202-account/clickservers.php`; `clickserver_api_management.php` |
| 19 | `access_to_api_integrations` | `202-account/api-integrations.php`; `tracking202/ajax/ltv_ui*`; menu item 3rd Party API Integrations |
| 20 | `access_to_settings` | `202-account/administration.php`, `auto-upgrade.php`, `auto-upgrade-premium.php`, `ui-kit.php`; `functions-traffic-source-owners-ui.php`; menu item Settings; v3 `/system/*` group (`$settingsPage`) |
| 21 | `remove_tracker` | `tracking202/setup/get_trackers.php`; `tracking202/ajax/delete_tracker*` |
| 22 | `view_attribution_reports` | `202-account/attribution.php`; top nav Attribution; `tracking202/_config/sub-menu.php`; `tracking202/analyze/MobileAppsReportController` |
| 23 | `manage_attribution_models` | `202-account/attribution.php`; `tracking202/setup/MobileAppsController` |

`api/v3/index.php` makes about 42 `requirePermission()` calls over these same strings. The string `manage_things` appears only in `tests/Api/V3/PreviewAuthParityTest.php` (a test fixture), not in any served file.

### My Account menu (`202-config/template.php`, `p202_chrome_header()`)

Personal Settings (always); VIP Perks Profile (`access_to_vip_perks`); 3rd Party API Integrations (`access_to_api_integrations`); User Management (`add_users`); Settings (`access_to_settings`); Help; theme buttons Auto/Light/Dark; Sign Out. The premium upgrade page is reached from the update banner's "1-Click Upgrade" button (`functions-update-banner.php`, drawn by `202-js/p202-chrome.js`, which polls `202-account/ajax/check-for-update.php`, `update-needed.php` and snoozes with `delay-alert.php`). No link to `auto-upgrade.php` was found in the code (grep found only comments).

---

### Settings: `/202-account/administration.php`

- **Where / who**: My Account › Settings (gear). `AUTH::require_user()`; without `access_to_settings` the page redirects to `/202-account/`. Every POST checks the session token first (`AUTH::check_csrf_token()`); a refusal flashes "This form expired or did not come from this page, so nothing was saved. Reload the page and try again." and redirects. Heading "Settings", title "Administration". All POSTs answer with post-redirect-get (303) and a flash (`ok`/`bad`/`warn`/`info`); flashes are moved by `p202-ui.js` into the panel the URL fragment names.
- **Purpose**: install-wide administration: system facts, click-data retention and one-off deletion, AutoCron, ISP/carrier lookup, the outbound (egress) proxy, the GeoIP City database refresh, repair of traffic sources moved between accounts, and the last 50 sign-in attempts.
- **Data shown**:
  - Notices at the top: field-error flash ("That was not saved. The field below says why."); traffic source owners notice (`#traffic-source-owners-notice`, counts of repairable / ambiguous / auto-split sources, Review link) or its read error; postback queue backlog notice (`p202_postback_queue_notice`, `OutboxBacklog` for this user, links to `#autocron`); restore notice (`p202_restore_notice`, with Dismiss); re-click backfill notice (`p202_reclick_backfill_notice`); unsaved click redirects warning; unlisted proxy warning (`UnlistedProxySighting`).
  - Tiles: Clicks recorded (`AUTO_INCREMENT − 1` of `202_clicks_counter` from information_schema, cached); Database size (`SHOW TABLE STATUS` summed, MB/GB); Cron job last ran (`202_cronjob_logs` id 1, "never" shown as bad, else "N units ago"); DataEngine conversion (`202_dataengine_job` done/total, %, "done of total tasks · Xd Yh Zm left").
  - Panel System (`#system`): Prosper202 version, PHP, MySQL, PHP safe mode, Memcache installed, Memcache running, Clicks redirected unsaved (`UnsavedClickRedirects::read()`: none / unreadable / unrecordable / count), Trusted proxies (`TrustedProxies::summary()`), BlazerCache, Keyword preference (with Change link to `account.php#profile`).
  - Panel PHP limits (`#php-limits`): post_max_size, upload_max_filesize, max_input_time, max_execution_time.
  - Panel Click data (`#database`): automatic deletion days (user 1's `user_auto_database_optimization_days`) and the scheduled one-off deletion (user 1's `user_delete_data_before`, or the legacy click-id marker).
  - Panel AutoCron (`#autocron`): the session user's `auto_cron`.
  - Panel ISP and carrier lookup (`#maxmind`): the session user's `maxmind_isp`, a MaxMind link and the geo directory (`P202_GEO_DIR` or `202-config/geo`).
  - Panel Egress proxy (`#egress-proxy`): pill Not sending / On / Off, "In use" text, an override note when the `P202_EGRESS_PROXY` constant is defined, stored URL.
  - Panel GeoIP database (`#geoip`): pill Current / Stale / Missing / Unreadable (`CityDatabase::status()` of `202-config/geo/GeoLite2-City.mmdb`); built date, file, refresh on/off, last refresh with pill, next refresh ("due now…" or a date), reason when not current, failed flash "The last refresh failed: <lastError>", MaxMind attribution.
  - Panel Traffic source owners (`#traffic-source-owners`, from `functions-traffic-source-owners-ui.php`; counts only when `?traffic_sources=review`): table `#traffic-source-owners-table` with Traffic source (name, #id, removed pill, "custom variables not recorded" pill), In the account of, Named by (user, accounts, clicks, conversions), Goes to (a user, or "you choose" with reasons) and auto-split note. Empty state "No traffic source is in another user's account". Wrapped in an Advanced disclosure remembered as `settings-traffic-source-owners`.
  - Panel Traffic source splits (`#traffic-source-splits`, table `#traffic-source-splits-table`): Traffic source, Stays with, Given a copy, When (automatic or by user).
  - Panel Last 50 sign-in attempts (`#logins`, table `#sign-in-attempts`): `202_users_log` newest 50; Time ("M d, y at g:ia"), Username, IP address (with ARIN and RIPE whois links), Attempt pill Passed / Failed / Not completed / Unknown. Empty state "No sign-in attempts yet"; read error flash.
- **Inputs**:
  - `auto_database_management`: number, min 0, max 36500, step 1, "Delete click data older than"; help "0 keeps every click. The cron job deletes older clicks each night."
  - `database_management` (Advanced, form `#erase_clicks_form`): date, required, max today, prefilled with the scheduled day; accepts Y-m-d or d-m-Y with a strict round-trip.
  - `autocron`: radios On (`autocron_on`, 1) / Off (`autocron_off`, 0); confirm form `#autocron_no_own_job_form` with `autocron=1`, `autocron_no_own_job=1` when `?autocron=confirm`.
  - `maxmind`: radios true / false.
  - Egress: `egress_proxy_url`; under Advanced `egress_proxy_username`, `egress_proxy_password`, `egress_proxy_clear_credentials=1`; `egress_action` = save | test | forget.
  - GeoIP: `geoip_account_id` (`/^[1-9][0-9]{0,9}$/`), `geoip_license_key` (`/^[A-Za-z0-9_-]{6,128}$/`); `geoip_action` = save | forget | refresh.
  - Traffic sources: repair form fields `traffic_source_repair` (ppc_network_id), `owner_user_id`, `repair_to_user_id` or radio `to_user_id`; split form fields `traffic_source_split`, `owner_user_id`, `copy_to_user_ids[]`.
  - Restore notice: `restore_notice_dismiss[]`.
  - Every form carries `token`; settings forms carry a hidden shown-values version (`p202_account_shown_field`) for the optimistic check.
- **Actions (including AJAX)** (no AJAX endpoint of its own; all are form POSTs, handled in this order):
  1. Repair a traffic source ("Move back…" modal `ts-repair-{id}`, buttons "Keep it where it is" / "Move it back" or "Move it"), offered only where `mayMove` allows.
  2. Split a traffic source ("Split…" modal `ts-split-{id}`, buttons "Keep it as it is" / "Split it"), offered only where `maySplit` allows.
  3. Dismiss restore notices.
  4. Save AutoCron.
  5. Save ISP lookup.
  6. GeoIP save / forget ("Remove the account", confirm) / refresh now.
  7. Egress save / Test connection / forget ("Remove the proxy", confirm).
  8. Schedule a one-off deletion ("Delete data…", `data-p202-confirm`: "Are you sure you want to delete all your click data from before this date? Setup data (campaigns, trackers, landing pages) is kept. This cannot be undone.").
  9. Save automatic deletion days.
- **Writes and side effects**:
  - Traffic source repair/split: `p202_ts_owners_controller()` builds the v3 `TrafficSourceOwnersController` through `ApiAuth::forSignedInUser`, `requireAdmin` (role name) and `requirePermission('access_to_settings')`, then calls `repair($payload)` / `split($payload)`. Flash on success: 'Traffic source "%s" (%d) moved from user %d's account to user %d's…'; refusals show the validation field messages, or the AuthException message plus " Nothing was moved." Redirect to `administration.php?traffic_sources=review#traffic-source-owners`.
  - Restore dismiss: `ServerStateStore::forInstall()->dismissRestoreNotices(names)`.
  - AutoCron: shown-values check on `auto_cron`; turning on reads `202_cronjob_logs` id 1 and `auto_cron` and, if the cron job ran in the last 300 s and AutoCron is off, flashes an info and redirects to `?autocron=confirm#autocron` unless `autocron_no_own_job=1`. Then a curl GET to `https://my.tracking202.com/api/v2/autocron/{register|deregister}/{base64(p202TrackingBaseUrl())}` (5 s connect, 10 s total). On success `UPDATE 202_users_pref SET auto_cron` for the session user.
  - ISP lookup: `UPDATE 202_users_pref SET maxmind_isp` ('1'/'0') for the session user; Slack event `maxmind_isp_changed` {user, type Activated|Deactivated}.
  - GeoIP: `RefreshStore` (table `202_geoip_refresh`, one row): `saveCredentials` / `forgetCredentials` (sets both to ''); refresh runs `GeoIpRefresh->run()` with `set_time_limit(420)` and `ignore_user_abort`, downloading `https://download.maxmind.com/geoip/databases/GeoLite2-City/download?suffix=tar.gz` and replacing `202-config/geo/GeoLite2-City.mmdb`.
  - Egress: `EgressProxyStore` (table `202_egress_proxy`, row 1) save / forget; stored credentials are dropped when host or port changes and none are typed. Test runs `EgressProxyCheck->run()` against `https://my.tracking202.com/api/v2/premium-p202/version` and saves nothing; result shown in `#egress-proxy-test`. The proxy then carries webhooks, postbacks, notices, syncs and calls to Tracking202.
  - One-off deletion: `ClickRetention->anyOlderThan(cutoff)` (cutoff = midnight in the account's zone); if clicks exist, `UPDATE 202_users_pref SET user_delete_data_before = cutoff, user_delete_data_clickid = NULL WHERE user_id = '1'`; Slack `click_data_deleted` {user, date}. The cron job deletes in batches, for **every account's** clicks.
  - Automatic deletion: `UPDATE 202_users_pref SET user_auto_database_optimization_days WHERE user_id = '1'` (install-wide).
- **Edge cases and states**:
  - Optimistic save: if the shown values changed since the page opened: "These settings were changed in another window or session after this page opened, so nothing was saved. The page now shows what is saved: make your change again."; if the settings lock is busy: "Another save of these settings is still running, so nothing was saved. Try again in a moment." Applies to autocron, maxmind, geoip save, egress save and auto deletion.
  - AutoCron messages: neither value sent ("…the form sent neither, so nothing was changed."); own-cron read failed; install address unreadable; service unreachable ("The AutoCron service could not be reached, so the setting is unchanged…"); HTTP 402 ("…works only for installs at a public web address… Use a cron job on this server instead, one that runs 202-cronjobs/index.php every minute."); other refusal (turned away); saved-but-not-stored ("AutoCron answered, but the setting could not be saved here; try again."); success "AutoCron is on." / "AutoCron is off.".
  - ISP lookup: turning on needs `GeoIP2-ISP.mmdb` or `GeoIPISP.dat` in the geo directory, else "The ISP database file isn't there, so lookup stays off. Upload … to <dir>, then turn it on."; success "ISP and carrier lookup is on. Live traffic picks it up within five minutes." / "…is off."
  - GeoIP save: an empty field keeps the stored value; empty with nothing stored is an error ("Enter your MaxMind account ID: the download needs it." / "…licence key…"); bad format errors; both empty gives info "Nothing was changed…"; success "Saved. The cron job refreshes the GeoIP database within the hour, then weekly; Refresh now does it at once." Refresh outcomes: REFRESHED ok, CURRENT/BUSY info, else bad. Unknown action and any throw have their own messages. Refresh now and Remove the account appear only when credentials are stored; the disclosure reads "Update" or "Set up the refresh".
  - Egress URL rules: required; no `@` ("Put the user name and password in their own boxes under Advanced…"); http or https only, port required 1–65535 without a leading 0, ≤500 characters, IPv6 bracketed. Credentials ≤255 characters, no control characters, no colon in the user name; a password needs a user name. When `P202_EGRESS_PROXY` is defined, saves and removals say the constant still overrides, and Test tests the constant's proxy.
  - Deletion date: empty/unreadable "Pick the date…"; future "Pick today or an earlier day…"; read failure "The clicks could not be read just now…"; no older clicks gives info "There are no clicks from before <day>, so nothing is deleted."; help says a click visited again on or after the day is kept whole.
  - Automatic days: empty means 0; not digits or >36500 gives "Enter a whole number of days, or 0 to keep all click data."
  - Not on this page (despite older descriptions): the MySQL errors list, the privacy setting and click filters. Click filters are on Personal Settings.
- **v3 coverage**:
  - System facts and tiles: `GET /system/info` (AdministrationController: version, schema_version, database_upgrade_needed, php, mysql, php_safe_mode, memcache, php_limits, clicks_recorded, database_size_bytes, cron_last_ran_at, dataengine, keyword_preference, geoip_database, geoip_refresh flags, unsaved_click_redirects) — **PARTIAL**: no trusted proxies summary, unlisted proxy warning, egress proxy state, BlazerCache, restore notices or postback queue notice. Related: `GET /system/version`, `GET /system/db-stats`, `GET /system/cron`, `GET /system/dataengine` (includes re-click backfill state), `GET /system/metrics`.
  - Automatic deletion: `GET /system/retention`, `PUT /system/retention` {auto_delete_days 0–36500} — **PARTIAL**: no shown-values version check.
  - One-off deletion: `POST /system/retention/delete-before` {before, cutoff_time} with `?dry_run=1` first then the confirmed `cutoff_time` (409 on mismatch) — **PARTIAL**: two-step, no Slack event.
  - ISP lookup: `GET /system/isp-lookup`, `PUT /system/isp-lookup` {enabled} — **PARTIAL**: no shown-values check, no Slack event.
  - Sign-in log: `GET /system/login-log` (limit 1–500, default 50, status filter) — covered; the ARIN/RIPE links are page-side only.
  - Traffic source owners: `GET /system/traffic-source-owners` (calls `report()`, whose `$counts` defaults to true, so counts are returned), `POST /system/traffic-source-owners/repair`, `POST /system/traffic-source-owners/split` — covered; the page calls the same controller (gate: `requireAdmin` + `access_to_settings`).
  - AutoCron — **MISSING** (the AdministrationController docblock says it is left out because it calls a remote service).
  - GeoIP credentials save / forget / refresh now — **MISSING** (only the read-only `geoip_refresh` flags in `/system/info`).
  - Egress proxy save / test / forget and its state — **MISSING**.
  - Restore notice read and dismiss — **MISSING**.
  - All `/system/*` routes are gated by `requireAdmin` (role name) plus `access_to_settings`; the page gates on `access_to_settings` alone, so a user granted the permission through a role not named admin can use the page but not the API.

### Users (User Management): `/202-account/user-management.php`

- **Where / who**: My Account › User Management. Signed in; without `add_users` the page redirects to `/202-account/`. `$canManageAdmins` = `add_edit_delete_admin`. Heading "Users", title "User Management". Token checked on every POST.
- **Purpose**: list, add, edit, switch off and remove the install's other users, and give each one role.
- **Data shown**:
  - List: every user except user 1 and deleted users, joined to roles, ordered by `user_fname, user_name`. Each row: name (first + last, or user name), role pill (accent for Admin, "no role" if none), "inactive" pill, Edit link (hidden for Admin rows unless the viewer can manage admins), Remove form (only with `add_edit_delete_admin`), meta line "user_name · email". Count pill. Empty state "No other users yet". Read error "The user list could not be read just now. Reload the page to see it."
  - Roles offered (with a description each): 2 Admin (hidden unless the viewer can manage admins or the user already holds it), 3 Campaign manager, 4 Campaign optimizer, 5 Campaign viewer (default), 6 Publisher (only when `random_bytes` exists). Super user (1) is never offered.
  - No API keys section (keys are on Personal Settings).
- **Inputs**: `user_fname`, `user_lname`, `user_email`, `user_name`, `user_password`, `user_password2`, `user_role`, `user_active` (switch, on by default when adding; help "Off keeps the account, signs it out at once, bars sign-in."), `user_id` when editing (with `?edit_user_id=`), hidden shown-values version. When editing, the password fields sit in an Advanced disclosure remembered as `account-user-password`. Remove: `delete_user_id`.
- **Actions (including AJAX)**: Add user; Save changes; Cancel; Remove (`data-p202-confirm`: "Remove X? They can no longer sign in, and their attribution models and reports are deleted. Campaigns and click data are kept."). No AJAX. The old `GET ?delete_user_id=` is retired and flashes "Removing a user now asks first. Use remove beside the user in the list."
- **Writes and side effects**:
  - Add/edit through `p202_account_save_user()` in one transaction: `202_users` first/last name, email, user name, and `user_time_register` and `user_timezone` copied from user 1's row (on add and on edit); `user_pass` (`hash_user_pass`) when a password is typed; `user_active`. On add also `user_dash_email` = email, `install_hash` from user 1, `user_hash` '', `modal_status` and `vip_perks_status` copied from user 1, `user_public_publisher_id` = `createId(5)`, and a new `202_users_pref` row. Role: the row is locked `FOR UPDATE`, all `202_user_role` rows deleted and the one chosen role inserted; a newly given role runs `PublisherCredit::grantWithRole`. Switching a user off or changing the password runs `RememberMeKeys::revokeAll` and `SessionGeneration::bump` (signs them out everywhere). On add: `DefaultModel::ensureFor` (attribution model), analytics event `account_created` (MessagingService, essential); Slack `user_management_user` Created / Updated.
  - Remove: `UserDataPurge->deleteUser`; Slack `user_management_user` Removed.
- **Edge cases and states**:
  - Validation messages: "Enter a first name." / "Enter a last name." / "Enter an email address." / "Enter a valid email address." / "The email you entered already exists." (own row excluded) / "The email could not be checked just now; try again."; "Enter a username." / "The username you entered already exists." / "The username could not be checked…"; on add "Enter a password."; 8–72 characters ("The password must be between 8 and 72 characters long."); "Retype the password."; "Make sure the passwords you entered match."; "Please select user role"; "Invalid user role.".
  - Without `add_edit_delete_admin`: assigning Admin gives "You are not authorized to assign the Admin role."; editing user 1 or a Super user/Admin gives "You are not authorized to modify this account."; opening such an edit redirects to the list; removing gives "You are not allowed to remove users.".
  - User 1 can never be edited or removed here; nor can you remove yourself ("That user cannot be removed here.").
  - Form opened for another user: "This form was opened for a different user. Open the user again from the list."; edited elsewhere: the shown-values message.
  - Remove: read error, "That user was not found, or was already removed.", purge failure "The user could not be removed, and nothing was changed; they can still sign in. The error log has the reason.", success "<name> is removed and can no longer sign in."
  - Save results: "<name> is saved as <Role>." / "<name> is added as <Role>. They sign in with the username and password you set." / "That user is not there any more…" / "The changes could not be saved, and nothing about the user changed. Try again." / "The user could not be created, and nothing was added. Try again." A refused form keeps typed values but never the password.
- **v3 coverage**:
  - Roles list: `GET /users/roles` — covered (role_id, role_name; does not hide Super user or the Publisher condition).
  - List: `GET /users` (`requireAdmin`) — **PARTIAL**: returns no roles, includes user 1, different sort; `GET /users/{id}` does include roles.
  - Add: `POST /users` (`requireAdmin`; user_name, user_email, user_pass, fname, lname, timezone default UTC, user_active) — **PARTIAL**: takes no role (a second call `POST /users/{id}/roles` is needed, not in one transaction), first/last name not required, timezone and register time not copied from user 1 (time_register = now), no Slack or analytics event.
  - Edit: `PUT /users/{id}` (`requireSelfOrMayManageUser`; user_name/user_active need `requireMayManageUser`; timezone needs `requirePersonalSettingsOf`) — **PARTIAL**: revokes keys and bumps the session generation as the page does, but has no shown-values check and does not change the role.
  - Role change: `POST /users/{id}/roles` {role_id} (`requireMayChangeRoles`) adds with `INSERT IGNORE`, `DELETE /users/{id}/roles/{roleId}` removes — **PARTIAL**: the page replaces the one role atomically; the API needs two calls and allows several roles.
  - Remove: `DELETE /users/{id}` (`requireMayDeleteUser`: admin role + `add_edit_delete_admin`, not user 1, not yourself; `dry_run` preview) — covered, no Slack event.
  - Gate difference: the page needs `add_users`; v3 list/create need an admin role name.

### 1-click upgrade: `/202-account/auto-upgrade.php`

- **Where / who**: no link found in the code. Signed in + `access_to_settings`; `die_if_auto_upgrade_disabled()` stops it with "1-Click upgrade is disabled on this deployment" when `P202_DISABLE_AUTO_UPGRADE` or a `COOLIFY_*` variable is set. Sets `memory_limit -1`. Heading "1-click upgrade", title "1-Click Upgrade".
- **Purpose**: download the latest free release, check its signature, unpack it over the install and run the database upgrade.
- **Data shown**: feed `https://my.tracking202.com/clickserver/currentversion/paid/` (RSS, 10 s): item 0's title is the version (`\d+.\d+.\d+(.\d+)?`), link and signature. States: "Already upgraded"; blockers list (`OneClickReadiness::blockers`: no sodium, no release signing key, no zip, no curl, install dir / `202-config` / `version.php` not writable, each with a fix) with manual steps (`p202_one_click_blocked`); otherwise panel "Prosper202 X is available / you have Y", signature flash, "What happened" log textarea `#upgrade-log`, manual download link `https://my.tracking202.com/clickserver/download/latest/paid`, backup warning, and a "What changed" panel of `changelog()` entries at or above the new version (as details).
- **Inputs**: `start_upgrade=1`, `token`; `date_from` (date, Y-m-d or d-m-Y) only when the stored version is below 1.9.3; `lp_ssl` radio (1 default / 0) when the stored version is below the code version.
- **Actions (including AJAX)**: button `#upgrade-submit` "Upgrade Prosper202". No AJAX.
- **Writes and side effects**: `ReleaseSignature::allowedUrl` (https on the release host only), `notReady`, download, signature check; writes `202-config/temp/prosper202_<ver>.zip`; extracts every entry through `resolve_update_target_path` / `resolve_update_write_path`, logging each directory/file created, updated or skipped; `clear_php_caches`; `UPGRADE::upgrade_databases($time_from)`. On success unsets `$_SESSION['user_id']`, flashes "Prosper202 has been upgraded. Sign in again…" and shows a sign-out form.
- **Edge cases and states**: bad token logs "You must use our forms to submit data."; blockers log "Nothing was changed."; missing date "Select date for Data Engine!"; bad URL "Invalid upgrade package URL. Operation aborted."; signature/version problems go to `OneClickReadiness::afterProblem`; upgrade busy message; `p202_upgrade_failure_log`; a failed database step goes to `p202_one_click_after_failed_database_step` (standalone page when the database is lost). `lp_ssl` is read into `$ssl_upgrade`, which is never used afterwards.
- **v3 coverage**: **MISSING** (no route checks for or runs an upgrade; `GET /system/version` and `/system/info`'s `database_upgrade_needed` only report).

### Premium 1-click upgrade: `/202-account/auto-upgrade-premium.php`

- **Where / who**: the update banner's "1-Click Upgrade" button (label from the feed's order-button-text and price). Same gates as the free page.
- **Purpose**: download and install the premium build tied to the account's customer API key.
- **Data shown**: version JSON from `https://my.tracking202.com/api/v2/premium-p202/version` (version `\d+.\d+.\d+(-x)?`, signature); header text from `$_SESSION['premium_p202_details']` (headline, body, release-date, register-link, register-button-text, order-button-text) as plain text; changelog from `changelogPremium()`. States: missing key ("Your Prosper202 customer API key is missing", optional Sign up link, "Save the key" link to `account.php#customer-key`); failure ("Prosper202 was unable to upgrade…", Get help link `https://support.tracking202.com/`, "Continue with the old version" sign-out form); success as on the free page.
- **Inputs**: `start_upgrade`, `token`; reads the session user's `install_hash` and `p202_customer_api_key`.
- **Actions (including AJAX)**: Upgrade button; sign-out forms. No AJAX.
- **Writes and side effects**: download from `https://my.tracking202.com/api/v2/premium-p202/download/{install_hash}/{p202_customer_api_key}` (a JSON answer is a refusal, logged with its msg); extraction as on the free page but with `realpath` on the base and directories logged without being created; database upgrade; sign-out.
- **Edge cases and states**: bad token "This form expired or did not come from this page, so nothing was upgraded. Reload the page and try again."; missing key; blockers; signature problems as on the free page.
- **v3 coverage**: **MISSING**.

## Missing v3 endpoints

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Settings | Turns AutoCron on/off (registers with my.tracking202.com, saves `auto_cron`) | none | no read or write of `auto_cron`, no register/deregister |
| Settings | Saves, forgets MaxMind GeoIP credentials and runs a refresh now | `GET /system/info` (`geoip_database`, `geoip_refresh` read-only) | no write or refresh route |
| Settings | Saves, tests and removes the egress proxy | none | no read or write of `202_egress_proxy`, no test |
| Settings | Shows and dismisses restore notices | none | no route |
| Settings | Shows trusted proxies summary and unlisted proxy warning | `GET /system/info` | fields absent |
| 1-click upgrade | Checks the feed and installs the free release | `GET /system/version` (report only) | no check or run route |
| Premium 1-click upgrade | Checks and installs the premium release | none | no check or run route |

## Partial v3 coverage

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Settings | System facts and tiles | `GET /system/info` | no BlazerCache, egress, trusted proxies, notices |
| Settings | Postback queue backlog notice (per user) | `GET /system/cron` | install-wide backlog, not this user's |
| Settings | Automatic deletion days | `GET`/`PUT /system/retention` | no shown-values version check |
| Settings | Schedule a one-off deletion | `POST /system/retention/delete-before` | dry run then confirmed cutoff (two steps); no Slack event |
| Settings | ISP and carrier lookup | `GET`/`PUT /system/isp-lookup` | no shown-values check; no Slack event |
| Settings | Gate | `/system/*` | API needs an admin role name as well as `access_to_settings` |
| Users | List users with roles | `GET /users` | no roles in the list; includes user 1; different order |
| Users | Add a user with a role | `POST /users` + `POST /users/{id}/roles` | no role on create, not atomic; names optional; timezone/register time not copied from user 1; no Slack/analytics |
| Users | Edit a user and change the role | `PUT /users/{id}`, `POST`/`DELETE /users/{id}/roles` | role is added not replaced; no shown-values check |
| Users | Gate | `/users` | API list/create need an admin role name; page needs `add_users` |
| Users | Remove a user | `DELETE /users/{id}` | no Slack event |

## Not determined from code

- Whether the 1-click upgrade's `lp_ssl` choice has any effect: the page computes `$ssl_upgrade` from it and no later line reads it (checked `auto-upgrade.php` and grep for `ssl_upgrade`).
- How a user reaches `/202-account/auto-upgrade.php`: grep of the tree found no link to it, only comments.
