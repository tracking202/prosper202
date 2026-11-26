# Visitors, Spy, Update and Conversion Logs

This area covers the pages that show clicks one at a time and the tools that change recorded clicks and conversions after the fact. Visitors lists every click in a date window, page by page, and can download it. Spy shows the last 24 hours of clicks and adds new ones at the top every few seconds. Both share one click table, a click-detail modal that explains a click's conversions, and a "Not fraud" button that counts back clicks the fraud score filtered. The Update tools set the cost of past clicks (Update CPC), mark subids as converted, reset a campaign's or category's conversions, delete conversions by subid, and apply a network's revenue report. Conversion Logs lists conversion rows with filters and a CSV download.

| Page | Path | Permission |
|---|---|---|
| Visitor History | `tracking202/visitors/` | Signed in; no role permission |
| Visitors download | `tracking202/visitors/download/` | Signed in; no role permission |
| Spy View | `tracking202/spy/` | Signed in; no role permission |
| Update (entry) | `tracking202/update/` | Signed in (redirects to Update Subids, which checks) |
| Update Subids | `tracking202/update/subids.php` | `access_to_update_section` |
| Update CPC | `tracking202/update/cpc.php` | `access_to_update_section` |
| Reset Campaign Subids | `tracking202/update/clear-subids.php` | `access_to_update_section` |
| Delete Subids | `tracking202/update/delete-subids.php` | `access_to_update_section` and `delete_individual_subids` |
| Upload Revenue Reports | `tracking202/update/upload.php` | `access_to_update_section` |
| Conversion Logs | `tracking202/logs/` | `access_to_setup_section` |

## Shared behaviour

The pages below refer back to these parts.

**Sign-in.** Every page calls `AUTH::require_user()`. A signed-out request made by a script (an `X-Requested-With` header or `Sec-Fetch-Dest: empty`) gets 401 with "You are signed out. Sign in again, then reload the page." Any other signed-out request is shown the access-denied page (`202-access-denied.php`).

**Menu.** In the top bar (`tracking202/_config/top.php`):
- Visitors and Spy are always shown.
- Update is shown only with `access_to_update_section`.
- Conversion Logs is shown only with `access_to_setup_section`.

The Update tab opens a strip labelled "Update tools" (`tracking202/_config/sub-menu.php`) with these entries:
- Update Subids (also highlighted for the bare `update/` path)
- Update CPC
- Reset Campaign Subids
- Delete Subids, shown only with `delete_individual_subids`
- Upload Revenue Reports

**Data scope.** Visitors, Spy, the click history fragment and the click breakdown read the account `DataScope::userId()` names: the signed-in user's own account (`user_own_id`), or every account when `$_SESSION['publisher']` is set and false. A publisher session hides these filters: traffic source, traffic source account, category, campaign, text ad, landing page and method of promotion. If the role lacks `access_to_campaign_data` and the session is not a publisher's, money is masked as `?` (`CampaignDataMask::hidden()`).

**Report filters (Visitors and Spy).** Both pages are built by `p202_overview_run()` (`202-config/functions-ui-overview.php`) with the filter bar from `p202_report_filters()` (`202-config/functions-ui-partials.php`). How the filters are read and kept:
- Filters arrive as GET parameters. They are validated and written to the user's `202_users_pref` row when the page loads, so a GET changes stored preferences with no form token, which is deliberate.
- The stored filters then open by default on the other report pages. The bar's note says so: "The filters you apply open by default here and on the other reports."
- The page installs the filters as a report view. The fragment, the polls and the download carry it as the `view` query parameter. A view that does not parse gets 400 with a flash.

The bar, in order:
- Common row:
  - `ppc_network_id`, "Traffic source": "All traffic sources" (default); the account's sources; "[No traffic source]" (value 16777215).
  - `aff_campaign_id`, "Campaign": "All campaigns" (default); the account's campaigns grouped by category.
  - `user_pref_show`, "Clicks":
    - `all` "All clicks" (default)
    - `real` "Real clicks" (`click_filtered = 0`)
    - `filtered` "Filtered out clicks" (`click_filtered = 1`)
    - `filtered_bot` "Filtered out bot clicks" (`click_bot = 1`)
    - `leads` "Converted clicks" (unfiltered and `click_lead = 1`)
- Advanced: a closed disclosure. It remembers whether it was open in the browser, under the key `overview-filters`.
  - `ppc_account_id`, "Traffic source account": grouped by source.
  - `aff_network_id`, "Category".
  - `landing_page_id`, "Landing page".
  - `text_ad_id`, "Text ad".
  - `method_of_promotion`: "Any", `directlink` "Direct link" (`landing_page_id = ''`), `landingpage` "Landing page".
  - `country_id`, "Country": install-wide list.
  - `region_id`, `isp_id`, `browser_id`, `platform_id` (Region, ISP/Carrier, Browser, Platform):
    - Each lists up to 300 values the account's clicks carried in the window, busiest first. Region labels carry the country code.
    - The choices load when Advanced is opened and the report has settled, through `tracking202/ajax/overview_filter_choices.php?filter=&from=&to=&current=`.
    - That call is a GET returning JSON `{options:[{value,label}]}`. A bad request gets 400 `{error}`. A failed read gets 503 "The choices could not be loaded. Try again.", and the page shows a "Retry loading choices" button.
  - `device_id`, "Device type": from `202_device_types`.
  - `publisher_id`, "Publisher": the grantable publishers. Hidden when there are none and none is selected.
  - `subid`, `ip` ("Visitor IP"), `keyword`, `referer`: text search boxes.
  - `user_pref_limit`, "Rows": Visitors only. Choices 10, 25, 50 (default), 75, 100, 150, 200.
- Range (Visitors only):
  - Presets: today, yesterday, last7, last14, last30, thismonth, lastmonth, thisyear, lastyear, alltime.
  - "Custom Date" adds `from` and `to` (YYYY-MM-DD). The window is inclusive at both ends, in the account's zone.
- Refusal messages (`p202_report_prefs_from_query()`):
  - An id field: "<Label> '<value>' is not one this report can filter by." The upper bound is 255 for country, region, ISP, device, browser and platform, and 16777215 for the other ids.
  - A choice field: "<Label> '<value>' is not one of …".
  - A text box (at most 100 characters): "<Label> is longer than 100 characters."
  - IP: "Visitor IP '<value>' is not an IP address; give one whole address, IPv4 or IPv6."
  - A repeated parameter: "<Label> was sent more than once."
  - Range:
    - "'<range>' is not a date range this report offers."
    - "A custom range needs a start and an end date."
    - "The <start|end> date '<value>' is not a date; use YYYY-MM-DD."
    - "The start date is after the end date."
    - "The date range was sent more than once."
  - When anything is refused, nothing is applied or stored, and the report panel says "These filters were not applied / Correct the field marked above and apply again. The report is not drawn under filters it does not match."
- "Reset" puts back: range today, Clicks all, Rows 50, everything else empty.
- If reading or saving the filters fails, the page shows "The report filters could not be read or saved, so this report cannot be drawn. Reload the page; if it keeps happening, the database is refusing the request and the server log says why."

**Click history fragment** (`tracking202/ajax/click_history.php`). The table both pages draw.

Request and auth:
- Requires a signed-in user and applies the page's `view`.
- `filter_reason` is optional. It must be one of `sign_in_address`, `crawler_range`, `repeat_visit`, `filter_list`, `fraud_score`. Anything else gets 400 with "filter_reason must be one of …".

What it reads:
- `202_dataengine` with account-tied joins to campaigns, traffic source accounts and sources, landing pages and text ads.
- IP (v4 and v6), keyword, browser, platform, device model and type, country, region, city, ISP.
- Site URLs for the referer, landing, outbound, cloaking and redirect links.
- Per click, the count of its `202_conversion_logs` rows (deleted rows included) and its stored filter reason.

Visitors mode:
- POST `offset` (page number) selects the page. POST `order` is accepted only as `click_time DESC` or `click_time ASC`; the page's script never sends it.
- The page is `user_pref_limit` rows (default 50), newest first.
- The header line reads "Clicks X–Y of N, newest first".
- Pagination is labelled "Pages of clicks".

Spy mode (`spy=1`):
- Reads through the read replica (`ConnectionFactory::readConnectionOf()`).
- Ignores the calendar window and takes `click_time > now − 86400`. It does not count.
- The first load is capped at the stored `user_pref_limit`.
- With `since` and `since_id` it returns only newer clicks (`click_time > since`, or the same second and a higher id), with no limit. It answers bare `<tr>` rows plus a hidden marker row (`data-p202-spy-latest`, `data-time`, `data-id`).

Above the table, a "Show" select (`#p202-filter-reason`) offers:
- "Any click"
- "Filtered: fraud score"
- "Filtered: your address"
- "Filtered: crawler"
- "Filtered: repeat visit"
- "Filtered: your list"

Choosing one reloads the fragment with `filter_reason`.

Empty states:
- Spy: "No clicks in the last 24 hours" / "New clicks appear here within a few seconds of arriving. Send traffic through a tracking link to see them."
- Visitors: "No clicks match these filters" / "Widen the date range or clear a filter above. If no clicks have arrived yet, send traffic through a tracking link."
- With a reason chosen, the title reads "No clicks filtered for this reason match these filters".
- Each has a "Get tracking links" button to `tracking202/setup/get_trackers.php`.

Table `#stats-table` columns (`click_history_row.php`), one row per click, carrying `data-click-id` and `data-click-time`:
- Subid: the click id.
- Date: `m/d/y g:ia`.
- User agent: a device-type icon with a "Browser/Platform/Device" tooltip, and a browser icon.
- Geo: a flag, with the tooltip "country (cc), city (region)".
- ISP/Carrier: or "-".
- Click: one pill.
  - "Filtered: <reason>" for a filtered click with a reason. A filtered click with none shows "Filtered", titled "No reason is on record…".
  - "Lead", titled "This click converted into a lead / sale".
  - "Real", titled "A real (unfiltered) click".
  - Two buttons can be added: "Not fraud" for a `fraud_score` click when the role has `access_to_personal_settings`, and "N conversion(s)" when the click has conversion rows.
- IP.
- Traffic source: icon with network and account.
- Offer / LP: the campaign name, or "Redirector url", or the landing page nickname for an advanced landing page click.
- Referer: a link to the host, cut at 150px; `http://` is added when there is no scheme.
- Text ad.
- Links: hop icons, all opening a new tab: referer, landing, and (when the click went out) outbound, cloaked referer (`cl.php?pci=` or `off.php?acip=&pci=`) and redirect.
- Keyword: cut at 250px.

**Click breakdown modal** (`#p202-click-conversions`, `tracking202/ajax/click_conversions.php`). Opened by the "N conversion(s)" button on Visitors and Spy, and by the Subid button on Conversion Logs.
- Request: GET `click_id`, digits only, 1 to 19 digits. Otherwise 400 "That is not a click id. Open the breakdown from a row of the click history."
- Owner: `DataScope::userId()`. Data comes from `ClickBreakdown::forClick()`.
- Refusals:
  - Unknown click: 404 "There is no click N in your account."
  - A ledger row that cannot be read: 500 "This click's conversions cannot be explained: …".
- Tiles:
  - Click value: "Not converted" when the click is not a lead.
  - Counted: "X of Y".
  - Payout mode: "Accumulate" ("conversions add up") or "Replace" ("the latest one counts").
- Flashes: a pre-ledger click (info), a mismatch between the stored value and the rows (warning), mixed currencies (warning).
- Table `click-conversions-table` columns:
  - Conversion (`#id`).
  - Time.
  - Amount, with "(currency not recorded)" and "(sent as …)".
  - In the value: a "counted" pill, or a reason pill with an explanation such as "Replaced by conversion N."
  - Source.
  - Click found by.
  - Linked to (label and " · event X").
  - Transaction ID.
- A totals row reads "Counted toward the click". The empty state reads "No conversions on this click". Money is `?` when masked.
- The script ignores an answer that arrives after another click's breakdown was opened. A refusal shows the server's flash in the modal. The modal takes no report view.

**Not fraud** (`tracking202/ajax/click_not_fraud.php`). The button on a fraud-score row POSTs `{click_id}` with the session token, which the shell's jQuery prefilter adds.

Refusals:
- No valid token: 403 "Invalid or expired form token. Reload the page and try again."
- Role lacks `access_to_personal_settings`: 403 "Your role cannot change the click filter, so it cannot mark a click as not fraud."
- `click_id` read by `PostedId::read()` is bad: 422 "That is not a click."
- Not a fraud-score click of the account: 404 "That click is not one of yours filtered for its fraud score."
- An exception: 500 "The click could not be changed. Nothing may have been written; reload the page to see."

On success it answers 200 `{ok, message}`. The message is "N click(s) from <address> count(s) again.", followed by one of:
- "More clicks from it are still filtered: choose Not fraud on one of them to count the next 200."
- "The address is on your click filter list as always count, so it is not scored again."
- " But <problem>."

Writes (`FraudVerdictUndo::undo()`):
- Puts the address on the account's click filter list (`202_click_filter_entries`) as always count, with the note "Not fraud: marked on click N".
- For up to 200 of the account's fraud-score clicks from that address, under the ledger's click lock:
  - sets `click_filtered = 0` on `202_clicks` and `202_clicks_spy`
  - deletes their `202_clicks_filter_reason` row
  - rolls up each click's report row, or queues a retry when the rollup fails

The script then turns the pill to "Real", removes the button and shows the message in the cell. On failure it shows the server's message, or "The click was not changed (HTTP n). Reload the page and try again."

**Update pages.** Each Update page redirects to `tracking202/` when the role lacks `access_to_update_section` (Delete Subids also needs `delete_individual_subids`). A refused user lands on the Overview with no message. Every Update form is a POST carrying the session token. A refused token shows "Your session expired before the form was sent. Nothing was changed; please send it again." The campaign, category, traffic source and account lists are the user's own, non-deleted rows.

## Pages

### Visitor History: `tracking202/visitors/`

- **Where / who:** Top bar › Visitors. Any signed-in user; there is no role permission check. Signed-out handling is under Shared behaviour. The page title is "Visitor History", the heading "Visitor history", with the description "Every click in the window you choose, newest first: where it came from, what it saw and whether it converted."
- **Purpose:** Browse individual clicks in a date window with the report filters, page by page.
- **Data shown:** The filter bar, then a panel "Clicks" holding the click history fragment in Visitors mode (Shared behaviour).
- **Inputs:**
  - Every filter in the Shared behaviour list, plus Rows (`user_pref_limit`) and the date range.
  - `offset`: the page number in the page URL, read as `max(0, (int) $_GET['offset'])`.
  - The fragment's "Show" select (`filter_reason`).
  - Filters are saved as the user's report preferences on load.
- **Actions:**
  - "Apply" reloads the page with the filters as GET. "Reset" puts back the defaults.
  - Pagination links (`data-p202-offset`) reload the fragment by POST `{offset}` to `tracking202/ajax/click_history.php?view=…`, replace `offset` in the address bar with `history.replaceState`, and scroll to the table.
  - A failed fragment load shows a flash with guidance by status: 504 (timeout), 502/503 (server), 401 (signed out), 403 (refused), no answer (connection).
  - The "N conversion(s)" button opens the click breakdown modal; "Not fraud" works as described under Shared behaviour.
  - "Download to Excel" links to `tracking202/visitors/download/?view=…`.
- **Writes and side effects:** On load, the filters are written to `202_users_pref`. Not fraud writes as described above.
- **Edge cases and states:**
  - Refused filters leave the report undrawn, with the field marked.
  - The empty states are under Shared behaviour.
  - A publisher session hides the campaign-side filters and may read every account.
  - Masked money shows `?`.
- **v3 coverage:** **PARTIAL**.
  - `GET /clicks` takes `period`, `time_from` and `time_to` (unix seconds, YYYY-MM-DD in the account's zone, or an offset time). It also takes every report filter (`ReportFilter::filterNames()`: the id filters, `device_type`, `method_of_promotion`, `show`, `keyword`, `ip`, `referer`, `publisher_id`, `click_id`), plus `click_lead`, `click_bot`, `filter_reason` (the page's five reasons plus `bot` and `not_recorded`), `limit` (1–500, default 50) and `offset`.
  - It answers `data`, `truncated` and `pagination{total,limit,offset}`, newest first, with money masked as the page masks it.
  - What is missing: a row carries no count of the click's conversion rows (the page's "N conversion(s)" button), and the sort is fixed at newest first.
  - The rest of the page is covered: `GET /clicks/{id}/conversions` (needs the key scope `conversions:read`) is the breakdown modal, and `POST /clicks/{id}/not-fraud` (needs `access_to_personal_settings`) is the Not fraud button. Saving the filters as preferences is covered only in part; see Partial v3 coverage.

### Visitors download: `tracking202/visitors/download/`

- **Where / who:** The "Download to Excel" button on Visitor History. Any signed-in user, with the same scope and masking as Visitors.
- **Purpose:** Export the Visitors list.
- **Data shown:** A file, not a page. Headers `Content-Disposition: attachment; filename=T202_visitors_<unix time>.xls` and `Content-Type: application/octet-stream`. The body is tab-separated text (`VisitorsDownload`).
- **Inputs:** `view`, as on Visitors. A view that does not parse gets 400.
- **Actions:** Columns, in order:
  - Subid, Date, Browser, OS, PPC Network, PPC account
  - Click Real/Filtered (`filtered`, `conversion` or `real`)
  - IP Address, ISP/Carrier, Country, Country Code, City
  - Offer/LP, Text Ad
  - Referer, Landing, Outbound, Cloaked Referer, Redirect, Keyword
  - gclid, gbraid, wbraid, ttclid, msclkid, fbclid, oppref
  - utm_campaign, utm_content, utm_medium, utm_source, utm_term

  In each cell, tabs and newlines become spaces. A value starting with `=`, `+`, `-` or `@` that is not a number gets a leading apostrophe.
- **Writes and side effects:** None.
- **Edge cases and states:**
  - The export runs the list query with its defaults: `LIMIT user_pref_limit` from offset 0. It downloads only the first page of rows (50 by default), whichever page the user was on.
  - It does not apply the "Show" select's `filter_reason`.
- **v3 coverage:** **PARTIAL**. `GET /clicks` pages through the same clicks with the same filters. It has no file export, and it does not return the cloaked referer, redirect or `utm_*` values. It does return referer, landing, outbound and the seven ad click ids.

### Spy View: `tracking202/spy/`

- **Where / who:** Top bar › Spy. Any signed-in user; there is no role permission. The page title is "Spy View", the heading "Spy", with the description "Clicks from the last 24 hours as they arrive; new ones appear at the top every few seconds."
- **Purpose:** Watch clicks arrive live.
- **Data shown:**
  - The filter bar without range and Rows.
  - A note: "The last 24 hours, whatever range the reports use. The filters you apply here also open by default on the reports."
  - A panel "Live clicks" with the status pill "live · last 24 hours" (`data-p202-spy-status`), holding the click history fragment in Spy mode.
- **Inputs:** The Shared behaviour filters except range and Rows, saved as preferences on load, and the "Show" select (`filter_reason`). There is no paging.
- **Actions:** Live refresh (`202-js/p202-overview.js`, `spy()`):
  - Every 5,000 ms the page GETs `click_history.php?spy=1&view=…&since=<time>&since_id=<id>`, using the latest marker it holds.
  - New rows are added at the top. Rows already shown are skipped by `data-click-id`.
  - New rows are highlighted (`table-active`) for 4 seconds, and the table is cut to 200 rows.
  - When a newer poll starts, an older request still running is cancelled.
  - On failure the pill changes to "reconnecting…" (warning style) and polling continues.
  - The breakdown modal and Not fraud work as on Visitors. There is no download.
- **Writes and side effects:** The filters are saved to `202_users_pref` on load. Not fraud writes as described above.
- **Edge cases and states:**
  - The first load shows at most the stored `user_pref_limit` rows, a setting Spy itself does not offer; later polls are unlimited.
  - The page reads from the replica when one is configured.
  - The empty state is under Shared behaviour.
- **v3 coverage:** **PARTIAL**. `GET /clicks` with `time_from` (an offset time or unix seconds) and the same filters can poll for recent clicks, newest first. It has no `since`/`since_id` cursor for "only newer than this click" and no read-replica path.

### Update (entry): `tracking202/update/`

- **Where / who:** The Update tab's link. Signed in. The page has no permission check of its own.
- **Purpose:** A redirect only. It sends the browser to `tracking202/update/subids.php`, which checks the permission.
- **Data shown:** None.
- **Inputs:** None.
- **Actions:** None.
- **Writes and side effects:** None.
- **Edge cases and states:** None.
- **v3 coverage:** Not applicable; there is nothing to cover.

### Update Subids: `tracking202/update/subids.php`

- **Where / who:** Update › Update Subids. Needs `access_to_update_section`; otherwise the user is redirected to `tracking202/`.
- **Purpose:** Mark a pasted list of subids (click ids) as converted.
- **Data shown:** The form, plus a side panel linking to Upload Revenue Reports and to Setup › Postback (`setup/get_postback.php`).
- **Inputs** (form `#update-subids`, POST):
  - `subids`: textarea, 8 rows, required. Placeholder "SUBID, PAYOUT, TRANSACTION ID, TIMESTAMP"; one line per subid.
  - `subid_payout`: text, optional. Placeholder "campaign payout", help "For lines that give no payout. Empty: each campaign's payout."
  - `subid_timezone`: select of PHP's listed zones. The account's zone is added first when it is not listed, and is marked "(your account)". It is the default.

  Refusals:
  - Token refused (Shared behaviour).
  - "Paste at least one subid, one per line."
  - "Enter the payout as a number, such as 12.50." (or the payout reader's own problem).
  - "Choose a time zone from the list."

  Line rules (`SubidBatch`):
  - At most 4 fields per line.
  - Payout: up to 5 decimals, not negative, at most 999999.99. The account currency's sign is accepted; thousands separators and decimal commas are refused.
  - Transaction id: at most 255 bytes, no control characters.
  - Time: unix seconds, m/d/y(yy), or Y-m-d with an optional time. It may not be more than 300 seconds in the future, or before the click's first visit.
  - A repeated line with a different payout or time is refused. So is a transaction id already on the click with a different payout or time.

  If any line is refused, nothing is recorded. The page lists "Line N (subid): error." (50 shown, then "and N more") and the flash "Nothing was recorded: N line(s) could not be read. Fix them and send the list again."
- **Actions:** "Mark as converted" submits. There is no preview or confirmation step.
- **Writes and side effects:**
  - Each line is recorded through `MysqlConversionRepository::record()` with source `subid_upload`, user agent `subid-upload`, match method click id, once per click unless a transaction id is given.
  - The click is unfiltered in `202_clicks` and `202_clicks_spy`.
  - Signed PurLink subids are accepted.

  Result flashes:
  - "N subid(s) marked as converted. Your account income now reflects them."
  - "Payouts written with a currency sign were read as CUR amounts: …"
  - "Not found in your account, so not marked: …"
  - "Already recorded, so left as they were: …"
  - "Recorded before and deleted since, so not recorded again: … A deleted sale keeps its transaction id; give the sale a new transaction id to record it."
  - "Repeats of an earlier line, recorded once: line N".
- **Edge cases and states:** A batch that stops part way (`BatchInterrupted`) is not caught by the page, so the user gets an uncaught error rather than a report of what stands.
- **v3 coverage:** `POST /conversions/subids`, which needs `access_to_update_section`. It takes `subids` (a list, at most 5,000), `payout` and `timezone`, and the same `SubidBatch` code. `?dry_run=1` previews per line.

### Update CPC: `tracking202/update/cpc.php`

- **Where / who:** Update › Update CPC. Needs `access_to_update_section`; otherwise the user is redirected to `tracking202/`.
- **Purpose:** Set what a set of past clicks cost, chosen by date range, traffic source, campaign and so on.
- **Data shown:** A check form, then a preview panel, then the result flash.
- **Inputs:** Check step: form `#cpc_form`, GET, with hidden `preview=1`.
  - `from`, `to`: dates, required, default the last 7 days. Help: "Whole days in your account's time zone."
  - `ppc_network_id`: "Every traffic source".
  - `ppc_account_id`: narrowed by the chosen source (`data-p202-filter-by`).
  - `aff_network_id`: "Every category".
  - `aff_campaign_id`: narrowed by the chosen category.
  - Advanced, a disclosure remembered under `update-cpc-advanced`:
    - `method_of_promotion`: "" "Direct links and landing pages", `directlink`, `landingpage`.
    - `landing_page_id`.
    - `text_ad_id`.
  - `cpc`: number, `$` prefix, required, min 0, max 99.99999, step 0.00001.

  Refusals:
  - "Choose a <what> from the list."
  - "Choose direct links, landing pages, or both."
  - "Enter the first/last day to update."
  - "'x' is not a date…"
  - "The last day is before the first day."
  - "Enter the CPC these clicks cost, for example 0.25."
  - "A CPC can be at most $99.99999."
  - "'x' is not a CPC…"
  - "You can not modify other peoples cpc history." (an id from another account)
  - Summary flash: "Nothing was saved. N fields need attention…"
- **Actions:** Two-step confirmation.
  1. "Check these clicks" shows "Check before you update":
     - a count pill
     - a summary of Days, Traffic source, Campaign, Promotion, Landing page, Text ad and New CPC
     - a warning ending "This cannot be undone."
     - with no matches, the empty state "No clicks match"
  2. Form `#cpc-apply` (POST, with the token) resends every field plus `expect_clicks` and `through_click_id`. Its button reads "Update N clicks".
- **Writes and side effects:** `CpcUpdate::apply()` runs in one transaction:
  - It locks the rows and recounts the selection, bounded by `through_click_id`.
  - It writes `click_cpc` on `202_clicks` and `202_clicks_spy`.
  - It queues the affected hours in `202_dirty_hours`.

  Clicks are chosen by their first visit. Method of promotion is read from the click's landing URL.

  Results:
  - If the count moved, nothing is written: "The clicks in this selection changed after you checked them: you confirmed X and Y match now…" (or "This confirmation did not say which clicks you checked…").
  - Success: "N clicks updated. Every click you checked now costs $X; clicks recorded since keep their own cost."
- **Edge cases and states:** The stored value is capped at 99.99 though the field accepts 99.99999 (as recorded in the summary of `CpcUpdate`; not re-measured).
- **v3 coverage:** `POST /clicks/cpc` (needs `access_to_update_section`). It takes the same fields and the same code. `?dry_run=1` answers `matching` and `through_click_id`. The write must carry them back as `expect_clicks` and `through_click_id`, and answers 409 when the count changed.

### Reset Campaign Subids: `tracking202/update/clear-subids.php`

- **Where / who:** Update › Reset Campaign Subids (template title "Clear Subids"). Needs `access_to_update_section`; otherwise the user is redirected to `tracking202/`.
- **Purpose:** Clear every conversion on the converted clicks of a category or one campaign, so subids can be uploaded again.
- **Data shown:**
  - The form, and a side panel. The panel links to Delete Subids when the role may use it; otherwise it says "Deleting individual subids needs a permission your account does not have."
  - With no categories, the empty state "No categories yet" with "Add a category" (`setup/aff_networks.php`).
- **Inputs** (form `#clear_subids_form`, POST):
  - `aff_network_id`: required, placeholder "Choose a category". Chosen automatically when there is only one.
  - `aff_campaign_id`: "Every campaign in the category" (0), narrowed by the category.

  Refusals:
  - "You have to at least select an affiliate network to clear out."
  - "Choose one of your categories from the list."
  - "Choose one of your campaigns from the list."
  - "That campaign is not in the category you chose."
- **Actions:** "Reset subids" (danger style). The form carries `data-p202-confirm`: "Clear every conversion in this selection? The clicks stay; the income their conversions added comes off your reports until you upload the subids again."
- **Writes and side effects:**
  - For each converted click at its latest visit, it soft-deletes the conversion rows and voids their revenue (`clearClicks`).
  - It then queues every hour from the earliest such click to now in `202_dirty_hours` (INSERT IGNORE).
  - It runs with no time limit.
  - Success: "You have reset N subids. You can now re-upload your subids."
- **Edge cases and states:** `BatchInterrupted` is not caught by the page.
- **v3 coverage:** `POST /conversions/subids/reset` (needs `access_to_update_section`). It takes `aff_network_id` and `aff_campaign_id`. `?dry_run=1` answers the matching count and the names of the category and campaign.

### Delete Subids: `tracking202/update/delete-subids.php`

- **Where / who:** Update › Delete Subids. Needs `access_to_update_section` and `delete_individual_subids`; otherwise the user is redirected to `tracking202/`. The menu entry is hidden without `delete_individual_subids`.
- **Purpose:** Delete the conversions recorded on a pasted list of subids.
- **Data shown:** The form.
- **Inputs** (form `#delete-subids`, POST):
  - `subids`: textarea, required, one per line.
  - Refusals: token refused, or "Paste at least one subid, one per line."
- **Actions:** "Delete conversions". The form's confirm: "Delete every conversion recorded on these subids? Their clicks stay; only the conversions go, and the income they added comes off your reports." The prompt is handled by `p202-ui.js`.
- **Writes and side effects:**
  - `SubidBatch::clear()` clears the conversions, unfilters the clicks and spy rows, and refreshes each click's report row.
  - Flashes: "N subid(s) cleared. Your account income now reflects the subids just deleted." and "Not subids, so nothing was cleared for them: …"
- **Edge cases and states:** Lines whose subid is not found in the account are not reported back.
- **v3 coverage:** `POST /conversions/subids/delete` (needs `access_to_update_section` and `delete_individual_subids`). It takes `subids` (a list). `?dry_run=1` lists the conversions it would remove, per line.

### Upload Revenue Reports: `tracking202/update/upload.php`

- **Where / who:** Update › Upload Revenue Reports. Needs `access_to_update_section`; otherwise the user is redirected to `tracking202/`.
- **Purpose:** Apply a network's CSV revenue report, one commission per subid.
- **Data shown:** Three steps on one URL, chosen by `case`.
- **Inputs:**
  - Step 1 (default): form `#upload-report`, POST multipart with the token. `csv` is a file input, required, accepting `.csv` and `.txt`.
    - Refusals: token refused.
    - "Choose the CSV report to upload. If you did, it may be larger than this server accepts (upload_max_filesize)."
    - "Upload the report as a .csv or .txt file; export it from your network as CSV."
    - The file cannot be read, or cannot be kept.
    - "The file is empty. Nothing was changed."
    - "Sorry, I can't write to the directory: …" when `tracking202/update/reports/` is not writable.
    - The file is kept as `tracking202/update/reports/<16 hex>.csv`, and the browser is redirected to `?case=1&file=<name>`.
  - Step 2 (`case=1`, GET `file`, checked by basename and real path):
    - A table of the columns: Column, First line, a Subid radio (`click_id`) and a Commission radio (`click_payout`), both required.
    - The radios are pre-chosen from the column names, with the note "Chosen from the column names…".
    - The form POSTs to `?case=2` with the token and the file name.
    - Buttons: "Upload a different file" and "Apply the report".
  - Step 3 (`case=2`):
    - A refused token: "Your session expired before the report was applied…".
    - "You forgot to check the subid and the commission column."
- **Actions:** The column table in step 2 is the only preview: it shows the first line of each column, not what each line would record. There is no confirmation dialog and no undo on the page.
- **Writes and side effects:** `RevenueUploadImporter::import()`:
  - Writes a `202_conversion_uploads` batch row (user, file name, line, recorded and skipped counts, upload time).
  - Writes each line as a ledger conversion with source `revenue_upload`, deduplicated per batch line, skipping LTV and the bridge.
  - Line 1 is read as the header.
  - Skip reasons: "not a subid (a click id is a whole number)", "the commission is not a number", "no click with this subid in your account".
  - Each converted click's hour is marked dirty. The file is deleted after the apply.
  - Result page:
    - Flash "Your report has been uploaded: N line(s) recorded, M skipped", plus an info flash about the header line.
    - A sortable table "Income recorded" (Subid, Commission).
    - A table "Lines not recorded" (Line, Subid, Commission, Why): the first 1,000 lines, with counts by reason.
  - A failure: "The report could not be applied: <message>. Lines before the failure were recorded…".
- **Edge cases and states:**
  - The kept file is not tied to the user who uploaded it: any user with the update permission who knows the name can apply it at step 2.
  - A file whose upload is never applied stays in the directory; no cleanup was found in these files.
- **v3 coverage:** **PARTIAL**, and the API offers more than the page.
  - `POST /conversions/uploads` takes `csv` (the text, body up to 8 MB), `file_name`, `subid_column` and `amount_column`. `?dry_run=1` is a full per-line preview.
  - `GET /conversions/uploads` lists batches.
  - `POST /conversions/uploads/{id}/undo` undoes one batch, with a dry run.
  - All need `access_to_update_section`.
  - The gap is on the page side: the page has no line preview, no batch list and no undo, and the API has no multipart upload or two-step column picking.

### Conversion Logs: `tracking202/logs/`

- **Where / who:**
  - Top bar › Conversion Logs, shown only with `access_to_setup_section`.
  - The page calls `AUTH::require_permissions('access_to_setup_section')`. A user without it gets 403 "This account's role does not have the 'access_to_setup_section' permission."
  - It reads `$_SESSION['user_own_id']`, not `DataScope`.
- **Purpose:** List conversion rows with filters, and download them.
- **Data shown:**
  - Table `conversion-logs-table`, 50 per page, ordered by conversion time then id, newest first. The panel's sub-line reads "N conversions". Columns:
    - Subid: a button that opens the click breakdown modal (Shared behaviour), and a link "its inbound requests" to `setup/inbound_log.php?click_id=`.
    - Campaign, Click time, Conversion time, Elapsed, IP address.
    - Type: the source, with "Reverses conversion N" or "Held back for consent…" where they apply.
    - Transaction ID.
    - Payout: with currency, "sent as …", "currency not recorded", or "in X, not converted to Y"; `?` when masked.
    - Deleted: a pill.
    - User agent.
  - Empty state "No conversions match". A failed read shows a flash.
- **Inputs** (GET, `ConversionLog::readQuery()`):
  - `range`: default `last30`; the presets or `custom`, which adds `from` and `to`.
  - `campaign_id`: "All campaigns" or a non-deleted campaign.
  - `click_id`: search box, label "Subid", placeholder "Click id".
  - Advanced:
    - `type`: Pixel, Postback, Universal pixel, API, Subid upload, Revenue upload, Campaign pixel, ClickBank, PayKickstart, JVZoo, Shopify, Zaxaa, App install, Goal, Value before the ledger.
    - `deleted`: "" "Live and deleted" (default), `no` "Live only", `yes` "Deleted only".
    - `time_basis` ("Date range applies to"): `conversion` (default) or `click`.
  - `offset`: the page, at most 100000.

  Refusals:
  - "<Label> takes one value."
  - "Enter a campaign from the list."
  - "Enter a click id (subid), digits only."
  - "Choose a type from the list."
  - "Choose live or deleted conversions."
  - "Choose conversion time or click time."
  - "Enter both dates as YYYY-MM-DD."
  - "The start date is after the end date."
  - "Choose a date range from the list."
  - "That is not a page of this list."
- **Actions:**
  - "Download CSV" (`?download=csv`, same filters). The file is `conversion-logs-YmdHis.csv`, at most 100,000 rows (`P202_CONVERSION_LOGS_CSV_MAX` can lower it). A note line is added when it is cut short or stopped.
  - Columns: conversion_id, subid, campaign, click_time, conversion_time, elapsed, ip_address, type, reverses_conversion_id, transaction_id, payout, currency, sent_payout, sent_currency, deleted, user_agent, held_back_for_consent.
  - The Subid button opens the click breakdown modal.
- **Writes and side effects:** None.
- **Edge cases and states:** It shows deleted rows by default, unlike the API.
- **v3 coverage:** **PARTIAL**.
  - `GET /conversions` takes `campaign_id`, `click_id`, `source` (the type), `match_method`, `goal`, `period`/`time_from`/`time_to`, `limit` (1–500) and `offset`. It orders the same way.
  - What is missing:
    - It always leaves deleted conversions out (`cl.deleted = 0`), so there is no `deleted` filter.
    - It has no click-time basis.
    - It returns no IP address or user agent.
    - It has no CSV export.
    - It has no role gate, where the page needs `access_to_setup_section`.
  - The breakdown modal is `GET /clicks/{id}/conversions`.

## Missing v3 endpoints

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Visitors download | Downloads the click list as a tab-separated `.xls` file with UTM, cloaked referer and redirect columns | `GET /clicks` | No file export; no `utm_*`, cloaked referer or redirect values on a click |
| Spy View | Polls for clicks newer than the last one shown (`since`, `since_id`) every 5 seconds | `GET /clicks` with `time_from` | No "newer than this click" cursor; a client can only poll by time window |
| Conversion Logs | Shows deleted conversions and filters by live, deleted or both | `GET /conversions` | Deleted rows are always excluded; no `deleted` filter |
| Conversion Logs | Applies the date range to click time instead of conversion time | `GET /conversions` | No `time_basis` |
| Conversion Logs | Downloads up to 100,000 rows as CSV | `GET /conversions` | No CSV export |

## Partial v3 coverage

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Visitor History | Lists clicks with filters, paging, conversion count per row | `GET /clicks` | No per-row conversion count; sort fixed at newest first |
| Visitor History, Spy View | Saves the filter bar (show, campaign, source, geo, text filters) as the user's report preferences | `PUT /users/{id}/preferences` | Writes `user_pref_limit` and `user_pref_time_predefined` only; the other report filters are not writable preferences |
| Visitors download | Exports the click list | `GET /clicks` | Covered for the shared columns only; see Missing |
| Spy View | Live list of the last 24 hours | `GET /clicks` | No incremental cursor, no read-replica path |
| Conversion Logs | Lists conversions with IP address and user agent | `GET /conversions` | IP address and user agent are not returned; no role gate (the page needs `access_to_setup_section`) |
| Upload Revenue Reports | Two-step upload with column picking | `POST /conversions/uploads`, `GET /conversions/uploads`, `POST /conversions/uploads/{id}/undo` | The API covers more than the page: it has a per-line dry run, a batch list and undo, which the page lacks; it takes the CSV as text, not as a multipart upload |

## Not determined from code

- Whether `CpcUpdate` caps the stored CPC at 99.99 while the form accepts 99.99999. This is taken from an earlier read of `CpcUpdate::apply()`; it was not re-read for this document.
- Whether anything removes report files left in `tracking202/update/reports/` by uploads that were never applied. No cleanup was found in the Update files or the Conversion Logs page; cron jobs were not searched.
- Whether `GET /conversions` returns the "held back for consent" mark. The list's selected columns (`ConversionsController::COLUMNS`) do not name it; the response post-processing was not read in full.
