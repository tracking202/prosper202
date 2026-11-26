# Attribution

This area is the multi-touch attribution dashboard at `202-account/attribution.php` and what feeds it: the five views of that one page (Report, Journeys, one conversion's Journey, Models, Exports), the old Setup URL `tracking202/setup/attribution_models.php` (now a redirect), the campaign form's "Attribution model" override field (`tracking202/setup/_includes/attribution_model_field.php`), the page helpers in `202-config/functions-attribution-ui.php`, and the engine classes under `202-config/Attribution/` as far as they decide what the page shows or writes (model types and parameters, credit maths, reports, the worker queue, export jobs, the export runner and the webhook sender). Every read and write on `attribution.php` goes through `Api\V3\Controllers\AttributionController`, the same object the v3 `/attribution/*` routes call, so most of the page's rules are the API's rules; the differences are listed per view and in the gap tables at the end.

| Page | Path | Permission |
|---|---|---|
| Attribution › Report | `202-account/attribution.php` (also `?view=report`, the default) | `view_attribution_reports` |
| Attribution › Journeys | `202-account/attribution.php?view=journeys` | `view_attribution_reports` |
| Attribution › one conversion's journey | `202-account/attribution.php?view=journey&conv_id=N` | `view_attribution_reports` |
| Attribution › Models | `202-account/attribution.php?view=models` (edit: `&edit=N`) | `view_attribution_reports` to read; `manage_attribution_models` to change |
| Attribution › Exports | `202-account/attribution.php?view=exports` (download: `&download=N`) | `view_attribution_reports` (read, create, retry, delete, download) |
| Old Setup › Attribution Models (redirect only) | `tracking202/setup/attribution_models.php` | signed in; no permission check of its own |

Include (not a page): the campaign form's attribution model field, `tracking202/setup/_includes/attribution_model_field.php`, described in its own section below.

---

## Shared by every view of `202-account/attribution.php`

These apply to all five views; each view section refers back here.

### Access
- `AUTH::require_user()` (202-config/functions-auth.php): a signed-out browser gets `202-access-denied.php` (a script request gets 401 text "You are signed out. Sign in again, then reload the page."). It also sets the session time zone (`AUTH::set_timezone($_SESSION['user_timezone'])`) and runs `AUTH::require_valid_api_key()` (the install licence check).
- Then `$userObj->hasPermission('view_attribution_reports')`; without it the browser is redirected (302, `header('location: …')`) to `202-account/` with no message.
- `$canManage = $userObj->hasPermission('manage_attribution_models')` decides whether the Models view shows action buttons and the add/edit form, and whether model POST actions run.
- Default role grants (202-config/Database/DataSeeder.php and the 1.9.56 upgrade rung in functions-upgrade.php): Super user (role 1) and Admin (role 2) have both permissions; Campaign manager (role 3) has `view_attribution_reports` only; no other role has either by default.
- Menu: top navigation link "Attribution" (id `AttributionPage`, icon graph), shown only when the user has `view_attribution_reports` (`p202_chrome_header()` in 202-config/template.php). Page title `template_top('Attribution')`; header icon `bi-diagram-3`, heading "Attribution", description "How much credit each click in a visitor's journey gets for the conversion at its end, under each of your models."
- Tabs (`nav.p202-tabs`): Report, Journeys, Models, Exports, each linking `?view=<key>`. The one-conversion journey view highlights the Journeys tab. Any unknown `view` value silently becomes `report`.

### Reads made on every view
- `listModels([])` → all of the account's models (`ModelRepository::rows()`: `202_attribution_models WHERE user_id = ? ORDER BY model_id`), each presented as `model_id, model_name, model_slug, model_type, weighting_config (object), lookback_days, status (active|inactive|invalid), status_reason, is_default, recompute_pending (recompute_requested_at IS NOT NULL), created_at, updated_at`.
- `queue(['limit' => '20'])` → `AttributionReports::queue()` with limit 20 (see the queue panels below).
- If either read throws, the page shows only the tabs and the red flash "The attribution tables could not be read. Check that the upgrade finished, then reload." (logged as `attribution page: …`), and no view content.
- Derived: active models (status `active`), the default model (`is_default`), a model label `"<name> · <type label>"` (just the name when the name equals the type label, case-insensitive), and an id→name map.

### Queue and worker panels (top of every view, when they apply)
All from `GET /attribution/queue`'s data (`AttributionReports::queue()`): it reads `202_attribution_pending` joined to `202_conversion_logs` (pending count, oldest, failing = attempts > 0, and up to `limit` rows ordered by attempts desc), `202_identity_merges` where `requeued_at IS NULL` (awaiting, failing = requeue_failures > 0, retired = requeue_retired_at set, and rows), `202_attribution_models` where `recompute_requested_at IS NOT NULL` (count, failing = recompute_failures > 0, and rows), and `ConversionBackfill::progress()`.
- **Catching up** strip (`#attribution-backfill`), when `backfill` is not null: warn pill "Catching up", label "Before the upgrade", value = the backfill note ("Conversions recorded before the upgrade are still being brought into attribution; reports over dates before the upgrade are incomplete until this finishes.") + " N% of the clicks read so far; it moves every minute."
- **Attribution worker** strip (`#attribution-worker`), when pending > 0 or failing > 0: pill "Failing" (bad) or "Waiting" (warn); value "N conversion(s) waiting" + either ", F failing; the reasons are below" or "; the figures below fill in when the worker runs, every minute".
- **Visitors merged into one person** strip (`#attribution-merges`), when merges_failing > 0: "N merge(s) failed to credit its/their conversions again; the reasons are below."
- **Model changes being applied** strip (`#attribution-model-recomputes`), when models_failing > 0: "N model(s) failed to queue its/their conversions for a recompute, while the rest of attribution carries on; the reasons are below."
- **Failures panel** (`p202_attr_failures()`, `#attribution-failures`), rendered when anything is failing (count = max(rows listed, failing + max(merges_failing, merges_retired) + models_failing)):
  - A POST form to `?view=<current view>` (journey view posts to `report`) with the session token, hidden `action=retry_failed`, button "Try again now" (`#attribution-retry`), and the note "Each of these is tried again by itself, less often the longer it fails. Fix what its reason names, then try them all again here."
  - A disclosure "What failed and why" (`#attribution-failure-reasons`) with hint "N shown" or "N of M shown", holding a table: What | Tries | Why it failed | Next try.
    - Conversion rows (only rows with attempts > 0): "Conversion N" linking to `?view=journey&conv_id=N`; attempts; last_error or "No reason was recorded."; next try "in the next run, within a minute" when retry_at ≤ now, else "in <p202_attr_before(retry_at − now)>".
    - Merge rows: "Visitor merge N of Y-m-d H:i"; failures; reason, or for a retired merge with 0 failures "Set aside by an earlier version: the person has more visitor keys than it could join at once."; next try.
    - Model rows: "Model <name>, its change of Y-m-d H:i"; failures; reason; next try.
    - If more are failing than listed: "At least N more are failing; they are listed here as these clear."

### "Try again now" (`action=retry_failed`)
- Runs before the role check and before the action whitelist, so any user who can open the page can press it (the v3 route has the same gate: `view_attribution_reports`).
- `p202_attr_retry_failed($db, $userId)` → `QueueRetry::tryNow()`: once per 30 s per account (`MysqlAlertStore::claimTurn($userId, 'attribution_retry', 30, now)`); then `makeDue()` sets `202_attribution_pending.retry_at = 0` (the account's rows with retry_at > 0), `202_identity_merges.requeue_retry_at = 0, requeue_retired_at = NULL` (awaiting rows that were waiting or retired), `202_attribution_models.recompute_retry_at = 0` (models awaiting recompute with retry_at > 0); if anything was made due, runs `AttributionWorker::runExclusive($conn, 5)` once. Then re-reads `queue($userId, 1)`.
- Flash, then redirect (303) to the same view (journey → report):
  - waiting (warn): "Nothing was tried again: it was tried less than 30 seconds ago. Try again in N seconds."
  - idle (ok): "Nothing was waiting on a failure to be tried again." or, if something still fails, "…; what is listed as failing is already due, and the worker's next run, within a minute, takes it."
  - busy (ok): "<X conversions, Y visitor merges and Z model changes> are due again. The attribution worker is running right now and takes them next; reload in a minute."
  - failed (warn): "<…> are due again, but the run made for them here failed; the server error log says why. The cron job's next run, within a minute, takes them."
  - ran, still failing (warn): "<…> were tried again, and N still fail(s). The reasons are under \"What failed and why\"."
  - ran, pending left (ok): "<…> were tried again and nothing is failing now. N conversion(s) is/are still waiting for the worker, which runs every minute."
  - ran, clean (ok): "<…> were tried again, and nothing is failing or waiting now."
  - any exception (bad): "Trying again failed: the attribution tables could not be read or written. The server error log says why; the worker keeps trying by itself."
- v3: `POST /attribution/queue/retry` (`retryQueue()`) returns `made_due`, `worker`, `retry_after_seconds` and `queue` (limit 50), enough to build every sentence above.

### POST handling common to all writes
- CSRF: `AUTH::check_csrf_token()` runs first, before any field is read. Refused → flash bad `P202_ACCOUNT_TOKEN_REFUSED` ("This form expired or did not come from this page, so nothing was saved. Reload the page and try again.") and redirect to the current view (journey → report). Every form on the page carries `p202_account_token_field()`.
- `id` is read as `^[1-9][0-9]{0,18}$`, else 0.
- Model actions (`create_model, update_model, set_default, set_status, delete_model`) without `manage_attribution_models`: flash bad "Your role cannot change attribution models, so nothing was saved. An admin can grant \"manage attribution models\"." → `?view=models`.
- Any other action name: flash bad "That is not an action this page takes, so nothing was saved." → current view.
- An action other than the two creates with id 0: flash bad "That request named no model or export, so nothing was saved. Reload the page and try again." → models or exports.
- Exceptions (after the switch):
  - `ValidationException` on create/update model or create export: field errors mapped to the form's fields (a `weighting_config.<key>` field maps to `<key>`), each through `p202_attr_sentence()` (capitalised unless the first word contains `_`, full stop added); errors for fields the form lacks are joined into a red alert above the form; if none mapped, the alert is the exception message + ".". The form re-renders with the posted values (no redirect). On other actions: flash bad with the message + all field sentences → models/exports.
  - `ConflictException`: on create/update model, a message containing "already exists" goes under `model_name`; otherwise on the three form actions it is the alert above the form; else flash bad → models/exports.
  - `NotFoundException`: flash warn "That model was not found; it may have been deleted already." / "That export was not found; it may have been deleted already." → models/exports.
  - `WriteCommittedException` (row written, read-back failed): logged; flash warn "It was saved, but the page could not read it back. Reload to see it before saving again." → models/exports.
  - Any other `Throwable`: logged; flash bad "The change could not be saved: the attribution tables could not be written. Try again; if it keeps failing, the server error log says why." → models/exports.
- Every successful write is post-redirect-get: 303 to `202-account/attribution.php?view=<models|exports>`; flashes are stored in `$_SESSION['p202_account_flash']` and rendered on the next page in a `[data-p202-flashes]` block.

### Page JavaScript
The page loads `202-js/p202-setup.js?v=2` (deferred) on top of the shell's `202-js/p202-ui.js`. No AJAX call is made by this page. Behaviours used:
- `form[data-p202-confirm]` (p202-ui.js): `window.confirm()` before submitting the model Delete and export Delete forms.
- `details[data-p202-remember]` (p202-ui.js): open/closed state saved in `localStorage` under `p202-disclosure:<key>` (keys `attribution-model-advanced`, `attribution-export-advanced`, and the filter bars' `attribution-filters` / `journey-filters` when no advanced filter is set).
- `[data-p202-show-when="name=a,b"]` with `data-p202-disable-hidden` (p202-setup.js): shows the weighting fields for the chosen model type and the "Run at" field when "Later" is chosen; hidden controls are disabled so they do not post.
- `select[data-p202-range]` / `[data-p202-range-field]` (p202-ui.js): the date range picker enables the From/To date inputs only while "Custom Date" is selected.
- `table[data-p202-sort]` (p202-ui.js + tablesort.js): client-side sorting of tables rendered with `sortable`.
- `[data-p202-copy]` (p202-ui.js): the webhook secret's Copy button.

### Date windows (`p202_attr_window()`)
Presets (`p202_attr_ranges()`): `today` Today, `yesterday` Yesterday, `last7` Last 7 Days, `last14` Last 14 Days, `last30` Last 30 Days (default, `P202_ATTR_DEFAULT_RANGE`), `last90` Last 90 Days, `thismonth` This Month, `lastmonth` Last Month, plus `custom` ("Custom Date"). Whole days in the PHP default time zone (the account's, set at sign-in): lastN = N days before today's midnight through today 23:59:59; month presets are calendar months. Range empty with from/to given means custom. Errors (window then falls back to last30): unknown range "'<x>' is not a range this report offers, so it shows Last 30 Days."; custom with a missing/invalid day "A custom range needs a start and an end date, each YYYY-MM-DD and a real day."; start after end "The start date is after the end date." The resolved from/to dates are always shown in the date inputs. The window is sent to the API as `time_from`/`time_to` unix seconds.

---

### Attribution › Report: `202-account/attribution.php?view=report`
- **Where / who**: default view of the Attribution page (top nav "Attribution", tab "Report"). Login + `view_attribution_reports` (see Shared). No difference by role inside the view.
- **Purpose**: attributed conversions, revenue, cost, ROI and assists by one click dimension, under the effective model or a chosen active model, optionally side by side with a second model; downloadable as CSV.
- **Data shown**:
  - Filter bar (`p202_report_filter_bar`, GET form `#attribution-filters`, hidden `view=report`) with the note "Conversions in the range, credited to the clicks in their journeys; clicks and cost are each group's own clicks in the range." and, when the report has rows, an aside button "Download CSV" (`#attribution-csv`).
  - Effective-model notice (`#attribution-decided`), when no model is chosen: "Each campaign's model, else your default: <default name>" with a "change" link to `?view=models`.
  - Tiles (`#attribution-tiles`): Conversions (`totals.conversions`, "with credits in range"); Attributed revenue (`totals.attributed_revenue` as money; sub = chosen model name or "effective model"); Compared revenue (`totals.compare_attributed_revenue`, sub = compare model name) when comparing; One-touch journeys (`journeyMetrics.one_touch_share` × 100, one decimal, %; muted when ≥ 50% and conversions > 0; sub link "by browser" to `?view=journeys` with the same range).
  - Table `#attribution-breakdown`, caption "Attributed conversions and revenue by <dimension label>": columns <dimension label> (row name; if empty, "Not set" for key 0 else "#<key>"), Clicks, Cost (money), Attributed conv. (credit sum, 2 places), Attributed revenue (money), ROI (2 places + %, or "—" when null), Assisted; with a comparison also "<compare> conv.", "<compare> revenue", "<compare> ROI". Totals row "Totals for report": attributed conversions and revenue from the report totals (all groups); Clicks and Cost summed on the page only when every group is on the page (exact decimal sum at 5 places, `p202_attr_sum`), otherwise blank; ROI and Assisted blank.
  - Sorting: client-side sortable only when every group is shown; otherwise the Attributed revenue header carries `aria-sort="descending"` (`p202_data_table` option `sorted`; server order: attributed revenue desc, then key).
  - Truncation note (`#attribution-truncated`) when `meta.groups` > rows shown: "The N groups with the most attributed revenue, of M. The Exports tab writes all of them."
  - Money formatting `p202_attr_money` ($1,234.50, half-up from the exact decimal), credit `p202_attr_credit`.
  - Source: `AttributionController::breakdown()` → `AttributionReports::breakdown()` / `breakdownAll()`, conversion cohort, account time zone for `day`. Three aggregates per row: credits/revenue (`202_attribution_credits` joined to `202_clicks` at the click's latest visit, for conversions whose `conv_time` is in range; with a chosen model `cr.model_id = that model` checked against the account; effective mode = each conversion's campaign override from `202_aff_campaigns.attribution_model_id` when that model is active, else the default), clicks/cost (`202_clicks` in range, `click_bot = 0`, latest visit, `SUM(click_cpc)`), assists (`202_attribution_journey_meta` + `202_attribution_journeys` touches that are not the converting click, `COUNT(DISTINCT conv_id)`). Names joined from the click's own account only. Served partly from `202_attribution_rollup` when clean (same answer). Plus `journeyMetrics()` for the one-touch tile.
  - Dimensions (`AttributionReports::dimensions()`, labels from `p202_attr_dimension_label`): `campaign` Campaign (`202_aff_campaigns.aff_campaign_name`), `traffic_source` Traffic source account (`202_ppc_accounts.ppc_account_name`), `landing_page` Landing page (`202_landing_pages.landing_page_nickname`), `keyword` Keyword (`202_clicks_advance` → `202_keywords`), `c1`–`c4` (`202_clicks_tracking` → `202_tracking_c1..c4`), `country` Country (`202_locations_country`), `device` Device type (`202_device_models.device_type` → `202_device_types.type_name`), `day` Day (account calendar date of the conversion for credits/assists, of the click for clicks/cost).
- **Inputs** (all GET):
  - Range picker `range` (+ `from`, `to` date inputs, YYYY-MM-DD) as in Shared › Date windows.
  - `group_by` "Group by", select of the 11 dimensions, default `campaign`. Unknown value: warn flash "'<x>' is not a dimension this report has, so it is grouped by campaign."
  - `model_id` "Model", select: "Effective (each campaign's model)" (empty) plus every active model, labelled `<model label>` + " (default)" for the default. A value that is not an active model id: warn "The report cannot read that model: it is not one of your active models, so the effective model is shown."
  - `compare_model_id` "Compare with" (under Advanced; hint "A second model side by side, over the same conversions and clicks."): "No comparison" plus active models. Not an active model id: warn "…The report cannot compare with that model: it is not one of your active models, so the comparison is left out." Equal to `model_id`: silently dropped.
  - `limit` "Rows" (Advanced; hint "The groups with the most attributed revenue."): 25, 50, 100 (default), 250, 1000; any other value silently becomes 100.
  - The Advanced disclosure's hint is "comparison, rows", or "N set" (and it opens) when an advanced filter differs from its default.
  - Buttons: Apply (submit), Reset (link `?view=report`).
  - No offset/pagination, no cohort choice, no key filter on the page.
- **Actions**:
  - Download CSV: `GET ?view=report&<the current query>&format=csv`. Built with `ExportCsv::build($report['data'], $compare)`, i.e. exactly the rows on screen (at most `limit`). Columns: `key, name, clicks, cost, attributed_conversions, attributed_revenue, roi, assisted_conversions` (+ `compare_attributed_conversions, compare_attributed_revenue, compare_roi`). Money/credit written as the exact DECIMAL strings; ROI as PHP float string; a text cell starting with `= + - @ TAB CR` (and not numeric) gets a leading apostrophe; RFC 4180 quoting with escape off. Filename `attribution-<group_by>-<from Y-m-d>-to-<to Y-m-d>.csv` (characters outside `A-Za-z0-9._-` become `-`). Headers: `Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment`, `Content-Length`, `Cache-Control: no-store`; output buffers are cleared first. Only sent when the report read succeeded.
  - Empty-state button "Show the last 90 days" (`?view=report&range=last90` keeping group_by and model_id), hidden when the range is already last90.
- **Writes and side effects**: none (read-only view).
- **Edge cases and states**:
  - Empty table: icon `bi-diagram-3`, "No attributed conversions in this range", "Credits appear a minute after a conversion is recorded, once the attribution worker has run. Widen the range, or check the worker above."
  - `ConflictException` or `ValidationException` from the API (e.g. "This account has no default attribution model; create one with is_default: true.", or a chosen model that went inactive): warn flash with the message + "."; no tiles or table.
  - Other errors: warn "The report could not be read just now. Reload to try again." (logged `attribution report: …`).
  - Range error sentences appear under the range picker.
  - A click naming another account's campaign/source/landing page is counted under its id with no name ("#<id>").
- **v3 coverage**:
  - Breakdown: `GET /attribution/reports/breakdown` (params `group_by, model_id, compare_model_id, time_from, time_to, period, limit, offset, cohort, keys`; returns `data` rows with exactly the page's fields, `truncated`, `totals`, `meta.groups`, `meta.model` (effective description and `default_model_id`), `meta.compare_model`, `meta.backfill`). Covered; the API refuses (422/409) where the page falls back with a sentence (unknown group_by, inactive or unknown model, compare = model, limit outside the page's list is accepted up to 1000).
  - One-touch tile: `GET /attribution/reports/journeys` (`one_touch_share`, `conversions`).
  - Model menus and default name: `GET /attribution/models`.
  - Queue panels and Try again now: `GET /attribution/queue`, `POST /attribution/queue/retry`.
  - Download CSV: **PARTIAL** — the rows are in `GET /attribution/reports/breakdown` JSON, but no v3 route returns the on-screen breakdown as CSV (the handler's `rejectUnknown` refuses a `format` parameter); a client must build the CSV itself with ExportCsv's columns and formula-escaping, or create an export job (`POST /attribution/exports`), which writes every group rather than the shown ones.

### Attribution › Journeys: `202-account/attribution.php?view=journeys`
- **Where / who**: tab "Journeys"; also reached from the Report's "by browser" link. Login + `view_attribution_reports`.
- **Purpose**: journey length, time to convert, and the one-touch share by browser for conversions in a range, with the newest attributed conversions as entry points to their journeys.
- **Data shown** (all from `AttributionController::journeyMetrics()` → `AttributionReports::journeyMetrics()`; counts from `202_attribution_journey_meta` / `202_attribution_journeys`, via the rollup's journey part when it can serve):
  - Filter bar `#journey-filters` (range only, hidden `view=journeys`), note "Every conversion attributed in the range, whatever the model: a journey is the same under all of them."; Apply, Reset (`?view=journeys`).
  - Tiles `#journey-tiles`: Conversions (`conversions`, "with a journey"); Average touches (`average_touches`, 2 places); One-touch journeys (`one_touch / conversions` as %, 1 place, "—" when 0 conversions; sub "N conversions"; muted when share ≥ 0.5); No visitor id (`unidentified`, "one touch by design"); Cut at 25 touches (`truncated`).
  - Panel "One-touch share by browser" (`#one-touch-by-browser`, sub "the converting click's browser"), with explanatory text about Safari/Chrome cookie clearing and signed customer ids; sortable table `#browser-table`: Browser (`202_browsers.browser_name` of the converting click via `202_clicks_advance`, "Unknown" when none) | Conversions | One touch | Share (`one_touch_share` × 100, 1 place). Ordered by conversions desc, browser.
  - Panel "Journey length" (`#journey-lengths`): Journey ("N touch(es)") | Conversions | Share (of all conversions). One row per distinct `touches` value, ascending.
  - Panel "Time to convert" (`#time-to-convert`, sub "from the first touch"): fixed rows Under an hour (`under_1h`), 1 hour to 1 day, 1 to 7 days, 7 to 30 days, Over 30 days (`over_30d`) | Conversions | Share. Time is conv_time minus the first touch's click_time, signed (a conversion dated before its click counts as under an hour).
  - Panel "Recent conversions" (`#recent-journeys`, sub "open one to see its journey and every model's credit"): table `#recent-table` of the newest 25 (`RECENT_JOURNEYS`) conversions in range: Conversion ("Conversion N" link to `?view=journey&conv_id=N`) | When (Y-m-d H:i) | Campaign (`202_aff_campaigns.aff_campaign_name` of the conversion's campaign, same account, or "—") | Amount (counted amount, `CountedAmount`: net of reversals) | Touches | Linked by (pill "visitor id" or "no visitor id"). Marked sorted by When descending.
- **Inputs**: range picker (`range`, `from`, `to`) only.
- **Actions**: Apply/Reset; the conversion links; empty-state button "Show the last 90 days" (`?view=journeys&range=last90`); Try again now (Shared).
- **Writes and side effects**: none.
- **Edge cases and states**:
  - 0 conversions: tiles show, then empty state "No journeys in this range" / "A journey is built for each conversion a minute after it is recorded, from the clicks the same visitor made before it."
  - Read failure: warn flash "The journey metrics could not be read just now. Reload to try again." (logged).
- **v3 coverage**: `GET /attribution/reports/journeys` (`time_from`, `time_to` or `period`) returns every field above (`conversions, one_touch, one_touch_share, truncated, unidentified, average_touches, length_distribution, time_to_convert, one_touch_by_browser, recent_conversions` with `conv_id, conv_time, touches, identified, truncated, amount, recorded_amount, counted, campaign_name`) plus `meta.backfill`. Covered.

### Attribution › one conversion's journey: `202-account/attribution.php?view=journey&conv_id=N`
- **Where / who**: reached from Journeys › Recent conversions and from the failures table's "Conversion N" links; tab "Journeys" is highlighted. Login + `view_attribution_reports`. The conversion must belong to the signed-in account (`202_conversion_logs WHERE conv_id = ? AND user_id = ?`).
- **Purpose**: one conversion's touches, the identity signals that linked them, and every model's credit and revenue per touch with exact column sums.
- **Data shown** (`AttributionController::journey()` → `AttributionReports::journey()`):
  - Back link "Journeys" (`?view=journeys`).
  - Summary strip `#journey-summary`: "Conversion N" · Amount (counted amount); when the conversion does not count or its recorded amount differs, a row "Reversed in part" (recorded amount + "; reversals leave the amount above, which the credits split") or "Does not count" (recorded amount + "; not payable, replaced, deleted, a reversal itself or reversed in full, so no model credits it"); When · Converted (Y-m-d H:i:s); Journey row when built: pill "Linked" or "No visitor id", "N touch(es), built over D days" + ", cut at 25 touches" when truncated (from `202_attribution_journey_meta`: touches, built_lookback_days, built_at, truncated, identified); Worker row when queued: pill "Queued", "Waiting to be recomputed (<reason>)" + "; last error: …" (from `202_attribution_pending`).
  - Panel "Touches" (`#journey-touches`, sub "oldest first; the last is the converting click"), table `#touches-table`: # (position + 1) | Click ("Click <click_id>") | Clicked (Y-m-d H:i) | Before conversion (`p202_attr_before`: "under a minute", "N min", "N h M min", "N d H h") | Campaign (name or "—") | Traffic source account (`202_ppc_accounts.ppc_account_name` or "—") | Linked by (pills from `202_identity_observations.signal_type` of the click: `vid` tracking cookie, `lpid` landing-page id, `cust` signed customer id, else the type with `_` as spaces; "none" when empty). Each touch is read at its click's latest visit.
  - Panel "Credit by model" (`#journey-credits`, sub "each model's share of the conversion per touch; each column sums to the whole conversion"), table `#credits-table`: Touch ("#N · <campaign name or 'click <id>'>") then one column per model that has credits (`202_attribution_credits` joined to the account's `202_attribution_models`), each cell the credit as a percentage (`p202_attr_share`, 2 places) over the revenue (money), "—" when the model gives the touch nothing; totals row "Total" with the exact sum of credits (8 places) as % and of revenue (5 places) as money, tagged `data-p202-credit-sum` / `data-p202-revenue-sum`.
- **Inputs**: `conv_id` (GET), read as `^[1-9][0-9]{0,18}$`.
- **Actions**: back link; Try again now (Shared).
- **Writes and side effects**: none.
- **Edge cases and states**:
  - Missing/invalid `conv_id`: flash warn "Choose a conversion from the list to see its journey." → `?view=journeys`.
  - Not found in the account: flash warn "Conversion N was not found in this account." → `?view=journeys`.
  - Other read error: warn flash "The journey could not be read just now. Reload to try again."
  - No journey built yet: empty state "No journey yet" / "This conversion's journey is built by the next attribution worker run, within a minute of it being recorded."
  - No credits: empty state "No credits" / "The conversion no longer counts (reversed, replaced or deleted), or no model is active. Credits are only kept for conversions that count."
- **v3 coverage**: `GET /attribution/conversions/{id}/journey` returns `conv_id, click_id, amount, recorded_amount, counted, conv_time, journey{touches, built_lookback_days, built_at, truncated, identified}, touches[]{position, click_id, click_time, campaign_id, campaign_name, ppc_account_id, ppc_account_name, signals[]}, credits[]{model_id, model_name, model_type, touches[]{position, click_id, credit, revenue}}, pending{reason, enqueued_at, attempts, last_error, retry_at}`; 404 "Conversion not found" for another account's conversion. Covered.

### Attribution › Models: `202-account/attribution.php?view=models`
- **Where / who**: tab "Models"; also the target of the campaign form's "Manage models" link and of the old `tracking202/setup/attribution_models.php`. Login + `view_attribution_reports` to see the list; the action column and the add/edit form need `manage_attribution_models`. Without it the right column shows the info alert "Your role can read these models but not change them. An admin can grant \"manage attribution models\"." and model POSTs are refused (Shared).
- **Purpose**: list the account's attribution models; add, edit, switch on/off, make default, delete.
- **Data shown**:
  - Panel "Your models" (`#model-list`) with a count pill "N model(s)". Table rows (`data-model-id`): Model (name, pill "default" when default, pill "recomputing" when `recompute_pending`, tooltip "The attribution worker recomputes this model's credits on its next run") | Type (`ModelType::label()`) | Lookback ("N days") | Settings (time_decay "half-life N h" from `half_life_hours`, default 48; position_based "first F · last L", defaults 0.4/0.4; others "—") | Status (pill active (good) / invalid (bad, title = status_reason, reason also printed under it) / inactive) | Actions (manage only).
  - Footnote: "Credits are computed for every active model. The default is used where a report names no model and a campaign has none of its own; it cannot be switched off or deleted, so make another model the default first."
  - Empty state (no models): "No models yet" / "Every account gets a last-touch default the first time the attribution worker runs for it." (In code, `DefaultModel` seeds a `last_touch` model named "Last touch", slug `last-touch`, at install, user creation and the 1.9.56 upgrade.)
  - Source: `listModels()` (Shared).
- **Model types and parameters** (`ModelType`, `ModelConfig`, `CreditCalculator`; this enum is the only list):

  | Value | Label (form hint) | Weighting config | How credit is split |
  |---|---|---|---|
  | `last_touch` | Last touch (the last click gets it all) | `{}`, no keys allowed | 100% to the converting click |
  | `first_touch` | First touch (the first click gets it all) | `{}` | 100% to the first touch in the window |
  | `linear` | Linear (every click the same) | `{}` | equal weights |
  | `time_decay` | Time decay (more to recent clicks) | `half_life_hours`: number, > 0 and ≤ 8760, default 48 | weight 2^(−(age − youngest age)/half-life) |
  | `position_based` | Position based (most to the first and last) | `first_weight`, `last_weight`: numbers in [0, 1], sum ≤ 1 (1e-9 tolerance), defaults 0.4 / 0.4 | first and last get their weights, middle touches share the rest equally; 1 touch gets all; 2 touches split in the ratio first:last; all-zero weights split evenly |

  Every model also has `lookback_days` (column, whole number 1–365, default 30): touches older than conv_time − lookback are left out, except the converting click, which is always in. Journeys are capped at 25 touches (fixed). Credits are integer units of 1e-8 summing to exactly 1 per conversion; revenue is units of 1e-5 summing exactly to the counted amount, remainder to the last credited touch. Unknown config keys and non-number values are refused (`weighting_config.<key>` "unknown key; … takes …" / "must be a number"). A stored definition that fails validation on load marks the model `invalid` with `status_reason` (`ModelRepository::markInvalid`).
- **Inputs** (add/edit form `#model-form`, POST to `?view=models`, shown only with manage permission):
  - Panel title "Add a model" (sub "computed for existing conversions within a minute") or "Edit <name>" (sub "a change recomputes its credits"). Hidden `action` = `create_model` or `update_model`; on edit hidden `id` and the shown-values field `p202_shown[attribution_model]` (version of model_id, name, type, lookback, config, is_default; status is not part of it).
  - `model_name` "Name *": text, `maxlength=255`, `required`, help "Shown in the report's model list." Server: trimmed; empty → "model_name is required" / "A non-empty string"; > 255 characters "At most 255 characters". Uniqueness is by slug (`lowercase, non [a-z0-9] runs → -`, max 191, "model" if empty) within the account: "A model named like \"<name>\" already exists (model N); update it, or choose another name." (create) / "… already exists (model N)." (update), shown under the Name field.
  - `model_type` "How credit is shared": select of the five types above ("<label> — <hint>"), default `last_touch`. Invalid → "Valid: last_touch, first_touch, linear, time_decay, position_based".
  - `lookback_days` "Lookback": number input min 1 max 365 step 1, suffix "days", default 30, help "30 by default: clicks this long before a conversion can earn credit." Page: empty → 30; not 1–4 digits → "A whole number of days from 1 to 365."; API then refuses 0 or > 365 with "Must be a whole number of days from 1 to 365."
  - Advanced disclosure (`data-p202-remember="attribution-model-advanced"`, hint "weights" plus ", default" when adding; opens when any weight is filled or has an error):
    - shown for `time_decay`: `half_life_hours` "Half-life", text (decimal), placeholder 48, suffix "hours", help "48 by default: a click this much older than another gets half its credit."
    - shown for `position_based`: `first_weight` "First click" and `last_weight` "Last click", text (decimal), placeholder 0.4 each; help "0.4 and 0.4 by default: the clicks between share the rest."
    - shown for the other three: "This type has no weights to set."
    - Page number rule for the weights: `^[0-9]{1,6}(\.[0-9]{1,8})?$`, else "A number, such as 48." / "A number, such as 0.4."; empty fields are left out (API defaults apply). Only the fields of the chosen type are put in `weighting_config`. API range sentences: "Must be greater than 0 and at most 8760.", "Must be between 0 and 1.", and the sum rule "first_weight + last_weight must be at most 1" (shown above the form, as it names no single field).
    - `is_default` checkbox "Make this the default model" (value 1), not rendered when editing the default model. Only sent as `true` when ticked.
  - Buttons: "Add model" / "Save model"; on edit a "Cancel" link to `?view=models`.
  - Edit is opened by `?view=models&edit=N` (`^[1-9][0-9]{0,18}$`), prefilled from the listed model; a missing or unknown id → flash warn "That model was not found; it may have been deleted." → `?view=models`.
- **Actions** (per row, manage permission only):
  - Edit (link `?view=models&edit=N`).
  - Make default (POST `action=set_default`, `id`): shown when the model is not default and is active. → `updateModel(id, {is_default: true})`. Flash ok "<name> is now the default: reports without a chosen model, and campaigns without their own, use it."
  - Switch off / Switch on (POST `action=set_status`, `id`, `status` = inactive or active): shown when not default; "Switch on" for inactive and invalid models. `status` must be active/inactive, else flash bad "A model is switched on or off; nothing else was asked for, so nothing was saved." Flash ok on: "<name> is on. The next attribution worker run computes its credits."; off: "<name> is off: its credits are removed and it is no longer computed. Switch it on to compute them again."
  - Delete… (POST `action=delete_model`, `id`), shown when not default, with confirm "Delete <name>? Its credits and exports are deleted; campaigns that use it go back to the default model. Conversions and clicks are kept." The page reads the model's name first (`getModel`), then `deleteModel`. Flash ok "<name> is deleted, with its credits and exports. Campaigns that used it use the default now."
  - Create: flash ok "<name> is added. The next attribution worker run (within a minute) computes its credits for your existing conversions."
  - Update: before saving, `p202_account_require_shown()` takes the account settings lock (`ShownSettings::hold`), re-reads the model and compares it with `p202_shown[attribution_model]`; a model deleted since reads as changed. Stale → flash warn "These settings were changed in another window or session after this page opened, so nothing was saved. The page now shows what is saved: make your change again." → `?view=models&edit=N`; lock busy → bad "Another save of these settings is still running, so nothing was saved. Try again in a moment."; lock/read failure → bad "Your settings could not be saved just now; nothing changed. Try again." Success: "<name> is saved." + " Its credits are recomputed by the next attribution worker run." when `recompute_pending`.
- **Writes and side effects** (all in one transaction per call, `Connection::transaction`):
  - Create (`ModelRepository::insert`): `202_attribution_models` row (`user_id, model_name, model_slug, model_type, weighting_config` JSON with defaults filled, `lookback_days, status` active, `is_default` 1 or NULL, `recompute_requested_at` = now when active, `recompute_cursor` 0, `created_at, updated_at`); when default, first `UPDATE … SET is_default = NULL` on the account's other default. Audit row in `202_attribution_audit` (`model_created`, metadata `{model_type, is_default}`). The worker then computes credits for existing conversions.
  - Update (`ModelRepository::update`): name, slug, type, config (a type change without new config resets to the new type's defaults; unchanged type keeps the stored config), lookback, status (an `invalid` model becomes `active` after a validated update), `status_reason = NULL`, is_default; when the definition changed or the model is switched on, `recompute_requested_at = now` and the recompute cursor/failures/retry/error reset; when the status is not active, the model's `202_attribution_credits` and its `202_attribution_rollup` credits/totals parts are deleted. Audit `model_updated` (`{fields, recompute}`). Refusals: unsetting the default ("Every account has one default model" / "Make another model the default (is_default: true on it) instead of unsetting this one"), default not active ("The default model must be active" / "Make another model the default before deactivating this one"), empty payload, unknown keys, a stored invalid config with no new config ("The stored weighting_config is invalid; send a new weighting_config with this update").
  - Delete (`deleteModel`): refused for the default ("Model N is the default model and cannot be deleted; make another model the default first.") and while one of its exports is running ("Model N has an export running (ids) and cannot be deleted until it has finished; try again in a minute."; export rows locked `FOR UPDATE`). Deletes the model's credits and rollup parts, every `202_attribution_exports` row naming it as model or comparison, the model row, and sets `202_aff_campaigns.attribution_model_id = NULL` where it pointed at it; audit `model_deleted`; after commit removes the deleted exports' CSV files from the export directory (failures logged).
- **Edge cases and states**: the default model has no Switch off/Delete buttons and no "Make this the default" checkbox on edit; an `invalid` model shows its reason; `recomputing` pill while the worker has not finished; model actions refused for roles without manage permission; CSRF refusal as in Shared.
- **v3 coverage**:
  - List: `GET /attribution/models` (optional `type` filter). Covered. Single model for edit: `GET /attribution/models/{id}`.
  - Add: `POST /attribution/models` (needs `manage_attribution_models`; Idempotency-Key supported). Covered (the API takes `status` too, and JSON numbers only).
  - Edit, Make default, Switch on/off: `PUT /attribution/models/{id}` with `model_name, model_type, weighting_config, lookback_days, status, is_default`. **PARTIAL** for Edit: the route has no counterpart to the page's "shown values" check (no version field or If-Match on this route), so a save over a model changed in another session is not refused. Make default and Switch on/off covered.
  - Delete: `DELETE /attribution/models/{id}` (manage permission checked in the handler); `?dry_run=1` preview with the counts of credits, exports and campaign overrides it would clear (the page shows no counts). Covered.

### Attribution › Exports: `202-account/attribution.php?view=exports`
- **Where / who**: tab "Exports"; the Report's truncation note points here. Login + `view_attribution_reports` for everything (create, retry, delete, download); no `manage_attribution_models` needed.
- **Purpose**: export jobs that write every group of a breakdown as CSV, now or at one later time, downloadable, optionally POSTed to a signed webhook.
- **Data shown**:
  - One-time secret panel (`#export-secret`), right after creating an export that has a webhook: "Webhook secret for export N" / "shown this once", text "Each delivery carries `X-P202-Signature: sha256=…`, the HMAC-SHA256 of the `X-P202-Timestamp` header, a dot and the body, under this secret. Keep it with the receiver.", the secret in `#export-secret-value` and a Copy button. Held in `$_SESSION['p202_attribution_new_secret']` (`export_id`, `secret`) by the create and removed on the next Exports render.
  - Panel "Exports" (`#export-list`, count pill, sub "newest first"): the newest 100 (`listExports(['limit' => '100'])` → `202_attribution_exports WHERE user_id = ? ORDER BY export_id DESC`). Table `#export-table`, rows `data-export-id`, `data-export-status`: Export (id; under it `completed_at` or else `run_at`, Y-m-d H:i) | What ("<dimension label> · <model name or 'model N'>" + " vs <compare>"; under it "Y-m-d to Y-m-d" of time_from/time_to, and " · webhook <host>" + " (<webhook_status_code>)" when a webhook is set) | Status (pill: pending → "scheduled" if run_at > now else "queued"; running (accent); completed (good); failed (bad); other values printed as is; `last_error` under it) | Rows (`rows_exported` or "—") | Actions.
  - Empty state: "No exports yet" / "An export writes every group of a breakdown as a CSV you can download, now or on a schedule, and can send it to your server."
- **Inputs** (form `#export-form` "New export", sub "every group, as CSV", POST to `?view=exports`, hidden `action=create_export`):
  - `group_by` "Group by" (`#export_group_by`): the 11 dimensions, default campaign.
  - `model_id` "Model" (`#export_model_id`): active models only (no blank option), default selected = the default model; help "Your default model unless you choose another." Page: must match `^[1-9][0-9]{0,18}$` else "Choose a model from the list."; API: unknown "No model N in this account; list them with GET /attribution/models", inactive → conflict "Model N is <status> and has no credits…; activate or fix it first." (alert above the form).
  - "Conversions from": the range picker (`range`, `from`, `to`; id `export-range`), default Last 30 Days, window computed as in Shared › Date windows; errors under the picker.
  - "When" radios `when`: `now` "Now" (default) / `later` "Later"; help "Now by default: the next export run writes it, within a minute." With Later, `run_at` "Run at" (`datetime-local`, `YYYY-MM-DDTHH:MM`, read in the account zone with `mktime`). Page errors: unparsable "Choose the day and time to run it."; not in the future "That time has passed. Choose a later one, or run it now." API: more than 366 days ahead "At most a year from now".
  - Advanced disclosure (`data-p202-remember="attribution-export-advanced"`, hint "comparison, webhook"; opens when any of these is filled or in error):
    - `compare_model_id` "Compare with": "No comparison" plus active models; same id rule; equal to model → "Pick a different model to compare against".
    - `webhook_url` "Webhook URL": `type=url`, `maxlength=500`, placeholder `https://`; help "Optional. The file is also sent here as a signed POST: https only, to a public address, no redirects." Checked at save time by `WebhookGuard::check()` (https://host[:port][/path] spelled plainly, no userinfo/fragment/backslash, no numeric-host tricks, no single-label host, every resolved IPv4/IPv6 address public; operator allowlist `P202_WEBHOOK_ALLOW_NETWORKS` can admit private ranges but never link-local/metadata/multicast/reserved); refusal sentences under the field; a malformed allowlist gives "Webhooks are unavailable on this server".
    - `webhook_secret` "Webhook secret": text, monospace, `maxlength=255`, autocomplete off; help "Leave empty and one is made for you, shown once after saving." Page: secret without URL → "A secret signs webhook deliveries; add the webhook URL, or leave the secret empty." API: 16–255 printable ASCII, no spaces; a `whsec_` secret must be `whsec_` + base64; empty → generated (`StandardWebhook::newSecret()`).
  - Submit "Export".
  - There is no recurring schedule: "Later" is one run at one time.
- **Actions**:
  - Create: `createExport({group_by, model_id?, compare_model_id?, time_from, time_to, run_at?, webhook_url?, webhook_secret?})`. Flash ok "Export N is scheduled for Y-m-d H:i." when run_at > now + 60, else "Export N is queued; the next export run (within a minute) writes it." → `?view=exports` (secret panel shown once).
  - Download (link `?view=exports&download=N`, shown when `file_ready`): GET, no token. `downloadExport(N)` reads the file through `ExportFiles` (name must match `u<user>-e<export>-<32 hex>.csv`) and sends it as `text/csv; charset=utf-8`, attachment, filename `attribution-<group_by>-export-<id>.csv`, `Cache-Control: no-store`. Not found / bad id → flash warn "That export was not found; it may have been deleted." Conflict (no file yet "Export N has no file yet (status S); it is written when the export runs.", file gone "The file for export N is no longer on disk; retry the export to write it again.", unreadable/corrupt name) → flash bad with that message. Other errors are not caught by the page.
  - Retry (POST `action=retry_export`, `id`), shown for failed exports: `ExportStore::retry` sets `status = pending, attempts = 0, last_error = NULL, webhook_status_code = NULL, queued_at = now, started_at = NULL, completed_at = NULL` only when failed ("Only a failed export can be retried; export N is S."). Flash ok "Export N is queued again; the next export run (within a minute) tries it."
  - Delete… (POST `action=delete_export`, `id`), shown when not running, confirm "Delete export N and its file? Your models and reports are not affected." Deletes the row when not running ("Export N is running and cannot be deleted until it has finished; try again in a minute."), then removes its file. Flash ok "Export N is deleted, with its file."
- **Writes and side effects**:
  - Create: `202_attribution_exports` row (`user_id, model_id` (default model's id when none given), `compare_model_id, group_by, range_start, range_end, status 'pending', webhook_url, webhook_secret, attempts 0, queued_at = run_at (or now), created_at, updated_at`); audit `export_created` in `202_attribution_audit` (`{export_id, group_by, webhook}`) under the model's id.
  - The export runner (`ExportRunner::run`, every minute from `202-cronjobs/index.php` with a 15 s budget, or `202-cronjobs/attribution-exports.php --budget=N`, default 50 s): reclaims jobs running over 900 s (back to pending, or failed after 3 attempts), claims due pending jobs with a conditional UPDATE (`status = running, started_at, attempts + 1`), builds `breakdownAll()` (every group; conversion cohort; account zone; the job fails with a reason if the model/compare model is gone or not active, the dimension is unknown, the range is inverted, or there are more than 50,000 groups), writes the CSV (same `ExportCsv` columns as the Report's CSV) to `P202_EXPORT_DIR` or `202-config/temp/attribution-exports/` atomically, records `file_path` and `rows_exported`, removes a previous file of a retried job, then completes or delivers.
  - Webhook delivery (`WebhookSender::send`): outbound HTTPS POST of the CSV body (max 5 MB, else failed "…download it instead."), re-checked by `WebhookGuard` at send time, connection pinned to the checked address, no redirects, connect timeout 5 s, total 15 s, response read capped. Headers: `Content-Type: text/csv; charset=utf-8`, `User-Agent: Prosper202-Webhook/1`, `X-P202-Event: attribution.export.completed`, `X-P202-Export-Id`, `X-P202-Delivery-Attempt`, `X-P202-Timestamp`, `X-P202-Signature: sha256=<HMAC-SHA256 of "<timestamp>.<body>">`, plus Standard Webhooks `webhook-id` (`msg_export_<id>_<24 hex>`, stable per job), `webhook-timestamp`, `webhook-signature`. Delivered → `completed` with `webhook_status_code`. Retryable (no connection, timeout, 5xx, 408, 429) and attempts < 3 → back to `pending` with `queued_at = now + 60·2^(attempt−1)` and last_error "<error> The file is ready; delivery is tried again (attempt n of 3)."; otherwise `failed` with "<error> The file is ready to download." The file stays downloadable either way.
  - Delete: row deleted; CSV file removed after.
- **Edge cases and states**: status pills as above; failed jobs show their reason and a Retry button; running jobs have no Delete; a job whose model is deleted is deleted with it (Models › Delete); the exports list read failure gives warn "The exports could not be read just now. Reload to try again."; the secret is never shown again after the first render.
- **v3 coverage**:
  - List: `GET /attribution/exports` (`status`, `limit` ≤ 200; fields `export_id, model_id, compare_model_id, group_by, time_from, time_to, status, rows_exported, file_ready, download_path, webhook_url, webhook_signed, webhook_status_code, attempts, last_error, run_at, started_at, completed_at, created_at`). Covered.
  - Create: `POST /attribution/exports` (returns `webhook_secret` once). Covered; **PARTIAL** on one rule: the page refuses a `run_at` that has passed ("That time has passed…"), the API accepts it and runs the job as soon as possible, so a client that wants the page's behaviour must check it itself.
  - Download: `GET /attribution/exports/{id}/download` (same file, same filename, `text/csv`). Covered.
  - Retry: `POST /attribution/exports/{id}/retry`. Covered.
  - Delete: `DELETE /attribution/exports/{id}` (and `?dry_run=1`). Covered.
  - Single export: `GET /attribution/exports/{id}` (the page does not use it).

---

### Old Setup › Attribution Models (redirect): `tracking202/setup/attribution_models.php`
- **Where / who**: not linked from any menu read here; an old bookmark. `AUTH::require_user()` only (sign-in, licence check); no permission check.
- **Purpose**: the old model editor's URL, kept as a redirect ("models are managed through the v3 API and both CLIs until the attribution pages are rebuilt").
- **Data shown / Inputs / Actions**: none. Sends `Location: <install>/202-account/attribution.php` (302, PHP's default for `header('location: …')`) and exits. The target then applies its own `view_attribution_reports` check (a user without it lands on `202-account/`).
- **Writes and side effects**: none.
- **v3 coverage**: not applicable (no data).

---

## Include: campaign form "Attribution model" field (`tracking202/setup/_includes/attribution_model_field.php`)
- Rendered by `tracking202/setup/_includes/campaign_form/advanced.php` inside Setup › Campaigns (`tracking202/setup/aff_campaigns.php`, documented with the Setup area); the Advanced disclosure there opens when this field has a value or an error.
- Returns nothing when `$db` or `$_SESSION['user_id']` is missing.
- Reads all of the account's models with `ModelRepository::rows($_SESSION['user_id'])` (every status). If that throws: logs "Attribution model field: …" and shows only the text "Attribution models could not be loaded, so this campaign keeps its current model." (no select, so nothing is posted for it).
- Field `attribution_model_id` "Attribution model" (select): first option "Account default" + " (<default model name>)" when there is one (value empty); then one option per model, value = model_id, label "<model_name> — <model_type>" (raw type value, e.g. `time_decay`) + " (<status>)" when the status is not active. The current value is `$campaignForm['values']['attribution_model_id']`. Help text: "Leave on the account default unless this campaign needs its own model: how a conversion's credit is shared across the touchpoints before it." with a "Manage models" link to `202-account/attribution.php`.
- Server rule in aff_campaigns.php: a non-empty value must match `^[1-9][0-9]{0,18}$` and be one of the account's own models (any status), else error "Choose one of your attribution models, or leave it on the account default."; stored in `202_aff_campaigns.attribution_model_id` (NULL for default). Effect: the Report's effective mode credits this campaign's conversions under that model only while the model is active, else the account default.
- v3 coverage: the model list is `GET /attribution/models`; the campaign's value is read and written by `GET /campaigns/{id}`, `POST /campaigns`, `PUT /campaigns/{id}` (`attribution_model_id`: null, 0 or '' = default; otherwise one of the caller's own models, same any-status rule, 422 otherwise). Covered.

---

## Missing v3 endpoints

No action or read in this area lacks a v3 route: every view's reads and writes are made through `AttributionController`, whose methods are all registered under `/attribution/*` in api/v3/index.php and listed in the served route list.

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| (none) | — | — | No missing endpoint found. |

## Partial v3 coverage

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Attribution › Report | "Download CSV" of the breakdown rows on screen (`&format=csv`, ExportCsv columns, formula-escaped cells, filename `attribution-<group_by>-<from>-to-<to>.csv`) | `GET /attribution/reports/breakdown` | Returns JSON only and refuses a `format` parameter; the client must build the CSV (or use an export job, which writes every group, not the shown page). |
| Attribution › Models | Edit refuses a save when the model was changed or deleted in another session since the form was shown (`p202_shown[attribution_model]` compared under the account settings lock) | `PUT /attribution/models/{id}` | No version field or If-Match on this route; a later save overwrites a concurrent change. |
| Attribution › Exports | "Later" refuses a run time that has passed ("That time has passed. Choose a later one, or run it now.") | `POST /attribution/exports` | The API treats a past `run_at` as "now" instead of refusing it; the page's rule must be enforced by the client. |

## Not determined from code

- Whether any menu or page still links to `tracking202/setup/attribution_models.php`: a search of 202-config/template.php, tracking202/_config/ and 202-account/ found no link to it; other directories were not searched exhaustively.
- The exact refusal sentences `WebhookGuard::check()` gives for each kind of bad webhook URL were not enumerated here (the class is 921 lines; its block list was read, its message set was not listed sentence by sentence).
