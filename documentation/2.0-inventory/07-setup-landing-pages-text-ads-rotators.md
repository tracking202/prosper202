# Setup: landing pages, text ads and redirectors

This part covers three Setup pages. Setup › Landing Pages manages simple pages (one offer, tied to one campaign) and advanced pages (several offers, tied to none). On the same form it also manages the page's split test, the Facebook Pixel Assistant, the ChatGPT Ads (OpenAI) pixel and the leave-behind URL. Setup › Text Ads manages ad copy for a campaign or for an advanced landing page. Setup › Redirector (page title "Smart Redirector"; the code calls it a rotator) manages redirectors and their default destination. It also manages their ordered rules, with each rule's criteria, destinations and split-test weights. Each page's AJAX endpoints are described inside that page: `tracking202/ajax/check_landing_code.php` and `tracking202/ajax/rotator.php`. The shared helpers in `tracking202/setup/_includes/setup_ui.php` are documented with Setup › Campaigns. The list below covers only what these three pages use. The click-time behaviour of a redirector appears here only as far as the Setup page configures it.

| Page | Path | Permission |
|---|---|---|
| Landing Pages | `tracking202/setup/landing_pages.php` | signed in; `access_to_setup_section`; removing needs `remove_landing_page` |
| Text Ads | `tracking202/setup/text_ads.php` | signed in; `access_to_setup_section`; removing needs `remove_text_ad` |
| Redirector (title "Smart Redirector") | `tracking202/setup/rotator.php` | signed in; `access_to_setup_section`; removing a redirector needs `remove_rotator`; a save that drops a rule needs `remove_rotator_rule` |

Shared mechanics, used by all three pages and summarised from `setup_ui.php`, `202-js/p202-ui.js` and `202-js/p202-setup.js`:

- **Sign-in.** `AUTH::require_user()` checks the session.
  - When the user is signed out and the request comes from a script (`X-Requested-With: XMLHttpRequest` or `Sec-Fetch-Dest: empty`), the answer is a 401 with "You are signed out. Sign in again, then reload the page.".
  - Otherwise the page includes `202-access-denied.php`.
- **Setup access.** Each page then checks `$userObj->hasPermission('access_to_setup_section')`. When that fails, the page redirects to `tracking202/`.
- **Seeded roles that hold the permissions** (`202-config/Database/DataSeeder.php`):
  - Super user and Admin hold every permission named in this file.
  - Campaign manager (role 3) holds `access_to_setup_section` but none of the remove permissions.
  - Campaign optimizer, Campaign viewer and Publisher do not hold `access_to_setup_section`.
- **AJAX permission refusal.** The AJAX endpoints call `AUTH::require_permissions('access_to_setup_section')`. A refused call gets HTTP 403 with the plain text "This account's role does not have the 'access_to_setup_section' permission.".
- **CSRF on posts.** Every POST carries `token` (rendered by `p202_setup_token_field()`) and is checked with `AUTH::check_csrf_token()`. jQuery posts get the token from the prefilter in `p202-ui.js`.
- **CSRF on removes.** A remove is a GET form, built by `p202_setup_remove_form()`. It carries `token` and is checked with `AUTH::csrf_token_matches($_GET['token'])`. A remove with a bad token, or by a user without the remove permission, redirects back to the page silently.
- **Remove confirmation.** The remove form carries `data-p202-confirm`. `p202-ui.js` shows that text in `window.confirm()` before it submits.
- **Flashes.** After a write the page redirects (post-redirect-get) and shows a flash from a query key, through `p202_setup_query_flashes()`.
- **Field errors.** These render as a summary flash, from `p202_setup_error_flashes()`:
  - one error: "Nothing was saved. One field needs attention; its sentence is under it."
  - more than one: "Nothing was saved. N fields need attention; each sentence is under its field."
  - The sentence itself sits under its field (`p202_setup_feedback()`), and the field gets `is-invalid`.
- **List filter.** A list longer than 5 items gets a client-side filter box (`p202_setup_list_filter()`) that matches `data-p202-filter-text`.
- **Collapsible sections.** A `.p202-disclosure` with `data-p202-remember="<key>"` stores its open or closed state in `localStorage` under `p202-disclosure:<key>`. This is per browser and never sent to the server.
- **Read failures.** A failed list read inside `p202_setup_rows()` calls `record_mysql_error()`, which shows the database error page.
- **Campaign choices.** `p202_setup_campaign_options()` builds the campaign `<select>` options. They are the account's live campaigns joined to the account's live categories, grouped by category. The option keys are prefixed `n<aff_network_id>`.

---

### Landing Pages: `tracking202/setup/landing_pages.php`

- **Where / who**
  - URL `tracking202/setup/landing_pages.php`. Menu: Setup sub-menu strip › "Landing Pages" (`tracking202/_config/sub-menu.php`; the strip is empty without `access_to_setup_section`).
  - Sign-in required (`AUTH::require_user()`). Without `access_to_setup_section` the user is redirected to `tracking202/`.
  - Remove links render only with `remove_landing_page`. Without it, a remove GET redirects back silently and nothing changes.
  - The check-code AJAX (`tracking202/ajax/check_landing_code.php`) requires `access_to_setup_section` (403 text as above) and a valid CSRF token (403 on a bad token).
- **Purpose:** Add, edit, copy and remove the account's landing pages. Configure each page's split test and tracking pixels, and check that the page carries the tracking code.
- **Data shown**
  - **"Advanced landing pages" panel.**
    - Lists `202_landing_pages` rows of the account with `landing_page_type = 1` and not deleted, ordered by nickname, with a count pill.
    - The filter box appears when there are more than 5.
    - Empty state: "None yet. Choose Advanced when one page promotes several offers."
  - **"Simple landing pages" panel.**
    - Lists type-0 pages grouped "Category › Campaign", through `p202_setup_campaign_options()`.
    - The count pill counts every type-0 page that is not deleted. A page whose campaign is deleted, or is not the account's, is counted but not listed.
    - Empty state: "No simple landing pages yet".
  - **Each list item** shows:
    - the nickname and the URL;
    - the code-check pill, from `p202_setup_lp_check_pill()`, read from `202_landing_page_checks`;
    - actions: "edit" (`?edit_landing_page_id=N`), "copy" (`?copy_landing_page_id=N`), "check code" (`data-p202-lp-check-now`) and remove;
    - for advanced pages only, an "optimize" link (LPO).
  - **Code-check pill labels:** Code found, Older code found, Two codes, Code from another tracker, Another page's code, Code commented out, Code not found, Could not be checked, Not checked yet.
  - **LPO link.**
    - Reads `202_users.install_hash` and `202_users_pref.lpo_status`.
    - When paired (`lpo_status = 'active'` and a non-empty hash), the link is `PairingClient::saasBaseUrl()/api/customers/experiments/create?lp=<URL>&install=<hash>` with `target="_blank"`.
    - Otherwise the link goes to `202-account/api-integrations.php#lpo`.
  - **Slack name.** The Slack account name is read from `202_users.user_name` for `user_own_id`, for notifications only.
- **Inputs (form `POST` to self)**
  - `token` (hidden). `landing_page_id` (hidden; set when editing).
  - **`landing_page_type`** (radio).
    - `0` "Simple: one offer on the page", `1` "Advanced: several offers on the page".
    - Default 0, or the edited row's type.
    - Any other value: "What type of landing page is this?".
  - **`aff_campaign_id`** (select grouped by category; required for simple pages).
    - Preselected from GET `aff_campaign_id` when that campaign is the account's and not deleted. When only one option exists, it is auto-selected.
    - With no campaigns the select reads "No campaigns yet", with a link to add one.
    - An advanced page posts a hidden `aff_campaign_id=0`.
    - Missing for a simple page: "What campaign is this landing page for?". The id is read with `PostedId::read()` (`/^[1-9][0-9]{0,18}$/`).
    - The ownership check (campaign `user_id` equals the session) does not check `aff_campaign_deleted`. Refusal: "You are not authorized to add a landing page to another users campaign".
  - **`landing_page_nickname`** (text, `maxlength=50`, required).
    - Empty: "Give this landing page a nickname".
    - Over 50 (`FieldLength`): "The nickname can be at most 50 characters; this one has N.".
  - **`landing_page_url`** (textarea, required, placeholder `https://`).
    - Empty: "What is the URL of your landing page?".
    - Over 255: "The landing page URL can be at most 255 characters.".
    - Not starting `http://` or `https://`: "Your Landing Page URL must start with http:// or https://".
    - A bare host or IPv4 address (`HttpUrlPrefix::complete`, regex `BARE_HOST`) is completed with `https://`, and the redirect then carries `&https=1`.
    - A "Placeholders" disclosure (remember key `setup-landing-pages-placeholders`) lists these tokens: `[[subid]] [[c1]] [[c2]] [[c3]] [[c4]] [[random]] [[referer]] [[gclid]] [[gbraid]] [[wbraid]] [[ttclid]] [[msclkid]] [[fbclid]] [[oppref]] [[utm_source]] [[utm_medium]] [[utm_campaign]] [[utm_term]] [[utm_content]] [[payout]] [[cpc]] [[cpc2]] [[timestamp]]`.
  - **Facebook Pixel Assistant disclosure** (simple pages only; parsed by `FacebookPixelAssistantSettings::fromForm`).
    - `fbpa_status`: switch with a hidden 0.
    - `fbpa_dynamic_epv`: switch with a hidden 0.
    - `fbpa_content_name`: text, max 100. Over 100: "The Facebook Pixel content name can be at most 100 characters."
    - `fbpa_content_type`: select with None=0 and these types (`FacebookPixelAssistantSettings::CONTENT_TYPES`):
      - 1 product, "eCommerce Product For Sale"
      - 2 flight, "Airline Flights"
      - 3 hotel, "Hotel Rooms & General Accommodations"
      - 4 destination, "Travel Destination"
      - 5 vehicle, "New & Used Vehicles"
      - 6 home_listing, "Real Estate & Rental Property"
    - `fbpa_outbound_clicks`: select with Not tracked=0, 1 ViewContent ("Track As: View Content") and 2 AddToCart ("Track As: Add To Cart").
    - A switch with an unexpected value: "Turn the Facebook Pixel switch on or off.".
    - A choice not in the list: "Choose one of the listed Facebook Pixel content types." or "Choose one of the listed Facebook Pixel outbound click events.".
  - **ChatGPT Ads pixel disclosure** (`OpenAiPixelSettings::fromForm`).
    - `openai_pixel_status`: switch.
    - Bad value: "Turn the ChatGPT Ads pixel switch on or off.".
  - **Split test disclosure** (both types; `LandingPageSplitTest::fromForm`).
    - `split_status`: switch.
    - `split_variants`: textarea, one `name weight` per line.
    - `split_overwrite_c4`: switch.
    - Rules:
      - at most 10 variants;
      - a name matches `/^[A-Za-z0-9][A-Za-z0-9_.-]{0,49}$/`;
      - a weight is an integer 0–1000, default 1;
      - names are unique, compared case-insensitively;
      - a test that is on needs at least 2 variants and a weight above 0.
    - Each rule's refusal is a field error on `split_*`. A failure to load the stored test gives the error key `split_load`.
  - **Leave-behind disclosure**, rendered only when `$_SESSION['user_mods_lb'] == '1'`.
    - `leave_behind_page_url` (text). Completed with `https://` like the main URL.
    - Over 255: "The leave-behind URL can be at most 255 characters.".
  - **Submit** reads "Add landing page" (new or copy) or "Save changes" (edit). "Cancel" shows when editing or copying.
  - **GET parameters**:
    - `edit_landing_page_id` loads a row for editing, ownership-checked.
    - `copy_landing_page_id` loads a row as a new one, with " (Copy)" appended to the nickname.
    - `aff_campaign_id` preselects a campaign.
    - flash keys `added`, `saved`, `deleted`, `https`.
- **Actions**
  - **Save (POST self).**
    - Validates as above.
    - For an edit, ownership of the posted `landing_page_id` is checked. Refusal: "You are not authorized to modify another users campaign".
    - Success redirects to `?added=1` or `?saved=1`, plus `&https=1` when a URL was completed.
  - **Remove (GET self).**
    - Parameters: `delete_landing_page_id`, `delete_landing_page_name`, `delete_landing_page_type`, `token`.
    - Confirmation: `Remove the landing page "X"? Clicks already tracked keep their history.`
    - Runs through `LandingPageDelete::fromSetupPage`. An advanced page that type-1 text ads or trackers still use is refused with a sentence naming them.
    - Not found: "That landing page was not found on this account. Nothing was removed.".
    - Failure: "The landing page could not be removed, and nothing was changed. Try again.".
    - Success redirects to `?deleted=1`.
  - **Check code (AJAX).**
    - `POST tracking202/ajax/check_landing_code.php` with `{landing_page_id, as: 'pill', token}`.
    - The answer is the pill's HTML, which replaces the pill.
    - On page load, the script queues up to 12 pills that are due, checked one at a time. "check code" checks one at once.
    - The endpoint runs `LandingPageCheckStore::run()`, which fetches the landing page URL with a 10 s timeout and stores the verdict.
  - **Optimize** link: external, as described under Data shown.
- **Writes and side effects**
  - **Save** runs in one `Connection::transaction()`, as a prepared `UPDATE` or `INSERT` of `202_landing_pages`:
    - `aff_campaign_id` (0 for advanced), `landing_page_nickname`, `landing_page_url`, `landing_page_type`, `landing_page_time = time()`;
    - `leave_behind_page_url`, only when `user_mods_lb` is on;
    - on insert, `landing_page_id_public = random_int(1,9) . id . random_int(1,9)`;
    - the split test, saved to `202_landing_page_split_tests` as JSON in the same transaction.
  - **Advanced made simple.** An edit that turns an advanced page into a simple one locks the page row (`FOR UPDATE`) and runs `AdvancedPageMadeSimple::uses()`. When type-1 text ads or trackers use the page, it is refused (`setupPageRefusal()`).
  - **Save failure:** "The landing page could not be saved, and nothing was changed. Try again.".
  - **After the transaction:**
    - Facebook Pixel Assistant: save, or switch off, in `202_bot202_facebook_pixel_assistant`.
    - OpenAI pixel: save, or switch off, in `202_landing_page_openai_pixel`.
    - LPO: `DimensionSync::markDirty()`.
  - **Slack** (`Slack::forAccount`), one event per change:
    - `simple_landing_page_campaign_changed`
    - `{simple|advanced}_landing_page_name_changed`
    - `{simple|advanced}_landing_page_url_changed`
    - `simple_landing_page_created` and `advanced_landing_page_created`
    - on remove, `simple_landing_page_deleted` and `advanced_landing_page_deleted`
  - **Remove** runs `SetupRemoval::mark('landing_page')`, which is `UPDATE 202_landing_pages SET landing_page_deleted = 1, landing_page_time = ? WHERE landing_page_id = ? AND user_id = ? AND landing_page_deleted = 0`. This is a soft delete. It also marks LPO dirty and sends Slack.
  - **Check code** upserts `202_landing_page_checks` and makes an outbound HTTP GET to the page's URL.
- **Edge cases and states**
  - Flash `added`: "Landing page added. Install its code from Get LP Code.".
  - Flash `saved`: "Landing page saved.".
  - Flash `deleted`: "Landing page removed. Clicks already tracked keep their history.".
  - Flash `https`: "The URL was typed without http:// or https://, so it was saved with https://. If the page is served over plain http, edit it and write http://.".
  - The CSRF refusal on save: "Invalid or expired form token. Please reload the page and try again.".
  - The campaign ownership check does not refuse a deleted campaign of the account's own; v3 does.
  - The leave-behind URL is neither shown nor written unless the session has `user_mods_lb`.
  - Copy keeps every setting on the form (pixels, split test) but saves a new row.
- **v3 coverage**
  - List and read: `GET /landing-pages`, `GET /landing-pages/{id}`.
    - Fields: url, nickname, type, campaign, leave-behind, `landing_page_id_public` (read-only).
    - `with=usage,health,last_seen`. Search by nickname. Sort by id, nickname, url, `aff_campaign_id`.
    - **PARTIAL**: the category and campaign names used for grouping come from `GET /campaigns` and `GET /aff-networks`, not this response.
  - Create, edit and copy:
    - `POST /landing-pages`, `PUT /landing-pages/{id}` (and `POST /landing-pages/bulk-upsert`).
    - The making-simple refusal returns 409.
    - **PARTIAL**: no split-test, Facebook Pixel Assistant or OpenAI pixel fields; no Slack notifications; v3 writes the leave-behind URL regardless of `user_mods_lb`.
  - Remove: `DELETE /landing-pages/{id}`, which requires `remove_landing_page`. The in-use refusal returns 409. `GET /landing-pages/{id}/uses` lists what uses the page. No Slack.
  - Check code: `POST /landing-pages/{id}/check`. **PARTIAL**: it adds a cooldown (`LandingPageCheckLimit::COOLDOWN`) and a per-key 429 the page does not have. It returns JSON (`status`, `found`, `message`, `checked_at`, `other_landing_page_ids_public`), not pill HTML. Last-check state is read through `with=health`.
  - Split test: **MISSING**. No route reads or writes `202_landing_page_split_tests`.
  - Facebook Pixel Assistant: **MISSING**. No route reads or writes `202_bot202_facebook_pixel_assistant`.
  - ChatGPT Ads pixel: **MISSING**. No route reads or writes `202_landing_page_openai_pixel`.
  - LPO optimize link: **MISSING**. No route answers the pairing state or deep link. The page builds it from `install_hash`, `lpo_status` and `PairingClient::saasBaseUrl()`.

### Text Ads: `tracking202/setup/text_ads.php`

- **Where / who**
  - URL `tracking202/setup/text_ads.php`. Menu: Setup strip › "Text Ads".
  - Sign-in and `access_to_setup_section` work as on Landing Pages (redirect to `tracking202/`).
  - Remove links render only with `remove_text_ad`. Without it, a remove GET redirects back silently.
- **Purpose:** Add, edit, copy and remove ad copy (nickname, headline, description and display URL) for a campaign or for an advanced landing page. The ad is chosen later on Get Links.
- **Data shown**
  - **"Ads for campaigns"** lists type-0 `202_text_ads` rows, grouped "Category › Campaign".
  - **"Ads for advanced landing pages"** lists type-1 rows, grouped by page nickname.
  - Each item shows the ad nickname, the headline as meta, and "edit", "copy" and remove.
  - The count pills count ads under deleted groups too, although those are not listed.
  - A filter appears when a list has more than 5 items: "Filter by ad, headline or campaign…" or "Filter by ad, headline or page…".
  - A live preview mirrors the headline, description and display URL as they are typed (`data-p202-mirror`). The defaults are "Luxury Cruise to Mars", "Visit the Red Planet in style. Low-gravity fun for everyone!" and "www.example.com".
- **Inputs (POST self)**
  - `token` (hidden). `text_ad_id` (hidden, edit).
  - **`text_ad_type`** (radio).
    - `0` "A campaign, directly or through a simple landing page", `1` "An advanced landing page".
  - **`aff_campaign_id`** (select grouped by category). Shown for type 0, with a hidden `landing_page_id=0`.
    - Missing: "What campaign is this advertisement for?".
    - The ownership check does not check deleted. Refusal: "You are not authorized to add an campaign to another users network".
  - **`landing_page_id`** (select of advanced pages). Shown for type 1, with a hidden `aff_campaign_id=0`.
    - Missing: "Please select a landing page.".
    - The ownership check does not check type or deleted. Refusal: "You are not authorized to add an text add to another users landing page".
  - **`text_ad_name`** (text, `maxlength=100`; hint "How you find this ad among the others in reports.").
    - Empty: "Give this ad variation a nickname".
  - **`text_ad_headline`** (text, max 100). Empty: "What is your ad headline?".
  - **`text_ad_description`** (textarea, max 100). Empty: "What is your ad description?".
  - **`text_ad_display_url`** (text, max 100). Empty: "What is your ad display URL?".
  - Each of the four over 100 gets the `FieldLength` refusal, labelled "The ad nickname", "The headline", "The description", "The display URL".
  - Edit ownership is checked on POST `text_ad_id`. Refusal: "You are not authorized to modify another users campaign".
  - GET parameters: `edit_text_ad_id`, `copy_text_ad_id` (copy does not append "(Copy)"), and the flash keys `added`, `saved`, `deleted`.
- **Actions**
  - **Save** redirects to `?added=1` or `?saved=1`.
  - **Remove** is a GET with `delete_text_ad_id`, `delete_text_ad_name` and `token`.
    - Confirmation: `Remove the ad "X"? Clicks already tracked keep their history.`
    - Not found: "That text ad was not found on this account. Nothing was removed.".
    - Success redirects to `?deleted=1`.
    - There is no in-use check.
- **Writes and side effects**
  - **Save** is an escaped raw `INSERT` or `UPDATE` of `202_text_ads`: `aff_campaign_id`, `text_ad_type`, `landing_page_id`, `text_ad_name`, `text_ad_headline`, `text_ad_description`, `text_ad_display_url`, `user_id`, `text_ad_time`.
  - **Remove** runs `SetupRemoval::mark('text_ad')`, a soft delete of `text_ad_deleted`.
  - **Slack** events:
    - `ad_copy_campaign_changed`
    - `ad_copy_landing_page_changed`
    - `ad_copy_name_changed`
    - `ad_copy_headline_changed`
    - `ad_copy_description_changed`
    - `ad_copy_display_url_changed`
    - `ad_copy_created`
    - `ad_copy_deleted`
- **Edge cases and states**
  - Flash `added`: "Ad added. Choose it when you get a tracking link.".
  - Flash `saved`: "Ad saved.".
  - Flash `deleted`: "Ad removed. Clicks already tracked keep their history.".
  - With no campaigns and no advanced pages, the empty state "Add a campaign first" replaces the form.
  - A `text_ad_type` other than 0 or 1 (for example 2) skips both the campaign check and the page check and is saved as posted.
  - An advanced page that was removed, or a simple page id, passes the page's type-1 ownership check.
- **v3 coverage**
  - List and read: `GET /text-ads`, `GET /text-ads/{id}`. Search by name. Sort by id, name, `aff_campaign_id`, `landing_page_id`. **PARTIAL**: group names come from other routes.
  - Create, edit and copy: `POST /text-ads`, `PUT /text-ads/{id}` (and `POST /text-ads/bulk-upsert`).
    - It holds the same four 100-character required fields.
    - Type 1 must name a live advanced page. v3 is stricter than the page here.
    - **PARTIAL**: v3 does not require `aff_campaign_id` for type 0 (it defaults to 0), while the page refuses it. No Slack.
  - Remove: `DELETE /text-ads/{id}`, which requires `remove_text_ad`. `GET /text-ads/{id}/uses` exists. No Slack.

### Redirector (title "Smart Redirector"): `tracking202/setup/rotator.php`

- **Where / who**
  - URL `tracking202/setup/rotator.php`. Menu: Setup strip › "Redirector". The page header reads "Redirector".
  - Sign-in and `access_to_setup_section` work as above.
  - The remove form renders only with `remove_rotator`. Without it, a remove GET redirects back silently.
  - A rule card's "Remove rule" button renders only for rules after the first and only with `remove_rotator_rule`. Rule cards added from the template are always removable in the browser.
  - The save (`tracking202/ajax/rotator.php`) passes `$mayRemoveRules`, which requires `remove_rotator_rule`, to the controller. A set that drops a stored rule without it answers 403 with the `AuthException` message.
  - `tracking202/ajax/rotator.php` requires `access_to_setup_section` (403 text as above).
- **Purpose:** Create and remove redirectors, which are one tracking link that sends each visitor to a destination by rules. Set each redirector's default destination and its ordered rules, with criteria, destinations and split-test weights.
- **Data shown**
  - **"Your redirectors" panel.**
    - Shows the `202_rotators` rows of the account, ordered by name, with a count pill. A filter ("Filter redirectors…") appears when there are more than 5.
    - Each item shows the name, a "N rule(s)" pill, "edit rules" (`?rotator_id=N#rules`), remove, and "Default: <destination>".
    - Under each redirector, its rules appear in `RuleOrder::orderBy('r')` order. Each rule shows:
      - its name;
      - an "inactive" pill when `status = 0`;
      - a "split test" pill when `splittest` is set or it has more than one redirect;
      - a "check this rule" pill when `NeverMatches::rules()` warns about an active rule;
      - a meta line "If <conditions joined by ' and '> · to <targets>". Conditions come from `Criterion::describe()`; with no criteria it reads "any visitor (no criteria)". Each target reads "Campaign/landing page name or URL (weight N)", where the weight appears for split tests only; with no targets it reads "nowhere yet";
      - each warning text, as its own meta line.
    - Empty state: "No redirectors yet" / "Name one, and its rules editor opens here." / button "Name your first redirector".
  - **Warnings**, from `NeverMatches::account()`, which reads `202_users_pref.maxmind_isp`, the account's IPQualityScore key and its privacy setting.
    - fraud with no key: "No IPQualityScore key is saved (Settings › API Integrations), so no visitor is scored and this rule is skipped for every one of them." The warning carries an "Add a key" link to `202-account/api-integrations.php#ipqs`.
    - fraud under privacy `all`: "Your privacy setting holds every visitor back, so none is sent to IPQualityScore and this rule is skipped for every one of them."
    - isp without MaxMind ISP: "Without the MaxMind ISP database no visitor has an ISP: "is" matches none of them, and "is not" matches all of them."
    - Per-criterion warnings come from `NeverMatches::criterion()`.
    - A failure to read the account calls `record_mysql_error()`.
  - **"Rules" section.**
    - A picker form: `rotator_id` select, which submits on change, and an "Open" button.
    - With none selected: "Choose a redirector to edit its default destination and rules."
- **Inputs**
  - **"Add a redirector" form** (POST self; "One tracking link that decides where each visitor goes.").
    - `rotator_name`: text, `maxlength=255`, required, label "Redirector name".
    - Empty after trim: "Type in the name of your rotator!".
    - The server does not check length; the column is `varchar(255)`.
    - Submit: "Add redirector".
  - **Rename** (POST `edit_rotator` + `rotator_id` + `rotator_name`). The handler exists, but no markup in the current page posts `edit_rotator`.
    - Locked `UPDATE`.
    - Not found: "That redirector was not found on this account. Nothing was saved.".
    - Flash `saved` "Redirector renamed.".
  - **Rules form `#rules-form`** (submitted by JavaScript; `data-rules-url` = `tracking202/ajax/rotator.php`).
    - **Default destination** ("Where a visitor goes when no rule matches.").
      - `default_type`, "Send to": Campaign, Landing page or URL. The initial value is lp when `default_lp` is set, else url when `default_url` is set, else campaign.
      - `default_campaign` (select grouped by category, "Choose a campaign").
      - `default_lp` (select; each page is suffixed "(advanced)" or "(simple)"; a simple page is offered only while its campaign is live and the account's).
      - `default_url` (`type=url`, placeholder `https://`).
      - Only the field for the chosen type shows. Each is `required`.
    - **Rule card.** With no stored rules, one empty card shows.
      - "Rule name" (`data-rule-field="rule_name"`, required, placeholder "US mobile visitors").
      - "Split test" switch.
      - "Inactive" switch.
      - "Remove rule".
      - "Add a criterion" and "Add a destination" buttons. "Add a destination" shows only when split test is on.
    - **Criterion row.**
      - "If" type select, with the 23 types from `CriterionMatcher::TYPES`:
        - country, region (State/Region), eu, city, isp, fraud (IPQualityScore), ip, iprange, browser, platform (OS), device, visitor (filtered/unique);
        - c1, c2, c3, c4, t202kw, referer;
        - utm_source, utm_medium, utm_campaign, utm_term, utm_content.
      - isp is disabled with "(needs MaxMind ISP)" when the account has no MaxMind ISP.
      - A stored type not in the list shows as "(unknown type: never matches)".
      - "is or is not": `is` or `is_not`.
      - "Values, comma separated" (required).
      - A remove (x) button.
      - Fixed choices come from datalists: device (bot, mobile, tablet, desktop), and `Criterion::FIXED_VALUES` for eu (true, false, Unknown), fraud (true, false) and visitor (filtered, unique).
      - Other types use the suggestion endpoint (below).
    - **Destination row.**
      - "Send to": Campaign, Landing page or URL.
      - `redirect_campaign` select, `redirect_lp` select or `redirect_url` (`type=url`). Only the chosen one shows, and it is required.
      - "Weight" (`type=number`, min 0, max `max(100, stored weight)`, required, placeholder 50). Shown only for split tests.
      - A remove (x) button, for split tests only.
    - Help text: "To split-test every visitor, give a rule one criterion with the value `ALL`. A country can be typed as its code (`US`) or its name; it is saved as `United States(US)`."
    - Buttons: "Add a rule" and "Save rules".
  - **GET parameters**: `rotator_id` (selected redirector) and the flash keys `added`, `saved`, `deleted`, `rules_added`.
- **Actions**
  - **Add (POST self).** Redirects to `?rotator_id=N&added=1`.
  - **Remove (GET).**
    - Parameters: `delete_rotator_id`, `delete_rotator_name`, `token`.
    - Confirmation: `Remove the redirector "X" and its rules? Tracking links that use it stop redirecting.`
    - Not found: "That redirector was not found on this account. Nothing was removed.".
    - Success redirects to `?deleted=1`.
  - **Value suggestions (AJAX).** `GET tracking202/ajax/rotator.php?autocomplete=true&type=<type>&query=<typed>`.
    - The request is sent by `p202-ui.js` after at least 2 characters and a 250 ms pause. Up to 20 options show.
    - The answer is `Content-Type: application/json`:
      - **country:** `CountryNames::matching()`. Up to 20 values formatted "Name(CC)": an exact code first, then names that start with the text, then names that contain it.
      - **c1–c4, t202kw, utm_*, referer:** `SeenValues::matching()`. Values the account's clicks carried in the last 30 days, taken from the newest 50,000 `202_dataengine` rows. Values containing a comma are excluded. Busiest first, up to 20. Referer values are site hosts. A read failure answers 500 with the body `[]`.
      - **region, city, isp, browser, platform:** `rotator_data()`. An outbound HTTPS GET to `https://my.tracking202.com/api/v1/deep/rotator/<type>/<urlencoded query>` (connect timeout 3 s, total 5 s, through `FixedHostRoute`). The body is echoed with `print_r()`.
      - **any other type:** `[]`.
  - **Save rules (AJAX).** `202-js/p202-setup.js`, `rulesEditor`, sends `POST tracking202/ajax/rotator.php`.
    - Body: `{post_rules: 1, rotator_id, token, default_type, defaults, data: [{rule_id or 'none', rule_name, status: 'active'|'inactive', split, redirects: [{id, type, value, weight?}], criteria: [{criteria_id, type, statement, value}]}]}`.
    - With no rule cards, the script shows "Add a rule before saving." and sends nothing.
    - The server refuses with `ERROR` in these cases:
      - `default_type` or `defaults` is missing, or `data` is not an array;
      - a rule has no criteria or no redirects;
      - a field is empty (`p202_rotator_fields_filled()`; "0" counts as filled);
      - `rotator_id` does not match `/^[1-9]\d{0,9}$/`.
    - Destination checks (`destinationOwned`):
      - `monetizer` is refused: "ERROR: The auto-monetizer is no longer offered as a destination: choose a campaign, a landing page or a URL.";
      - a URL passes;
      - a campaign or landing page must be live and the account's.
    - The server builds the controller payload with `p202_rotator_page_payload()`:
      - `default_<type>`;
      - `splittest` from `split == 'true'`;
      - status `active` becomes 1, anything else 0;
      - each redirect's `name`: "Campaign: X", "Landing page: X" or `URL: <a href=VALUE>link</a>`, cut to 255;
      - a weight only for a split rule (otherwise the controller's default 100).
    - It then calls `RotatorsController::setDefaultAndRules()`.
    - Responses:
      - `DONE`, or `DONE\n<json list of rewrites>`. The script stores the rewrites in `sessionStorage` under `p202-rotator-rewrites-<id>` and loads `?rotator_id=N&rules_added=1#rules`, where they show as info flashes.
      - `ERROR: <reason>`. The script shows "The rules were not saved. <reason>" and marks each named "Rule N, criterion M" value invalid. `p202_rotator_refusal()` maps controller field errors to "Rule N, criterion M: …." or "Default destination: …".
      - Bare `ERROR`: a generic sentence.
      - HTTP failure: the message includes "(HTTP N). Your session may have expired…".
- **Writes and side effects**
  - **Add:**
    - raw `INSERT INTO 202_rotators` of the untrimmed `name` and `user_id`;
    - then `public_id = rand(1,9) . id . rand(1,9)`;
    - Slack `rotator_created`.
  - **Rename:** `UPDATE 202_rotators SET name`. It also sends Slack `rotator_created`.
  - **Remove:** one transaction that locks the rotator, then hard-deletes:
    - `202_rotator_rules_criteria`
    - `202_rotator_rules_redirects`
    - `202_rotator_rules`
    - `202_rotators`
    - It also sends Slack `rotator_deleted`.
  - **Save rules:** `setDefaultAndRules()` locks the rotator. In one transaction it:
    - updates `default_url`, `default_campaign`, `default_lp` and sets `auto_monetizer = NULL`;
    - writes the planned rule set: inserts, updates and removals of `202_rotator_rules` (`rule_name`, `splittest`, `status`, `position`), `202_rotator_rules_criteria` (`type`, `statement`, `value`) and `202_rotator_rules_redirects` (`redirect_url`, `redirect_campaign`, `redirect_lp`, `weight`, `name`).
    - Values stored in another form (for example a country code to "United States(US)") come back as `meta.normalized`.
    - Slack then sends `SaveNotices::of(before, rotator, names)`.
  - **Suggestions:** read only. Region, city, isp, browser and platform make an outbound call to my.tracking202.com.
  - **Click-time effect configured here.** Rules are tried in order. An inactive rule is skipped. A split rule picks among its destinations by weight. No match sends the visitor to the default destination.
- **Edge cases and states**
  - Flash `added`: "Redirector added. Give it a default destination and its first rule below.".
  - Flash `saved`: "Redirector renamed.".
  - Flash `deleted`: "Redirector removed, with its rules.".
  - Flash `rules_added`: "Rules saved.".
  - The page requires at least one rule per save.
  - A stored weight above 100 raises the input's max to that weight, so it stays valid.
  - A stored auto-monetizer destination cannot be saved again.
  - The first rule card never shows "Remove rule".
- **v3 coverage**
  - List and read:
    - `GET /rotators`: filters id, public_id, name, default_*, auto_monetizer; search by name; sort by id, name, public_id.
    - `GET /rotators/{id}`: with rules and `meta.warnings`, which covers the NeverMatches warnings.
    - `GET /rotators/{id}/rules`.
  - Add: `POST /rotators`.
    - The name is trimmed, 1–255 characters.
    - `public_id` is random (100000–9999999) or supplied. **PARTIAL** against the page: the page uses a different public id format and does not trim the name.
    - No Slack.
  - Rename: `PUT /rotators/{id}`. No Slack.
  - Remove: `DELETE /rotators/{id}` (`remove_rotator`), with a dry-run preview. No Slack.
  - Save rules and default: `PUT /rotators/{id}/rules`.
    - `setRules()` accepts `rules` with `name`, `default_url`, `default_campaign` and `default_lp` in one transaction, with `dry_run`.
    - It asks for `remove_rotator_rule` when rules are dropped.
    - Limits: at most 200 rules and 100 entries, weights 0–100, and `meta.normalized`.
    - Also `POST /rotators/{id}/rules`, `PUT /rotators/{id}/rules/{ruleId}` and `DELETE /rotators/{id}/rules/{ruleId}` for one rule.
    - **PARTIAL**: v3 accepts an empty list (it removes every rule), while the page refuses it. No Slack.
  - Value suggestions: **MISSING**. No v3 route answers country names, seen click values or the hosted region, city, isp, browser or platform lists.

## Missing v3 endpoints

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Landing Pages | Reads and writes a page's split test (variants, weights, on/off, overwrite c4) in `202_landing_page_split_tests` | `PUT /landing-pages/{id}` | No split-test fields or route |
| Landing Pages | Reads and writes the Facebook Pixel Assistant settings (status, dynamic EPV, content name, content type, outbound clicks) | `PUT /landing-pages/{id}` | No Facebook Pixel Assistant fields or route |
| Landing Pages | Reads and writes the ChatGPT Ads (OpenAI) pixel switch | `PUT /landing-pages/{id}` | No OpenAI pixel field or route |
| Landing Pages | Builds the LPO "optimize" deep link from pairing state | none | No route answers LPO pairing state or the experiment link |
| Redirector | Suggests rule values as the user types (countries, seen c1–c4, t202kw, utm, referer; hosted region, city, isp, browser, platform) | none | No suggestion or autocomplete route |
| Landing Pages, Text Ads, Redirector | Sends Slack notifications for every create, change and remove | none | v3 writes send no Slack notifications |

## Partial v3 coverage

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Landing Pages | Lists pages grouped by category › campaign | `GET /landing-pages` | Group names need `GET /campaigns` and `GET /aff-networks` as well |
| Landing Pages | Writes the leave-behind URL only when the session has `user_mods_lb` | `PUT /landing-pages/{id}` | v3 writes it unconditionally |
| Landing Pages | Accepts a campaign of the account's that is deleted | `POST /landing-pages` | v3 refuses a deleted campaign (stricter) |
| Landing Pages | Check code with no cooldown, answering pill HTML | `POST /landing-pages/{id}/check` | v3 adds a cooldown and a 429 rate limit, and answers JSON |
| Text Ads | Requires a campaign for a type-0 ad | `POST /text-ads` | v3 defaults `aff_campaign_id` to 0 and does not require it |
| Text Ads | Type 1 accepts any page id of the account | `POST /text-ads` | v3 requires a live advanced page (stricter) |
| Text Ads | Lists ads grouped by campaign or page | `GET /text-ads` | Group names come from other routes |
| Redirector | Creates with the untrimmed name and a `d.id.d` public id | `POST /rotators` | v3 trims, checks 1–255, and uses a random 6–7 digit public id |
| Redirector | Saves the default and the rules, refusing an empty set | `PUT /rotators/{id}/rules` | v3 accepts `rules: []` and removes every rule |
| Redirector | Rename handler with no control on the page | `PUT /rotators/{id}` | v3 covers it, but the page has no control to compare |

## Not determined from code

- **The hosted suggestion format.** The page passes the upstream body through with `print_r()`. I checked `202-config/functions-tracking202.php` `rotator_data()` and the ajax endpoint. The upstream body is not in the repo. On a curl failure `print_r(false)` prints nothing, so the answer is an empty body.
