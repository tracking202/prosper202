# Customer LTV

Customer LTV is one page (`tracking202/analyze/ltv.php`). The page is a frame, and `202-js/ltv.js` swaps views into `#m-content`. Each view is drawn by its own partial in `tracking202/ajax/`. The script POSTs to the partial with `loadContentPost()` and puts the answer in with jQuery `.html()`, so the partial's inline scripts run. Then `p202ui.init(content)` wires the sortable tables. Each view has a URL of its own, `tracking202/analyze/ltv.php?view=<view>&<params>`, so each one is listed below as a page. The views and their partials:

- `report`: `sort_ltv.php` (the default)
- `customer`: `ltv_customer.php`
- `company`: `ltv_company.php`
- `companies`: `ltv_companies.php`
- `products`: `ltv_products.php`
- `subscriptions`: `ltv_subscriptions.php`
- `settings`: `ltv_settings.php`

Every view's URL carries the window keys `range`, `from` and `to`. A param that is `0`, `'0'` or `''` is left out of the URL. The legacy deep link `?customer_id=N` with no `view` opens the customer view. History uses `pushState`/`replaceState` with `{ltvView, ltvParams, ltvScroll}`, and Back/Forward draw the view again (`popstate`). `ltv.js` sets `history.scrollRestoration = 'manual'` and records the scroll position 150 ms after scrolling stops; it puts the position back once the partial has arrived. A view change scrolls to the top. An open Bootstrap modal is hidden before the swap.

The frame works out the report window with `p202_report_view_query([], [], $window)` and puts it in `data-ltv-view`. `ltv.js` adds `view=<that>` to every request it sends to the AJAX URL, through an `ajaxPrefilter` and `withView()`. Each partial calls `p202_report_view_begin()`, so it draws the window the page showed and not what is stored now. When a request fails, the panel shows this alert at the top: "The request failed (HTTP N|network error). Your session may have expired — reload the page and try again."

The posted-value readers in `tracking202/ajax/ltv_helpers.php` apply to every partial:

- `p202_ltv_posted_id()` reads with `Prosper202\Http\PostedId::read()`. On a bad id it throws a RuntimeException that carries the page's own sentence.
- `p202_ltv_posted_id_or_none()` reads an id that may be absent.
- `p202_ltv_posted_offset()` takes `'0'`, `''` or absent as 0. It refuses anything else that is not an id with "Pick a page with Previous or Next."
- `p202_ltv_posted_text()` refuses a list with 'The form sent "<field>" as a list; it takes one value.'
- `p202_ltv_posted_group()` refuses one value with 'The form sent "<field>" as one value; it takes a group of fields.' It refuses a list inside the group with 'The form sent "<field>[key]" as a list; it takes one value.'
- `p202_ltv_posted_or()` keeps the first refusal and falls back to a default.
- `p202_ltv_posted_choice()` reads a value from a list of choices.
- `p202_ltv_xls_cell()` puts `'` before a cell that starts with `= + - @`, a tab or a carriage return.

Shared UI lives in `tracking202/ajax/ltv_ui.php`:

- `p202_ltv_tabs()` draws the tabs Report, Subscriptions, Products, Companies and Settings. Settings is hidden unless the user has `access_to_api_integrations`. Each tab is a real href carrying the window query, and its onclick calls `ltvNav`.
- `p202_ltv_window_query()` builds the window query.
- `p202_ltv_stat()` draws a stat tile, with the tone good or bad.
- `p202_ltv_card_open()`/`p202_ltv_card_close()` open and close a panel.
- `p202_ltv_pill()` draws a pill. `p202_ltv_status_pill()` colours by status: active and delivered are green; trialing and pending are blue; past_due, dead and failed are red; paused is amber; any other status is gray, with `_` shown as a space.
- `p202_ltv_empty()` draws an empty state, and `p202_ltv_flash()` a flash (success, error, warn or info).
- `p202_ltv_pager()` draws "Showing X–Y of Z" with Previous and Next, which call a JS function with the new offset. It draws nothing when total ≤ limit and the offset is 0.
- `p202_ltv_chips()` draws a hidden input with pills; a click sets the input and runs the JS.

Money is `number_format(…, 2)`. Dates are `M j, Y`, or `M j, Y g:ia` with a time, and `—` when the value is 0.

Access works the same way on every partial:

- `require_user()`, asked over XHR by a signed-out session, answers 401 with the plain text "You are signed out. Sign in again, then reload the page." A normal request is shown `202-access-denied.php`. The function also needs a valid licence API key, and sends the user to `api-key-required.php` without one.
- `require_permissions()` answers 403 with "This account's role does not have the '<perm>' permission."
- Every write checks the CSRF token: `AUTH::check_csrf_token()` on `$_POST['token']`, which fails closed. The refusal is "Your session token was invalid — please try again."

In v3, `LtvController::wrap()` maps exceptions to statuses: `LtvInputException` is 422, `RecordNotFoundException` is 404, `LtvConflictException` and `CompanyConflictException` are 409, and anything else is 500. The v3 window params for summary, customers, breakdown and predict are `period`, `time_from` and `time_to`. Those routes also take up to three `cf.<key>[.min|.max]` custom-field filters, which the page does not have.

| Page | Path | Permission |
|---|---|---|
| LTV report (default view) | `tracking202/analyze/ltv.php` (`?view=report`) | `access_to_campaign_data` |
| Customer detail | `tracking202/analyze/ltv.php?view=customer&customer_id=N` | `access_to_campaign_data`; any action also needs `access_to_setup_section` |
| Companies | `tracking202/analyze/ltv.php?view=companies` | `access_to_campaign_data`; any action also needs `access_to_setup_section` |
| Company detail | `tracking202/analyze/ltv.php?view=company&company=<name>` | `access_to_campaign_data` |
| Products | `tracking202/analyze/ltv.php?view=products` | `access_to_campaign_data`; any action also needs `access_to_setup_section` |
| Subscriptions | `tracking202/analyze/ltv.php?view=subscriptions` | `access_to_campaign_data` |
| LTV Settings | `tracking202/analyze/ltv.php?view=settings` | `access_to_campaign_data` and `access_to_api_integrations` |
| Customer export (download) | `tracking202/analyze/ltv_download.php` | `access_to_campaign_data` |

### LTV report: `tracking202/analyze/ltv.php`

- **Where / who**:
  - Reached from the Analyze strip item "Customer LTV" (`tracking202/_config/sub-menu.php`), shown only when the user has `access_to_campaign_data`.
  - Also reached from the Overview's LTV snapshot ("Full report" button, described under Edge cases) and the Report tab.
  - Settings › API Integrations links to `?view=settings` (the LPO section shows an "LTV Settings" button when `$lpo_revive`).
  - The frame calls `require_user()`, then `require_permissions('access_to_campaign_data')`, then `set_timezone()`. The view's partial `tracking202/ajax/sort_ltv.php` checks `access_to_campaign_data` again.
  - A refused user sees the access-denied page (frame) or the 401/403 text (partial).
- **Purpose**: Revenue per customer over their lifetime, for customers first seen in the chosen range: totals, a projection, LTV by acquisition dimension, cohort maturation, and the customer list.
- **Data shown**:
  - Title "Customer Lifetime Value". Description: "Revenue per customer across their lifetime — repeat purchases, subscriptions and refunds included."
  - The range bar (`p202_report_filter_bar`, id `ltv-range`, range only, no filters), with the note "The range selects customers by when they were first seen. It is shared with your other reports."
  - The tabs from `p202_ltv_tabs()`.
  - Summary tiles (`#ltv-summary`), from `$ltv->summary(LtvQuery)` and `$ltv->mrr($userId)`:
    - Customers, with "N purchasing".
    - Revenue, with "$X refunded".
    - Orders.
    - Avg LTV.
    - AOV.
    - Repeat Rate (%, 1 decimal).
    - MRR: `summary.mrr`, scoped to this cohort, with "N active subs".
    - Monthly Churn: the account's `mrr.monthly_churn_rate`, 2 decimals.
  - "Predicted LTV" panel ("deterministic projection from this range's realized data"), from `predictFromComputed(summary, mrr)`:
    - Tiles: Predicted LTV / Customer, Subscriber Pool Value, AOV Input, Repeat Rate Input, Monthly Churn Input.
    - The line "Guards applied: <caps_applied, or none>". The caps: repeat rate at most 0.95, churn floor 1%, subscriber value at most 60× MRR, and `low_sample_size` under 20 customers.
  - "LTV by" panel: dimension chips and a client-sorted table `#ltv-breakdown-table` (`data-p202-sort`), 25 rows.
    - Campaign, Traffic Source and Landing Page come from `$ltv->breakdown(query, by, 25, 0)`. The repo joins each customer's first click at its latest visit, takes spend as SUM(click_cpc) in the window, and orders by total revenue. Columns:
      - the dimension name
      - Customers
      - Orders
      - Revenue
      - Avg LTV
      - AOV
      - Repeat Rate
      - MRR
      - Spend
      - CAC (— when spend is 0)
      - LTV:CAC (×; bold green at ≥ 3, red below 1)
    - Product groups revenue line items by `occurred_at` in the window. Columns: Product, Customers, Orders, Units (1 decimal), Revenue, Revenue / Customer.
    - Company (ABM) comes from `MysqlEngagementRepository::abmBreakdown(user, 90, 25, 0)`. Columns:
      - Company (a click opens the Company detail)
      - Score (engagement score /100)
      - Contacts
      - Engagements (90d)
      - Avg Time (s)
      - Avg Scroll (%)
      - Top Interest (`top_campaign_name`)
      - Top Event
      - Revenue
      - MRR
      - Last Activity
  - "LTV Maturation by Acquisition Cohort" panel ("revenue by months since first seen (last 6 months, all time ranges)"), from `cohorts(userId, 6, null, account zone)`. Columns: Cohort, Customers, Month 0 to Month 4, Month 5+, Total, LTV / Customer.
  - "Customers by Lifetime Value" panel ("N total"), from `customers(query, 'total_revenue', 'DESC', 50, offset, q, segment)`:
    - The scope is the account's customers that were not merged away and were first seen in the window.
    - Columns:
      - Customer (`primary_ref`, cut to 40 chars)
      - Name / Company
      - First Seen
      - Last Activity
      - Orders
      - Revenue
      - Refunded
      - Active Subs
      - MRR
    - A row click opens the Customer detail.
    - The table sorts in the browser only when the offset is 0 and the total is ≤ 50; otherwise the headers are plain labels.
- **Inputs**:
  - Range bar: `range` preset or `custom` with `from`/`to` (Y-m-d). `ReportFilterInput::fromQuery($_GET, [])` reads it, ignoring the `page` and `order` errors. A valid range is saved with `ReportPrefsStore::save()`, which is the window the other reports share. A bad range shows the flash "The range was not applied: <errors> The report still shows your previous range."
  - POST to `sort_ltv.php`:
    - `offset` (paging, refusal "Pick a page with Previous or Next.").
    - `ltv_by` chip (`campaign` "Campaign", `ppc_account` "Traffic Source", `landing_page` "Landing Page", `product` "Product", `abm` "Company (ABM)"). The default is `campaign`; the refusal is "Pick what to group LTV by from the list."
    - `q`: the search input `#ltv-customer-search`, maxlength 255, placeholder "Search ref, name, email or company…", trimmed. Enter runs `ltvLoad(0)`. The server LIKE-escapes it and matches `primary_ref`, email, company and first+last name.
    - `segment` chip (`''` "All customers", `repeat` "Repeat buyers" = order_count ≥ 2, `subscribers` "Active subscribers" = active_subscription_count > 0, `at_risk` "At risk (past-due subs)" = has a past_due subscription). The refusal is "Pick a segment from the list."
  - There is a Search button, and a "clear" link shown when a search or segment is applied. The pager (`p202_ltv_pager`, limit 50) calls `ltvLoad(offset)`.
- **Actions**:
  - `ltvLoad(offset)` runs `ltvNav('report', {offset, ltv_by, q, segment})`, which POSTs to `sort_ltv.php` and pushes the URL.
  - `ltvCustomer(id)` goes to `ltvNav('customer', {customer_id})`.
  - `ltvCompany(name)` goes to `ltvNav('company', {company})`.
  - "Export customers" (in the "LTV by" panel, `target=_blank`) opens `ltv_download.php` with the view query (see the Customer export page).
- **Writes and side effects**: Only the range preference, saved by the frame when a valid range arrives in the query. The partial writes nothing.
- **Edge cases and states**:
  - When the LTV tables are missing (any exception), the partial shows the warning "Customer LTV data is unavailable. If you just upgraded, run the database upgrade (202-config/upgrade.php) or 202-config/migrations/run_ltv_migration.php to install the LTV tables."
  - With 0 customers, an info flash explains how customers arrive: the `&cust=CUSTOMER_ID` parameter, `/api/v3/ltv`, the c1–c4 parameter, and `run_ltv_backfill.php`.
  - An empty breakdown shows "No data for this range" / "Try a wider date range, or a different dimension." No cohorts shows "No customers acquired in the last 6 months". No customers shows "No customers in this range", with a hint to clear the filters when some are applied.
  - **Overview snapshot fragment** (`tracking202/ajax/ltv_snapshot.php`, not a page):
    - Loaded by `tracking202/overview/index.php` as a `data-p202-snippet` placeholder; it needs `access_to_campaign_data`.
    - It reads all-time `summary()` and `mrr()` and draws the panel "Customer lifetime value" ("all time") with a "Full report" button to `ltv.php`.
    - Tiles: Customers, Revenue, Avg LTV ("per customer"), Repeat rate ("bought more than once"), MRR (the account's, "monthly recurring"), Monthly churn ("of subscribers").
    - It draws nothing on an exception or with 0 customers, and `p202-overview.js` removes the placeholder.
- **v3 coverage**:
  - Summary tiles: `GET /ltv/summary` (window params; it answers `mrr` for the cohort) and `GET /ltv/mrr` for the churn tile.
  - Prediction: `GET /ltv/predict`.
  - LTV by: `GET /ltv/breakdown?by=campaign|ppc_account|landing_page|product` (limit/offset), and `GET /ltv/abm` (`days`, default 90) for Company (ABM).
  - Cohorts: `GET /ltv/cohorts?months=6` (1–24).
  - Customer list: `GET /ltv/customers` (limit 1–500, offset, `sort` in total_revenue/order_count/last_activity_time/first_seen_time/mrr, `dir`, `q`, `segment`, `updated_since`; answers `pagination.total`).
  - Range preference: shared report prefs, not an `/ltv` route; not checked further here.
  - Snapshot: `GET /ltv/summary` with no window plus `GET /ltv/mrr`.

### Customer detail: `tracking202/analyze/ltv.php?view=customer&customer_id=N`

- **Where / who**:
  - Reached by a row click on the report's customer list, the Subscriptions list or the Company detail, or by the legacy link `ltv.php?customer_id=N`.
  - The partial is `tracking202/ajax/ltv_customer.php`. Reading needs `access_to_campaign_data`. Any posted non-empty `action` also needs `access_to_setup_section` (403 text otherwise).
- **Purpose**: One customer's lifetime record: profile, identities, suggested next offer, engagement, subscriptions and purchase history. It also edits, merges, links and unlinks identities, and erases personal data.
- **Data shown**:
  - Reads:
    - `$crm->get(user, id, 50)`: the customer with aliases, custom fields, subscriptions and the 50 most recent revenue events with their items.
    - The custom field definitions.
    - `customerEngagement(user, id, 90)` (25 rows) and `customerEvents(user, id, 90, 25)`.
    - `nextOffer()`.
    - A raw SQL read of `SUM(times_shown)` and `MIN(first_shown_at)` from `202_offer_recommendations` for the suggested campaign.
    - `engagementScore(customerEngagementAggregates(…, 90), null, scoreWeights(user))`.
  - Header:
    - The display name: name, else company, else `primary_ref`.
    - A status pill when the customer is not active.
    - The pill "Engagement N/100": ≥ 70 green, ≥ 40 blue, else gray.
    - Buttons Edit, Merge and Erase, and a Back link.
  - Tiles: Lifetime Revenue (with refunded), Orders, Avg Order, Active Subs, MRR, First Seen, Last Activity.
  - Profile panel:
    - Customer Ref
    - Email
    - Phone
    - Company
    - Address (the parts joined)
    - Acquisition Click (`#first_click_id`)
    - Each custom field (a boolean shows Yes or No)
  - Linked Identities table: Type pill, Value (cut to 60), Linked (date), and an × button to unlink.
  - Suggested Next Offer panel: the offer name and campaign #, and why it was chosen.
    - For `basis` transition: direct and eventual transitions, the campaigns it was based on, the score and the average order.
    - For `basis` engagement: clicks and `last_engaged_at`.
    - Otherwise: a fallback naming its `window_days` (default 180).
    - It also shows "Shown N time(s) since …" and the suppressed campaigns. Empty: "Not enough data yet to suggest an offer".
  - Engagement panel (last 90 days):
    - Columns: Campaign, Landing Page, Views, Conv., Last Seen.
    - Then "Instrumented Events": Event, Value (time_on_page in seconds; scroll_depth and video_viewed in %), Source, When.
  - Subscriptions table (only when there are any): Plan, Status, Amount / interval, MRR, Started, Paid Through, Canceled.
  - Purchase History ("most recent N events"):
    - Date
    - Type pill (refund and chargeback red, adjustment amber, renewal blue, else green)
    - Source (with conversion #)
    - Amount
    - Products (each item as "name ×qty — $amount", or the plan name)
    - Transaction (cut to 30)
- **Inputs**:
  - `customer_id` (POST or GET; refusal "Pick the customer from the Customer LTV list.").
  - `mode` (text; `edit` draws the edit form).
  - `action` in `erase`, `merge`, `add_alias`, `delete_alias`, `save` (else "Unknown action."). Every action is CSRF-checked.
  - Edit form `#ltv-customer-edit-form` (mode=edit):
    - Profile: Customer Ref is read-only ("Identity key — managed via aliases, not editable."). Inputs: `crm[first_name]` maxlength 100, `crm[last_name]` 100, `email` 255, `crm[phone]` 50, `crm[company]` 255.
    - Address: `crm[address_line1]` 255, `crm[address_line2]` 255, `crm[city]` 100, `crm[region]` 100 ("Region / State"), `crm[postal_code]` 20, `crm[country]` 2 ("2-letter code").
    - Custom Fields, one per definition as `cf[<key>]`:
      - boolean: a select of —, Yes (1) and No (0)
      - select: a select of — and the field's options
      - any other type: a text input (date has placeholder YYYY-MM-DD, number "e.g. 42.5")
    - The note "Emptying a field clears its stored value."
    - Validation on save:
      - Every `crm[]` column must be present, else 'The form left out "crm[x]"; nothing was saved.'
      - An unknown one is refused with 'The form sent "crm[x]", which is no customer field.'
      - `email` must be present.
      - Required custom fields are enforced by the repository.
      - A refusal draws the edit form again with the typed values.
  - Add identity form:
    - `#ltv-alias-type` select (`custom` first, then `email_md5`, `email_sha256`, `esp_id`, `merchant_id`; `subid` is not offered). The default is `custom`.
    - `#ltv-alias-value`, maxlength 255.
    - A "Link Identity" button.
  - `delete_alias` sends `alias_id` (refusal "Pick the identity to unlink from the list.").
  - `merge` sends `source_customer_id` (refusal "Pick the customer to merge away from the list.").
- **Actions** (each is a POST to `ltv_customer.php` through `loadContentPost`, whose answer replaces the panel):
  - Edit, then Save Changes, posts the serialized form with `action=save`. The success flash is "Customer updated." Cancel goes back to view mode.
  - Link Identity posts `action=add_alias` with `alias_type` and `alias_value`. When the identity belongs to another customer: "That identity already belongs to customer #N — merge the two records instead of re-pointing the alias."
  - × posts `action=delete_alias` with `alias_id`. Confirm: "Unlink this identity? Future events using it will create or match a different customer."
  - Erase posts `action=erase`. Confirm: "Erase this customer's personal data?\n\nName, contact info, custom fields, aliases and personalization tokens will be deleted. Revenue totals are kept for reporting. This cannot be undone." Success draws only the Back link and the flash "Customer erased: personal data, aliases, custom fields and personalization tokens removed. Revenue totals were kept for reporting integrity."
  - Merge opens the shared merge modal (`tracking202/ajax/ltv_merge_modal.php`, `ltvMergeOpen`), with entity `customer`, placeholder "Search by name, email, ref or company…", and the text "moves aliases, revenue history, conversions, subscriptions and engagement".
    - Step 1 is the modal `#ltv-merge-overlay`, titled 'Merge another <noun> into "<label>"'. Typing in `#ltv-merge-input` POSTs to `tracking202/ajax/ltv_merge_search.php` after 250 ms with `{entity, q, exclude: <this id>}`. A sequence number drops stale answers.
    - The search shows "Start typing to search.", "Searching…", "No matching <noun>s.", the error text on a 422, or "Search failed — try again." Results are built with `textContent`; arrow keys, Enter and Esc work.
    - Step 2, "Confirm merge", shows:
      - a "gone" card ("Merged away — excluded from reports")
      - a "kept" card ("Kept — everything ends up here")
      - a "swap direction" link
      - "Moves to the kept record: <moves>." and "This cannot be undone."
      - Back and "Merge records"
    - Confirming POSTs `{customer_id: kept, action: 'merge', source_customer_id: gone, token}`. If the direction was swapped, `history.replaceState` moves the URL to the kept record.
  - **Merge search endpoint** (`tracking202/ajax/ltv_merge_search.php`, JSON, `no-store`, POST, `access_to_campaign_data`, no CSRF check since it only reads):
    - `entity` must be customer or company ("The merge dialog searches customers or companies."). `q` is the search text. `exclude` is required ("Pick the record to merge into from the list.").
    - A refusal is a 422 with `{results: [], error}`. Any exception answers empty results.
    - Customer search: `$ltv->customers(all-time LtvQuery, total_revenue DESC, 12, 0, q)`. It skips the excluded id and anonymized customers and stops at 8. Each result is `{id, label: name or primary_ref, sub: "email · company · #id", meta: "$revenue · N orders"}`.
    - Company search: `MysqlCompanyRepository::search(user, q, exclude, 8)`. It LIKE-matches name or domain and orders by contacts, then name. Each result is `{id, label: name, sub: "domain · #id", meta: "N contacts"}`.
- **Writes and side effects**:
  - save: `$crm->upsert()` in one transaction. It writes the CRM columns, email and custom field values. Company goes through `syncCompanyLink`, which attaches the customer to the company found or created for the name, or writes `''` as a detach marker.
  - add_alias: `addAlias()` in a transaction, into `202_customer_aliases`.
  - delete_alias: deletes the alias row.
  - merge: one transaction with `FOR UPDATE` locks. It moves the source's rows to the target:
    - custom field values (`UPDATE IGNORE`, then the leftovers deleted)
    - aliases
    - revenue events
    - conversion logs
    - subscriptions
    - engagement events
    - personalization tokens
    - click tracking rows
    - offer recommendations (`UPDATE IGNORE`, then deleted)

    The target takes the earliest `first_click_id`/first seen and the latest activity. The source becomes status `merged`, with a zeroed cache, its company cleared and a terminal pointer. `reconcileCustomer` then runs. Refusals: merging into itself, a source that is not found, and a target that already merges into the source.
  - erase: `eraseIdentitySignals`, then deletes `202_customer_aliases`, `202_personalization_tokens` and `202_customer_field_values`. It sets `primary_ref` to `erased:<id>`, every personal column to NULL and the status to `anonymized`. Revenue events and subscriptions are kept.
  - None of the page's writes queues a `customer.updated` webhook event. The API's create/patch/merge/delete of a customer do (`enqueueEvent` in LtvController).
- **Edge cases and states**:
  - A customer that is not found shows "Customer not found." (warn), or the reader's refusal when `customer_id` was 0 or bad.
  - Erased customers are left out of merge search.
  - The suggested offer has its own empty state.
- **v3 coverage**:
  - Read: `GET /ltv/customers/{id}` (profile, aliases, custom fields, subscriptions, recent events) is **PARTIAL**. It returns 20 recent events with no limit param (the page shows 50), and it has no engagement score.
  - Engagement: `GET /ltv/customers/{id}/engagement` (`days`; browsing limit 25, events default 50).
  - Next offer: `GET /ltv/customers/{id}/next-offer` is **PARTIAL**. It does not answer the times-shown count or `first_shown_at` that the page reads from `202_offer_recommendations`.
  - Engagement score: **MISSING** for one customer. Per-contact scores appear only through `GET /ltv/abm/company`.
  - Save: `PATCH /ltv/customers/{id}` (CRM columns, email, aliases, custom_fields). `POST /ltv/customers` upserts.
  - Link identity: `POST /ltv/customers/{id}/aliases` (type, value; an owner conflict is a 422).
  - Unlink: `DELETE /ltv/customers/{id}/aliases/{aliasId}`.
  - Merge: `POST /ltv/customers/{id}/merge` (`source_customer_id`).
  - Erase: `DELETE /ltv/customers/{id}` (with a `?dry_run=1` preview).
  - Merge search, customers: `GET /ltv/customers?q=` is **PARTIAL**. It takes no `exclude` and does not leave out anonymized customers, so the client must filter.
  - Custom field definitions for the form: `GET /ltv/fields`.

### Companies: `tracking202/analyze/ltv.php?view=companies`

- **Where / who**: The Companies tab. The partial is `tracking202/ajax/ltv_companies.php`. It needs `access_to_campaign_data`, plus `access_to_setup_section` when an `action` is posted. A list sent for `action` is read as `?`, which gives "Unknown action."
- **Purpose**: The account's companies (ABM accounts) with rollups of their attached customers. Add, rename and set the domain, merge, or delete them.
- **Data shown**:
  - Panel "Companies", "N account(s) — customers attach by company name or email domain".
  - The rows come from `listWithRollups(user, 50, offset)`. Each company's rollups sum its attached customers that were not merged away. Rows are ordered by revenue DESC, contacts DESC, id ASC, and the total is a count of the account's companies.
  - Columns:
    - Company (name in bold, #id; a click opens the Company detail by name)
    - Email Domain
    - Contacts
    - Orders
    - Revenue
    - MRR
    - Last Activity
    - Edit, Merge and Delete buttons
- **Inputs**:
  - `offset`, the pager (limit 50).
  - `edit` (the company id whose row is in edit mode; refusal "Pick the company to edit from the list.").
  - The edit row: `company_name` maxlength 255, `company_domain` maxlength 191 (placeholder example.com), Save and Cancel.
  - The add form: `#ltv-new-company` (posted as `company_name`), maxlength 255, placeholder "New company name", with Enter or "Add Company". The JS skips a blank name.
- **Actions** (POST to `ltv_companies.php` through `loadContentPost`, CSRF-checked):
  - `add_company` with `company_name` calls `resolveOrCreate(user, name)`. It finds the existing company by normalized name or creates one, and never refuses a duplicate. An empty name after canonicalizing is refused with "Company name must not be empty". Notice: "Company saved."
  - `update_company` with `company_id`, `company_name` and `company_domain` calls `update(user, id, {name, domain})`.
    - All checks run before one transaction. A rename also rewrites the attached customers' company string and queues their `customer.updated` events.
    - Refusals:
      - "Company name must not be empty"
      - "Another company already uses that name (#N); merge the two companies instead of renaming"
      - "Domain <d> already belongs to company #N"
      - "Company not found"
    - Notice: "Company updated. Customers with matching email domains will auto-attach." On an error the row stays in edit mode.
  - `merge_company` with `company_id` (kept) and `source_company_id` (gone) calls `merge(user, gone, kept)`. It uses the shared merge modal with entity `company`, placeholder "Search companies by name or domain…", and the text "moves all attached contacts (their company name is rewritten to the kept record)". See Customer detail for the modal and its search.
    - Attached customers move to the kept company, their company string becomes its name, and their events are queued. The source row is deleted. The kept company takes the source's domain when it has none.
    - Refusals: "Cannot merge a company into itself", "Company not found", "source_company_id N not found for this account", and "Company was merged or deleted concurrently; retry the merge".
    - Notice: "Companies merged."
  - `delete_company` with `company_id` calls `delete(user, id)` under a row lock. Confirm: "Delete this company? Only possible when no customers are attached."
    - Refusals:
      - "Company still has N attached customer(s); merge it into another company instead"
      - "Company has N customer(s) pending entity linking; run the maintenance cron (202-cronjobs/ltv_maintenance.php) and retry"
      - "Company not found"
    - Notice: "Company deleted."
- **Writes and side effects**: `202_companies`, and `202_customers.company_id`/`company`/`updated_at` for a rename or merge. These two also queue `customer.updated` events (`announce()` in the repository).
- **Edge cases and states**:
  - An exception shows "Company data is unavailable. Run the LTV migration (or the 1.9.70 upgrade) if you have not yet."
  - An empty list shows "No companies yet" / "They are created when a customer gets a company name, by the nightly linking sweep, or manually below."
- **v3 coverage**:
  - List: `GET /ltv/companies` (limit, offset, `pagination.total`).
  - Add: `POST /ltv/companies` is **PARTIAL**. It uses the strict `create()`, which answers 409 for an existing name, while the page's add finds the existing company and answers success.
  - Rename and domain: `PATCH /ltv/companies/{id}`.
  - Merge: `POST /ltv/companies/{id}/merge` (`source_company_id`).
  - Delete: `DELETE /ltv/companies/{id}` (with `?dry_run=1`, which asks `deleteRefusal()`).
  - Merge search, companies: **MISSING**. There is no v3 search of companies by name or domain that leaves out one id. `GET /ltv/companies` only pages.

### Company detail: `tracking202/analyze/ltv.php?view=company&company=<name>`

- **Where / who**: Reached by a click on a company in the Companies list or in the report's Company (ABM) breakdown. The partial is `tracking202/ajax/ltv_company.php`, which needs `access_to_campaign_data`. The company is keyed by its name string, not its id.
- **Purpose**: An account view of one company: its contacts with their engagement scores and value over the last 90 days.
- **Data shown**:
  - The data comes from `abmCompanyDetail(user, company, 90)`, with each row scored.
  - Back link "All companies". Header: the company name, and "Account view · N contact(s) · last 90 days".
  - Contacts table: Contact (name or #id), Email, Score pill N/100, Engagements, Orders, Revenue, MRR, Last Activity. A row click opens the Customer detail.
- **Inputs**: `company`, read as text (a list is refused).
- **Actions**: Row click, and the Back link to the Companies view.
- **Writes and side effects**: None.
- **Edge cases and states**: An empty name, or a company with no contacts, shows the warning "No contacts found for this company." (or the reader's refusal).
- **v3 coverage**: `GET /ltv/abm/company?name=<name>&days=90`.

### Products: `tracking202/analyze/ltv.php?view=products`

- **Where / who**: The Products tab. The partial is `tracking202/ajax/ltv_products.php`. It needs `access_to_campaign_data`, plus `access_to_setup_section` for an action.
- **Purpose**: The product catalog, which is built automatically from order line items, with each product's sales. The page edits or deletes a product.
- **Data shown**:
  - Panel "Product Catalog", "N product(s) — created automatically from order line items".
  - A count of the account's products comes first, and an offset past the end is moved back to the last page.
  - The rows are read with raw SQL: products LEFT JOIN line-item aggregates. Revenue is SUM(amount), Orders is COUNT DISTINCT of purchase/renewal/one_time event ids, and Units is SUM(quantity). The order is revenue DESC, then product_id ASC.
  - Columns: Product, SKU, External ID, List Price, Orders, Units, Revenue, First Seen (`created_at`), and Edit and Delete buttons.
- **Inputs**:
  - `offset`, the pager (limit 50).
  - The edit row: `product_name` maxlength 255, `product_sku` maxlength 191, and `product_price` (size 8). Save serializes the row's inputs with `&offset`.
  - `product_id` (refusal "Pick the product to edit from the list.").
  - There is no form to add a product.
- **Actions** (POST, CSRF-checked):
  - `save_product`:
    - An empty name: "Product name must not be empty."
    - A price that is not numeric, or below 0: "Price must be a non-negative number."
    - Raw SQL `UPDATE 202_products SET name (cut to 255 chars), sku (cut to 191, or NULL when empty), price, updated_at WHERE product_id AND user_id`. When no row changed, it checks the row exists, else "Product not found."
    - Notice: "Product updated. Past order line items keep their sale-time snapshot."
  - `delete_product`:
    - Confirm: "Delete this product? Only possible when no order line items reference it."
    - When `202_revenue_line_items` names the product: "This product appears on N order line item(s) and cannot be deleted."
    - Then a DELETE; no row deleted gives "Product not found." Notice: "Product deleted."
- **Writes and side effects**: `202_products` only.
- **Edge cases and states**:
  - An exception shows "Product data is unavailable. Run the LTV migration if you have not yet."
  - An empty list shows "No products yet" / "They appear automatically when conversions or API revenue include line items."
  - The page cuts a long name or SKU and saves it, where the API refuses it.
- **v3 coverage**:
  - List: `GET /ltv/products` is **PARTIAL**. It answers the catalog columns only (no revenue, orders or units), ordered by product_id DESC, with no total count.
  - Edit: `PATCH /ltv/products/{id}` (name, sku, price; it refuses rather than cuts, with a maximum price of 999999999.99999).
  - Delete: `DELETE /ltv/products/{id}` (an atomic delete, 409 when line items name the product; `?dry_run=1`).
  - `POST /ltv/products` creates a product, which the page cannot do.

### Subscriptions: `tracking202/analyze/ltv.php?view=subscriptions`

- **Where / who**: The Subscriptions tab. The partial is `tracking202/ajax/ltv_subscriptions.php`, which needs `access_to_campaign_data`. It makes no writes.
- **Purpose**: Recurring revenue health, and the account's subscriptions by status.
- **Data shown**:
  - Tiles from `mrr(user)`:
    - MRR
    - ARR (MRR × 12)
    - Active
    - Trialing
    - Past Due (bad tone when > 0)
    - Paused
    - Canceled (90d), from `churn_inputs.canceled_in_window`
    - Monthly Churn: (canceled in 90 days / 3) / (active + canceled in 90 days)
  - Panel "Subscriptions", "N record(s)". The rows come from `listForUser(user, status|null, 50, offset)`.
  - Columns:
    - Plan (`plan_name` or `external_sub_id`)
    - Customer (name, else email, else #id, with the company in brackets)
    - Customer LTV (the customer's `total_revenue`)
    - Status pill
    - Amount / interval
    - MRR
    - Started
    - Paid Through
    - Canceled
  - A row click opens the Customer detail.
- **Inputs**:
  - `offset`, the pager (limit 50).
  - The status chips `#ltv-sub-status`: `''` All, `trialing`, `active`, `past_due` "Past Due", `paused`, `canceled`. The refusal is "Pick a status from the list."
- **Actions**: The chips and the pager reload the view through `ltvNav('subscriptions', …)`. A row click opens the customer.
- **Writes and side effects**: None.
- **Edge cases and states**:
  - With a status filter and nothing found: "No subscriptions with this status".
  - With no subscriptions at all: "No subscriptions recorded yet", "Push them via POST /api/v3/ltv/subscriptions."
  - An exception shows "Subscription data is unavailable. Run the LTV migration if you have not yet."
- **v3 coverage**: `GET /ltv/subscriptions` (limit, offset, `status`, `pagination.total`; the same `listForUser()`, so `customer_ltv` is included) and `GET /ltv/mrr`. `POST /ltv/subscriptions` and `POST /ltv/subscriptions/{ref}/events` take subscriptions in; the page has no form for them.

### LTV Settings: `tracking202/analyze/ltv.php?view=settings`

- **Where / who**:
  - The Settings tab, which is shown only with `access_to_api_integrations`. Settings › API Integrations also links here with "LTV Settings".
  - The partial is `tracking202/ajax/ltv_settings.php`. It needs `access_to_campaign_data` and `access_to_api_integrations` for reading and for writing. Writes do not ask `access_to_setup_section`, and every write is CSRF-checked.
- **Purpose**: How customers are identified and personalized, how engagement is scored, and offer fatigue. It also holds the custom field definitions, outbound webhooks with their delivery log, and integration records.
- **Data shown**:
  - The reads:
    - the preferences row
    - the field definitions
    - the webhooks (webhook_id, url, subscribed_events, status, created_at, updated_at)
    - `missedCounts` when any webhook is dead
    - the integrations
  - "Identity & Personalization" form, with a Save Settings button. The fields are listed under Inputs.
  - "Custom Fields" table: Key, Label, Type (with a select field's options), Required, Created, and a Delete button.
  - "Outbound Webhooks" table:
    - Columns: #, URL (cut to 70), Events, Status, Created.
    - A dead status carries the tooltip "A delivery failed 6 times; events are held. Fix the endpoint, then Revive."
    - Buttons: Revive (dead only), Log, Rotate secret, Delete. Rotate secret is hidden for the LPO optimizer webhook (`pairingWebhookOf()` from `202_users_pref.lpo_bridge_config`), which the repository refuses as well.
  - Delivery Log (shown with `show_deliveries=<id>`), from `recentDeliveries(user, id, 25)` with secrets redacted:
    - #
    - Event
    - Status
    - Last Response (`DeliveryReason::line()`, full text up to 1000 chars in a title)
    - Attempts
    - Last HTTP
    - Next Retry (pending only, `M j, g:ia`)
    - Queued
    - Updated
  - "Integrations" table: #, Provider, Name, Status (with "⚠ config unreadable" when `config_invalid`), Created, and a Delete button.
- **Inputs**:
  - `save_prefs` form:
    - `cparam` select: Off, c1–c4. It must match `^[0-4]$`; the refusal is "Customer c-param must be Off or c1–c4."
    - `p13n_fields`, maxlength 500. The allowed values are listed beside it: the CRM fields (`ALLOWED_CRM_FIELDS`), `cf:<key>` and `rec:next_offer`. It is checked with `PreferenceRules::personalizationFields`.
    - `weight_volume`, `weight_time`, `weight_scroll`, `weight_video`, `weight_recency`: number inputs, min 0, max 100. Each must match `^\d{1,3}$` ('Score weight "X" must be an integer 0-100.').
      - The five are stored as `''` when they equal the defaults (40/20/15/15/10).
      - Otherwise they are stored as `k:v,…`, checked by `parseScoreWeights`, and must total 100.
    - `rec_fatigue`, maxlength 20, placeholder `3,21`. It must be empty or match `^\d{1,3}(,\d{1,4})?$` ('Offer fatigue must be empty (defaults), 0 (off), or "times,days" — e.g. 3,21.').
  - `add_field` form:
    - `field_key`, maxlength 64, "field_key (a-z, 0-9, _)". The repository refuses "field_key must be 1-64 chars of a-z, 0-9 and underscore".
    - `field_label`, maxlength 255.
    - `field_type` select: text, number, date, boolean, select, email, url. Refusal: "field_type must be one of: …".
    - `field_options`, maxlength 1000, comma-separated and used for select only. A select with none is refused with "select fields require a non-empty options array".
    - `field_required` checkbox, value 1. Absent is false and `1` is true; anything else is refused with "Required is ticked or left clear; the form sent something else."
  - `add_webhook` form:
    - `webhook_url`, maxlength 500, with the note "HTTPS only; hosts resolving to private or reserved addresses are rejected…". A `WebhookRefused` from the repository is shown as an error flash.
    - `webhook_events[]`: one checkbox per known event, all ticked by default: customer.updated, revenue.recorded, subscription.changed, conversion.recorded, engagement.recorded, alert.conversions_stopped, alert.spend_without_conversions, alert.postback_refusals_spiked, alert.test. It must be a non-empty list of strings ("Tick the events this webhook is sent.").
  - `add_integration` form: `integration_provider`, maxlength 50, and `integration_name`, maxlength 255.
  - `show_deliveries=<webhook id>`, a read with no CSRF check. A bad id: "Pick the webhook whose log to show from the list."
- **Actions**:
  - `ltvSettingsSubmit(formId)` POSTs a serialized form. `ltvSettingsAction(action, idField, id, confirm)` confirms, then POSTs `{action, token, <idField>: id}`. `ltvWebhookLog(id)` runs `ltvNav('settings', {show_deliveries: id})`.
  - `save_prefs` runs `UPDATE 202_users_pref SET user_ltv_customer_cparam, user_ltv_personalization_fields, user_ltv_score_weights, user_ltv_rec_fatigue`. Notice: "Settings saved."
  - `add_field` calls `fieldsRepo->create`. Notice: "Custom field created."
  - `delete_field` (`field_id`). Confirm: "Delete this field AND every value stored on customers? This cannot be undone." Notice: "Custom field and all its stored values deleted."
  - `add_webhook` calls `webhooksRepo->create`.
    - The new signing secret is shown once, in a warn flash, with the Standard Webhooks note: `webhook-id`, `webhook-timestamp` and `webhook-signature` headers, plus `X-P202-Signature` sha256 through 1.9.82.
    - Notice: "Webhook #N registered."
  - `delete_webhook`. Confirm: "Delete this webhook and its delivery history?" Notice: "Webhook deleted."
  - `revive_webhook` calls `revive(user, id, true, now)`.
    - The confirm includes the missed count.
    - When the webhook is not the account's: "That webhook is not one of this account's."
    - On a conflict: "…deliveries are being sent right now. Nothing was changed; Revive it again in a minute."
    - Notice: "Webhook #N is active again; R delivery(ies) it missed in the last 7 days will be sent on the next cron run." Or: "Webhook #N was already active."
  - `rotate_webhook` calls `rotateSecret(user, id, 86400, now)`.
    - Confirm: "Give webhook #N a new signing secret? The old one still signs its deliveries for 24 hours, then stops."
    - The new secret is shown once. The notice says that for the next 24 hours its deliveries are signed by the old secret too.
  - `add_integration` calls `create`. Notice: "Integration added."
  - `delete_integration`. Confirm: "Delete this integration record?" Notice: "Integration deleted."
  - Any other action: "Unknown action."
- **Writes and side effects**:
  - `202_users_pref` (the four `user_ltv_*` columns).
  - The custom field tables (a delete removes every stored value).
  - The webhook tables: a revive requeues the deliveries missed in the last 7 days for the next cron run, and a rotate keeps the old secret for 24 hours.
  - The integration records.
- **Edge cases and states**: When the tables are missing: the danger flash "LTV settings could not be loaded. Run the LTV migration (202-config/migrations/run_ltv_migration.php) if you have not yet." A secret is shown only in the answer to the create or rotate.
- **v3 coverage**:
  - Preferences: `GET /users/{id}/preferences` and `PUT /users/{id}/preferences` are **PARTIAL**. The PUT also needs `access_to_personal_settings` for one's own user (`requirePersonalSettingsOf`) on top of the LTV settings-write check (`ltv:write` + `access_to_api_integrations`). The score weights are sent as one raw string normalized by `PreferenceRules::scoreWeights`, where the page has five numbers.
  - Fields: `GET /ltv/fields`, `POST /ltv/fields` (field_key, label, field_type, options, is_required, sort_order), `DELETE /ltv/fields/{id}`. `PATCH /ltv/fields/{id}` edits a field, which the page cannot do.
  - Webhook list: `GET /ltv/webhooks` is **PARTIAL**. It answers neither the missed counts the Revive confirm shows nor which webhook is the LPO optimizer's.
  - Webhook writes: `POST /ltv/webhooks` (url, events or `*`), `DELETE /ltv/webhooks/{id}`.
  - Revive: `PUT /ltv/webhooks/{id}` (`status: active`, `requeue` default true).
  - Rotate: `POST /ltv/webhooks/{id}/rotate-secret` (`overlap_hours` 0–168, default 24).
  - Delivery log: `GET /ltv/webhooks/{id}/deliveries` (limit 1–100, default 25, `status`).
  - Integrations: `GET /ltv/integrations`, `POST /ltv/integrations` (provider, name, config), `DELETE /ltv/integrations/{id}`.

### Customer export: `tracking202/analyze/ltv_download.php`

- **Where / who**: The "Export customers" link in the report's "LTV by" panel opens it in a new tab, with the view query (`p202_report_view_url`). It needs `require_user()` and `access_to_campaign_data` and calls `p202_report_view_begin()`.
- **Purpose**: A spreadsheet of the window's customers.
- **Data shown**:
  - The file is `customer_ltv.xls`, sent as `application/vnd.ms-excel`. It is an HTML table with `border=1`.
  - The rows come from `customers(LtvQuery(user, from, to), 'total_revenue', 'DESC', 10000, 0)`. There is no search or segment, and at most 10,000 rows.
  - Columns:
    - Customer Ref
    - First Name
    - Last Name
    - Email
    - Company
    - Country
    - First Seen (Y-m-d)
    - Last Activity (Y-m-d)
    - Orders
    - Revenue
    - Refunded
    - Active Subscriptions
    - MRR (2 decimals)
  - Text cells go through `p202_ltv_xls_cell()` and `htmlspecialchars`.
- **Inputs**: The view/window query only. The report's search and segment are not carried.
- **Actions**: Download.
- **Writes and side effects**: None.
- **Edge cases and states**: An exception ends with "Customer LTV data is unavailable. Run the database upgrade to install the LTV tables." A window with more than 10,000 customers is cut with no notice.
- **v3 coverage**: **MISSING** as an export. The nearest is `GET /ltv/customers` paged at limit 500.

## Missing v3 endpoints

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Customer detail | Shows the customer's engagement score (N/100, account's score weights, 90 days) | `GET /ltv/abm/company` (per contact, through the company only) | No per-customer engagement score |
| Companies (merge modal) | Searches companies by name or domain, leaving out the merge target, with contact counts | `GET /ltv/companies` (pages only) | No company search, no exclude |
| Customer export | Downloads up to 10,000 customers of the window as `.xls` | `GET /ltv/customers` (limit 500, paged) | No export; the client must page and build the file |

## Partial v3 coverage

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Customer detail | Shows the 50 most recent revenue events | `GET /ltv/customers/{id}` | Fixed at 20 events, no limit param |
| Customer detail | Shows how often the suggested offer was shown and since when | `GET /ltv/customers/{id}/next-offer` | Times shown and first shown are not answered |
| Customer detail (merge modal) | Searches customers for a merge, leaving out the target and anonymized records | `GET /ltv/customers?q=` | No `exclude`, anonymized customers not filtered |
| Companies | Adds a company, or finds the existing one by name | `POST /ltv/companies` | Strict create: 409 on an existing name instead of returning it |
| Products | Lists products with revenue, orders and units, by revenue, with a total | `GET /ltv/products` | Catalog columns only, ordered by id DESC, no total |
| LTV Settings | Saves c-param, personalization fields, five score weights and offer fatigue | `PUT /users/{id}/preferences` | Also needs `access_to_personal_settings`; the weights are one raw string |
| LTV Settings | Lists webhooks with missed counts and hides Rotate for the optimizer webhook | `GET /ltv/webhooks` | No missed counts, no flag for the optimizer webhook |

Behaviour difference (not a missing route): the page's customer save, merge and erase queue no `customer.updated` webhook event, while the API's `POST`/`PATCH /ltv/customers`, `POST /ltv/customers/{id}/merge` and `DELETE /ltv/customers/{id}` queue one.

## Not determined from code

- The range-preference write (`ReportPrefsStore::save()`) and its v3 counterpart were not traced; it is the window shared by every report page, not an `/ltv` route.
- The refusal messages of `MysqlCustomerRepository::addAlias()` other than the owner conflict, of `MysqlIntegrationRepository::create()`, and of the webhook URL guard (`WebhookRefused`) were not read. The page shows each as an error flash.
- The exact rule `syncCompanyLink`/`attachCustomer` uses to match a company by email domain was not read beyond what the notices say.
