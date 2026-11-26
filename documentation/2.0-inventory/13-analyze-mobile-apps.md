# Analyze: Mobile Apps report

This area is the single page Analyze › Mobile Apps (`tracking202/analyze/mobile_apps.php`). It reports what registered mobile apps' install signals add up to: Apple's SKAdNetwork / AdAttributionKit postbacks for iOS apps, and SDK-reported installs with their goal outcomes for Android apps. The page has five views, chosen by `?view=` and shown as tabs: Report (grouped totals and a CSV download), Funnel (one Android app's goals in order), iOS postbacks (the stored postback rows), Postbacks sent (the traffic-source notification outbox for app goals), and Verify (a scratch pad that checks a pasted postback's signature and stores nothing). The page is read-only. Every read goes in-process through v3 API controllers (`AppReportController`, `AppPostbacksController`, `AppNotificationsController`, `GoalsController`, `AppRegistrationsController`, `UsersController`), so the numbers and messages match the REST API. Its state lives only in the query string. It reads and writes no stored preference (`202_users_pref`), session value or cookie of its own. Code: `tracking202/analyze/mobile_apps.php` (entry), `tracking202/analyze/MobileAppsReportController.php` (logic), `tracking202/analyze/templates/mobile_apps.php` (shell, tabs, filter form) and the partials `templates/mobile_apps/_report.php`, `_funnel.php`, `_postbacks.php`, `_notifications.php`, `_verify.php`.

| Page | Path | Permission |
|---|---|---|
| Analyze › Mobile Apps (Report tab, the default) | `tracking202/analyze/mobile_apps.php` or `?view=report` | signed in, valid licence, `view_attribution_reports` |
| Funnel tab | `tracking202/analyze/mobile_apps.php?view=funnel` | same |
| iOS postbacks tab | `tracking202/analyze/mobile_apps.php?view=postbacks` | same |
| Postbacks sent tab | `tracking202/analyze/mobile_apps.php?view=notifications` | same |
| Verify tab | `tracking202/analyze/mobile_apps.php?view=verify` (GET form, POST result) | same |
| Report CSV download (not a page; a response of the Report tab) | `tracking202/analyze/mobile_apps.php?view=report&download=csv&…` | same |

All five tabs are one PHP page and one controller. They are documented below as one page section with one sub-section per tab, because the access rules, the filter form and the shared helpers are the same for all of them.

### Analyze › Mobile Apps ("Mobile App Attribution"): `tracking202/analyze/mobile_apps.php`

#### Where / who

- **URL**: `/tracking202/analyze/mobile_apps.php`. The browser `<title>` comes from `template_top('Analyze Mobile Apps')`. The page header reads "Mobile App Attribution" with the `bi-phone` icon. The description reads "What the apps' installs add up to on iOS and Android — and the goals they reached, the funnel, the postbacks behind the totals, and whether each traffic source was told."
- **Menu**: Prosper202 CS › Analyze strip (`tracking202/_config/sub-menu.php`), entry "Mobile Apps". The entry is added only when `$userObj->hasPermission('view_attribution_reports')`, after the Customer LTV and Publishers entries. Setup › Mobile Apps (`tracking202/setup/templates/mobile_apps.php` line 292) links each app row here as `?registration_id=<id>&platform=ios|android`.
- **Login**: login required. `\AUTH::require_user()` runs in the controller's constructor:
  - A signed-out visitor gets `202-access-denied.php`.
  - A signed-out request made by a script (`X-Requested-With: XMLHttpRequest`, or `Sec-Fetch-Dest: empty`) gets a 401 with the plain-text body "You are signed out. Sign in again, then reload the page."
  - `AUTH::require_valid_api_key()` (the licence check) redirects to `api-key-required.php`, or to `api-key-required.php?licence=unconfirmed` when ClickServer gave no answer.
- **Permission**: `view_attribution_reports` (the constant `VIEW_PERMISSION`), checked with `$userObj->hasPermission()`. A refused user is sent with a `location:` redirect to `tracking202/`, with no message. Seeded role grants (`202-config/Database/DataSeeder.php`): Super user (role 1), Admin (2) and Campaign manager (3) hold permission 22 `view_attribution_reports`. Campaign optimizer (4) and Campaign viewer (5) do not. No other role check exists, and no admin-only or Super-user-only branch exists on the page.
- **Account scope**: every read is scoped to `$_SESSION['user_own_id']`, the signed-in user's id, set at sign-in in `functions-auth.php`.
- **Uncaught failure**: `mobile_apps.php` wraps the controller in `try { … } catch (\Exception $e)`. It logs `Mobile Apps Report Error: <message>` with `error_log()` and redirects to `tracking202/analyze/?error=mobile_apps_error`. That index page redirects at once to `tracking202/analyze/keywords.php` and drops the `error` parameter, so the user sees the Keywords report with no message. A PHP `\Error` (for example a `TypeError`) is not caught and is a fatal error. API `HttpException`s (validation and `DatabaseException`) are caught inside each view builder and shown as flashes; they never reach this outer catch.

#### Purpose

Shows the account's mobile-app attribution: installs, goals reached, revenue and trust classes for both platforms. It covers grouped reports, a per-app goal funnel, the raw iOS postbacks, the delivery state of the postbacks sent to traffic sources for app goals, and an offline signature checker for a captured iOS postback.

#### Shared reads (every view)

1. **Registered apps**: `RegisteredApps::read(AppRegistrationsController)` (`tracking202/Apps/RegisteredApps.php`). It calls `AppRegistrationsController::list(['limit' => 500])`, which reads `202_app_registrations`, both platforms, own rows. It then sorts the rows by `app_name` case-insensitively (`strcasecmp`). It reports `truncated` when `pagination.total > count`, or when the total is missing and the count is at least 500. The list feeds the App filter menu, the Funnel's app chooser, the app-name column on the iOS postbacks tab, the app-name fallback on Postbacks sent, the default-platform rule, and whether the Funnel and Postbacks sent tabs are shown. The Verify view reads it too, because the tab set depends on it.
   - A failed read (an `HttpException`) gives the flash (bad) "The list of registered apps could not be read, so the App filter is empty: <message>", and the list is empty.
   - A truncated list gives the flash (warn) "The App filter lists the first N[ of TOTAL] registered apps, by name. To report on one that is not listed, add registration_id=<the app's number on Setup › Mobile Apps> to this page's address." This flash does not count as a dropped filter (see the CSV gate).
2. **Account currency**: `UsersController::accountCurrency($userId)` reads `202_users_pref.user_account_currency` through `preferencesRow()` and normalizes it to `SUPPORTED_CURRENCIES`. A failed read gives `DEFAULT_CURRENCY` without a message. It is read on every view except Verify, which gets the fallback `UsersController::DEFAULT_CURRENCY`. Money is rendered by `dollar_format((float)$v, $currency)`.
3. **Tabs**: Report, Funnel, iOS postbacks, Postbacks sent, Verify. Each tab is a link built by `$link(['view' => <key>, 'page' => null])`, which keeps the other filters. The current tab carries `active` and `aria-current="page"`. If no registered app has `platform` = `android` (case-insensitive), the Funnel and Postbacks sent tabs are hidden, except the one currently being viewed. Their URLs still answer and explain why they are empty.
4. **Flashes**: rendered under the header with `p202_flash($kind, $text)`, where the kind is `bad` or `warn`.

#### Inputs: the filter form (every view except Verify)

The form is `<form class="p202-table-toolbar" method="get" action="<self>">`. Submitting it reloads the page with the filters in the query string. No CSRF token is used (GET, read-only).

| Field (`name`) | Label | Type | Shown on | Choices / default | Validation (server, `readFilters()`) and message when refused |
|---|---|---|---|---|---|
| `view` | none (hidden) | hidden | all | the current view | Unknown value means `report`, silently (not flashed). |
| `group_by` | none (hidden) | hidden | all | the current grouping, carried so that applying a filter on another tab keeps the Report tab's grouping | Must be a key of the platform's grouping list (below). Otherwise it is set to `day`, and when the value was not `day` the warn flash reads "That grouping is not one the <Both/iOS/Android> report offers, so it is grouped by Day." |
| `platform` | Platform | select (Report tab); hidden input elsewhere | Report | `all` = Both, `ios` = iOS, `android` = Android. Default: see the rule below. | Lowercased and trimmed. An unknown value gives the default platform, with the flash "That platform is not one this report offers, so it shows <label>." |
| `range` | Range | select, `data-p202-range="custom"` | all | `today` Today, `yesterday` Yesterday, `last7` Last 7 Days, `last14` Last 14 Days, `last30` Last 30 Days (default), `last90` Last 90 Days, `thismonth` This Month, `lastmonth` Last Month, `custom` Custom Date | An unknown non-empty value gives `last30`, with the flash "That range is not one this report offers, so it shows Last 30 Days." An empty `range=` gives `last30` silently. With no `range` parameter at all, the range is `custom` when `from` or `to` is non-empty, otherwise `last30`. |
| `from` | From | `type="date"`, `data-p202-range-field="from"` | all | the window's start, as a UTC `Y-m-d` | Read only when `range` is `custom` (or absent while dates are given). It must match `^\d{4}-\d{2}-\d{2}$`, or the flash reads "A date was not in YYYY-MM-DD form, so it was ignored." The date must exist (checked with a `gmmktime` round trip), or the flash reads "There is no such date as <date>, so it was ignored." A missing or refused start is the end minus 30 days. |
| `to` | To | `type="date"`, `data-p202-range-field="to"` | all | the window's end, as a UTC `Y-m-d` | Same rules. A missing or refused end is today (UTC). When start > end the two are swapped, with the flash "The dates were the wrong way round, so they were swapped." The window ends at the end-date's 23:59:59 UTC. |
| `registration_id` | App | select | all | "All apps" (empty value), then one option per registered app, labelled `<app_name> (Android\|iOS)`, with value `registration_id`. If the URL names an id that is not in the list, an extra selected option is shown: "registration <id> (not in your list)". | Must round-trip as a positive integer (`'0012'`, `0` and overflowing values are refused). Otherwise the filter is dropped with the flash "The app filter was ignored: an app is chosen by its registration number, a whole number." |
| `signature` | Signature | select | Report when the platform is iOS; iOS postbacks tab always (`signatureApplies()`) | Any (empty), Valid, Invalid, Unverifiable, Development (the values of `SignatureState`, in order: `valid`, `invalid`, `unverifiable`, `development`) | Lowercased and trimmed. Not one of those values: "The signature filter was ignored: it must be one of valid, invalid, unverifiable, development." Given on a view where it does not apply: "The signature filter was ignored: it filters Apple's postbacks, so it applies to the Postbacks tab and the iOS report only." |
| `trusted` | Installs | select | Report when the platform is Android | "Trusted (default)" (empty), "Refuted only" (`refuted`), "Unvouched only" (`unvouched`). The server also accepts `trusted`. | Must be one of `trusted`, `refuted`, `unvouched`, and the platform must be `android`. Refused with "The trust filter was ignored: it filters Android installs, so it applies to the Android report only." or "The trust filter was ignored: it must be one of trusted, refuted, unvouched." |
| `ctit_flag` | Click-to-install | select | Report when the platform is Android | Any (empty), `short` Too short, `ok` Within limits, `long` Too long, `unmeasured` Not measured | Must be in `ClickToInstallTime::filterValues()`, and the platform must be Android. Refused with "The click-to-install filter was ignored: it filters Android installs, so it applies to the Android report only." or "… it must be one of short, ok, long, unmeasured." |
| `fast_goals` | Goals | select | Report when the platform is Android | Any (empty), `1` Reached too fast, `0` None too fast | Must be `0` or `1`, and the platform must be Android. Refused with "The fast-goals filter was ignored: it filters Android installs, so it applies to the Android report only." or "The fast-goals filter was ignored: it must be 1 (installs with a goal reached too fast) or 0 (installs without one)." |
| `status` | Status | select | Postbacks sent tab | Any (empty), Pending, Sent, Failed, Cancelled, Suppressed, Withheld (`AppNotificationsController::STATUSES`) | Lowercased and trimmed. Not in the list: "The status filter was ignored: it must be one of pending, sent, failed, cancelled, suppressed, withheld." The filter is read on any view, but used only on Postbacks sent. |
| `page` | none (pager links only) | query parameter | iOS postbacks, Postbacks sent | 1 | `min(max(1, (int)page), 1000000)`. A page past the last one is clamped to the last page with the flash "There is no page N of these postbacks, so this is the last one." The form never carries `page`, so applying a filter starts at page 1. |
| `download` | none (link only) | query parameter | Report | `csv` | See the CSV action. |

Every refusal above uses `flashFilterDropped()`: a warn flash, plus a mark that the shown report is not the one the URL asked for. The mark blocks the CSV download.

- **Apply** (`button type=submit`, `btn-primary`) submits the form.
- **"UTC days" help**: a focusable `<a href="#" class="p202-help" role="button" data-bs-toggle="tooltip" aria-label="Why these dates are UTC">` with a clock icon. Its tooltip reads "Signals are grouped into whole UTC days, so these dates are UTC — unlike the click reports, which use your account timezone. Android installs count on the day they were received, with every goal they reached."
- **Window resolution** (`resolveWindow()`, static, in UTC): a preset that `ReportWindow::PRESETS` has (`today`, `yesterday`, `last7`, `last14`, `last30`, `thismonth`, `lastmonth`) is `ReportWindow::preset($range, 'UTC', now)`. The `last7`, `last14` and `last30` presets run from midnight N days ago through the end of today, so they span N+1 whole days. `thismonth` is the 1st through the end of today. `lastmonth` is the whole previous month. `last90` is this page's own preset: midnight 90 days ago through the end of today. A custom window is the start-date 00:00 UTC through the end-date 23:59:59 UTC. The inputs show `gmdate('Y-m-d')` of the resolved window.
- **Default platform** (`resolvePlatform()` / `defaultPlatform()`): when the URL gives no `platform`, an app filter means that app's platform. Otherwise, if all the account's registered apps share one platform, that platform is used. Anything else is `all`.
- **Groupings per platform** (pills on the Report tab): see that tab.
- **JavaScript (`202-js/p202-ui.js`, loaded by the shell)**: off a custom range, the server renders the From and To inputs `disabled` and shows the hint "Choose Custom Date to set these." (`data-p202-range-hint`). Once the script runs, it re-enables the inputs, hides the hint, and withholds the inputs from submission by removing their `name` attribute whenever the Range select is not on `custom`. Typing in a date input switches the Range select to `custom` and restores the names. Tooltips are initialised by the same script. The page makes **no AJAX calls**: every read happens server-side during the request.
- **Link builder** (`$link()` in the shell template), used by every in-page link: it keeps the current filters and changes the ones it is given. It carries `from` and `to` only for a custom range. It drops a `group_by` that the new platform does not offer. It carries `signature` only where `signatureApplies(view, platform)`, and `trusted`, `ctit_flag` and `fast_goals` only for the Android platform. It drops empty values.

#### Report tab (`view=report`, the default)

**Reads**

- `AppReportController::report()` is called with `platform`, `time_from`, `time_to`, the non-empty `registration_id`, `signature`, `trusted`, `ctit_flag` and `fast_goals`, plus `group_by` and `limit=200` (`MAX_GROUPS`, per platform; with Both, up to 200 iOS plus 200 Android rows).
  - iOS rows come from `AppPostbacksController::report()` over `202_app_postbacks`, decoded through `202_app_skan_encodings` and the goals they name, via `SkanEncodingTimeline`.
  - Android rows come from `Api\V3\Apps\Android\InstallReport` over `202_app_installs`, `LEFT JOIN 202_clicks` (latest visit, own clicks), `202_goal_outcomes`, `202_conversion_logs` (the counted amount via `CountedAmount`) and `202_goals`.
- The headline figures are always the API's ungrouped `data.totals`, never a sum of the groups.
- On an `HttpException` the message is flashed as bad, and the view shows the empty state "The report could not be read" with "The message above says why. This is not a statement that there are no installs."
- When the answer has no `totals` array, the flash (bad) reads "The report came back without its totals, so the figures above the table are not shown.", the empty-state card "The totals could not be read" is shown, and the table still renders.

**Groupings (pills; the current one has `p202-pill--accent`; each links to `$link(['group_by' => key])`)**

- Both (`all`): Day (`day`), App (`registration`), Platform (`platform`).
- iOS: Day, App (`registration`), Ad network (`ad-network`), Source (`source`), Country (`country`), Protocol (`protocol`), Version (`version`), Type (`conversion-type`).
- Android: Day, App (`registration`), Campaign (`campaign`), Match state (`match-state`), Play Integrity (`integrity-state`), Click-to-install (`ctit-flag`), Goal (`goal`).
- First-column value (`groupLabelParts()`, shared with the CSV):
  - `registration`: the app name followed by the app key (App Store id or package) in muted text. When there is no key, "registration <id>".
  - `source`: `source_identifier` · `campaign_id`, with empty parts left out.
  - `campaign`: the campaign name followed by "campaign <id>" (muted). With no id, "no campaign".
  - `platform`: "iOS" or "Android".
  - `match-state` and `integrity-state`: the value with `_` replaced by a space.
  - `ctit-flag`: `short`, `ok`, `long` or `unmeasured`.
  - `goal`: `goal_name`.
  - `day`: `date` (`Y-m-d`).
  - The other iOS groupings use the field named by `AppPostbacksController::groupKeys()`: `ad_network_id`, `country_code`, `protocol`, `version`, `conversion_type`.
  - An empty value renders as "not given" (muted).
- Order: Day groups keep the newest days and are shown oldest first. Other groupings show the busiest first (iOS by `postbacks DESC`; Android by `received DESC`; Android `goal` by `installs DESC, goal_id`). With Both and Day, rows are merged and sorted by date, iOS before Android within a day. With Both and other groupings, the iOS rows come first, then the Android rows.

**Data shown when platform = Both (`all`)**

- **Tiles**:
  - Installs (`is-good`): `totals.combined.installs`, sub-line "both platforms · iOS <totals.ios.installs> · Android <totals.android.installs>".
  - Goals reached: `combined.goals_reached`, sub-line "both platforms".
  - Revenue: `combined.revenue` in money, sub-line "iOS decoded + Android credited".
  - Refuted (`is-bad`): `combined.refuted_count`, sub-line "checked and found false".
  - `combined` = the iOS plus Android sums of `installs`, `trusted_count`, `refuted_count`, `unvouched_count`, `test_count` and `goals_reached`, and `revenue` rounded to 5 decimals.
- **Info notice**: "iOS figures are Apple's postbacks: they arrive a day or more after the install, only for installs a campaign won, and Apple withholds values below its privacy thresholds. Android figures are every install the SDK reported, as it happened. Choose one platform above for its own dimensions."
- **Table**: <grouping label> | Platform (omitted when grouped by Platform) | Installs | Goals reached | Revenue | Refuted | Unvouched. The totals row is labelled "Both platforms" and shows the `combined` figures (a blank Platform cell where that column is shown).
- No per-goal events panel is shown for Both (`events` is forced to `[]`).

**Data shown when platform = iOS**

- **Tiles**:
  - Postbacks: `totals.postbacks`, sub-line "all signatures".
  - Installs (`is-good`): `totals.installs`, sub-line "verified only", or "as filtered" under a signature filter.
  - Re-downloads: `totals.redownloads`.
  - Re-engagements: `totals.reengagements`.
  - Losses (`is-bad`): `totals.losses`, sub-line "did not win".
  - Decoded revenue: `totals.revenue` (= `decoded_revenue`), with the sub-line "<n> ambiguous, credited to neither meaning" when `ambiguous_encoding` > 0.
- **Notices**:
  - Under a signature filter (`meta.trusted` ≠ `trusted-only`), a warning: "Every figure here counts <signature>-signature postbacks, not signature-verified ones — the signature filter replaces the usual trust gate." with a "Drop the filter" link (`signature` removed).
  - Otherwise, when `postbacks > trusted_count`, an info notice: "Installs, losses and revenue count signature-verified postbacks only. Of N postbacks in this range, A verified, B failed verification and C could not be verified; D of them was/were development-signed, which is a class the others overlap rather than a fourth share of the total. Filter by signature above to count a different class."
- **Table**: <grouping label> | Postbacks | Installs | Re-downloads | Re-engagements | Losses | Revenue | Ambiguous | Verified (`trusted_count`). The totals row is labelled "Totals for report", or "Totals for the range" when the groups were truncated. Below the table: "Ambiguous counts postbacks whose value changed meaning within the 48 days before they arrived, where the meanings disagree: they are credited to neither." (48 = `SkanEncodingTimeline::HORIZON_DAYS` = 35 + 6 + 7.)
- **Metric formulas** (`AppPostbacksController::metricColumns()`):
  - Every count is `COUNT(DISTINCT identity)`, where identity is the length-prefixed binary of (protocol, ad_network_id, transaction_id, COALESCE(postback_sequence_index, −1)).
  - The trust gate is `trusted = 1`, or `1 = 1` under an explicit signature filter.
  - `postbacks` = all unique postbacks.
  - `losses` = gate AND `did_win = 0`.
  - `installs`, `redownloads` and `reengagements` = gate AND (`did_win` IS NULL OR 1) AND first window (`COALESCE(postback_sequence_index, 0) = 0`) AND `conversion_type` = `download`, `redownload` or `re-engagement` respectively.
  - `trusted_count`, `refuted_count` and `unvouched_count` = `trusted` = 1, 0 or NULL.
  - `test_count` = `signature_state = 'development'`.
  - Decode: each unique postback, through its first-received copy (MIN(postback_id)), trusted and won, any window. It counts in `measurable`, `decoded`, `undecoded`, `ambiguous_encoding` or `null_conversion_values`. `decoded_revenue` is the goal rule's revenue (or its revenue override) × count.
  - `goals_reached` for iOS = `decoded`; `revenue` = `decoded_revenue`.
- **Events panel** "Decoded goals": "Conversion values read through the rules on Setup › Mobile Apps, over the whole range." It has a pill "<n> goal(s)" and the table Goal | Postbacks | Revenue, from `totals.events`, sorted by count descending, then name. Footnote: "Conversion values decode across all three windows, so these counts can exceed installs."

**Data shown when platform = Android**

- **Tiles**:
  - Installs (`is-good`): `totals.installs`, sub-line "attributed to a click", or "<trusted> installs, as filtered" under a trust filter.
  - Organic: `totals.organic`, sub-line "no campaign".
  - Goals reached: `totals.goals_reached`, sub-line "by attributed installs" or "by these installs".
  - Revenue: `totals.revenue`, sub-line "credited to campaigns".
  - Refuted (`is-bad`): `totals.refuted_count`, sub-line "forged or implausible".
- **Notices**:
  - Under a trust filter, a warning: "Every figure here counts <trusted> installs and the goals they reached, not attributed ones." with a "Drop the filter" link.
  - When `ctit_flag` or `fast_goals` is set, a warning: "Every figure here is narrowed to installs whose click-to-install time is <flag> [and] with a goal reached too fast / with no goal reached too fast." with a "Drop these filters" link (both removed).
  - When trusted-only and `received > installs`, an info notice: "Installs, goals and revenue count installs attributed to a click. Of R installs the SDK reported, I were attributed, U nobody could vouch for (organic among them), F were refuted and P are waiting for their click or their Play Integrity verdict."
  - When `pending_failing` > 0, a warning: "N of the installs waiting for their click failed to settle on their last try. Each is tried again, less often the longer it fails; one whose stored token or body can no longer be read is settled as a bad token 24 hours after it arrived. The server log says why each failed."
- **Table** (every grouping except Goal): <label> | Received | Installs | Organic | Pending | Refuted | Unvouched | Goals reached | Too fast (`fast_goals`) | Revenue. The totals row is labelled "Totals for report" or "Totals for the range".
- **Table** (Goal grouping): Goal | Installs reaching it | Unvouched | Times reached (`goals_reached`) | Too fast | Revenue. It has no totals row, and its footnote reads "The Funnel tab puts one app's goals in order." with a link to `view=funnel`.
- **Metric formulas** (`InstallReport::METRICS`, over `202_app_installs i`):
  - `received` = COUNT(*).
  - `installs` = SUM(gate), where the gate is `i.trusted = 1`, or the class named by the trust filter.
  - `organic` = `match_state = 'organic'`.
  - `pending` = `match_state IN ('pending_click', 'pending_integrity')`.
  - `pending_failing` = `pending_click` AND `settle_failures > 0`.
  - `trusted_count`, `refuted_count` and `unvouched_count` = `trusted` = 1, 0 or NULL.
  - `test_count` = `is_test = 1`.
  - `ctit_measured` = `ctit_flag IS NOT NULL`; `ctit_short` and `ctit_long` = flag `short` or `long`.
  - `goals_reached` = the live outcomes (`202_goal_outcomes`, `subject_type = 'install'`, `superseded_at IS NULL`) of gated installs.
  - `fast_goals` = those outcomes with `too_fast = 1`.
  - `revenue` = the sum of `CountedAmount` of the outcome's `202_conversion_logs` row for payable outcomes.
  - Goal rows: `installs` = COUNT(DISTINCT gated `subject_id`), with the trust classes per distinct install, `goals_reached` = outcomes, and `revenue` = payable counted amount.
  - Installs fall in the group of their install (a cohort by `received_at`), however late a goal was reached.
- **Panels** (below the table):
  - "How installs were matched": "Every install in the range, by what the intake concluded." Table Match state | Installs, from `totals.match_states`, sorted by count descending. Each state name is a link to `group_by=match-state` (the same link on every row). Empty: "No installs in this range."
  - "Play Integrity verdicts": "Not requested unless an app turned Play Integrity on." Table Verdict | Installs, from `totals.integrity_states`. The rows are not links. Empty: "No installs in this range."
  - "Fraud signals": "Marked, not refused: each app's limits are under Setup › Mobile Apps, Advanced." Table Signal | Count, with these rows:
    - "Installs too soon after the click" (`ctit_short`), linking to `ctit_flag=short`.
    - "Installs too long after the click" (`ctit_long`), linking to `ctit_flag=long`.
    - "Installs with a click-to-install time" (`ctit_measured`), linking to `group_by=ctit-flag`.
    - "Goals reached too soon after the install" (`fast_goals`), linking to `fast_goals=1`.
  - "Goals reached" events panel: "What the installs above went on to do, over the whole range." Table Goal | Times reached | Revenue, from `totals.events`.

**Empty and limit states**

- No groups on iOS: "No postbacks in this range" / "Apple sends a postback a day or more after an install, and only when a campaign wins attribution. Widen the range, or check that the receivers are reachable.", with the button "Check the receivers" linking to `tracking202/setup/mobile_apps.php`.
- No groups on Both or Android: "No installs in this range" / "Widen the range, or check the app's setup: its campaigns' store link and the SDK's app token.", with the button "Open Setup › Mobile Apps".
- When the groups were truncated (`meta.groups_truncated`): "More groups matched than are shown, so the rows are the newest (Day) / busiest (other groupings) ones. The figures above the table and its totals row are the whole range. Narrow the range or filter by app to see the rest."

**Action: Download to CSV**

- The link (`btn-secondary`, spreadsheet icon) is `$link(['view' => 'report', 'download' => 'csv'])`, a GET of the same URL plus `download=csv`.
- The page builds the report first. If the report read failed, no file is sent and the page renders normally.
- If any filter was dropped (`filterDropped`), no file is sent, and the flash (bad) reads "Nothing was downloaded: the report is not the one the link asked for. Check the messages above, then use Download to CSV again." The App-list truncation flash does not block the download.
- **Columns**: the first is the grouping label (for example "Day", "App"), with the value from `groupLabelParts()`. For `registration` and `campaign` with two parts the value is "Name (id)"; otherwise the parts are joined with " / "; an empty value is an empty cell. Then, by platform:
  - iOS: Postbacks, Installs, Re-downloads, Re-engagements, Losses, Revenue, Decoded, Ambiguous encoding, Trusted, Refuted, Unvouched, Development-signed.
  - Android: Received, Installs, Organic, Pending, Refuted, Unvouched, Test, Goals reached, Revenue, Click-to-install measured, Click-to-install short, Click-to-install long, Goals reached too fast. Goal-grouped rows have no `received`, `organic`, `pending` or `ctit_*` fields, so those columns are 0 there.
  - Both: Platform (the raw `ios` or `android`; shown even when grouped by Platform), Installs, Goals reached, Revenue, Trusted, Refuted, Unvouched, Test.
- **Values**: integers are cast; Revenue is the bare number rounded to 5 decimals (not money-formatted). There is no totals row. When the groups were truncated, the file ends with an empty row and the row "More groups matched than are listed here. Narrow the range or filter by app to see the rest."
- **Encoding**: `fputcsv` with `,`, `"` and an escape of `''` (RFC 4180, not PHP's backslash). A string cell starting with `=`, `+`, `-` or `@` is prefixed with `'` to block formula injection. The body is built in memory (`php://temp`). If any write fails, the flash (bad) reads "The CSV could not be built, so nothing was downloaded." and the page renders.
- **Response**: all output buffers are discarded. Headers: `Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment; filename="mobile-apps-<platform>-<group_by>-<from>-to-<to>.csv"` (characters outside `[A-Za-z0-9._-]` replaced by `-`; fallback `mobile-apps.csv`) and `Content-Length`. Then `exit`.

#### Funnel tab (`view=funnel`)

**Choosing the app**: the app named by `registration_id`. With no filter and exactly one Android app, that app. Otherwise none.

- No app chosen and the account has no Android app: the empty state "No Android app to read a funnel from" / "A funnel is the goals an Android app's installs reach, in order. Register an Android app and give it goals on Setup › Mobile Apps.", with the button "Open Setup › Mobile Apps".
- No app chosen and Android apps exist (or the filter names an app that is not in the list): the panel "Choose an app" / "A funnel is one app's goals in order.", listing each Android app as a link to `registration_id=<id>`, with the meta "Android · <app_key>". iOS apps are not offered in the list.

**iOS app chosen**

- The page calls `AppReportController::report(['platform' => 'ios', 'group_by' => 'platform', 'time_from', 'time_to', 'registration_id'])` and lists `totals.events`.
- Info notice: "<app> is an iOS app. Apple's postback carries one conversion value — the step the device reached last — not each step it passed, so there is no funnel to read. These are the goals its postbacks decoded to in the range."
- Table: Goal | Postbacks | Revenue.
- Read failed: the message is flashed, plus "The decoded goals could not be read" / "The message above says why."
- Empty: "No decoded postbacks in this range" / "Widen the range, or check the app's conversion values on Setup › Mobile Apps.", with a button linking to `setup/mobile_apps.php?app=<id>`.

**Android app chosen**

- The page reads `AppReportController::report(['platform' => 'android', 'group_by' => 'goal', 'limit' => 500, window, registration_id])` and `GoalsController::list(['registration_id' => id, 'limit' => '500'])`. The goals list comes from `202_goals` and its versions, with archived goals excluded by default.
- Either read failing is flashed, and the view shows "The funnel could not be read" / "The message above says why. This is not a statement that nothing was reached."
- No current goals: "This app has no goals yet" / "Give it goals — a tutorial, a level, a purchase — and each becomes a step here.", with the button "Add goals" linking to `setup/mobile_apps.php?app=<id>#goals`.
- **Steps** (`funnelSteps()`):
  - Every goal with `archived_at` null is a step, including goals nothing reached (shown with zero counts).
  - Steps are ordered by depth, then goal id. The depth of the built-in `install` goal is 0. Any other goal's depth is 1, or (the largest depth among the goals in `definition.after`) + 1. A cycle counts as depth 0.
  - `installs` = the report goal row's `installs` (trusted, distinct installs). `unvouched` = `unvouched_count`. `revenue` = the row's `revenue`.
  - `of_first` = installs ÷ the first ordered step's installs. It is null (shown "—") when the first step has 0.
  - `of_previous` = installs ÷ min(installs of the goals in `after`). A goal with no `after` uses the install goal. When that denominator is 0 or missing, the value is null ("—").
- **Panel** (id `funnel`), titled with the app name, sub-line "Installs attributed to a click in the range, and the goals they went on to reach, in order."
  - Table: Step ("N. <name>", plus the pill "built in" for a built-in goal, and a progress bar at width round(100 × min(1, of_first))%) | Installs | Of the first | Of the step it follows | Unvouched | Revenue. Percentages are shown to 1 decimal.
  - Each row carries `data-funnel-goal="<goal_id>"`.
  - Footnote: "An install counts at a step once, however often it reached it. Unvouched installs — organic among them — are counted beside, not in, the funnel: anyone holding the app token can report one. Revenue is what the steps paid on their campaigns."

#### iOS postbacks tab (`view=postbacks`)

**Read**: `AppPostbacksController::list()` with `time_from`, `time_to`, `limit=50` (`PER_PAGE`), `offset=(page−1)×50`, and the non-empty `registration_id` and `signature`. It reads `202_app_postbacks` own rows, ordered by `received_at DESC, postback_id DESC`. `attribution_signature` and `raw_payload` are never in list rows, and the untrusted string fields are sanitized. The platform filter is ignored: this tab lists only Apple postbacks.

- **Paging**:
  - total = `pagination.total`, or (page−1)×50 + the rows returned when the total is missing.
  - pages = max(1, ceil(total/50)).
  - A page past the last one is re-read as the last page, with the warn flash.

**Table**: Received | App | Protocol | Ad network | Source | Type | Value | Won | Signature | Transaction.

- **Received**: `gmdate('Y-m-d H:i', received_at)` (UTC).
- **App**: the registered name from the app list, followed by `app_id` (muted). When the name is not known, `app_id` alone.
- **Protocol**: `protocol` + " " + `version`.
- **Ad network**: `ad_network_id`.
- **Source**: `source_identifier` · `campaign_id`. When neither is given, "not given".
- **Type**: `conversion_type`, plus the muted "window N" when `postback_sequence_index` > 0.
- **Value**: `conversion_value`, or else `coarse_conversion_value`.
- **Won**: "yes" or "no" from `did_win`; null shows "not said".
- **Signature**: a pill for `signature_state`: valid is good, invalid is bad, development is warn, anything else is neutral.
- **Transaction**: `transaction_id`, in monospace.

**Footer and states**

- Footer: "N row(s) in this range. A postback replayed with different unsigned values is stored as its own row, so this can exceed the Report tab's postback count."
- Pager (only when pages > 1): ‹ (previous), the page numbers from page−2 to page+2, › (next). The first and last arrows are disabled at the ends (`aria-disabled`).
- Read failed: the message is flashed, and the view shows "The postbacks could not be read" / "The message above says why. This is not a statement that none have arrived."
- Empty: "No postbacks in this range" / "Widen the range, or check that the receivers are reachable.", with the button "Check the receivers" linking to Setup › Mobile Apps.

#### Postbacks sent tab (`view=notifications`)

**Read**: `AppNotificationsController::list()` with `time_from`, `time_to`, `limit=50`, `offset`, and the non-empty `registration_id` and `status`.

- It reads `202_notification_pending n` JOIN (for each conversion, the minimum outcome id among `202_goal_outcomes` rows with `subject_type = 'install'`) JOIN `202_goal_outcomes o` LEFT JOIN `202_goals g` LEFT JOIN `202_app_registrations r` (own rows).
- The window applies to `n.created_at`. `registration_id` applies to `o.app_registration_id`.
- Rows are ordered by `n.notification_id DESC`.
- `meta.summary` counts every status under the same filters except `status`.
- Paging works as on the iOS postbacks tab, except that a missing `pagination.total` is not handled specially (the value is cast to int).

**Tiles**: one per status, in this order:

| Status | Sub-line |
|---|---|
| Pending | "waiting or backing off" |
| Sent (`is-good`) | "the traffic source answered" |
| Failed (`is-bad` when > 0) | "every attempt refused" |
| Cancelled | "replaced before it went out" |
| Suppressed | "no correction URL" |
| Withheld | "the visitor refused consent" |

Each tile carries `data-outbox-status`.

**Table**: Queued | App | Goal | Kind | Status | Attempts | Last error | Destination | URL.

- **Queued**: `gmdate('Y-m-d H:i', created_at)`.
- **App**: `app_name`, or else the name from the app list.
- **Goal**: `goal_name`.
- **Kind**: `kind` (`reached`, `correction` or `retraction`).
- **Status**: a pill (sent is good, failed is bad, pending is warn), plus the `gmdate('H:i', sent_at)` time when sent.
- **Attempts**: `attempts`.
- **Last error**: `last_error`.
- **Destination**: "Pixel <pixel_id>, URL <destination+1>".
- **URL**: `url`, in monospace (sanitized to 4096 characters by the API).
- Each row carries `data-outbox-destination="<pixel_id>:<destination>"`.

**Footer and states**

- Footer: "N postback(s)[ <status>] in this range. One row per URL: a pixel whose code holds several URLs is sent, retried and failed at each on its own, so one endpoint that accepted is never sent the conversion again because another failed. A failed postback is retried with backoff before it is marked failed; the worker in 202-cronjobs sends them."
- Pager: ‹ | "page of pages" | ›.
- Read failed: "The postbacks could not be read" / "The message above says why. This is not a statement that none were sent."
- Empty (the tiles are still shown): "No postbacks queued in this range" / "A traffic source is told when an attributed install reaches a goal its campaign pays for, through a server-to-server pixel on the traffic source's account (Setup › Traffic Sources). Widen the range, or check that the account has one."

#### Verify tab (`view=verify`)

- **No filter form** is shown on this tab. The tab nav is still shown.
- **Panel** "Check a postback's signature" / "Paste the JSON a device sent. Nothing is stored, and nothing here changes your report."
- **Form**: `method="post"`, action = `$link(['view' => 'verify'])`, so the query string keeps the current filters. **No CSRF token**, deliberately: the POST stores nothing.
  - `payload`: a textarea labelled "Postback JSON", 10 rows, monospace, with no maxlength and no `required`. Placeholder `{"version":"4.0","ad-network-id":"example.skadnetwork", ...}`. Help text: "An AdAttributionKit postback is the object containing `jws-string`; a SKAdNetwork one is the object Apple POSTs." The pasted text is echoed back into the textarea.
  - **Check signature** (`btn-primary`, submit).
- **Server**:
  - Nothing is done on a GET, or when `payload` is blank after trimming.
  - `json_decode($payload, true)` that does not give an array produces one of two flashes (bad):
    - Valid JSON that is not an object: "That is valid JSON but not an object; paste the whole postback, braces included."
    - Not JSON: "That is not JSON: <json_last_error_msg()>."
  - Otherwise the page calls `AppPostbacksController::verify($decoded)`:
    - A `ValidationException` is flashed as "<message> <field>: <why> …". Examples: `{}` gives "Provide the postback JSON object as the request body"; a bad `jws-string` gives "Invalid postback jws-string: Must be the compact JWS string, at most N characters" or the decoder's reason.
    - Any other `HttpException` is flashed with its message.
  - Protocol detection: a `jws-string` key means AdAttributionKit (`JwsVerifier`); anything else is verified as SKAdNetwork (`PostbackVerifier`).
- **Result** (shown under the form):
  - A strip with the signature pill and the label "Signature", followed by the protocol. The state is `valid`, `invalid`, `unverifiable` or `development`; the pill tone is as on the postbacks tab.
  - For AdAttributionKit, a "Signing key" row showing `key_id`.
  - For SKAdNetwork:
    - When `signed_message_base64` is not null: the section "Signed message" / "Base64 of the exact bytes Apple signed, for diffing against another implementation when a signature unexpectedly fails." with the value in a code block and a **Copy** button (`data-p202-copy`). The button shows "Copied" for 1.6 s, or "Press Ctrl+C" when copying fails.
    - When it is null: the warning "The bytes Apple signs could not be rebuilt from this postback, so the signature was never really tested." followed by either "It carries no `version`, and the fields that are signed depend on which one it is." or "Either a field version `<v>` requires is missing, or that is not a version this install can check.", then "Checkable versions: <verifiable_versions>."
  - When the signature is `unverifiable` and a `key_id` is present: the warning "`<kid>` is not a signing key this install knows, so the signature could not be judged either way. Production keys here: <known_key_ids>. Development keys: <development_key_ids>."
  - When the answer has a decoded `payload` (AdAttributionKit): "What the postback claims", with the payload as pretty-printed JSON, or "(could not be re-encoded)" when it cannot be encoded.
- **Writes**: none.

#### Writes and side effects (whole page)

- No database writes, no cache writes, no rollup or refresh queue, no cookies, no saved preferences, no e-mail and no outbound HTTP.
- Logging: `error_log()` of an uncaught exception in the entry script. API controllers may log internally; this page adds nothing beyond that.
- The CSV response discards PHP output buffers and exits.

#### Edge cases and states (not covered above)

- **Fold of unusable filters**: every unusable filter is dropped and said in a warn flash rather than sent to the API, where it would be a 422 that replaces the report. Unknown `view` values fall back to `report` silently.
- **An app filter that is not in the menu**: it stays applied, and the menu shows "registration <id> (not in your list)". The id goes to the API as typed, so a foreign or unknown id returns the account-scoped empty result.
- **A Report tab URL with a filter that does not fit the platform**: for example `signature` on Android, or `trusted` on iOS or Both. The filter is dropped with a flash, and the CSV is refused for that URL.
- **API error text in flashes**: the message of an `HttpException`, including a `DatabaseException`'s message (for example "Report query failed"), is shown verbatim in a flash.
- **Empty or partial answers**: a missing `totals` or `pagination.total` is handled as described in each tab (never read as zero).
- **Privacy mode and roles**: no behaviour on this page differs by privacy mode or by role beyond the single permission check. Revenue is not masked for roles without `access_to_campaign_data`.
- **Verify size limit**: the textarea has no size limit; PHP's `post_max_size` applies. POST /apps/verify instead caps the body at 1 MB (413 "Request body too large").

#### v3 coverage

| Page read or action | v3 route | Status |
|---|---|---|
| Permission to see the page and menu entry (`view_attribution_reports`) | `GET /me` (`data.permissions`) | Covered. |
| Registered apps for the App filter, Funnel chooser, name lookups and tab visibility | `GET /apps` (`AppRegistrationsController::list`, limit up to 500, `pagination.total`) | **PARTIAL**. The route asks for `access_to_setup_section`; the page asks only for `view_attribution_reports`. With the seeded roles every holder of `view_attribution_reports` (Super user, Admin, Campaign manager) also holds `access_to_setup_section`, so the difference matters only to a role granted the one without the other. Sorting by name and the truncation notice are done client-side, as the page does. |
| Account currency for money formatting | `GET /me` (`data.account.currency`) | Covered. |
| Report tab, every platform, grouping and filter (`platform`, `group_by`, `time_from`, `time_to`, `registration_id`, `signature`, `trusted`, `ctit_flag`, `fast_goals`, `limit`) | `GET /apps/report` | Covered: the same controller, the same `view_attribution_reports` check, and the same `data.groups`, `data.totals` and `meta.trusted`/`groups_truncated`/`notes`. The API defaults `platform` to `ios` when it is omitted, so the client must apply the page's default-platform rule and send `platform` explicitly. The API answers an unfitting filter with a 422 where the page drops it with a flash; the client reproduces the drop. |
| Report CSV download | none | **MISSING**. No CSV route exists; the file has to be built client-side from `GET /apps/report`, with the same columns, formula-injection guard, truncation line, filename and refusal on dropped filters. |
| Funnel, iOS app: decoded goals | `GET /apps/report?platform=ios&group_by=platform&registration_id=` (`data.totals.events`) | Covered. |
| Funnel, Android app: reach per goal | `GET /apps/report?platform=android&group_by=goal&limit=500&registration_id=` | Covered (goal rows carry `installs`, `unvouched_count`, `revenue`, `after`, `builtin` and `archived`). |
| Funnel, Android app: the full goal list, including goals nothing reached | `GET /goals?registration_id=&limit=500` | **PARTIAL**. The `/goals` group asks for `access_to_setup_section` (the page asks only for `view_attribution_reports`; see the `GET /apps` row about seeded roles). No route returns the ordered funnel with its shares: the ordering and the `of_first`/`of_previous` computation (`funnelSteps()`) must be redone client-side. |
| iOS postbacks list with paging | `GET /apps/postbacks` (`time_from`, `time_to`, `registration_id`, `signature`, `limit`, `offset`; `pagination.total`) | Covered. |
| Postbacks sent list, status summary and paging | `GET /apps/notifications` (`time_from`, `time_to`, `registration_id`, `status`, `limit`, `offset`; `meta.summary`, `pagination.total`) | Covered. |
| Verify a pasted postback | `POST /apps/verify` (JSON body; `view_attribution_reports`; treated as a read scope) | Covered. The 1 MB body cap is noted under edge cases. |

## Missing v3 endpoints

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Analyze › Mobile Apps (Report tab) | Downloads the grouped report as a CSV (`download=csv`): per-platform columns, formula-neutralised cells, a truncation note row, filename `mobile-apps-<platform>-<group_by>-<from>-to-<to>.csv`, refused when a filter was dropped | `GET /apps/report` (JSON only) | No CSV export route. The client must build the file from the JSON answer. |

## Partial v3 coverage

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Analyze › Mobile Apps (all tabs) | Lists the registered apps (App filter, Funnel chooser, app names, tab visibility) for any user with `view_attribution_reports` | `GET /apps` | The route requires `access_to_setup_section`. With the seeded roles this never differs, because every role holding `view_attribution_reports` also holds it. |
| Analyze › Mobile Apps (Funnel tab) | Reads the Android app's current goals, including unreached ones, for any user with `view_attribution_reports` | `GET /goals?registration_id=` | The route requires `access_to_setup_section` (the same seeded-role caveat applies). |
| Analyze › Mobile Apps (Funnel tab) | Orders goals by their `after` depth and computes the share of the first step and of the step each follows | `GET /apps/report?group_by=goal` + `GET /goals` | No route returns funnel steps. The ordering and both share formulas are page logic, to be reimplemented client-side. |
