# Overview reports

The Overview tab holds the account's whole-traffic reports: Account Overview (with its chart and the LTV strip), Breakdown analysis by hour, day, month or year, Day parting, Week parting, Group Overview with up to four nested groupings and a spreadsheet download, and Rotator breakdown. Every page is drawn the same way (`p202_overview_run()` in `202-config/functions-ui-overview.php`): the page renders a filter bar as a GET form, writes the applied filters to the user's `202_users_pref` row, and then `202-js/p202-overview.js` POSTs to an AJAX fragment that draws the report into the page's panel. Every page needs only a signed-in user; no page asks a role permission to open, though campaign figures and the chart are hidden from a role without `access_to_campaign_data`.

| Page | Path | Permission |
|---|---|---|
| Overview entry (redirect) | `tracking202/index.php` | Signed-in user (`AUTH::require_user()`); redirects to `tracking202/overview/` |
| Account Overview | `tracking202/overview/index.php` | Signed-in user; the chart needs `access_to_campaign_data`; the old Google feed notice needs `access_to_setup_section` and `access_to_campaign_data`; the LTV strip needs `access_to_campaign_data` |
| Breakdown analysis | `tracking202/overview/breakdown.php` | Signed-in user |
| Day parting | `tracking202/overview/day-parting.php` | Signed-in user |
| Week parting | `tracking202/overview/week-parting.php` | Signed-in user |
| Group overview | `tracking202/overview/group-overview.php` | Signed-in user |
| Group overview download | `tracking202/overview/group_overview_download.php` | Signed-in user (a file download, not a page) |
| Rotator breakdown | `tracking202/overview/rotator-breakdown.php` | Signed-in user |

Navigation: the Overview tab (`tracking202/_config/top.php`) is shown to every signed-in user. Its strip "Overview reports" (`tracking202/_config/sub-menu.php`) lists Campaign Overview, Breakdown Analysis, Day Parting, Week Parting and Group Overview. Rotator breakdown is not in the strip, and no link to it was found in the served tree (searched for `rotator-breakdown` in every PHP file outside `tests/`); it is reached by its URL.

## Shared parts

These are described once here and referenced from each page.

### The report filter bar

Rendered by `p202_overview_run()` and the filter catalog in `202-config/functions-ui-partials.php`. Each page names which filters it offers (see each page). The bar is a GET form whose action is the page itself.

- **Date range** (`range`): Today, Yesterday, Last 7 Days, Last 14 Days, Last 30 Days, This Month, Last Month, This Year, Last Year, All Time, Custom Date. Presets are computed in the account's time zone on calendar days (`tracking202/Report/ReportWindow.php`): "Last N days" runs from midnight N days before today to the end of today; All Time starts on the user's registration day (`202_users.user_time_register`). Custom Date enables two date inputs, `from` and `to` (type date; disabled with the hint "Choose Custom Date to set these." unless Custom Date is chosen). Dates are read as `YYYY-MM-DD`, `mm/dd/yyyy` or `mm/dd/yy`. A custom range is stored as 00:00:00 of the first day to 23:59:59 of the last day in the user's zone.
- **Main filters**: Traffic source (`ppc_network_id`, "All traffic sources", plus "[No traffic source]", stored as 16777215, and the account's non-deleted traffic sources by name); Campaign (`aff_campaign_id`, "All campaigns", grouped by category); Clicks (`user_pref_show`: All clicks, Real clicks, Filtered out clicks, Filtered out bot clicks, Converted clicks; default All clicks).
- **Advanced** (a closed disclosure labelled "more filters", or "N set" and opened when any is set): Traffic source account (`ppc_account_id`, grouped by traffic source), Category (`aff_network_id`), Landing page (`landing_page_id`, by nickname), Text ad (`text_ad_id`), Method of promotion (`method_of_promotion`: Any, Direct link, Landing page), Country (`country_id`, the install's country list), Region (`region_id`), ISP/Carrier (`isp_id`), Device type (`device_id`, from `202_device_types`), Browser (`browser_id`), Platform (`platform_id`), Publisher (`publisher_id`, the publishers this account may credit, by name; the menu is hidden when there are none and none is selected), Subid (`subid`), Visitor IP (`ip`), Keyword (`keyword`), Referer (`referer`), and Costs (`user_cpc_or_cpv`: CPC costs or CPV costs, default CPC).
- **Group overview only**: Group by (`group_1`), Then by (`group_2`), and under Advanced Third grouping (`group_3`) and Fourth grouping (`group_4`); the second to fourth offer "Nothing more". The level list is under Group overview below.
- **Breakdown only**: Group by (`user_pref_breakdown`: Hour, Day, Month, Year; default Day).
- **Region, ISP/Carrier, Browser and Platform choices are loaded late.** The page renders only the selected value's label. When Advanced is opened, and after the report has finished drawing, `p202-overview.js` fetches the choices one list at a time from `tracking202/ajax/overview_filter_choices.php` (GET `filter`, `from`, `to`, `current`). The answer is JSON `{options: [{value, label}]}`: the ids this account's clicks carried in the window (read from `202_dataengine`), the busiest 300, sorted by label; a region is labelled "name (CC)". A 400 answers `{error}` with one of "Choose a valid report filter.", "Choose a valid report date range.", "The report start must not be after its end." or "Choose a valid current filter value."; a failed read answers 503 "The choices could not be loaded. Try again." While the report is still loading the list says "Choices will load when the report finishes."; a "Load choices" button retries. The answer is cached (`FilterChoicesCache`).
- **A stored value not in a list** is still shown, as "<value> (not in your list)". A stored 0 is shown as empty.
- **Validation** (`202-config/functions-report-prefs.php`). Ids must be whole numbers up to 16777215 (country, region, ISP, device, browser and platform up to 255); Subid up to `PHP_INT_MAX`; Visitor IP, Keyword and Referer up to 100 characters. Messages: "<Label> '<v>' is not one this report can filter by.", "<Label> '<v>' is not one of a, b.", "<Label> is longer than 100 characters.", "Visitor IP '<v>' is not an IP address; give one whole address, IPv4 or IPv6.", "<Label> '<v>' is not a grouping this report offers.", "<Label> was sent more than once.", "'<r>' is not a date range this report offers.", "A custom range needs a start and an end date.", "The start date '<v>' is not a date; use YYYY-MM-DD." (and the same for the end date), "The start date is after the end date.", "The date range was sent more than once." When any field is refused, the panel shows "These filters were not applied" / "Correct the field marked above and apply again. The report is not drawn under filters it does not match.", and nothing is saved.
- **How each filter is applied** (`UserPrefFilters`, `TextFilterSql`): Subid matches the click id exactly. Clicks: Real is `click_filtered = 0`, Filtered `click_filtered = 1`, Filtered bot `click_bot = 1`, Converted `click_lead != 0`. Device type matches through the device model's type. "[No traffic source]" matches clicks with no traffic source. Method of promotion: Direct link is `landing_page_id = 0`, Landing page is not 0. Publisher matches the clicks credited to that publisher. Keyword and Referer are case-insensitive "contains" matches in which `%` and `_` are literal; Visitor IP is one exact address. A stored value that is refused at read time makes the report match nothing rather than everything.
- **Quirk**: when Clicks is anything but All clicks, the show condition replaces the filter string built so far, so the Subid filter is dropped (`UserPrefFilters`).
- **Publisher view**: when `$_SESSION['publisher']` is non-empty, Traffic source, Traffic source account, Category, Campaign, Text ad, Landing page and Method of promotion are removed from the bar. No code sets that session key (see Not determined from code).
- **Buttons**: Apply (submits the GET form) and Reset (a link to the page with `range=today` and every filter set to its default: Clicks all, Costs cpc, Rows 50, Group by day, `group_1` Traffic source, `group_2` to `group_4` nothing). Below the bar: "The filters you apply open by default here and on the other reports."

### Saved preferences

- What the bar applies is written to the user's `202_users_pref` row in one prepared `UPDATE` that sets only the columns the page offers (and the range), all or nothing. This is a GET write with no form token, by design: the URL is the report, and opening a report link changes what the user's reports open with next. A preset is stored in `user_pref_time_predefined` with `user_pref_time_from`/`to` cleared; a custom range stores `''` there and the two times. An empty id is stored as `''` (traffic source as NULL).
- Each report, each fragment and the download read their filters from that row. To keep a second tab from changing what this tab draws, the page passes its filters to its fragment and download as a `view` parameter (`ReportView::PARAM`), which is laid over the stored row for that request. A bad `view` answers 400 with an HTML flash: "The report view was not applied: …" followed by "Reload the report page and try again." (reasons: "The report view was sent more than once.", "The report view has dates but no range.", "The report view names 'x', which is not a report filter.", "… which this report does not group by.").
- A failure to read or save the row shows the flash "The report filters could not be read or saved, so this report cannot be drawn. Reload the page; if it keeps happening, the database is refusing the request and the server log says why."
- Group overview's Group by and Then by, and Breakdown's Group by, show a line when they come from the default: "Grouped by X until you choose another grouping." with a "change" link.
- The Analyze pages share the same row, so filters applied here also open there (and the other way round).

### The report panel and loading

- The panel shows a skeleton ("Loading the report"), then `p202-overview.js` POSTs `offset` (from the page's `?offset=` or 0) to the fragment URL and inserts the HTML answer. The fragment URL carries `view`.
- A failed request replaces the panel with a flash "The report could not be loaded (HTTP n)." and guidance chosen by status: 504 "The server did not respond in time. Try a shorter date range."; 502 or 503 "The server is temporarily unavailable. Try again shortly."; 401 "Your session may have expired. Sign in again and retry."; 403 "The request was not authorized. Reload the page and check your access."; no answer "Check your connection and try again."; anything else "Reload the page and try again. If this continues, ask your administrator to check the server logs."
- Empty state (`p202_overview_empty()`): "No clicks in this range" / "Widen the date range or clear a filter above. If no clicks have arrived yet, send traffic through a tracking link." with a "Get tracking links" button to `tracking202/setup/get_trackers.php`.
- None of this area's fragments is paginated; every row is drawn. (A shared pager, `p202_overview_pagination()`, exists but these fragments do not call it.)

### The metrics table

Used by Breakdown, Day parting, Week parting and the Account Overview tables (`p202_overview_metrics_table()`), with figures from `DataEngine` over `202_dataengine` (costed from the spend ledger where the account has one).

- Columns after the label column: Clicks, Click throughs, CTR, Leads, Avg S/U, Avg payout, Avg EPC, Avg CPC, Income, Cost (shown in parentheses), Net, ROI. Net and ROI are coloured green when positive and red when negative. The last row is "Totals for report".
- How each is computed (`MetricsSql`): CTR = click throughs / clicks x 100; S/U = converted clicks (`click_lead`) / clicks x 100; Payout = income / leads; EPC = income / clicks; CPC = cost / clicks; Net = income - cost; ROI = net / cost x 100; each is 0 when its denominator is 0. In the totals row S/U is leads / clicks x 100 (not converted clicks), so it can differ from the rows' basis; totals CPC and EPC use 5 decimals internally and ROI 0 decimals (`ReportTotals`).
- Display (`HtmlReportFormatter`): counts with thousands separators; money in the account currency (`202_users_pref.user_account_currency`) with 2 decimals and negatives in parentheses, also in CPV mode (the formatter ignores the CPV flag); S/U and CTR to 2 decimals with %; ROI as a whole number with %.
- A role without `access_to_campaign_data` (and not in publisher view) sees `?` for clicks, click throughs, leads, income, cost and net (`CampaignDataMask`).
- Sorting is done in the browser: each column header sorts (tablesort) on a numeric `data-sort`, and the totals row stays last. No server sort is used.
- Above the table: a note on how far the spend ledger reaches when figures are costed from it, and a currency notice when the period holds conversions in other currencies, which are added as recorded and not converted (`RevenueCurrencies::notice()`).

### Endpoints in this area not used by the Overview pages

- `tracking202/ajax/report_dispatch.php`: a JSON endpoint for the Analyze report types only (keyword, textad, referer, ip, country, region, city, isp, landingpage, device, browser, platform), answering 404 "Tracking JSON architecture mode is disabled." unless `TRACKING202_JSON_ARCHITECTURE_ENABLED` is defined true or `?tracking_json_mode=` turns it on. No Overview page calls it.
- `tracking202/ajax/set_user_prefs.php`: a POST (form token required, 403 "Invalid token" without it) that writes the whole report preference row from the old calendar form. The comments name `display_calendar()` as its caller, and no definition of that function exists in the tree, so nothing in the served UI posts to it.
- `tracking202/Report/ReportFilterInput.php` and `ReportPrefsStore.php` are the Analyze pages' reader and store of the same preferences (and `ReportPrefsStore::load()` is how fragments read the row with the view laid over it).

## Pages

### Overview entry: `tracking202/index.php`

- **Where/who**: the address of the Prosper202 CS section; any signed-in user.
- **Purpose**: sends the user to Account Overview.
- **Data shown**: none.
- **Inputs**: none.
- **Actions**: checks the sign-in and answers a `Location` redirect to `tracking202/overview/`.
- **Writes and side effects**: none.
- **Edge cases and states**: a user not signed in is handled by `AUTH::require_user()`.
- **v3 coverage**: not applicable (navigation only).

### Account Overview: `tracking202/overview/index.php`

- **Where/who**: Overview tab, strip entry "Campaign Overview"; the page title is "Account Overview" and the heading "Account overview". Any signed-in user.
- **Purpose**: "How every campaign and landing page is performing in the window you choose."
- **Data shown**:
  - Above the report, three optional parts:
    - A warning when the account's queued traffic-source postbacks are stuck or overdue (`OutboxBacklog`), with an "Open Settings" link to `202-account/administration.php#autocron`. Not shown when the queue cannot be read (logged).
    - For a role with Setup and campaign data: "Your Google Ads conversion uploads have stopped." when the old Google conversion feed is still being fetched (`LegacyGoogleFeedFetches`), linking to `tracking202/setup/offline_conversions.php#old-feed`.
    - The LTV strip, loaded from `tracking202/ajax/ltv_snapshot.php` (POST, empty body): panel "Customer lifetime value", all time, tiles Customers, Revenue, Avg LTV (per customer), Repeat rate (bought more than once), MRR (monthly recurring) and Monthly churn (of subscribers), and a "Full report" button to `tracking202/analyze/ltv.php`. It needs `access_to_campaign_data`; an empty answer (no customers, or a failed read, which is logged) or a failed request removes the strip. The strip's money is shown with `$` whatever the account currency.
  - Panel "Performance" (fragment `tracking202/ajax/account_overview.php`):
    - **Chart** (only with `access_to_campaign_data`): a Highcharts line chart of the lines the user chose (stored in `202_charts.data`; by default one line, all campaigns' clicks), by hour or by day (`202_charts.chart_time_range`, default days). Title "From d/m/Y to d/m/Y", subtitle the currency note; x labels "M d h:iA" by hour or "M d" by day; a missing point is 0. Series are named "<Figure> (<campaign name> or all)". A failed read shows "The chart could not be read; the server log says why. This is not a statement that there were no clicks."
    - **"Direct links and landing pages"** table: one row per landing page (nickname) and "[direct link]" for clicks with none; label column "Direct link / landing page".
    - **"Campaigns"** table: one row per campaign, labelled "network · campaign"; clicks with no campaign are "[Landing Page/Smart Redirector Campaign]".
    - **"By traffic source"** sections: for each campaign (direct link and simple landing page traffic) and for each advanced landing page, a table labelled "Traffic source account" with one row per "network · account" ("[No traffic source account]" when none), the last row being that campaign's or page's totals.
    - All tables use the metrics table above. A failed read shows "The overview tables could not be read; the server log says why. This is not a statement that there were no clicks."
- **Inputs**: the filter bar with Date range, Clicks and Costs only (`user_pref_show`, `user_cpc_or_cpv`). Only the Clicks filter is applied to the tables; the per-traffic-source tables apply no Clicks filter.
- **Actions**:
  - Apply/Reset the filter bar (GET, see Saved preferences).
  - Chart range radios "By hour" / "By day": `p202-overview.js` POSTs `chart_time_range=hours|days` to `tracking202/ajax/charts.php` and redraws the chart from the JSON answer `{json: {series}, categories, title, subtitle}`.
  - "Choose what the chart shows" opens a modal with one row per line: Campaign (`data_level[]`: "All campaigns" or one of the account's campaigns) and Figure (`data_type[]`: Clicks, Click throughs, CTR, Leads, Average S/U, Average payout, Average EPC, Average CPC, Income, Cost, Net, ROI), "Remove", "Add a line", Cancel and "Draw the chart". Submit POSTs `levels[i][id]` and `types[i][type]` to `charts.php`, which answers `{saved: n}`; the modal closes and the panel reloads. A refusal shows "The chart was not saved: <the server's sentence>" in the modal.
  - `charts.php` details: POST only (405 "The chart is changed with a POST from the Overview."); form token required (403 "Invalid or expired form token. Reload the page and try again."); 403 "Your role is not shown campaign data, so it has no chart." without `access_to_campaign_data`; a range other than hours or days is 422 "chart_time_range must be one of: hours, days."; lines are limited to 50, each campaign id up to 8 digits and each figure from the list (422 otherwise).
- **Writes and side effects**: the GET filter write to `202_users_pref`; `charts.php` writes `202_charts.chart_time_range` or `202_charts.data` (serialized line list), inserting the user's row with the default lines (clicks, click throughs, leads) when there is none.
- **Edge cases and states**: no clicks shows the empty state; a role without campaign data sees no chart and `?` in the masked columns; the Campaigns table is scoped by `$_SESSION['user_own_id']` while the other tables use `DataScope::userId()` (the same value unless publisher view is set).
- **v3 coverage**: **PARTIAL**. The tables: GET /reports/groups with `by=landing_page`, `by=campaign`, `by=campaign,ppc_account` and `by=landing_page,ppc_account` (groups use outer joins, so a "none" group stands in for "[direct link]" and the no-campaign row; GET /reports/breakdown uses inner joins and drops those rows). The chart data: GET /reports/timeseries with `interval=hour|day` and `group_by=campaign`. The chart range: GET/PUT /users/{id}/preferences (`chart_time_range`). The LTV strip: GET /ltv/summary and GET /ltv/mrr. The postback queue warning: GET /system/cron answers `traffic_source_postbacks` for every account, behind the Settings permission, not this account's. Missing: the saved chart lines (`202_charts.data`) and the old Google feed notice.

### Breakdown analysis: `tracking202/overview/breakdown.php`

- **Where/who**: strip entry "Breakdown Analysis"; title "Breakdown analysis". Any signed-in user.
- **Purpose**: the account's figures over time, one row per hour, day, month or year.
- **Data shown**: panel "Breakdown", fragment `tracking202/ajax/sort_breakdown.php`: caption "Your figures per <hour|day|month|year>", then the metrics table with a "Time" column labelled "Mon dd, YYYY at h AM" by hour, "Mon dd, YYYY" by day, "Mon YYYY" by month and "YYYY" by year, in time order, in the account's zone. Spend ledger note and currency notice above.
- **Inputs**: Date range, Group by (`user_pref_breakdown`), Traffic source, Campaign, Clicks, and every Advanced click filter, plus Costs.
- **Actions**: Apply/Reset; client-side column sort. Fragment POST with `offset` (ignored; no paging).
- **Writes and side effects**: the GET filter write to `202_users_pref`.
- **Edge cases and states**: empty state; masked columns; a Rows preference (`user_pref_limit`) is read into the filters but this report's SQL does not apply it.
- **v3 coverage**: **PARTIAL**: GET /reports/timeseries (`interval=hour|day|week|month`, every bar filter). It has no year interval.

### Day parting: `tracking202/overview/day-parting.php`

- **Where/who**: strip entry "Day Parting"; page title "Hourly Overview", heading "Day parting". Any signed-in user.
- **Purpose**: the window's figures by hour of the day, all days together.
- **Data shown**: panel "By hour of the day", fragment `tracking202/ajax/sort_hourly.php`: the metrics table with an "Hour" column ("12 AM" … "11 PM", local hour), ordered by hour.
- **Inputs**: Date range, Traffic source, Campaign, Clicks, every Advanced click filter, Costs.
- **Actions**: Apply/Reset; client-side sort.
- **Writes and side effects**: the GET filter write.
- **Edge cases and states**: empty state; masked columns; only hours that have clicks appear.
- **v3 coverage**: GET /reports/daypart.

### Week parting: `tracking202/overview/week-parting.php`

- **Where/who**: strip entry "Week Parting"; title "Week parting". Any signed-in user.
- **Purpose**: the window's figures by day of the week.
- **Data shown**: panel "By day of the week", fragment `tracking202/ajax/sort_weekly.php`: the metrics table with a "Day" column (Sun … Sat), ordered by weekday number (Sunday first).
- **Inputs**: as Day parting.
- **Actions**: Apply/Reset; client-side sort.
- **Writes and side effects**: the GET filter write.
- **Edge cases and states**: empty state; masked columns; only days that have clicks appear.
- **v3 coverage**: GET /reports/weekpart.

### Group overview: `tracking202/overview/group-overview.php`

- **Where/who**: strip entry "Group Overview"; title "Group overview". Any signed-in user.
- **Purpose**: "All of your traffic grouped by up to four dimensions, one inside the other."
- **Data shown**: panel "Grouped report", fragment `tracking202/ajax/group_overview.php` (`ReportSummaryForm`): a caption "Report for m/d/Y to m/d/Y · report run on: m/d/Y h:i a", then one table whose rows are the groups indented by depth (the outermost in bold), sorted by name, with columns Group, Clicks, Click throughs, LP CTR, Leads, S/U, Payout, EPC, CPC, Income, Cost, Net, ROI and a last row "Totals for report". A parent's figures are the sums of its children. Payout = income / leads, EPC = income / clicks, CPC = cost / clicks, S/U = leads / clicks, ROI = net / cost x 100 rounded. Money is shown with `$` and 2 decimals whatever the account currency. When a Transaction ID, Goal / source or Match method level is chosen, a note says those rows add up the conversions and that a click's other figures go to its latest counted conversion.
- **Grouping levels** (value: label): 1 Traffic Source, 2 Traffic Source Account, 3 Category, 4 Campaign, 5 Landing Page, 10 Keyword, 11 Text Ad, 12 Referer, 16 Country, 23 Region, 17 City, 18 ISP/Carrier, 20 Device name, 24 Device type, 21 Browser, 22 Platform, 14 IP, 25 Utm_campaign, 26 Utm_content, 27 Utm_medium, 28 Utm_source, 29 Utm_term, 6 c1, 7 c2, 8 c3, 9 c4, 32 Rotator, 33 Rotator Rule, 34 Rotator Rule Redirect, 35 Transaction ID, 37 Goal / source, 38 Match method, 36 Publisher/User. Default Group by is Traffic Source.
- **Inputs**: Date range, the four groupings, Traffic source, Campaign, Clicks, every Advanced click filter, Costs. Method of promotion is offered but not applied by this report; the Rows preference is not applied either.
- **Actions**:
  - Apply/Reset.
  - "Download to Excel" (shown only when the filters were accepted): a link to `tracking202/overview/group_overview_download.php` carrying `view`.
  - The table is not sortable in the browser.
- **Download** (`group_overview_download.php`): sign-in required; the view is read with the groupings. The answer is tab-separated text sent as `application/octet-stream` with the file name `T202_group_overview_<unix time>.xls`. Lines: "Group Overview Report for m/d/Y to m/d/Y", "report run on: …", "Currency: X" (with the other currencies in the period, added as recorded and not converted), a blank line, then a header row and one row per deepest-level group only (no parent rows, no totals). Header: for each chosen level its columns ("Traffic Source Id"/"Traffic Source Name" and the same for each id-and-name level such as Campaign, Landing Page, Text, Device, Type, IP and c1; one column for the Utm levels, c2 to c4, and the ledger levels), then Clicks, Click Throughs, LP CTR, Leads, S/U, Payout, EPC, CPC, Income, Cost, Net, ROI. Payout, EPC, Income, Cost and Net are "$" plus 2 decimals; S/U is a percentage to 2 decimals; ROI is the raw number; masked values are `?`. With no rows the body says "no data for this selected date range". Quirks: CPC is written as cost per click x 100 with a "$"; the Rotator Rule Redirect cell is written when Rotator Rule is chosen rather than when Rotator Rule Redirect is; the device type column is filled from the device id.
- **Writes and side effects**: the GET filter write (including `user_pref_group_1` to `_4`).
- **Edge cases and states**: empty state; masked figures; a database error goes to the database error page (`record_mysql_error()`).
- **v3 coverage**: **PARTIAL**: GET /reports/groups (`by=` one to four dimensions, every bar filter, `sort`, `sort_dir`). It has no Transaction ID, Goal / source or Match method level, it refuses an answer over 5000 leaf groups where the page draws all, and it answers JSON rather than the spreadsheet file.

### Rotator breakdown: `tracking202/overview/rotator-breakdown.php`

- **Where/who**: not in the Overview strip (reached by URL); page title "Redirectors Breakdown Overview", heading "Rotator breakdown". Any signed-in user.
- **Purpose**: how each rotator and each of its rules performed in the window.
- **Data shown**: panel "Rotators", fragment `tracking202/ajax/sort_rotator.php` (`tracking202/Report/RotatorBreakdown.php`), read from the click tables (`202_clicks` joined to `202_clicks_rotator`, latest visit of each click), not the rollup. One table: each rotator in bold, then each of its rules in rule order with a "Criteria and redirects" detail ("If <criterion>", "Sends to campaign/landing page/<url> (weight n)", or "No redirect configured"), rows for rules since deleted ("Deleted rule #id, since removed; its clicks in this window"), and "Default when no rule matches". Columns Rotator, Clicks, Leads, S/U, Payout, EPC, Avg CPC, Income, Cost, Net, ROI; last row "Totals for report". Income = sum of payout of converted clicks; cost = sum of click CPC; S/U = leads / clicks.
- **Inputs**: Date range, Clicks and Costs.
- **Actions**: Apply/Reset.
- **Writes and side effects**: the GET filter write.
- **Edge cases and states**: with no rotators, "No rotators yet" / "A rotator sends each click to a campaign, landing page or URL by rules you set. Its figures appear here once it has one." and "Set up a rotator" to `tracking202/setup/rotator.php`. Rotators are read by `$_SESSION['user_id']`. Masked figures for a role without campaign data. Quirks: in CPV mode the CPC, Income, Cost and Net cells are formatted with the CPV flag passed where the currency is expected, so they show a leading "1" instead of the currency sign (`dollar_format(x, true)`, executed: 1.234 gives "11.23"); Payout and EPC always show "$"; ROI has 0 decimals on rotator rows and 2 on rule and default rows.
- **v3 coverage**: GET /rotators, then GET /rotators/{id}/stats for each (rules, deleted rules, default and totals, every bar filter) and GET /rotators/{id}/rules for the criteria and redirects. The v3 figures come from `202_dataengine`, the page's from the click tables.

## Missing v3 endpoints

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Account Overview | Saves which lines the chart draws (campaign and figure per line, up to 50), via `charts.php` into `202_charts.data` | GET/PUT /users/{id}/preferences (only `chart_time_range`) | No route reads or writes the chart's lines |
| Account Overview | Warns that the old Google conversion feed is still being fetched | none | No route reports `LegacyGoogleFeedFetches` |

## Partial v3 coverage

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Account Overview | Postback queue warning for this account | GET /system/cron | Answers every account's backlog and needs the Settings permission |
| Account Overview | Tables by landing page, campaign and traffic source account | GET /reports/groups | Same figures by composing `by=`; no single route answers the page's sections, and the per-campaign split into direct link/simple LP and advanced LP traffic has to be rebuilt by the client |
| Breakdown analysis | Figures per hour, day, month or year | GET /reports/timeseries | No `year` interval |
| Group overview | Up to four nested groupings | GET /reports/groups | No Transaction ID, Goal / source or Match method levels; refuses more than 5000 leaf groups |
| Group overview download | Spreadsheet (tab-separated) file of the leaf groups with header lines | GET /reports/groups | JSON only; no file with the report and currency header lines |
| Filter bar (all pages) | Saved report filters (every bar field, groupings, Group by, Clicks) that every report opens with | GET /users/{id}/preferences | GET serves the columns; PUT writes only `user_pref_limit`, `user_pref_time_predefined`, `user_cpc_or_cpv` and `chart_time_range`, not the filters or groupings (v3 reports take filters per request instead) |
| Filter bar (all pages) | Option lists for Country, Device type and Publisher | GET /reports/breakdown (`breakdown=country`, `device_type`, `publisher`) | Lists only values seen in a window; no route lists the install's countries, the device types or the publishers an account may credit |

## Not determined from code

- Whether publisher view is ever active: the bar hides filters and `DataScope::userId()` widens the scope when `$_SESSION['publisher']` is set, and no code in the tree writes that key (searched for writes of `$_SESSION['publisher']`).
- Whether anything still posts to `tracking202/ajax/set_user_prefs.php`: its comments name `display_calendar()`, which is not defined anywhere in the tree; no page or script references the endpoint.
- Whether Rotator breakdown is linked from anywhere outside the PHP tree (for example documentation or an email); no link was found in the served PHP files.
