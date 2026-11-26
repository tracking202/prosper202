# Analyze reports

This part covers the Analyze tab of the old UI. That is the redirect at `tracking202/analyze/index.php`, the thirteen dimension reports and the Publishers report. The Mobile Apps and Customer LTV pages are in other parts of the inventory. Each dimension report has a download script, and those are described inside the page they belong to. So are the AJAX endpoint the reports use for region and ISP suggestions (`tracking202/ajax/analyze_filter_choices.php`) and the two AJAX endpoints this part was assigned that no Analyze page calls (`tracking202/ajax/revoke_publisher.php`, `tracking202/ajax/custom_variables.php`); those two are written up at the end with their real callers. The thirteen dimension pages are each a two-line file, `(new AnalyzeReportController('<type>'))->handleRequest()`. They share one controller (`tracking202/analyze/AnalyzeReportController.php`), one template (`tracking202/analyze/templates/report.php`) and one report engine (`202-config/class-dataengine.php`). That shared behaviour is written up once, in full, under "Shared behaviour of the dimension reports". Each page section after it lists only how that page differs.

| Page | Path | Permission |
|---|---|---|
| Analyze (redirect) | `tracking202/analyze/index.php` | signed in |
| Keywords | `tracking202/analyze/keywords.php` | signed in |
| Text Ads | `tracking202/analyze/text_ads.php` | signed in |
| Referers | `tracking202/analyze/referers.php` | signed in |
| IP Addresses | `tracking202/analyze/ips.php` | signed in |
| Countries | `tracking202/analyze/countries.php` | signed in |
| Regions | `tracking202/analyze/regions.php` | signed in |
| Cities | `tracking202/analyze/cities.php` | signed in |
| ISPs and Carriers | `tracking202/analyze/isp.php` | signed in |
| Landing Pages | `tracking202/analyze/landing_pages.php` | signed in |
| Devices | `tracking202/analyze/devices.php` | signed in |
| Browsers | `tracking202/analyze/browsers.php` | signed in |
| Platforms | `tracking202/analyze/platforms.php` | signed in |
| Custom Variables | `tracking202/analyze/variables.php` | signed in |
| Publishers | `tracking202/analyze/publishers.php` | signed in + `access_to_campaign_data` |

"Signed in" means `AUTH::require_user()`. That check also requires a valid API licence key (see below).

---

## Shared behaviour of the dimension reports

This section applies to every page from Keywords to Custom Variables. The Custom Variables page's table and download differ, and its own section says how.

### Where / who (shared)
- **Menu.** Every signed-in user sees the Analyze tab in the top bar (`tracking202/_config/top.php`). The sub-menu strip (`tracking202/_config/sub-menu.php`) lists these entries, in this order: Keywords, Text Ads, Referers, IPs, Countries, Regions, Cities, ISP/Carrier, Landing Pages, Devices, Browsers, Platforms, Custom Variables. Customer LTV and Publishers are added only when the user holds `access_to_campaign_data`. Mobile Apps is added only when the user holds `view_attribution_reports`.
- **Login.** `AUTH::require_user()`:
  - A signed-out browser is shown `202-access-denied.php`.
  - A script request is answered `401` with the text "You are signed out. Sign in again, then reload the page." A script request is one sent with `X-Requested-With: xmlhttprequest`, or with `Sec-Fetch-Dest: empty`.
  - Without a confirmed licence key the user is redirected to `api-key-required.php`, with `?licence=unconfirmed` added when the licence service gave no answer.
- **Permissions.** The dimension pages and their downloads ask for no permission beyond being signed in.
- **Campaign figures.** A user without `access_to_campaign_data` still gets the page, but the figures are masked by `CampaignDataMask`. Clicks, leads and every money cell show `?`. The note under the table says: "Your account cannot see campaign figures, so clicks, leads and money show as “?”."
- **Whose clicks.** `DataScope::userId()` decides whose clicks are read. The SQL is `2st.user_id = <id>`. When the scope is every account the SQL is `2st.user_id != 0` instead.
- **Publisher session.** If `$_SESSION['publisher']` is non-empty, the controller drops and saves as `''` these filters (`PUBLISHER_HIDDEN`): `ppc_network_id`, `aff_campaign_id`, `ppc_account_id`, `aff_network_id`, `landing_page_id`, `text_ad_id`, `method_of_promotion`.

### Inputs (shared): the filter bar
The URL holds the report (`ReportFilterInput::fromQuery`). If the URL names any offered field, or `range`, `from` or `to`, or `region` or `isp`, then:
- the request is saved as the user's report preferences in `202_users_pref`;
- every offered field the URL does not name is saved as "not filtering";
- if no range is given, the stored window is kept.

A URL that names nothing shows the stored preferences. The saved filters are shared with the user's other reports: the bar says "What you apply here becomes your default on every report."

The form is `p202_report_filter_bar`, a `GET` form with id `report-filters` that submits to the page itself. Its fields, in the order the controller lists them (`FILTERS`):

| name | Label | Type | Choices / rule | Default |
|---|---|---|---|---|
| `range` | (date range) | select (`p202_date_range`) | `today`, `yesterday`, `last7`, `last14`, `last30`, `thismonth`, `lastmonth`, `thisyear`, `lastyear`, `alltime`, `custom` | stored |
| `from`, `to` | start / end | date inputs (ISO `YYYY-MM-DD`) | Also accepted: `mm/dd/yyyy` and `mm/dd/yy`; the year must be 1970 or later. Dates with no `range` are read as `custom`. | stored |
| `ppc_network_id` | Traffic source | select | the user's undeleted `202_ppc_networks`, plus "No traffic source" (value `16777215`) | none |
| `aff_campaign_id` | Campaign | select, grouped by category | the user's campaigns | none |
| `user_pref_show` | Clicks | select | `all`, `real`, `filtered`, `filtered_bot`, `leads` | `all` |
| `ppc_account_id` | Traffic source account (Advanced) | select, grouped by traffic source | accounts whose source is the user's and not deleted | none |
| `aff_network_id` | Category (Advanced) | select | the user's `202_aff_networks` | none |
| `landing_page_id` | Landing page (Advanced) | select | grouped by campaign, "Advanced landing pages" (type 1), or "No campaign" | none |
| `text_ad_id` | Text ad (Advanced) | select | grouped by campaign, or "No campaign" | none |
| `method_of_promotion` | Method of promotion (Advanced) | select | `directlink`, `landingpage` | `''` |
| `country_id` | Country (Advanced) | select | `202_locations_country`, `MIN(id)` per name | none |
| `region` (saved as `region_id`) | Region (Advanced) | text with suggestions, placeholder "Start typing" | Typed "Name (CC)" or a bare name, resolved to an id. Hint: "Suggestions are the regions your clicks in this range came from." | none |
| `isp` (saved as `isp_id`) | ISP/Carrier (Advanced) | text with suggestions, placeholder "Start typing" | Typed name, resolved to an id. Hint: "…networks your clicks in this range came through." | none |
| `device_id` | Device type (Advanced) | select | `202_device_types` | none |
| `browser_id` | Browser (Advanced) | select | `202_browsers`, `MIN(id)` per name | none |
| `platform_id` | Platform (Advanced) | select | `202_platforms`, `MIN(id)` per name | none |
| `publisher_id` | Publisher (Advanced) | select | `PublisherCredit::grantable()`. Hidden when there are no publishers and none is selected. | none |
| `ip` | Visitor IP (Advanced) | text | at most 100 characters; must pass `FILTER_VALIDATE_IP` | `''` |
| `keyword` | Keyword (Advanced) | text | at most 100 characters | `''` |
| `referer` | Referer (Advanced) | text | at most 100 characters | `''` |
| `user_pref_limit` | Rows (Advanced) | select | `10`, `25`, `50`, `75`, `100`, `150`, `200` | `50` |
| `user_cpc_or_cpv` | Costs (Advanced) | select | `cpc`, `cpv` | `cpc` |
| `order` | (hidden) | hidden | carries the sort | `''` |
| `page` | (pager links only) | query | `^[1-9]\d{0,6}$` | 1 |

The subid filter of older versions is no longer offered.

**Validation messages** (`ReportFilterInput`):
- ID fields: `''` or `0` mean no filter. Otherwise the value must match `^[1-9]\d{0,9}$`, or the field is refused with "That is not one of the choices on the list."
- An array sent for any single field: "That is not a single value." For `region` or `isp`: "That is not a name."
- A text over 100 characters: "Keep this under 100 characters; the saved filter holds no more."
- A bad IP: "<raw> is not an IP address."
- Ranges:
  - A range not on the list: "That is not one of the ranges on the list."
  - `custom` without both dates: "Choose a start and an end date for a custom range."
  - A start after the end: "The start date is after the end date."
- A typed region or ISP name is used only when the URL gives no `region_id` / `isp_id` (`ReportPrefsStore::resolveName`):
  - No match: "There is no region called “X”. Choose one of the suggestions." The ISP message is the same with ISP in place of region.
  - A bare region name found in more than one country: "More than one country has a region called “X”: choose X (AA), X (BB)."
  - When one name has several ids, the id this account's clicks used most in the window is taken.
- A value that does not fit its storage column rolls back the whole save: "This value (X) does not fit the column the saved filter is kept in, so it was not applied." The region and ISP preference columns are TINYINT.
- If anything blocks the save:
  - nothing is applied;
  - the page flashes (bad) "Nothing you asked for was applied, because of the field marked below. The report still shows your previous filters.";
  - the bar shows the submitted values, with the error under each refused field.
- `page` that cannot be read: the first page is shown, with the warning "That page number could not be read, so this is the first page."
- `order` that is not a sort token: the report keeps its default order, with the warning "That sort could not be read, so the report is in its usual order: most leads first." A sort token is `sort_breakdown_{clicks|click_throughs|ctr|leads|su_ratio|payout|epc|cpc|income|cost|net|roi} asc|desc` (`ReportDispatchRequest`).

**How the bar shows stored values:**
- A stored id that is not in its list shows as "X (not in your list)".
- Stored region and ISP ids are shown as their names (`ReportPrefsStore::nameOf`).
- The Advanced section is a `<details>` with remember key `analyze-report-advanced`. It opens by itself and shows "N set" when any Advanced filter is set.
- The note under the bar reads "<range label> · <clicks label> · <limit> rows a page · CPC|CPV costs. What you apply here becomes your default on every report."

**Buttons:**
- **Apply** submits the form.
- **Reset** is a link that clears every filter and the order.
- **Download to Excel** (aside) links to the page's download script with `?view=<urlencoded view query>`, so the download reads the same view as the page (`p202_report_view_query`).

### Data shown (shared): the flat table
`p202_data_table`, id `stats-table`. The page header shows the report's icon, title and description, then the flashes, then the other-currency notice from `RevenueCurrencies::notice`. The currency notice is not shown when figures are masked.

The columns come from `MetricsSql::GROUPED_SELECT`. Each ratio is 0 when its divisor is 0.

| Column | Formula |
|---|---|
| (feature: the page's dimension) | see each page |
| Clicks | `SUM(clicks)` |
| Click throughs | `SUM(click_out)` |
| CTR | click_out / clicks × 100, shown with % |
| Leads | `SUM(leads)` |
| Avg S/U | SUM(click_lead) / SUM(clicks) × 100, shown with % |
| Avg payout | income / leads |
| Avg EPC | income / clicks |
| Avg CPC | cost / clicks |
| Income | `SUM(income)` |
| Cost | `SUM(cost)`, shown in parentheses |
| Net | income − cost, toned green or red |
| ROI | (income − cost) / cost × 100, shown with %, toned green or red |

**Formatting** (`HtmlReportFormatter`):
- Counts go through `number_format`. Money goes through `dollar_format` in the account's currency.
- Values are HTML-entity escaped.
- `FlatReportPayloadBuilder` cuts the feature cell at 250px for keyword and referer, and at 240px for landing page.

**Grouping.** Every flat report groups by the dimension's name, not its id, so two ids with one name are one row. The query is `SELECT label, COUNT(*) OVER () AS report_group_count, GROUPED_SELECT FROM <click table or aggregate> <joins> WHERE <scope> AND click_time BETWEEN <window> <filters> GROUP BY <name> ORDER BY <sort>, <name> LIMIT offset*limit, limit`. A page past the end returns no rows, so a separate count is run to draw the pager. The totals row is summed over the page's rows (`ReportTotals`).

**Totals row.** "Totals for this page" when there is more than one page; "Totals for report" otherwise.

**Sort:**
- Default: leads descending, then the name.
- On a single page, sorting is done in the browser (tablesort) and any column can be sorted.
- With more than one page, the header links sort on the server through `order`. The first click on a column sorts descending, the next ascending. The feature column cannot be sorted on the server.

**Pagination.** ‹ and › links, a window of the current page ±2, and "Page X of Y · N rows". The rows per page are `user_pref_limit`.

**Costs.** The spend ledger note (`LedgerClicks`, spend-ledger costing) is flashed as info when it applies.

**CPC/CPV setting.** `user_cpc_or_cpv` is saved and passed to `getReportData()` as `cpv`, but the formatter ignores it. Read from the code, the setting changes no figure on these pages.

**Filters in the SQL** (`UserPrefFilters`):
- `user_pref_show` replaces the subid filter.
- `device_id` filters by device type: `2st.device_id IN (SELECT device_id FROM 202_device_models WHERE device_type = N)`.
- "No traffic source" is `(ppc_network_id IS NULL OR ppc_network_id = 0)`.
- `publisher_id` goes through `PublisherCredit::creditedClicksCondition`.
- `method_of_promotion`: `directlink` is `landing_page_id = 0`; `landingpage` is `landing_page_id != 0`.
- `keyword` and `referer` are case-insensitive contains (`TextFilterSql`), with `%` and `_` taken literally.
- `ip` is an exact match; IPv6 is compared packed.
- A stored text filter that is no longer valid adds `0=1`, so the report is empty.
- The row limit applies to the page, not to downloads.

### Actions (shared)
- Apply, Reset, Download to Excel, the sort header links and the pager links, as above.
- **AJAX, region and ISP suggestions.** The page loads `202-js/p202-overview.js` through `p202_overview_choices_script`.
  - The calls start when the Advanced `<details>` is first opened. The script calls `jQuery.getJSON(choices_url)` once per suggest field (Region, then ISP), one request at a time.
  - `choices_url` is `tracking202/ajax/analyze_filter_choices.php?filter=region_id|isp_id&from=<unix>&to=<unix>`.
  - It expects `{options:[{value,label}]}` and fills the field's `<datalist>` without overwriting what the user has typed.
  - Status lines: "Loading choices for this date range…", "Choices loaded.", "No choices in this date range."
  - On failure it shows a "Retry loading choices" button and the error: the JSON's `error`, or else the HTTP status.

**`tracking202/ajax/analyze_filter_choices.php`** (GET):
- Requires a signed-in user. Sends `Cache-Control: no-store`.
- Parses the request with `p202_overview_filter_choices_request($_GET)`:
  - `filter` must be a known list; otherwise "Choose a valid report filter."
  - `from` and `to` must be 1 to 10 digits; otherwise "Choose a valid report date range."
  - `from` must not be after `to`: "The report start must not be after its end."
  - An optional `current` matches `^(|0|[1-9]\d{0,18})$`.
  - `filter` must then be `region_id` or `isp_id`, or the request is refused with "Choose a valid named report filter."
  - Every one of these refusals (`InvalidArgumentException`) is answered `400 {"error":"<message>"}`.
- Closes the session, then returns `ReportPrefsStore::suggestions(kind, DataScope, from, to, 300, cache)` as `{"options":[{"value":<label>,"label":<label>}]}`.
- The suggestions are the region or ISP names in `202_dataengine` for the window (`ReportAggregation`), busiest first, at most 300. Regions are labelled "Name (CC)".
- They are cached by `FilterChoicesCache` under `analyze-names-v2-<kind>`.
- A `RuntimeException` or `JsonException` is answered `503 {"error":"The suggestions could not be loaded. Try again."}`.

### Downloads (shared)
Each `<name>_download.php`:
- Requires a signed-in user.
- Sends:
  - `Content-type: application/octet-stream`
  - `Content-Disposition: attachment; filename=T202_<name>_<unix time>.xls`
  - `Pragma: no-cache`
  - `Expires: -1`
- Reads the view with `p202_report_view_begin()`. A `view` it cannot read is answered `400` with the HTML flash "<msg> Reload the report page and try again.", and the Content-Disposition header is removed.
- Then runs `grab_timeframe`, `setDownload` (which removes the row LIMIT, so every row is written), `getReportData`, and `DisplayData::downloadReport`.

**Format.** Tab-separated text in an `.xls` file:
- Header row: the feature label, then `Clicks`, `Click Throughs`, `LP CTR`, `Leads`, `S/U`, `Payout`, `EPC`, `Avg CPC`, `Income`, `Cost`, `Net`, `ROI`, `Currency`, `Other currencies in period`.
- Values are the formatter's display strings: entity-escaped, not decoded, and with currency signs and `%`.
- Rows whose feature is false or empty are skipped. Device, browser and platform write "Unknown" instead.
- There is no totals row.
- A masked viewer gets `?` cells.
- `TsvCell` puts `'` before a leading `=`, `+`, `-` or `@` (formula guard), and turns tabs and newlines into spaces.

### Writes and side effects (shared)
- Applying the bar runs `UPDATE 202_users_pref SET user_pref_*` inside one transaction, then reads the row back (`ReportPrefsStore::save`).
  - A custom window stores `user_pref_time_predefined = ''` and `user_pref_time_from` / `_to` by `mktime` in the user's zone, at 00:00:00 and 23:59:59.
  - A preset stores its name, with from and to NULL.
  - A missing preferences row throws `RuntimeException`.
- Suggestion results are cached (`FilterChoicesCache`).
- A report that fails is logged and flashed (bad): "The report could not be read. Try again in a moment; if it keeps happening, the server log says why." The table is replaced by the "The report could not be read" state.

### Edge cases and states (shared)
- Empty states:
  - With filters set: "No clicks match these filters", with a "Clear the filters" button.
  - With a range other than all time: "No clicks in this range", with "Show all time".
  - Otherwise: "No clicks yet".
- A page past the end: "This page is past the end of the report", with "Go to the last page".
- The page's "all time" window starts at the user's `user_time_register`.
- The DataEngine formatter's currency read throws on failure.

### v3 coverage (shared)
- **Report rows.** **PARTIAL**: `GET /reports/breakdown?breakdown=<dimension>` (`ReportsController::breakdown`).
  - It groups by id, not name, with an INNER JOIN. So the "[no keyword]" / "[direct link]" style row for clicks without the value is dropped, and two ids sharing a name are two rows.
  - Its metrics are `total_clicks`, `total_click_throughs`, `total_leads`, `total_income`, `total_cost`, `total_net`, `epc`, `avg_cpc`, `conv_rate` (leads / click_out), `roi` and `cpa`. It has no CTR, no S/U and no average payout.
  - It has no totals row and no total row count (only a truncated flag), so the page's "Page X of Y · N rows" cannot be drawn.
  - `limit` is 1–500 (default 50), with `offset`. `sort` is one of those metrics; the default is `total_clicks` DESC, not leads.
  - The filters (`ReportFilter`) cover every filter on the bar: `ppc_network_id` (with none / 16777215), `ppc_account_id`, `aff_network_id`, `aff_campaign_id`, `landing_page_id`, `text_ad_id`, `method_of_promotion`, `country_id`, `region_id`, `isp_id`, `device_type` (the bar's `device_id`), `browser_id`, `platform_id`, `publisher_id`, `ip`, `keyword`, `referer` and `show`.
  - The window is `time_from` / `time_to` or `period`. `period` also offers `last90`, and its `alltime` has no bounds, while the page's starts at registration.
  - There is no CPV option, and it has no effect on the page either.
  - It masks rather than refuses without `access_to_campaign_data`, and gives `currency`, `other_currencies` and the spend-ledger meta.
- **Filter menu lists.** **PARTIAL**: `GET /ppc-networks`, `GET /ppc-accounts`, `GET /aff-networks`, `GET /campaigns`, `GET /landing-pages` and `GET /text-ads` exist, but each requires `access_to_setup_section`, which the pages do not.
  - **MISSING**: the country, browser, platform and device-type lists, and the grantable-publisher list.
- **Region and ISP suggestions.** **PARTIAL**: `GET /reports/breakdown?breakdown=region|isp` over the window approximates them, but it gives ids and names per id, not the merged, "Name (CC)"-labelled list the page offers. Resolving a typed name to an id is **MISSING**.
- **Saving the bar.** **PARTIAL**: `GET /users/{}/preferences` serves every `user_pref_*`. `PUT /users/{}/preferences` writes only `user_pref_limit`, `user_pref_time_predefined` and `user_cpc_or_cpv` of the report preferences, and requires `access_to_personal_settings`. The filter fields and a custom from/to cannot be saved through v3.
- **Downloads.** **MISSING**: no v3 route returns a report as a file (TSV/CSV). A client would have to page `GET /reports/breakdown` and build the file itself.

---

### Analyze (redirect): `tracking202/analyze/index.php`
- **Where / who**: The URL of the Analyze tab. Requires a signed-in user (`AUTH::require_user`, refusals as above). No permission check.
- **Purpose**: Sends the user to the first Analyze report.
- **Data shown**: none.
- **Inputs**: none.
- **Actions**: answers with `Location: /tracking202/analyze/keywords.php`.
- **Writes and side effects**: none.
- **Edge cases and states**: none beyond the sign-in refusal.
- **v3 coverage**: none needed (navigation only).

### Keywords: `tracking202/analyze/keywords.php`
- **Where / who**: Analyze › Keywords; the shared rules.
- **Purpose**: "Clicks, leads and profit for each keyword your traffic sources passed." The page title is "Analyze Your Keywords", with the bi-key icon.
- **Data shown**: The feature column is the keyword, from `LEFT JOIN 202_keywords 2k`, grouped by `keyword`. A click without one shows "[no keyword]". The cell is cut at 250px.
- **Inputs / Actions / Writes / Edge cases**: shared.
- **Download**: `keywords_download.php`, `T202_keywords_<time>.xls`, first header cell "Keyword".
- **v3 coverage**: **PARTIAL**, `GET /reports/breakdown?breakdown=keyword`, with the shared gaps; the "[no keyword]" row is dropped. Download **MISSING**.

### Text Ads: `tracking202/analyze/text_ads.php`
- **Where / who**: Analyze › Text Ads; the shared rules.
- **Purpose**: "How each of your text ads performed, from click to profit." Title "Analyze Your Text Advertisements", icon bi-card-text.
- **Data shown**: The feature column is `text_ad_name`, from `LEFT JOIN 202_text_ads` with the ad's `user_id` tied to the click's. It is grouped by name; the aggregate groups by `user_id` and `text_ad_id`. A click with no ad, or with another account's ad, shows "[no text ad]".
- **Inputs / Actions / Writes / Edge cases**: shared.
- **Download**: `text_ads_download.php`, `T202_text_ads_<time>.xls`, "Text ad".
- **v3 coverage**: **PARTIAL**, `GET /reports/breakdown?breakdown=text_ad`. Download **MISSING**.

### Referers: `tracking202/analyze/referers.php`
- **Where / who**: Analyze › Referers; the shared rules.
- **Purpose**: "The sites your visitors came from, and what their clicks earned." Title "Analyze Incoming Referers", icon bi-box-arrow-in-right.
- **Data shown**: The feature column is the referring host (`site_domain_host AS referer_name`), through `202_site_urls` → `202_site_domains`, grouped by host. Missing values show "[no referer]". The cell is cut at 250px.
- **Inputs / Actions / Writes / Edge cases**: shared.
- **Download**: `referers_download.php`, `T202_referers_<time>.xls`, "Referer".
- **v3 coverage**: **PARTIAL**, `GET /reports/breakdown?breakdown=referer` (grouped by domain). Download **MISSING**.

### IP Addresses: `tracking202/analyze/ips.php`
- **Where / who**: Analyze › IPs; the shared rules.
- **Purpose**: "Clicks, leads and profit for each visitor IP address." Title "Analyze Incoming IP Addresses", heading "IP Addresses", icon bi-hdd-network.
- **Data shown**: The feature column is `IFNULL(inet6_ntoa(2i6.ip_address), 2i.ip_address)`, from `LEFT JOIN 202_ips 2i` plus `IpAddressSql::join('2i','2i6')`. That join ties the `202_ips_v6` row to the IPv4 row safely.
  - `inet6_ntoa` is used only when `$_SESSION['ipv6']` is set. `connect.php` sets it on every session after a probe of `inet6_ntoa`, and a failed probe is a fatal database error, so on a working install it is always set.
  - Missing values show "[no ip]".
  - Under privacy mode the stored address is already masked (/24, /48), so the rows are the masked addresses.
- **Inputs / Actions / Writes / Edge cases**: shared.
- **Download**: `ips_download.php`, `T202_ips_<time>.xls`, "IP".
- **v3 coverage**: **PARTIAL**, `GET /reports/breakdown?breakdown=ip` (IPv6 decoded). Download **MISSING**.

### Countries: `tracking202/analyze/countries.php`
- **Where / who**: Analyze › Countries; the shared rules.
- **Purpose**: "Where your visitors are, by country, and what their clicks earned." Title "Analyze Incoming Countries", icon bi-globe2.
- **Data shown**: The feature column is `country_name` with its `country_code`, from `202_locations_country`, grouped by name. The cell shows a flag (`202-img/flags/<cc>.png`) and "Name (CC)". A missing code shows as `non`, and a missing country as "[no country]".
- **Inputs / Actions / Writes / Edge cases**: shared.
- **Download**: `countries_download.php`, `T202_countries_<time>.xls`, "Country". Cells are written as "Name (CC)".
- **v3 coverage**: **PARTIAL**, `GET /reports/breakdown?breakdown=country`. It has no country code, so no flag and no "(CC)". Download **MISSING**.

### Regions: `tracking202/analyze/regions.php`
- **Where / who**: Analyze › Regions; the shared rules.
- **Purpose**: Clicks by state or region. Title "Analyze Incoming Regions", icon bi-map.
- **Data shown**: The feature column is `region_name`, with its country's code through `main_country_id`, grouped by region name. Shown as flag plus "Name (CC)"; missing values show "[no region]".
- **Inputs / Actions / Writes / Edge cases**: shared.
- **Download**: `regions_download.php`, `T202_regions_<time>.xls`, "Region", written as "Name (CC)".
- **v3 coverage**: **PARTIAL**, `GET /reports/breakdown?breakdown=region`, with no country code. Download **MISSING**.

### Cities: `tracking202/analyze/cities.php`
- **Where / who**: Analyze › Cities; the shared rules.
- **Purpose**: Clicks by city. Title "Analyze Incoming Cities", icon bi-geo-alt.
- **Data shown**: The feature column is `city_name` with its country code, grouped by city name. Shown as flag plus "Name (CC)"; missing values show "[no city]".
- **Inputs / Actions / Writes / Edge cases**: shared.
- **Download**: `cities_download.php`, `T202_cities_<time>.xls`, "City", written as "Name (CC)".
- **v3 coverage**: **PARTIAL**, `GET /reports/breakdown?breakdown=city`, with no country code. Download **MISSING**.

### ISPs and Carriers: `tracking202/analyze/isp.php`
- **Where / who**: Analyze › ISP/Carrier; the shared rules.
- **Purpose**: "The networks your visitors connect through…". Title "Analyze Incoming ISP/Carrier", heading "ISPs and Carriers", icon bi-broadcast.
- **Data shown**: The feature column is the ISP name from `202_locations_isp`, grouped by name. Missing values show "[no isp]".
- **Inputs / Actions / Writes / Edge cases**: shared.
- **Download**: `isps_download.php`, `T202_isps_<time>.xls`, "ISP/Carrier".
- **v3 coverage**: **PARTIAL**, `GET /reports/breakdown?breakdown=isp`. Download **MISSING**.

### Landing Pages: `tracking202/analyze/landing_pages.php`
- **Where / who**: Analyze › Landing Pages; the shared rules.
- **Purpose**: "How each landing page converted, direct links included." Title "Analyze Landing Pages", icon bi-window.
- **Data shown**: The feature column is `landing_page_nickname`, with the landing page's user tied to the click's, grouped by nickname. Clicks with no landing page show "[direct link]". The cell is cut at 240px.
- **Inputs / Actions / Writes / Edge cases**: shared.
- **Download**: `landing_pages_download.php`, `T202_landing_pages_<time>.xls`, "Landing Page".
- **v3 coverage**: **PARTIAL**, `GET /reports/breakdown?breakdown=landing_page`. It names rows by `landing_page_url`, not the nickname the page shows, and drops the "[direct link]" row. Download **MISSING**.

### Devices: `tracking202/analyze/devices.php`
- **Where / who**: Analyze › Devices; the shared rules.
- **Purpose**: Clicks by device. Title "Analyze Devices", icon bi-phone.
- **Data shown**: The feature column is `202_device_models.device_name`, the device **model**, grouped by name. Note that the bar's Device type filter (`device_id`) filters by **type**. Missing values show "[no device]".
- **Inputs / Actions / Writes / Edge cases**: shared.
- **Download**: `device_download.php`, `T202_devices_<time>.xls`, "Device". An empty name is written as "Unknown".
- **v3 coverage**: **PARTIAL**, `GET /reports/breakdown?breakdown=device`; there is also `device_type`. Download **MISSING**.

### Browsers: `tracking202/analyze/browsers.php`
- **Where / who**: Analyze › Browsers; the shared rules.
- **Purpose**: Clicks by browser. Title "Analyze Browsers", icon bi-browser-chrome.
- **Data shown**: The feature column is the `202_browsers` name, grouped by name. Missing values show "[no browser]".
- **Inputs / Actions / Writes / Edge cases**: shared.
- **Download**: `browser_download.php`, `T202_browsers_<time>.xls`, "Browser", with "Unknown" for an empty name.
- **v3 coverage**: **PARTIAL**, `GET /reports/breakdown?breakdown=browser`. Download **MISSING**.

### Platforms: `tracking202/analyze/platforms.php`
- **Where / who**: Analyze › Platforms; the shared rules.
- **Purpose**: "The operating systems your visitors used…". Title "Analyze Platforms", icon bi-laptop.
- **Data shown**: The feature column is the `202_platforms` name, grouped by name. Missing values show "[no platform]".
- **Inputs / Actions / Writes / Edge cases**: shared.
- **Download**: `platform_download.php`, `T202_platforms_<time>.xls`, "Platform", with "Unknown" for an empty name.
- **v3 coverage**: **PARTIAL**, `GET /reports/breakdown?breakdown=platform`. Download **MISSING**.

### Custom Variables: `tracking202/analyze/variables.php`
- **Where / who**: Analyze › Custom Variables; the shared rules.
- **Purpose**: "Clicks, leads and profit for each value of the custom variables your traffic sources pass." Title "Analyze Your Custom Variables", heading "Custom Variables", icon bi-braces.
- **Data shown**: The report is nested, not flat (`DataEngine::doVariableReport`, flattened by `AnalyzeReportController::variableReport()`).
  - **First query.** Totals per user and traffic source, inner-joined to `202_ppc_networks` with the user tied, over clicks with `variable_set_id != 0`. The row LIMIT (`user_pref_limit`) applies here, to traffic sources, not to values. A publisher session skips rows of other users.
  - **Second query.** The values, through `202_variable_sets2` → `202_custom_variables`, then `LEFT JOIN 202_ppc_network_variables` (matched also on `copied_from_ppc_variable_id`), then `202_ppc_networks`. It is grouped by source, variable id and value, ordered by source, name, variable and value, with no LIMIT.
  - The variable's label is "Unnamed variable N" when it has no name, the name, or "name (removed)" for a retired variable.
  - The query plan is chosen by a probe, `SELECT variable_set_id FROM 202_variable_sets2 LIMIT 10000,1`. It chooses a plan only and cuts nothing.
  - The table has one `<tbody>` per source and variable, headed "Source · Variable". Its value rows carry the 12 shared metrics. A final row reads "Totals for report".
  - There is **no pagination and no sort** (neither server nor browser).
- **Inputs**: The shared filter bar. There is no control for choosing a traffic source's variable: every source and variable is shown, nested. The Traffic source filter narrows it.
- **Actions**: shared (Apply, Reset, Download, suggestion AJAX).
- **Download**: `variables_download.php`, `T202_custom_variables_<time>.xls`, first header cell "Custom Variables". Rows, from `downloadVariables`:
  - `- <source>`, carrying the metrics of that source's **first value row**, not a subtotal;
  - ` - <variable>`, carrying the metrics of its first value row;
  - ` -- <value>` rows.
  - Masking is applied with `applyDeep`.
- **Writes / Edge cases**: shared. The empty states are the shared ones.
- **v3 coverage**: **MISSING**. `GET /reports/breakdown` has no custom-variable dimension, and no other v3 route reports clicks by custom variable value. The variable *definitions* are in `GET /ppc-networks/{}/variables` (Setup-gated). Download **MISSING**.

---

### Publishers: `tracking202/analyze/publishers.php`
- **Where / who**: Analyze › Publishers. The menu entry is shown only with `access_to_campaign_data`.
  - The page requires a signed-in user and `access_to_campaign_data`.
  - A refused role gets 403 with "This account's role does not have the 'access_to_campaign_data' permission."
  - The report is read for the account `user_own_id`.
- **Purpose**: Clicks, leads and money per publisher, per publisher link and per campaign. "A click counts for the publisher whose link it came on."
- **Data shown**: `PublishersReport::read()` builds the report from v3 `ReportsController::groups` with `by=publisher,publisher_link,campaign` over the window. Grantable publishers come from `PublisherCredit::grantable`.
  - Table `publisher-table`. Columns: "Publisher, link and campaign", Clicks, Click throughs, Leads, Conv. rate (leads / click throughs), Income, Cost, Net, ROI. Net and ROI are toned.
  - There is one group per publisher. Each row reads "Link <t202id>" or "Link not recorded", then "·", then the campaign, or "No campaign".
  - Each group ends with "All of <publisher>". After the groups come "Clicks on no publisher link", then "Totals for report".
  - Footnote: "A link is named by the number after t202id= in it." When unrecorded links exist it adds "Clicks from before this version did not record their link…".
  - The spend-ledger note comes from `p202_spend_ledger_note`.
- **Inputs**: Only the date range, the shared `range` / `from` / `to` (`ReportFilterInput::fromQuery` with no fields), which is saved to `202_users_pref` like the other reports.
  - A refused range: "The range was not applied: <errors> The report still shows your previous range."
  - The bar's note reads "A click counts for the publisher whose link it came on. The range is shared with your other reports."
- **Actions**:
  - **Download to Excel**, shown only when there are publishers. It goes to `publishers_download.php`, which requires `access_to_campaign_data` and is named `T202_publishers_<time>.xls` (TSV).
    - Columns: Publisher, Link, Campaign, Clicks, Click Throughs, Leads, Conv Rate, Income, Cost, Net, ROI, Currency, Other currencies in period. The last row is "No publisher".
    - A failure is answered 500 with "The report could not be read…".
  - There is **no revoke action** on this page; revoking is on Setup › Get Links (see below).
- **Writes and side effects**: Saving the range to `202_users_pref`. Nothing else.
- **Edge cases and states**:
  - No publishers and none grantable: "No publishers yet", with "Add a publisher" → `202-account/user-management.php`.
  - No clicks in a range other than all time: "No clicks on a publisher link in this range", with "Show all time" → `?range=alltime`.
  - All time with no clicks: "No clicks on a publisher link yet", with "Get a publisher link" → `tracking202/setup/get_trackers.php`.
  - A failure: a bad flash and the "The report could not be read" state.
- **v3 coverage**:
  - Report: `GET /reports/groups?by=publisher,publisher_link,campaign` covers it in full; it is the page's own source.
  - Grantable-publisher list (the empty-state decision): **MISSING**.
  - Saving the range: **PARTIAL**, through `PUT /users/{}/preferences` (`user_pref_time_predefined` only, no custom from/to, and it needs `access_to_personal_settings`).
  - Download: **MISSING**.

---

### AJAX endpoints assigned to this area but called from Setup pages
Neither endpoint below is called by any Analyze page. Both are described here because they were assigned to this area.

**`tracking202/ajax/revoke_publisher.php`**, called by Setup › Get Links (`tracking202/setup/get_trackers.php`, its "stop crediting" button, through `202-js/p202-setup.js`).
- Requires a signed-in user and `access_to_setup_section`.
- POST only; anything else is answered 405 "Send this as a POST.".
- The CSRF token is checked by `AUTH::check_csrf_token`; a failure is answered 403 "Invalid token, please reload the page and try again."
- `publisher_id` is read with `PostedId`; anything else is answered 422 "That is not a publisher id."
- Write: `DELETE FROM 202_publisher_grants WHERE user_id = ? AND publisher_user_id = ?`. A failure is answered 500 "Your links still credit this publisher: the change could not be saved. Reload the page and try again."
- The JS first confirms: "Stop crediting this publisher? Clicks already credited stay theirs; new clicks on your links are credited to no publisher until you make a link for them again." It then sends `fetch` POST with `publisher_id` and `token`. On success it removes the item, and shows "Your links credit no publisher now." when none remain.
- v3 coverage: **MISSING**. No route reads or deletes `202_publisher_grants`.

**`tracking202/ajax/custom_variables.php`**, called by Setup › Traffic Sources' variables dialog (`tracking202/setup/ppc_accounts.php`, through `202-js/p202-setup.js`).
- Requires a signed-in user, `access_to_setup_section` and `remove_traffic_source`.
- POST only; anything else is answered 405 "POST only".
- CSRF is checked; a failure is answered 403.
- The traffic source must be the user's: `ppc_network_id` is owner-checked, and a failure is answered 404 "That traffic source is not yours, or it was removed."
- `post_vars` with `vars[]` entries of `{id | 'false', name, parameter, placeholder}`:
  - Any empty field is answered 400 "VALIDATION FAILD!".
  - Each entry is inserted into or updated in `202_ppc_network_variables`.
  - The source's other variables are retired (`deleted = '1'`).
- `delete_vars` deletes the source's undeleted variables.
- A success is answered `DONE!`. Any other action is answered 400 "Unknown action.".
- The JS then reloads with `?variables_saved=1`.
- v3 coverage: `GET /ppc-networks/{}/variables`, `POST /ppc-networks/{}/variables`, `PUT /ppc-networks/{}/variables/{}` and `DELETE /ppc-networks/{}/variables/{}` (`PpcNetworkVariablesController`, Setup-gated). These are one variable at a time, not the page's save-the-whole-set call. **PARTIAL** for that replace-the-set semantics (retiring the variables not sent).

---

## Missing v3 endpoints

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Custom Variables | Clicks, leads and money per traffic source › variable › value | `GET /ppc-networks/{}/variables` (definitions only) | No report by custom-variable value; no custom-variable dimension in `/reports/breakdown` |
| All 13 dimension pages | "Download to Excel": every row as TSV (.xls) with Currency and Other-currencies columns | `GET /reports/breakdown` (pages of 500) | No export or file route for reports |
| Publishers | "Download to Excel" | `GET /reports/groups` | No export or file route |
| All dimension pages (filter bar) | Country, Browser, Platform and Device-type menus | none | No lookup list routes for countries, browsers, platforms or device types |
| All dimension pages and Publishers | Publisher menu, and the "No publishers yet" decision (`PublisherCredit::grantable`) | none | No route lists the publishers this account can credit |
| All dimension pages (filter bar) | Resolve a typed region or ISP name ("Name (CC)") to an id, with the refusal for an ambiguous name | `GET /reports/breakdown?breakdown=region\|isp` | No name-to-id resolution |
| Setup › Get Links (revoke_publisher.php, assigned here) | Stop crediting a publisher (delete the grant) | none | No route for `202_publisher_grants` |

## Partial v3 coverage

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| All 12 flat dimension pages | Grouped report rows | `GET /reports/breakdown?breakdown=<dim>` | Groups by id, not name; drops the no-value row ([no keyword], [direct link], …); no CTR, S/U or average payout; no totals row and no total row count; default sort is clicks, not leads; limit at most 500 |
| Countries, Regions, Cities | Flag and "Name (CC)" | `GET /reports/breakdown` | No country code in the rows |
| Landing Pages | Rows named by nickname | `GET /reports/breakdown?breakdown=landing_page` | Named by `landing_page_url` |
| All dimension pages (filter bar) | Traffic source, account, category, campaign, landing page and text ad menus, for any signed-in user | `GET /ppc-networks`, `/ppc-accounts`, `/aff-networks`, `/campaigns`, `/landing-pages`, `/text-ads` | Each requires `access_to_setup_section`; the pages need none |
| All dimension pages (filter bar) | Region and ISP suggestions for the window, merged by name, busiest first, at most 300 | `GET /reports/breakdown?breakdown=region\|isp` | Per id, not merged by name or labelled "Name (CC)" |
| All Analyze pages | Save the filter bar as the user's default on every report | `GET`/`PUT /users/{}/preferences` | PUT writes only `user_pref_limit`, `user_pref_time_predefined` and `user_cpc_or_cpv`; no filter fields and no custom from/to; needs `access_to_personal_settings` |
| All dimension pages | "All time" window | `period=alltime` | v3's all time has no lower bound; the page's starts at the user's registration time |
| Setup › Traffic Sources (custom_variables.php, assigned here) | Save the whole variable set and retire the rest | `POST`/`PUT`/`DELETE /ppc-networks/{}/variables[/{}]` | One variable per call; no replace-the-set call |

## Not determined from code
- Whether any page or setting still reads `user_cpc_or_cpv` to change a figure. In the code read (`AnalyzeReportController`, `class-dataengine.php`, `HtmlReportFormatter`) it is passed through and ignored. Other report families were not read.
