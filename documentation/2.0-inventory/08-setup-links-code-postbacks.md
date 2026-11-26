# Setup: Get Links, landing page code and postbacks

This area holds the Setup pages that hand out what an operator pastes somewhere else. They are the tracking links ("Get Links", `tracking202/setup/get_trackers.php`), the landing page code (`get_landing_code.php`, which only redirects, then `get_simple_landing_code.php`, `get_adv_landing_code.php` and the unlinked `get_dynamic_smart_component_code.php`, all drawn by `tracking202/setup/_includes/landing_code_page.php`) and the conversion pixels and postback URLs (`get_postback.php`). It also covers the AJAX endpoints these pages call: `tracking202/ajax/generate_tracking_link.php`, `delete_tracker.php`, `revoke_publisher.php`, `get_landing_code.php`, `get_adv_landing_code.php` and `check_landing_code.php`. `tracking202/ajax/dni_get_offers.php` is listed in the brief for this area, but its only caller is Setup › Campaigns' offer search. It is described at the end. Snippets come from `202-config/Setup/LandingPageCode.php` and `202-config/Setup/PostbackCode.php`. The v3 API builds its answers with the same two classes. The pixel and postback intake endpoints (`gpx.php`, `gpb.php`, `upx.php`) belong to another area and are named here only for the parameters the snippets carry.

| Page | Path | Permission |
|---|---|---|
| Get Links | `tracking202/setup/get_trackers.php` (edit: `?edit_tracker_id=<public id>`) | `access_to_setup_section`. The Remove button needs `remove_tracker` too. |
| Get LP Code (redirect only) | `tracking202/setup/get_landing_code.php` | `access_to_setup_section` |
| Get LP Code › Simple page | `tracking202/setup/get_simple_landing_code.php` | `access_to_setup_section` |
| Get LP Code › Advanced page | `tracking202/setup/get_adv_landing_code.php` | `access_to_setup_section` |
| Dynamic Smart Component (not linked from any menu) | `tracking202/setup/get_dynamic_smart_component_code.php` | `access_to_setup_section` |
| Postback / Pixel | `tracking202/setup/get_postback.php` | `access_to_setup_section` |

---

## Shared by every page in this area

### Access
- Every page calls `AUTH::require_user()`. A signed-out browser is sent to `202-access-denied.php`. A script request (an `X-Requested-With` header, or `Sec-Fetch-Dest: empty`) gets a 401 with the text "You are signed out. Sign in again, then reload the page." The call then runs `AUTH::require_valid_api_key()`, which redirects to `api-key-required.php` when the install licence is not confirmed.
- Every page then asks `$userObj->hasPermission('access_to_setup_section')`. A user without it is redirected (302) to `tracking202/` with no message.
- Default role grants (`202-config/Database/DataSeeder.php`):
  - `access_to_setup_section` (permission 14): Super user (1), Admin (2) and Campaign manager (3).
  - `remove_tracker` (21): Super user and Admin.
  - `add_users` (1): Super user and Admin. It decides who may credit publishers (see Get Links).
  - Campaign optimizer (4), Campaign viewer (5) and Publisher (6) hold none of these.
- Every AJAX endpoint in this area calls `AUTH::require_user()` and `AUTH::require_permissions('access_to_setup_section')`. A missing permission answers 403 with "This account's role does not have the 'access_to_setup_section' permission."
- Menu (`tracking202/_config/sub-menu.php`): the Setup strip is hidden entirely for a role without Setup access. Three of its entries belong to this area:
  - "Get LP Code" goes to `get_landing_code.php`. It is marked current on `get_landing_code.php`, `get_simple_landing_code.php` and `get_adv_landing_code.php`.
  - "Get Links" goes to `get_trackers.php`.
  - "Postback/Pixel" goes to `get_postback.php`.
  - No entry links to or marks `get_dynamic_smart_component_code.php`. That page is reached only by typing its URL.

### CSRF token
- Each form carries a hidden `token` field. The jQuery `ajaxPrefilter` in `202-config/template.php` also adds the session `token` to every same-origin jQuery POST (string data, object data or no data). It skips `FormData`, JSON-string bodies and bodies that already have a token.
- A `fetch()` call does not pass through that prefilter. The two fetch callers here (publisher revoke, landing-page check) add the token themselves.
- Every POST endpoint checks it with `AUTH::check_csrf_token()`. A refusal answers 403. The text is "Invalid token", or for `delete_tracker.php` "Invalid token, please reload the page and try again."

### Tracking base URL and the HTTPS choice
- The base for links the operator pastes is `p202TrackingBaseForResponse(p202StoredTrackingDomain())`. It uses `TrackingBaseUrl::buildForResponse()`.
- `p202StoredTrackingDomain()` reads `202_users_pref.user_tracking_domain` of the signed-in account.
- The scheme is decided, not asked. If the domain is saved with `https://` or `http://`, that scheme is used. Otherwise the request's own scheme is used: https when the page was served over HTTPS, else http.
- `p202_setup_link_scheme_sentence()` writes the line that explains the choice. It reads "Direct and redirector links use https://, because your tracking domain is saved with it." or "… because this page was served over HTTPS." The http case is worded the same way. Each line ends with a "change" link to `202-account/account.php#profile`.
- Get Links has no scheme choice. Postback / Pixel keeps an http/https choice under Advanced.
- The landing-page code endpoints build a different base. It is scheme-relative: `'//' . getTrackingDomain() . get_absolute_url()`.
- The v3 API (`Api\V3\Support\TrackingBaseLookup`) reads the same account's `user_tracking_domain` and builds with the same `buildForResponse()`.

### Shared UI behaviours
- Code boxes (`p202_setup_code_box()`) each have a Copy button (`[data-p202-copy]`, `navigator.clipboard`).
- `<details class="p202-disclosure" data-p202-remember="KEY">` remembers whether it is open in `localStorage` under the key `p202-disclosure:KEY`.
- `data-p202-show-when="name=value"` shows a block only while that radio or select has that value.
- `data-p202-disable-hidden` disables the fields of a hidden section, so they are not posted.
- Forms with `data-p202-ajax-target` post `serialize()` with jQuery and replace the target with the answer. While the request runs, the target shows a skeleton and the submit button is disabled. On a 403 the target shows the response text. On any other failure it shows "The request failed (HTTP n|network error). Your session may have expired; reload the page and try again."
- Validation refusals come back as `p202_flash('bad', …)` blocks and warnings as `p202_flash('warn', …)` blocks.
- These behaviours live in `202-js/p202-setup.js` and `202-js/p202-ui.js`.

---

### Get Links: `tracking202/setup/get_trackers.php`

- **Where / who**: Setup › Get Links. Browser title "Get Trackers", heading "Get Links", description "A tracking link for each place you buy traffic: it records the source, the cost and the ad before sending the visitor on." Needs `access_to_setup_section`. The Remove button appears only for a role with `remove_tracker`.
- **Purpose**: make a tracking link (a row in `202_trackers`) that records the traffic source account, the cost and the ad copy, then sends the visitor to one of three places: a campaign (directly or through a simple landing page), an advanced landing page, or a redirector (rotator). The page also edits existing links, lists every link of the account to copy again, and removes links.
- **Data shown**:
  - Option lists, all scoped to the signed-in account:
    - Campaigns come from `p202_setup_campaign_options()`: `202_aff_campaigns` joined to the account's live `202_aff_networks`. There is one optgroup per campaign category (network), ordered by category name and then campaign name. Each option carries `data-network=<aff_network_id>`.
    - Simple landing pages: `landing_page_type` 0, not deleted, whose campaign is live and the account's own. Ordered by nickname. Each carries `data-campaign`.
    - Advanced landing pages: `landing_page_type` 1, not deleted.
    - Text ads (not deleted) are split in two. `text_ad_type` '1' are page ads and carry `data-lp`. All others are campaign ads and carry `data-campaign`. Each carries `data-headline`, `data-description` and `data-display`.
    - Redirectors: `202_rotators` of the account, ordered by name.
    - Traffic source accounts: `202_ppc_accounts` joined to the account's `202_ppc_networks`, neither deleted. One optgroup per source; each option carries `data-source=<ppc_network_id>`.
  - Publisher list (only when there is anyone to show; see Inputs).
  - "Your tracking links" panel, with a count pill. It is read with `202_trackers tr` LEFT JOINed to `202_landing_pages`, `202_aff_campaigns`, `202_rotators`, `202_ppc_accounts` and `202_ppc_networks`, each join tied to `tr.user_id`, `WHERE tr.user_id = <account> ORDER BY tr.tracker_id DESC`. There is no limit. Each row shows:
    - Name: the landing page nickname, else the redirector name, else the campaign name. "Untitled" when empty.
    - Kind pill: "landing page", "redirector" or "direct link". A warn pill replaces it in three cases: "no landing page" (the link names a landing page that is not this account's or was removed), "no redirector", and "no landing page URL".
    - A "ChatGPT Ads" pill when the source account's platform is `chatgpt_ads`.
    - Buttons: "copy" (the link). For a ChatGPT Ads link, "copy destination URL", "copy tracking template" and "copy full link" instead. Then "edit" (goes to `?edit_tracker_id=<tracker_id_public>`) and "remove" (only with `remove_tracker`).
    - Meta line: "ID <tracker_id> · made <M j, Y of tracker_time>".
  - How the listed link is built:
    - A landing page link uses `TrackersController::buildLandingPageUrl()`: the landing page URL with its port kept, plus `t202id=<public id>` and the variables, inserted before any `#fragment`.
    - Any other link uses `buildDirectUrl()`: the base plus `tracking202/redirect/dl.php?t202id=N` (direct) or `tracking202/redirect/rtr.php?t202id=N` (redirector), plus the variables.
    - Variables come from `TrackingLinkVariables::query()`. First come the source's custom variables (`202_ppc_network_variables` of that source, not deleted, ordered by `ppc_variable_id`), each as `&<parameter>=<placeholder>`. Then come the built-in tokens c1, c2, c3, c4, utm_source, utm_medium, utm_campaign, utm_term, utm_content, t202ref, t202b and t202kw. Each built-in appears only when a source variable supplies its placeholder, except `t202kw`, which always appears (as `&t202kw=` when nothing fills it).
  - A scheme note under the list (when it holds any direct or redirector link), as in Shared › Tracking base URL.
  - Empty list: "None yet. The links you get are kept here, to copy or change later." With more than 5 links, a filter box ("Filter links…", `data-p202-filter-list`) appears.
  - Result panel "Your link" (`#tracking-links`). Before a submit it reads "Choose where visitors go, then Get tracking link: the link appears here, ready to copy."
- **Inputs** (form `#tracking_form`, POST to `tracking202/ajax/generate_tracking_link.php`):
  - Hidden: `token`. On edit, also `edit_tracker=1` and `tracker_id=<public id>`.
  - Radio `tracker_type`, legend "Send visitors to":
    - `0` "A campaign, directly or through a simple landing page" (default)
    - `1` "An advanced landing page"
    - `2` "A redirector"
  - Section for type 0:
    - `aff_campaign_id` "Campaign", required. Picked automatically when there is only one. Placeholder "No campaigns yet" or "Choose a campaign".
    - Hidden `aff_network_id`, copied from the chosen campaign's `data-network`.
    - `method_of_promotion` "Visitors land on": `directlink` "The offer, directly" (default) or `landingpage` "A landing page first". Hint: "Directly by default: the link goes straight to the campaign URL."
    - `landing_page_id` "Landing page", required. Shown only for `landingpage`, and filtered to the chosen campaign. Placeholders "No landing pages for this campaign" or "Choose a landing page".
    - `text_ad_id` "Ad copy", default "None" (0), filtered to the campaign. Hint: "None by default. Choose the ad this link runs behind, and reports split by ad."
  - Section for type 1:
    - `landing_page_id` (element id `adv_landing_page_id`) "Advanced landing page", required. Picked automatically when there is only one. Placeholder "No advanced landing pages yet".
    - `text_ad_id` (element id `adv_text_ad_id`) "Ad copy", filtered to the chosen page's `data-lp`.
  - Section for type 2: `tracker_rotator` "Redirector", required. Placeholder "No redirectors yet".
  - Ad preview card (types 0 and 1). It shows the chosen ad's headline, description and display URL. With no ad chosen it shows the defaults "Luxury Cruise to Mars", "Visit the Red Planet in style. Low-gravity fun for everyone!" and "www.example.com", dimmed.
  - Traffic source account:
    - `ppc_account_id` "Traffic source account", default "No traffic source" (''), grouped by source.
    - Hidden `ppc_network_id`, copied from the option's `data-source`.
    - Hint: "Grouped by traffic source. Without one, clicks on this link report under no source."
  - Publisher block, shown only when the grantable list or the granted list is not empty:
    - `publisher_id` "Publisher", default None (''). Its hint explains the `t202pubid` parameter, Analyze › Publishers and the Publisher role.
    - "Publishers your links credit" list (`#granted-publishers`). A publisher no longer grantable carries the pill "not credited now" (title "Switched off, or without the Publisher role, in User management"). Each row has a "stop crediting" button.
    - Who may be offered: `PublisherCredit::grantable(account)`. The signed-in account must be active and hold a role with `add_users`. The offered users are those of the same `install_hash`, not the account itself, active, not deleted, with the Publisher role (6), and with a valid `user_public_publisher_id` (1–10 letters or digits, unique without regard to case).
    - `granted()` reads `202_publisher_grants` joined to users that are not deleted. Each grant's "credits" flag is whether that user is still grantable.
  - Cost fieldset:
    - Radio `cost_type`: `cpc` "Per click (CPC)" or `cpa` "Per conversion (CPA)".
    - Text inputs `cpc_dollars` (maxlength 2), `cpc_cents` (maxlength 5), `cpa_dollars` and `cpa_cents`. Defaults 0 and 00. Hint: "Dollars, then cents: as small as 0.00001."
    - Both pairs are always posted. The hidden pair is not disabled.
  - Advanced disclosure (`data-p202-remember="setup-get-links-advanced"`; open on edit when cloaking is not -1). Summary "Advanced": cloaking, keyword, bid and referrer tokens, c1–c4. It holds:
    - `click_cloaking`: `-1` "The campaign's setting" (default), `0` "Off for this link", `1` "On for this link". Hint "The campaign decides by default." Shown for types 0 and 1 and still posted for type 2.
    - `t202kw` "Keyword token". Its hint names `{keyword}` (Google Ads) and `{QueryString}` (Bing).
    - `t202b` "Bid (dynamic CPC) token".
    - `t202ref` "Referrer token".
    - `c1`–`c4` "Tracking ID c1" … "c4". The hint says "up to 350 characters each", but the inputs have no maxlength.
  - Buttons: submit "Get tracking link" ("Update tracking link" on edit), and "Cancel" on edit (a link back to the bare page).
- **Edit mode** (`?edit_tracker_id=<public id>`):
  - The id is read with `\Prosper202\Click\PublicId::parse()`, which takes exact digits only. `12abc`, `+12` and `12-1` open nothing.
  - The tracker is read with tied joins to its landing page, campaign and source account, requiring a live campaign (or no campaign).
  - When nothing is found, a bad flash reads "That tracking link is not one of yours, or it was removed, so there is nothing to edit."
  - Prefill:
    - CPC or CPA is split on "." into dollars and cents.
    - The type is 2 if `rotator_id` is set, 1 if the landing page's type is 1, and 0 otherwise. The method is `landingpage` for type 0 with a landing page.
    - The cost type is cpa when `click_cpa` > 0, otherwise cpc.
    - Cloaking defaults to -1.
    - `c1`–`c4`, `t202kw`, `t202b` and `t202ref` are not stored on the tracker, so they come up blank on edit.
  - The panel is titled "Edit tracking link" ("Get a tracking link" otherwise). Its sub-line is "The link stays the same; what it records changes." ("Test a new link yourself before you run traffic to it." otherwise).
- **Actions**:
  - **Get tracking link / Update tracking link**: jQuery POST of the form to `tracking202/ajax/generate_tracking_link.php`. The answer replaces `#tracking-links`. See the endpoint below.
  - **edit**: a link to `?edit_tracker_id=<tracker_id_public>`.
  - **remove**:
    1. A JavaScript confirm: "Remove this tracking link? Clicks it already recorded keep their history; new clicks on it are not tracked."
    2. A jQuery POST to `tracking202/ajax/delete_tracker.php` with `tracker_id=<tracker_id>` (the internal id, not the public one). The prefilter adds the token.
    3. On success the row is removed, the count pill updates, and an empty list reads "None left. The links you get are kept here, to copy or change later."
    4. On failure a flash reads "The link was not removed: <response text, or 'the request failed'>."
  - **stop crediting**:
    1. A `fetch` POST to `tracking202/ajax/revoke_publisher.php` with body `publisher_id` and `token` (from `data-revoke-token`).
    2. On success the row is removed. With none left, the list reads "Your links credit no publisher now."
    3. On failure a flash reads "The publisher is still credited: <reason, or 'the request failed'>."
  - **copy buttons**: they copy the listed link, or for ChatGPT Ads links, the destination URL, the template or the full link.
  - **Empty state**: when the account has no campaigns, no advanced landing pages and no redirectors, the form is hidden. In its place the page shows "Nothing to link to yet", "A tracking link sends visitors to a campaign, a landing page or a redirector. Add a campaign first." and a button "Add a campaign" that goes to `tracking202/setup/aff_campaigns.php`.

#### Endpoint `tracking202/ajax/generate_tracking_link.php`
- Needs `require_user` and `access_to_setup_section`. A CSRF failure answers 403 "Invalid token".
- **Refusals** (bad flash; nothing is written):
  - Type 0 without `aff_network_id`: "You have not selected an affiliate network."
  - Type 0 without `aff_campaign_id`: "You have not selected an affiliate campaign."
  - Type 0 without `method_of_promotion`: "You have to select your method of promoting this affiliate link."
  - The three type-0 messages above are all shown together.
  - Type 2 without `tracker_rotator`: "You have not selected rotator."
  - `ppc_network_id` without `ppc_account_id`: "You have a traffic source selected, but no account for it. To track a traffic source, choose one of its accounts; add one on Traffic Sources if it has none."
  - `click_cloaking` not -1, 0 or 1: "Choose the cloaking for this link: as the campaign sets it, off, or on."
  - `landingpage` without `landing_page_id`: "You have not selected a landing page to use."
  - Ownership of each of `aff_network_id`, `aff_campaign_id`, `landing_page_id`, `text_ad_id`, `ppc_network_id`, `ppc_account_id` and `tracker_rotator`:
    - Sent as an array: "<What> was sent more than once."
    - '' or '0' is skipped.
    - Anything that is not `^[1-9]\d{0,9}$`, or not a live row of the account: "<What> is not one of yours, or it was removed."
    - <What> is "That campaign category", "That campaign", "That landing page", "That text ad", "That traffic source", "That traffic source account" or "That redirector". Rotators have no deleted column.
  - A source account under another source: "That traffic source account belongs to a different traffic source."
  - A campaign under another category: "That campaign belongs to a different category."
  - `publisher_id`:
    - Sent as an array: "That publisher was sent more than once."
    - Not grantable: "That publisher cannot be credited on your links: it needs the Publisher role, and to be switched on, in User management."
  - Edit with a `tracker_id` that `PublicId::parse()` refuses, or that is not the account's (also re-checked under `FOR UPDATE`): "That tracking link is not one of yours, or it was removed."
- **Warnings** (warn flash; the link is still made):
  - No `ppc_network_id`: "This tracking link is not attached to any traffic source, are you sure you want to do this?"
  - No `ppc_account_id`: "This tracking link is not attached to any traffic source account, are you sure you want to do this?"
  - CPC dollars or cents not numeric: "This tracking link does not have its CPC set, are you sure you want to do this?"
  - Type other than 2 with `click_cloaking` not set: "This tracking link is not attached to any cloaking preference, are you sure you want to do this?"
  - A CPA value is not checked as a number.
- **Writes and side effects** (all inside `Connection::transaction()`):
  - **Create**:
    1. `INSERT INTO 202_trackers` (user_id, aff_campaign_id, tracker_id_public '0', text_ad_id, ppc_account_id, click_cpc or click_cpa = `<dollars>.<cents>`, landing_page_id, rotator_id, click_cloaking, tracker_time = now).
    2. The public id comes from `TrackerPublicId::forPage()`: a random digit 1–9, then the tracker id, then a random digit. It tries 20 times for an id unique across all accounts, then falls back to a number drawn from 10,000,000–999,999,999.
    3. `UPDATE … SET tracker_id_public`.
  - **Edit**:
    1. `SELECT … FOR UPDATE`.
    2. `UPDATE 202_trackers SET` aff_campaign_id, text_ad_id, ppc_account_id, click_cpc (the value or NULL), click_cpa (the value or NULL), landing_page_id (from the looked-up landing page row, or 0), rotator_id, click_cloaking, tracker_time = now. `tracker_id` and the public id are kept.
  - **Publisher**: `INSERT INTO 202_publisher_grants` (user_id, publisher_user_id, granted_at) `ON DUPLICATE KEY` keeps the existing grant. It runs in the same transaction.
  - **Tokens**: the posted `c1`–`c4`, `t202kw`, `t202b` and `t202ref` values are `trim()`med and escaped with `$db->real_escape_string()` before they go into the link. That escape backslash-escapes quotes. No character set is enforced.
  - **Analytics**: `Analytics::event('tracker_created', ['via' => 'ui'], 'analytics')` on create only. It is consent-gated.
  - **Slack** (`Slack::forAccount`):
    - Create: `tracking_link_created`, with type "Direct Link", "Simple Landing Page", "Advanced Landing Page" or "Smart Rotator", plus the id and the user.
    - Edit, one event per thing that changed: `tracking_link_category_changed`, `tracking_link_campaign_changed`, `tracking_link_method_of_promotion_changed`, `tracking_link_landing_page_changed`, `tracking_link_text_ad_changed`, `tracking_link_text_ad_added`, `tracking_link_text_ad_removed`, `tracking_link_cloaking_changed`, `tracking_link_pcc_network_changed`, `tracking_link_ppc_account_changed`, `tracking_link_cost_type_changed`, `tracking_link_cost_value_changed` and `tracking_link_rotator_changed`.
    - The cloaking labels are "Campaign Default On", "Campaign Default Off", "Off - Overide Campaign Default" and "On - Overide Campaign Default".
    - When a name for a Slack message cannot be read, a warn flash reads "Slack was not told about every change to this link: a name for its message could not be read. The server log has the error."
  - **Variables**: read from `202_ppc_network_variables WHERE ppc_network_id = <posted> AND deleted = 0`, with no `ORDER BY`. The list on the page, and the API, order them by `ppc_variable_id`.
- **Answer** (HTML into `#tracking-links`):
  - On an edit that succeeds, an ok flash reads "Tracker updated. Your tracking link stays the same."
  - **Direct link** (type 0, `directlink`): `<base>tracking202/redirect/dl.php?t202id=N[&t202pubid=<rawurlencoded publisher public id>]&<variables>`.
  - **Redirector** (type 2): `<base>tracking202/redirect/rtr.php?t202id=N…`, with the same parameters.
  - **Landing page** (type 0 `landingpage`, or type 1): the landing page URL. The scheme defaults to http if the saved URL has none. Host, port and path are kept. An existing query gets `&`. Then come `t202id=N`, the publisher parameter, the variables and any `#fragment`.
  - The code box is labelled "Destination URL".
  - Except for ChatGPT Ads accounts, a landing page answer adds a **Google Ads parallel tracking** block:
    - "Final URL": the landing page URL, trimmed.
    - "Tracking template": `<base>tracking202/redirect/pt.php?t202id=N<publisher><variables>&lpurl={lpurl}`.
    - The text explains that auto-tagging must be on.
  - **ChatGPT Ads** (the account's source platform is `chatgpt_ads`, read by `ChatGptAdsSource::isAccountOnSource()`):
    - A heading and an explanation.
    - A warning when the template is over 2048 characters: "… longer than the 2048 characters Ads Manager takes. Remove some of this traffic source's custom variables."
    - "Destination URL": the landing page URL as saved when there is a landing page with a host. Otherwise `<base>dl.php` or `rtr.php` `?t202id=N` with the publisher parameter and no variables.
    - "Tracking template": `utm_source=chatgpt&utm_medium=paid&utm_campaign={campaign_id}&utm_content={ad_id}&campaign_id={campaign_id}&ad_id={ad_id}`. For a landing page it adds `t202id=N<publisher>`. It then adds the remaining variables, except the template's own keys and `oppref`. An empty `t202kw` is dropped unless it was set on the page. The source's own `campaign_id` and `ad_id` placeholders replace the defaults.
    - The full link sits under a disclosure (`setup-get-links-chatgpt-full`).
    - The answer stops before the tips.
    - If the platform cannot be read, a warn flash reads "Your link is saved, but whether its traffic source is ChatGPT Ads could not be read just now, so its ChatGPT Ads tracking template is not shown. Get the link again to see it."
  - A scheme note: "This link uses https:// / http://, because …", with a change link.
  - Tips: "Use this as the destination URL…". A disclosure "Keyword tokens" (`setup-get-links-keywords`) shows `&t202kw={QueryString}` for Bing and `&t202kw={keyword}` for Google.

#### Endpoint `tracking202/ajax/delete_tracker.php`
- Needs `require_user` and `access_to_setup_section`. It acts only on a POST from a role with `remove_tracker`. Anything else answers 200 with an empty body, so a role without the permission gets no refusal message.
- A CSRF failure answers 403 "Invalid token, please reload the page and try again."
- A missing `tracker_id` answers 200 "Error: No tracker ID provided".
- The id is read with `Prosper202\Http\PostedId::read()`.
- The write is `DELETE FROM 202_trackers WHERE tracker_id = ? AND user_id = ?`, a hard delete.
- A database failure answers 500 "The tracker could not be deleted. Reload the page and try again." When no row was deleted it answers 404, with a message saying it was not found on this account.
- Slack `tracking_link_deleted`, with type "Direct Link", "Simple Landing Page", "Advance Landing Page", "Smart Redirector" or "Unknown".

#### Endpoint `tracking202/ajax/revoke_publisher.php`
- Needs `require_user` and `access_to_setup_section`.
- A request that is not a POST answers 405 "Send this as a POST." A CSRF failure answers 403. An id that cannot be read answers 422 "That is not a publisher id."
- The write is `DELETE FROM 202_publisher_grants WHERE user_id = ? AND publisher_user_id = ?`.
- A failure answers 500 "Your links still credit this publisher: the change could not be saved. Reload the page and try again." It answers 200 even when no grant existed.

- **Edge cases and states**:
  - On edit, the token fields come back blank, so updating a link without typing them again drops them from the answer. They were never stored.
  - The landing page select is filtered client-side; the server separately refuses a landing page that is not the account's.
  - Both CPC and CPA pairs are posted. The chosen `cost_type` decides which is stored, and the other is set to NULL on edit.
  - The list is unbounded.
  - A link whose landing page, campaign or rotator now belongs to nobody (removed, or another account's) still lists, with a warn pill.
- **v3 coverage**: PARTIAL.
  - `POST /trackers`, `GET /trackers`, `GET /trackers/{id}`, `PUT /trackers/{id}`, `DELETE /trackers/{id}` and `POST /trackers/bulk-upsert` (`TrackersController`). All need `access_to_setup_section`; DELETE also needs `remove_tracker`.
  - Fields: aff_campaign_id (required unless rotator_id > 0 or the landing page is advanced), ppc_account_id, text_ad_id, landing_page_id, rotator_id, click_cpc, click_cpa (0–99.99999, not both), click_cloaking (-1 default, 0, 1) and tracker_id_public (kept if free, else drawn from 10M–999M).
  - `assertLinksOwned` checks the campaign, landing page, text ad, source account and rotator are the account's own and live.
  - Differences from the page:
    - `{id}` is the internal `tracker_id`, while the page edits by public id.
    - The list and get return only the raw columns: no names, no link, no `tracker_time`.
    - `tracker_time` is set on create and not refreshed on PUT.
    - There is no `aff_network_id`/`ppc_network_id` consistency check and no warnings.
    - No Slack notices, no analytics event, no publisher grant.
  - `GET /trackers/{id}/url` returns `tracker_id`, `tracker_id_public`, `direct_url` (the landing page URL when one resolves, else dl.php or rtr.php), `tracking_params` (`?t202id=N` plus variables) and `chatgpt_ads` ({destination, destination_url, tracking_template} or null).
    - It accepts only the built-in tokens as query parameters. Values are checked: no `&`, `#`, `?` or whitespace, at most 255 characters. Any other parameter is refused.
    - It has no `t202pubid` and no Google Ads final URL or `pt.php` tracking template.
  - There is no route for the publisher lists or for revoking a publisher.

---

### Get LP Code (redirect): `tracking202/setup/get_landing_code.php`

- **Where / who**: the Setup › "Get LP Code" menu entry. Needs `access_to_setup_section`.
- **Purpose**: entry point only.
- **Data shown**: none.
- **Inputs**: none.
- **Actions**: always redirects to `tracking202/setup/get_simple_landing_code.php`.
- **Writes and side effects**: none.
- **Edge cases and states**: a role without Setup is sent to `tracking202/` before the redirect.
- **v3 coverage**: not applicable (no content of its own).

### Get LP Code › Simple page: `tracking202/setup/get_simple_landing_code.php`

- **Where / who**: Setup › Get LP Code, tab "Simple page". The page calls `p202_setup_landing_code_page($db, 'simple', 'Get Landing Page Code', 'Landing Page Code')` in `tracking202/setup/_includes/landing_code_page.php`. Needs `access_to_setup_section`.
- **Purpose**: give the script and outbound links for a simple landing page, which promotes one campaign: ad, then page, then offer. It also checks whether the live page carries the script.
- **Data shown**:
  - Header icon `bi-terminal`, description "The code that tracks a visitor through your landing page to the offer.", and tabs "Simple page" and "Advanced page".
  - Panel "Your landing page", sub-line "A page that promotes one campaign: ad, then page, then offer."
  - Output panel "Your code" (`#tracking-links`). Before a submit it reads "Choose your landing page, then Get the code: it appears here, ready to copy."
  - Empty state when the account has no simple landing page: "No simple landing pages yet", "The code is made for one of your landing pages. Add the page first.", and a button "Add a landing page" that goes to `tracking202/setup/landing_pages.php`.
- **Inputs** (form POST to `tracking202/ajax/get_landing_code.php`):
  - `aff_campaign_id` "Campaign", required, picked automatically when there is only one.
  - Hidden `aff_network_id`, copied from the campaign.
  - Hidden `method_of_promotion=landingpage`.
  - `landing_page_id` "Landing page", required, filtered to the campaign.
  - `token`.
  - Submit "Get the code" (id `generate-tracking-link-simple`).
- **Actions**:
  - **Get the code**: jQuery POST to `tracking202/ajax/get_landing_code.php`. The answer replaces `#tracking-links`.
  - **Check my page**: a form inside the answer. It posts to `tracking202/ajax/check_landing_code.php` and shows the result in `#lp-check-result` (described below).

#### Endpoint `tracking202/ajax/get_landing_code.php`
- Needs `require_user` and `access_to_setup_section`. A CSRF failure answers 403.
- Refusals:
  - The same three type-0 messages as `generate_tracking_link.php` ("You have not selected an affiliate network.", "… affiliate campaign.", "You have to select your method of promoting this affiliate link.").
  - "You have not selected a landing page to use."
- Read: `202_landing_pages` LEFT JOIN `202_aff_campaigns` and `202_aff_networks` (tied to the user), by id and user. It does not check deleted, the landing page type, or whether the page's campaign is the one posted. If no row is found: "That landing page is not yours, or it was removed."
- Slack: `simple_landing_page_code_generated`, with the page name, campaign, network and user.
- Answer:
  1. "Test every link yourself …"
  2. **"1. Landing page code"**: the loader, to paste above `</body>` of the first page only.
  3. A disclosure "With the deferred pixel" (`setup-lp-code-defpixel`): the same loader with `&p202_defpixel=1`.
  4. The **"Check my page"** form (`p202_setup_lp_check`). When a result is stored, it shows "Last checked M j, H:i. <message>".
  5. **"2. Link out to the offer"**:
     - **PurLink**: the campaign URL itself, when it is an absolute http(s) URL, labelled "PurLink: link straight to the offer". When the campaign URL has no `[[subid]]` (or `%5b%5bsubid%5d%5d`), a warning reads "Add [[subid]] to the campaign URL where your network takes its SubID, or its conversions cannot be matched to the click."
     - **Option 1: outbound redirect link**: `<base>tracking202/redirect/go.php?lpip=<landing page public id>`.
     - **Option 2: outbound PHP redirect**, under a disclosure (`setup-lp-code-php`).
     - **Option 3: outbound JavaScript redirect**, under a disclosure (`setup-lp-code-js`).
  6. The dynamic content help (`setup-lp-code-segments`).
  - Base: `'//' . getTrackingDomain() . get_absolute_url()` (scheme-relative).

#### Snippets (`202-config/Setup/LandingPageCode.php`)
- **Loader**: an async `<script id="upxif">`. Its `src` is `<base>tracking202/static/landing.php?lpip=<public id>[&p202_defpixel=1]&t202id=" + encodeURIComponent(t)`, where `t` is the `t202id` read from `location.search`.
- **Outbound PHP (simple)**: a comment "Tracking202 PHP Redirection, created on <D M, Y>" with the landing page URL. It uses the cookie `tracking202outbound` when set. Otherwise it uses `htmlentities` of `<base>tracking202/redirect/lp.php?lpip=<public id>&pci=<cookie tracking202pci>`. It ends with `header('location: …')`.
- **Outbound JavaScript (simple)**: an HTML page titled "GO" with a comment marking where other pixels go. `readCookie('tracking202outbound')` is used when set, otherwise `lp.php?lpip=<public id>`.
- **Dynamic content segments**: `t202Country`, `t202CountryCode`, `t202Region`, `t202City`, `t202Postal`, `t202Browser`, `t202OS`, `t202Device`, `t202ISP`, `t202IP` (only when privacy tracks fully), `t202kw`, `t202c1`–`t202c4`, `t202utm_source`, `t202utm_medium`, `t202utm_term`, `t202utm_content` and `t202utm_campaign`. The help's example is `<span name="t202Country" t202Default="Your Country">Your Country</span>`.

#### Endpoint `tracking202/ajax/check_landing_code.php` (the landing-code checker)
- Needs `require_user` and `access_to_setup_section`. A CSRF failure answers 403.
- `landing_page_id` is read with `PostedId`. Without one, a bad flash reads "Choose a landing page, get its code, then check the page."
- `as=pill` makes it answer a status pill instead of a flash. Setup › Landing Pages uses that mode, up to 12 pages per load, one at a time; a failed check shows the pill "Could not be checked".
- It calls `session_write_close()`, then `LandingPageCheckStore::run(user, id, getTrackingDomain())`.
  - A null answer means "That landing page is not yours, or it was removed." (in pill mode, the unchecked pill).
  - Otherwise it shows a flash of the result's kind with its message, or the pill.
- **How the check works** (`LandingPageCheck`):
  - It fetches the saved URL with any `[[placeholders]]` removed.
  - The fetch is guarded by `WebhookGuard` (public addresses only, or the allowlist), pinned with curl, and routed through `EgressProxy`. User agent "Mozilla/5.0 (compatible; Prosper202 landing page check)".
  - Limits: 10 seconds in total, 5 redirects, 2 MB.
  - It looks for `tracking202/static/landing.(php|js)?lpip=N`.
- **Results** (status, then pill text):

  | Status | Pill text | Flash kind |
  |---|---|---|
  | `present` | "Code found" | ok |
  | `legacy` (landing.js) | "Older code found" | ok |
  | `mixed` | "Two codes" | warn |
  | `other_tracker` (the code's host is not the tracking host) | "Code from another tracker" | warn |
  | `wrong_id` | "Another page's code" | bad |
  | `commented` | "Code commented out" | bad |
  | `absent` | "Code not found" | bad |
  | `error` | "Could not be checked" | bad |
  | `none` | "Not checked yet" | bad |

  - Error messages cover a page with no public id, no URL ("Add it in Setup › Landing Pages."), a URL refused by the guard, timeouts, an HTTP status and too many redirects.
- **Writes**: `INSERT … ON DUPLICATE KEY UPDATE 202_landing_page_checks` (landing_page_id, user_id, checked_url (up to 255), check_status, check_message (up to 2000), checked_at).
  - A stored result counts only while `checked_url` equals the page's current URL.
  - A cron job (`recheckDue`) checks again every 86400 seconds, 5 pages per run.

- **Writes and side effects** (page): none on load. The endpoints send Slack notices (code) and store check results (checker).
- **Edge cases and states**:
  - The endpoint does not tie the landing page to the posted campaign. A crafted POST naming another of the account's pages gets that page's code, with the PurLink of the page's own campaign.
  - The base is scheme-relative, unlike Get Links.
- **v3 coverage**: PARTIAL.
  - `GET /landing-pages/{id}/code` (`SetupCodeController::landingPageCode`; needs Setup). For a simple page it returns landing_page_id, landing_page_id_public, landing_page_type, nickname, url, base_url, loader, loader_deferred_pixel, aff_campaign_id, purlink, outbound_link, outbound_php, outbound_javascript and segments. It repairs a missing public id. It requires a live campaign in a live category, which the page does not. It returns no flag for a missing `[[subid]]`: `purlink` is just the value or null, so the consumer must check. No Slack notice.
  - `POST /landing-pages/{id}/check` (no body) returns status, found, message, other_landing_page_ids_public, checked_at and fetched. Within a 30-second cooldown it answers the stored result instead, with `fetched: false`, `fetch_again_in_seconds` and a note. It is limited to 10 per key per 60 seconds (429).

### Get LP Code › Advanced page: `tracking202/setup/get_adv_landing_code.php`

- **Where / who**: Setup › Get LP Code, tab "Advanced page". The page calls `p202_setup_landing_code_page($db, 'advanced', 'Get Landing Page Code', 'Landing Page Code')`. Needs `access_to_setup_section`.
- **Purpose**: give the script and one outbound link set per offer for an advanced landing page, which links out to several campaigns or redirectors.
- **Data shown**:
  - The same header and tabs as the simple page.
  - Panel sub-line "A page with several offers, or a redirector, to link out to."
  - The same output panel.
  - Empty state: "No advanced landing pages yet", with the same text and button as the simple page.
- **Inputs** (form with `data-lp-offers`, POST to `tracking202/ajax/get_adv_landing_code.php`):
  - Hidden `counter`: the number of offers minus one.
  - `landing_page_id`: the advanced pages, required, preselected when there is only one.
  - "Offers on the page": one row per offer N, holding:
    - Radio `offer_typeN`: campaign (default) or rotator ("Redirector").
    - `aff_campaign_id_N` ("Choose a campaign", value 0).
    - `rotator_id_N` ("Choose a redirector", value 0; hidden until rotator is chosen).
    - Switching the type resets the other select to 0.
  - "Remove this offer" (not on the first row) and "Add another offer" (from a template). The script renumbers the rows 1..n and sets `counter`.
  - `token`.
  - Submit "Get the code" (id `generate-tracking-link-adv`).
- **Actions**: **Get the code**, by jQuery POST. **Check my page**, as for the simple page.

#### Endpoint `tracking202/ajax/get_adv_landing_code.php`
- Needs `require_user` and `access_to_setup_section`. A CSRF failure answers 403. The number of offers is `counter + 1`.
- Refusals:
  - No `landing_page_id`: "You have not selected a landing page to use."
  - No offer with a campaign or rotator other than 0: "Please select an affiliate campaign or rotator, and make sure no unused ones are there."
  - The landing page is read by id and user, with no type or deleted check. If none is found: "That landing page is not yours, or it was removed."
- Answer:
  - The loader, the deferred variant and the check form, as for the simple page. Then "2. Link out to each offer".
  - **Campaign offer**: the campaign is read by id and user only, with no deleted check. A failure shows the flash "Offer N: that campaign is not yours, or it was removed." Otherwise the offer shows:
    - Heading "Offer N: campaign <name>".
    - PurLink: the campaign URL plus `acip=<campaign public id>`. It is refused if the URL already has `acip`. The same missing-subid warning applies.
    - "Outbound link": `<base>tracking202/redirect/go.php?acip=<public id>`.
    - A disclosure "PHP redirect": `off.php?acip=…&pci=<cookie tracking202pci>`.
  - **Redirector offer**: the rotator is read by id and user. A failure shows "Offer N: that redirector is not yours, or it was removed." Otherwise the offer shows:
    - "Outbound link": `go.php?rpi=<rotator public_id>`.
    - "PHP redirect": `offrtr.php?rpi=…`.
  - The segments help.
- Slack: `advanced_landing_page_code_generated`, with the page name, the offer list and the user.
- **Writes and side effects**: Slack only.
- **Edge cases and states**:
  - The endpoint accepts a simple or removed landing page id, and a removed campaign, when posted by hand.
  - An offer that fails its check shows a flash, and the other offers are still answered.
  - An offer row left at 0 is skipped, as long as one offer is set.
- **v3 coverage**: PARTIAL.
  - `GET /landing-pages/{id}/code?offers=campaign:12,rotator:3` (at most 100 offers) returns `offers[]` with {position, type, id, public_id, name, purlink (campaign only), outbound_link, outbound_php}, plus the loader fields and segments.
  - It refuses `offers` for a simple page.
  - It requires live campaigns, which the page does not.
  - No Slack notice.
  - The checker is as for the simple page.

### Dynamic Smart Component: `tracking202/setup/get_dynamic_smart_component_code.php`

- **Where / who**: not linked from any menu or page in the served tree, so it is reached only by URL, and no Setup menu entry is marked current on it. It calls `p202_setup_landing_code_page($db, 'simple', 'Get Simple Landing Page Code', 'Dynamic Smart Component')`. Needs `access_to_setup_section`.
- **Purpose**: the same simple landing page code page, under the heading "Dynamic Smart Component". The dynamic content segments help is the part this heading points to.
- **Data shown, Inputs, Actions, Writes and side effects, Edge cases and states**: identical to Get LP Code › Simple page. It uses the same form, the same endpoint `tracking202/ajax/get_landing_code.php` and the same checker. Only the browser title and the heading differ.
- **v3 coverage**: PARTIAL, as for the simple page (`GET /landing-pages/{id}/code`, whose `segments` field carries the same list).

---

### Postback / Pixel: `tracking202/setup/get_postback.php`

- **Where / who**: Setup › Postback/Pixel. Browser title "Pixel And Postback URLs", heading "Postback / Pixel", description "Report conversions back to Prosper202: a pixel on the thank-you page, or a postback URL the network calls." Needs `access_to_setup_section`.
- **Purpose**: give the conversion pixel and postback URL snippets (simple, advanced per campaign, and the universal smart pixel), carrying the account's postback token. Nothing on the page posts. The snippets are drawn for the defaults on the server and rebuilt in the browser as the choices change.
- **Data shown**:
  - **Authentication line** (`PostbackAuthStore::ensureCredentials(user, now)`, run on every load):
    - If the read fails, a danger alert reads "Your postback token could not be read just now, so these URLs carry none, and an account that requires one refuses them. Reload the page; if it persists, see the server's error log." The URLs are then drawn without a token.
    - A recovery notice (warning), when the store has one.
    - Mode TOKEN: "Your account accepts only postbacks and pixels that carry its token (p202_token), as these URLs do, or a signature."
    - Mode OFF: "Your account also accepts postbacks and pixels without a token. These URLs carry it; once your networks use them, require it."
    - Mode SIGNED: "Your account accepts only signed postbacks, so these URLs are refused as they are: the token alone is not enough, and the pixels below cannot sign, so they are refused. Sign each postback with your signing secret, or accept the token again."
    - Each mode line ends with a link "Postback authentication settings" to `202-account/api-integrations.php#postback-auth`.
  - **Left panel** "Your pixel", sub-line "The simple pixel suits most campaigns.": the form below.
  - **Right panels**, one per pixel type:
    - **Simple pixel and postback** (`pixel_type=0`):
      - "Put the pixel on your conversion or thank-you page. It records the conversion whenever it loads."
      - Box "Global tracking pixel".
      - "Or give the network this postback URL for server-to-server tracking: it calls it with the sub id when a conversion happens. If the network only supports sid, change ?subid= to ?sid=."
      - Box "Global postback URL".
    - **Advanced pixel and postback** (`pixel_type=1`):
      - "For several clicks at once: the campaign you choose fills cid, so each conversion lands on its own campaign."
      - Boxes "Advanced global tracking pixel" and "Advanced global postback URL".
    - **Universal smart pixel** (`pixel_type=2`):
      - "Records the conversion and fires your traffic sources' pixels too. The JavaScript version carries a fallback for browsers without JavaScript."
      - Boxes "JavaScript universal smart pixel" and "Iframe universal smart pixel".
- **Inputs** (form `#tracking_form`, `onsubmit="return false"`, no submit button):
  - Radio `pixel_type`:
    - `0` "Simple: one click tracked at a time" (default)
    - `1` "Advanced: several clicks at once, per campaign"
    - `2` "Universal smart pixel: also fires your traffic sources' pixels"
  - `aff_campaign_id` "Campaign", shown for type 1 only. Default "Choose a campaign" (''). Hint "Fills the cid the advanced pixel reports under." Options come from `p202_setup_campaign_options()`.
  - `subid_value` "Sub id placeholder", placeholder `{aff_sub}`. Its datalist offers:
    - `{aff_sub}` HasOffers
    - `#s2#` Cake
    - `xxC1xx` HitPath
    - `[=SID=]` LinkTrust
    - `%subid1%`
    - `#s1#`
    - Hint: "Your network's token for the sub id, such as {aff_sub} or #s1#; empty leaves subid= for you to fill."
  - Advanced disclosure (`setup-postback-advanced`), summary "Advanced": amount, protocol.
    - `amount_value` "Amount" (inputmode decimal). Hint: "Empty by default, so each conversion pays the campaign's payout. An amount here overrides it."
    - Radio `secure_type`: `0` "http://" or `1` "https://". The default is the decided scheme (Shared › Tracking base URL). Hint: "Use https:// only when your tracking domain has a certificate."
  - The decided line under the form reads "Links use https://, because your tracking domain is saved with it." or "… because this page was served over HTTPS." (http in the same way). It has a "change" link, which opens the disclosure and focuses the radio. After a change, the line reads "Links use http(s)://, as you chose under Advanced."
- **Snippets** (`Prosper202\Setup\PostbackCode::snippets(root, amount, campaignId, subid, token)`):
  - `root` is `<scheme>://<tracking host and path>tracking202/static/`.
  - `<t>` is the account's postback token. The `&p202_token=<t>` part is left out when the token is empty.
  - The browser builder (`p202-setup.js`, postback builder, driven by `data-postback-builder`, `data-postback-token` and `data-root-path`) rebuilds the same strings on every input or change.

  | Snippet | Text |
  |---|---|
  | Global tracking pixel | `<img height="1" width="1" border="0" style="display: none;" src="<root>gpx.php?amount=<a>&subid=<s>&p202_token=<t>" />` |
  | Global postback URL | `<root>gpb.php?amount=<a>&subid=<s>&p202_token=<t>` |
  | Advanced global tracking pixel | the global pixel with `&cid=<campaign id>` |
  | Advanced global postback URL | the global postback with `&cid=<campaign id>` |
  | JavaScript universal smart pixel | a `<script>` that sets `vars202` = {amount, cid: "" (always empty), subid} and writes an iframe (id `upxif`) to `<root>upx.php?amount=&cid=&subid=&p202_token=`, plus a `<noscript>` iframe to the same URL |
  | Iframe universal smart pixel | `<iframe … src="<root>upx.php?amount=<a>&subid=<s>&p202_token=<t>" seamless></iframe>` |

- **Parameters the intake endpoints read that the snippets do not write** (from `gpx.php`, `gpb.php` and `upx.php`; documented in their own area):
  - `sid` in place of `subid`. With no sub id, the endpoints fall back to the cookies `tracking202subid_a_<cid>` and `tracking202subid`, or a PurLink signed sub id.
  - `cid`.
  - `amount`. A numeric value overrides the payout.
  - `p202_token`, or `p202_ts` + `p202_sig` (the signature).
  - Transaction id under any of `txid`, `transaction_id`, `transactionid`, `order_id`, `orderid`, `oid` or `t202txid`.
  - Customer: `cust` or `customer_ref`. Its type: `cust_type` or `customer_ref_type`.
  - Line items: `sku`, `product_id`, `product_name`, `qty` and so on.
  - Consent (`RequestSignals::consentGiven`).
- **Actions**: copy buttons only. There are no AJAX calls.
- **Writes and side effects**: loading the page runs `ensureCredentials()`. It mints the account's postback token, and signing secret, in `202_postback_auth` the first time, and can write deployment secrets. The page itself posts nothing.
- **Edge cases and states**:
  - The page script does not check what is typed in the amount or sub id fields. A value with a quote, `<`, `&` or whitespace goes into the snippet as typed. The API refuses such a value (see v3 coverage).
  - In SIGNED mode the snippets are still shown, with the line saying they are refused.
  - The JavaScript universal pixel always writes `cid: ""`, even after a campaign is chosen; campaigns are chosen only for type 1.
- **v3 coverage**: PARTIAL.
  - `GET /conversions/postback-code` (`SetupCodeController::postbackCode`; needs Setup) takes `amount`, `subid` (checked by `PostbackCode::problem()`: at most 255 characters, and no whitespace, control characters, quotes, `<`, `>`, `\` or `&`), `campaign_id` (an own, live campaign) and `scheme` (`http` or `https`).
  - It returns scheme, base_url, amount, subid, campaign_id, postback_token, postback_token_withheld, postback_auth_mode, postback_auth_recovered and the simple, advanced and universal snippets.
  - The token is included, and credentials minted, only for a key with the `conversions:write` scope. Otherwise `postback_token_withheld` is set.
  - The authentication settings themselves are under `/conversions/postback-auth`, which needs `conversions:write` and `access_to_api_integrations`.

---

### Not a page of this area: `tracking202/ajax/dni_get_offers.php`

This endpoint is used only by Setup › Campaigns (`aff_campaigns.php`, the "search offers" modal `#dni-offers-modal`). None of the pages above calls it.
- Needs `require_user` and `access_to_setup_section`. It reads the account's integration row in `202_dni_networks` by user and id, with `202_users.install_hash`.
- GET `all_offers` with `dni`, `offset`, `limit`, `column` and `filter[0]` (offer id) / `filter[1]` (name):
  - Sort `column[key]`: 0 id, 1 name, 2 default_payout, 3 payout_type, 5 require_approval.
  - `limit` is clamped to 1–100.
  - It POSTs to `https://my.tracking202.com/api/v2/dni/<hash>/offers/<network>/all/<offset>/<limit>`.
- GET `get_offer` with `offer_id`: `…/id/<id>`.
- POST branches, which need the token (403 otherwise):
  - `request_offer_access` with `offer_id` and `type`: `…/<type>/<id>`.
  - `submit_offer_questions` with `offer_id`: posts the answers (`$_POST` less the token) to `…/answers/<id>`.
  - `setup_offer` with `offer_id` and `ddlci`:
    - It requires the account's own non-deleted `202_aff_networks` row with that `dni_network_id`. Without one it answers 409 JSON "This network has no campaign category yet. Open Account › Integrations and save the network again."
    - It calls `…/setup/<id>`. A non-JSON answer gives 502 "The network did not answer with the offer's details. Try again in a minute."
    - It returns the JSON plus `aff_network_id`. The page script fills `dni_id`, `dni_offer_id`, `#aff_network_id`, `#aff_campaign_name`, `#aff_campaign_url` and `#aff_campaign_payout`.
- `offer_id` and `type` must match `^[A-Za-z0-9_-]{1,64}$`; anything else answers 400 "That offer id could not be read."
- There is no v3 route for the DNI offer browser.

---

## Missing v3 endpoints

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Get Links | Lists the users a link may credit as publisher (`PublisherCredit::grantable`) and those already credited (`granted`) | none | No route reads the grantable or granted publishers. |
| Get Links | Grants a publisher on link creation (`202_publisher_grants` insert) and adds `&t202pubid=<id>` to the link | `POST /trackers`, `GET /trackers/{id}/url` | Neither takes a publisher. The URL route never adds `t202pubid`. |
| Get Links | Stop crediting a publisher (`revoke_publisher.php`) | none | No route deletes a publisher grant. |
| Get Links | Google Ads parallel tracking: "Final URL" and the `tracking202/redirect/pt.php?…&lpurl={lpurl}` tracking template | `GET /trackers/{id}/url` | The answer has no final URL and no `pt.php` template. |
| Get Links, Get LP Code | Slack notices for link create, edit and delete and for code generation; `tracker_created` analytics event | none | The API sends no Slack notice and records no analytics event for these acts. |
| (Setup › Campaigns, via `dni_get_offers.php`) | Browse, request access to, answer questions for and set up DNI network offers | none | No DNI route exists. |

## Partial v3 coverage

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Get Links | Lists every link with its name, kind, warnings, the built link and its made date | `GET /trackers`, `GET /trackers/{id}/url` | The list returns raw columns only (no names, no link, no `tracker_time`); the link needs one URL call per tracker. |
| Get Links | Creates or edits a link, with category/campaign and source/account consistency checks and warnings | `POST /trackers`, `PUT /trackers/{id}` | No `aff_network_id`/`ppc_network_id` consistency checks or warnings. PUT does not refresh `tracker_time`. `{id}` is the internal id, not the public id the page edits by. |
| Get Links | Puts `c1`–`c4`, `t202kw`, `t202b` and `t202ref` values into the link | `GET /trackers/{id}/url` | Covered for the built-in tokens, but the API refuses values the page accepts (with `&`, `#`, `?`, whitespace, or over 255 characters). The page escapes quotes with backslashes and checks nothing. |
| Get LP Code › Simple page, Dynamic Smart Component | Loader, deferred-pixel loader, PurLink with a missing-`[[subid]]` warning, outbound link, PHP and JavaScript redirects, segments | `GET /landing-pages/{id}/code` | No missing-subid flag. It requires a live campaign in a live category, which the page does not. |
| Get LP Code › Advanced page | Per-offer PurLink, outbound link and PHP redirect for campaigns and redirectors | `GET /landing-pages/{id}/code?offers=…` | It requires live campaigns, and refuses offers for a simple page; the page answers removed campaigns and any page type. No per-offer flash for a bad offer: the request is refused. |
| Get LP Code (all) | "Check my page" | `POST /landing-pages/{id}/check` | Same check, with a 30-second cooldown that answers the stored result, and a limit of 10 per 60 seconds that the page does not have. |
| Postback / Pixel | Shows the pixel and postback snippets with the account's token, and mints the token on load | `GET /conversions/postback-code` | The token is included, and minted, only with the `conversions:write` scope. The API checks `amount` and `subid`; the page does not. |

## Not determined from code

- `dni_get_offers.php`: the page script sends `offset` as the page index (`state.page`) with `limit` 25. Whether the remote network reads that value as a page number or a row offset was not determined from code. I checked the endpoint and the Campaigns page script; the remote API is not in the tree.
- Whether the landing code pages' base (`'//' . getTrackingDomain() . get_absolute_url()`) always equals the API's scheme-relative `base_url` from the tracking base (for example, for a tracking domain saved with a path) was not determined from code. Both sides were read; no test compares them.
- How MySQL stores a CPA value that is not a number (`click_cpa = '<dollars>.<cents>'`, unchecked by the page) under the session's `sql_mode` was not determined from code. It depends on the server's mode, which was not measured.
