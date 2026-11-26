# Sign-in, install, upgrade and standalone pages

This area covers four groups of pages:

- **Before sign-in.** The site root, sign-in, lost password, password reset, 404 and the licence-key page.
- **First run.** The database wizard (`setup-config.php`), the requirements check, the licence hand-off (`get_apikey.php`) and account creation (`install.php`). The upgrade page belongs here too.
- **Standalone signed-in pages.** Sign-out, safe mode, the two "key required" notices, TV202, Hot Deals, App Store, Help, the bundled docs, the analytics disclosure, VIP Perks and the UI kit.
- **Redirect-only and non-PHP leftovers.** The `202-Mobile/` stubs, `202-license.php` and the empty `health/index.php`.

All of them bootstrap through `202-config/connect.php`, except `health/index.php`, `202-license.php` and `index.php`/`setup-config.php` before a config file exists. The auth rules are in `202-config/functions-auth.php` and the `AUTH` class, and they are described once below under "Shared rules". That section covers the session, remember-me, the licence gate, the throttle and the login log.

The pages before sign-in and on first run render through the standalone shell: `info_top()` / `info_bottom()` and `_die()` in `202-config/functions-standalone-ui.php`. The signed-in pages use `template_top()` / `template_bottom()`.

v3 (`api/v3`) can authenticate a browser with the pages' own session (`SessionSignIn`). The API cannot sign anyone in, though, and it cannot run before `202-config.php` exists, because `api/v3/index.php` requires that file first and a missing file is a fatal error. So a React front end still needs the sign-in, sign-out, password-reset, setup, install and upgrade flows as server pages, or as new endpoints.

| Page | Path | Permission |
|---|---|---|
| Site root (redirect only) | `index.php` | none (pre-login) |
| Sign in | `202-login.php` | none (pre-login) |
| Reset your password (request) | `202-lost-pass.php` | none (pre-login) |
| Choose a new password | `202-pass-reset.php?key=…` | none; the emailed key |
| Page not found | `202-404.php` | none |
| Access denied (redirect only) | `202-access-denied.php` | none; included by `AUTH::require_user()` |
| License key needed | `api-key-required.php` | none (pre-login) |
| Add your customer API key (notice) | `202-account/api-key-required.php` | signed in, licensed |
| App key required (notice) | `202-account/app-key-required.php` | signed in, licensed |
| License text | `202-license.php` | none; served as plain text |
| Sign out | `202-account/signout.php` | signed in (POST needs the session token) |
| PHP safe mode is on | `202-account/disable-safe-mode.php` | signed in, licensed |
| Setup entry (redirect only) | `202-config/setup.php` | none (pre-install) |
| Database settings wizard | `202-config/setup-config.php` | none; locked once installed |
| Requirements check | `202-config/requirements.php` | none (pre-install) |
| Get your license key | `202-config/get_apikey.php` | none (pre-install) |
| Create your account (install) | `202-config/install.php` | none (pre-install) |
| Upgrade | `202-config/upgrade.php` | none (no sign-in; POST needs the session token) |
| Mobile sign-in (redirect only) | `202-Mobile/202-login.php` | none |
| Mobile home (redirect only) | `202-Mobile/index.php` | none |
| Mobile mini stats (redirect only) | `202-Mobile/mini-stats/index.php` | none |
| TV202 | `202-tv/index.php` | signed in, licensed |
| Hot deals & discounts | `202-resources/index.php` | signed in, licensed |
| App Store | `202-appstore/index.php` | signed in, licensed |
| Health probe | `health/index.php` | none (empty file) |
| Bundled document viewer | `202-account/docs.php?doc=…` | signed in, licensed |
| Help | `202-account/help.php` | signed in, licensed |
| Product Analytics Disclosure | `202-account/disclosure.php` | signed in (no licence needed) |
| VIP Perks profile | `202-account/vip-perks.php` | signed in, licensed, `access_to_vip_perks` |
| UI kit (internal component gallery) | `202-account/ui-kit.php` | signed in, licensed, `access_to_settings` |

"Licensed" means `AUTH::require_user()` with its default `$requireLicense = true`, which runs `AUTH::require_valid_api_key()` (see "Licence gate" below).

---

## Shared rules (bootstrap, session, remember-me, licence, throttle, login log)

### Bootstrap (`202-config/connect.php`)

**Session settings:**

- `session.gc_maxlifetime` is 50000.
- The cookie lifetime is 0 (a browser-session cookie), `httponly`, SameSite Lax, and `secure` when the request is HTTPS.
- Strict mode is on.
- A script request (`X-Requested-With: XMLHttpRequest`) opens the session with `read_and_close`.

**Missing pieces:**

- With no `202-config.php`, the browser is redirected to `setup-config.php` (relative path).
- With no `vendor/`, the page answers a 500.
- When the database connection fails, `_die()` shows the database error page.

**Session token (CSRF):** a token is minted in `$_SESSION['token']` whenever the current one is not a non-empty string (`AUTH::csrf_token_usable()` and `mintCsrfToken()`, 128 bits). Every form here checks it with `AUTH::csrf_token_matches()`, or with `install_csrf_ok()` on install and upgrade. Both refuse an empty token.

**Upgrade redirect:** when the app is installed and `upgrade_needed()` holds, every page outside `202-config/` (and not `upgrade.php`) is redirected to `202-config/upgrade.php`. The click and redirect paths are exempt.

- `upgrade_needed()` is true when `PROSPER202::prosper202_version()` (from `202_version`) differs from `PROSPER202::php_version()` (the code, 1.9.81 here).
- `is_installed()` is true when `202_users` has at least one row. Only MySQL error 1146 (table missing) counts as "not installed"; any other failure throws.

**Safe-mode redirect:** with PHP `safe_mode` on, the sections `offers202`, `alerts202` and `stats202` redirect to `202-account/disable-safe-mode.php`.

**Who is signed in:**

1. `AUTH::remember_me_on_logged_out()` runs before the user object is built, except on `install.php`.
2. Then `new User($_SESSION['user_own_id'])`.
3. Then `AUTH::endSessionIfItsUserCannotAct()`.

**Finally:** an IPv6 probe runs, and `SET session sql_mode=''`.

### Who may act (`AUTH::logged_in()`)

A session counts as signed in only when both checks pass.

**`sessionClaimsSignIn`:**

- `user_name`, `user_id` and `session_fingerprint` are present.
- The fingerprint equals HMAC-SHA256 of `'session_fingerprint|' . User-Agent`, keyed by the session id.
- `session_time` is within the last 50000 seconds.

**`sessionUserStands`:**

- `UserStanding::readWithGeneration()` finds the user with `user_active = 1` and `user_deleted = 0`.
- The stored `user_session_generation` equals the session's copy.

A session whose user may no longer act is ended, and all of that user's remember-me keys are revoked. A session from before a generation change is ended without revoking anything.

### Page guard (`AUTH::require_user($auth_type = '', bool $requireLicense = true)`)

- **Signed in:** a session, or a remember-me cookie, through `logged_in_with_remember()`.
- **Not signed in, script request:** a script request (`X-Requested-With: xmlhttprequest`, or `Sec-Fetch-Dest: empty`) gets 401 with the text "You are signed out. Sign in again, then reload the page."
- **Not signed in, browser request:** a browser request includes `202-access-denied.php`, which redirects to sign-in. A `toolbar` type sets `$_SESSION['toolbar']`.
- **Signed in:** it calls `AUTH::set_timezone()`, then `require_valid_api_key()` when `$requireLicense` is true.

`AUTH::require_permissions()` (not used by these pages) answers 403 with the text "This account's role does not have the 'X' permission."

### Sign-in session (`begin_user_session`)

**Session values set:**

- It regenerates the session id.
- It sets `session_fingerprint`, `session_time`, `user_name`, `user_id`, `user_own_id`, `user_api_key`, `user_stats202_app_key`, `user_timezone` (UTC if empty), `user_mods_lb`, `account_owner_id`, `user_session_generation`, and the consent geo-hold flag (`ConsentPolicy::SESSION_GEO_HELD`).
- `account_owner_id` comes from `determineAccountOwnerId()`, which uses the install hash when there is no key.

**Database write:** `rememberSignInGeo` looks the address up in GeoLite2 and writes `202_users_pref.analytics_geo_is_eu`, `consent_version` and `analytics_revoked_version`.

**Analytics:** it fires `Analytics::event('login', [], 'essential')`.

### Remember-me

**The cookie:**

- Name `remember_me`.
- Value `<user_id>-<auth_key>-<hmac_sha256("<user_id>-<auth_key>", 202_users.secret_key)>`.
- Expires after 14 days (`LOGOUT_DAYS`), path `/`, domain `CookieDomain::fromServer()`, `secure` per `getSecureStatus()`, `httponly`, SameSite Lax.

**Issue:** issued only when the sign-in form's box is ticked.

- It inserts into `202_auth_keys`: `auth_key` (48 characters from `generate_random_string`), `user_id`, and `expires` (now + 14 days).
- If the user's `secret_key` is empty, a 48-character one is minted first.

**Check:**

- It joins `202_auth_keys` to `202_users`, needing `expires > now`, `user_deleted = 0` and `user_active = 1`, and verifies the HMAC.
- On success it begins a session as above and also sets `user_cirrus_link = user_api_key`. Only this path sets `user_cirrus_link`, which TV202 reads.
- A refused cookie is cleared. A cookie whose database read failed is kept.

**Clean-up and revocation:**

- `delete_old_auth_hash()` deletes expired keys at each sign-in.
- `RememberMeKeys::revokeOne()` runs on sign-out (the key the cookie names).
- `RememberMeKeys::revokeAll()` runs on switch-off, delete and password change. Those also bump `user_session_generation`.

### Licence gate (`AUTH::require_valid_api_key()`)

**Key lookup:** it reads `p202_customer_api_key` for `account_owner_id`, then `user_id`, then `user_own_id`.

**Validation:**

- `is_valid_api_key()` is cached per session in `$_SESSION['valid_key']`.
- Otherwise it asks `ClickServerKeyValidator::check()`. A licence confirmed within the grace period counts (`ShellAccessCache` / `MysqlLicenceAnswerStore`, table `202_licence_answers`).

**Valid key:** it writes `UPDATE 202_users SET p202_customer_api_key = ? WHERE user_id = <session user_id>` and records the answer.

**Refused key:**

- It redirects to `api-key-required.php`.
- When no answer came back and a key exists, it redirects to `api-key-required.php?licence=unconfirmed` instead.

### Sign-in throttle and login log

**Throttle (`RATE_LIMIT_WINDOW` = 900 seconds):**

- It counts `202_users_log` rows with `login_success = 0` and `login_time >= now - 900`.
- The limit is 10 failures by `user_name` or 50 by `ip_address`.
- If the count query fails, the throttle lets the attempt through (fails open).

**Outcome codes (`SignInOutcome`):** Failed = 0, Passed = 1, NotCompleted = 2. They read as `failed`, `passed`, `not_completed` and `unknown`.

**Log row (`SignInLog::append`):** it inserts into `202_users_log`:

- `user_name`.
- `user_pass = '[filtered]'`.
- `ip_address`, `login_time`, `login_success`.
- `login_error`: the serialized error list.
- `login_server`: a serialized snapshot of `REQUEST_METHOD`, `REQUEST_URI`, `SERVER_NAME`, `HTTP_HOST`, `HTTP_USER_AGENT`, `HTTP_REFERER` and `REMOTE_ADDR`.
- `login_session = ''`.

No row is written for an attempt refused by the throttle or by the session-token check.

**Last-sign-in record (`SignInRecord::record`):** it writes `UPDATE 202_users SET user_last_login_ip_id = ?, modal_status = COALESCE(?, modal_status), vip_perks_status = COALESCE(?, vip_perks_status)`.

- The IP id comes from `LocationRepository::findOrCreateIp()`.
- The two flags come from `getSurveyData(install_hash)`, a GET to `https://my.tracking202.com/api/v1/deep/survey/{hash}` (3 s connect, 5 s total).

**Passwords:** stored with `password_hash(PASSWORD_DEFAULT)`. The legacy salted MD5 (`md5('202' . md5(pass . '202'))`) and plain MD5 are still accepted at sign-in and rehashed there (`UPDATE user_pass`). A failed rehash is the NotCompleted outcome.

### v3 and the pages' session (`Api\V3\Auth\SessionSignIn`)

**When the session is used:** v3 accepts the pages' session only when the request carries no `Authorization` header and does carry the session cookie.

**Writes:** a write also needs the `X-P202-CSRF` header. Its value is `auth.csrf_token` from `GET /me`.

**Not tried:** the remember-me cookie is not tried. A session that is no longer valid answers 401.

**Licence:** v3's licence gate applies only to the Go CLI's User-Agent, so v3 has no equivalent of the pages' licence redirect for a browser.

---

## Pre-login pages

### Site root: `index.php`
- **Where / who:** `/`. No sign-in.
- **Purpose:** send the visitor to the right first step.
- **Data shown:** none, except the PHP-version failure card.
- **Inputs:** none.
- **Actions:**
  - With no `202-config.php`, it checks the PHP version first. Below 8.3.0 it shows `_die()` "PHP 8.3.0 or newer is needed" with links. Otherwise it redirects to `202-config/setup.php`.
  - With a config file, it loads `connect.php`:
    - Not installed: `202-config/setup.php`.
    - Upgrade needed: `202-config/upgrade.php`.
    - Otherwise: `202-login.php`.
- **Writes and side effects:** none.
- **Edge cases and states:** the PHP check runs before the config file is read, so it shows even on a fresh copy of the code.
- **v3 coverage:** none needed. It is routing. `GET /versions` and `GET /system/health` answer without auth, but only once a config file exists.

### Sign in: `202-login.php`
- **Where / who:** `/202-login.php`, optional `?redirect=`. No sign-in. Linked from every "Sign in" button and from `202-access-denied.php`.
- **Purpose:** password sign-in, with an optional 14-day remember-me.
- **Data shown:**
  - The standalone card "Sign in" with the wallpaper.
  - `getWallpaper()` POSTs to `https://my.tracking202.com/api/ads/wallpapers/extern` and falls back to a bundled image.
  - Ad slot `div-gpt-ad-1398648278789-0` (Google GPT `/1006305/P202_CS_Login_Page_288x200`), loaded by `info_top(['ads' => true])`.
- **Inputs:**
  - `user_name`: "Username", text, required, `autocomplete="username"`, trimmed.
  - `user_pass`: "Password", password, required.
  - `remember_me`: checkbox "Keep me signed in on this browser", off by default.
  - Hidden `token`: the session token.
  - Errors are checked in this order:
    1. Token refused: "Your session has expired. Please reload the page and try again."
    2. Empty username: "Please enter a username."
    3. Empty password: "Please enter a password."
    4. Throttled: "Too many failed login attempts. Please wait a few minutes and try again."
    5. Authentication:
       - NotCompleted: "Your sign-in could not be completed. Please try again in a moment."
       - Runtime error: "We were unable to process your login. Please try again later."
    6. Bad credentials: "Your username or password is incorrect."
- **Actions:**
  - It POSTs back to itself. The account read is `LOGIN_SELECT`: `202_users` with `user_name = ?`, `user_deleted = 0` and `user_active = 1`, joined to `202_users_pref` for the Slack webhook.
  - Link "Forgot your password or username?" goes to `202-lost-pass.php`.
- **Writes and side effects:**
  - On failure with a known account whose `user_slack_incoming_webhook` is set, it pushes `failed_login` to Slack: `Slack::forAccount()->push()`, "Failed Login Attempt:\n - Username: … - IP Address: …", with the address from `REMOTE_ADDR`.
  - On success:
    1. `delete_old_auth_hash()`.
    2. `SignInRecord::record()`, which makes the outbound survey GET.
    3. A memcache-cached `SHOW COLUMNS FROM 202_landing_pages LIKE 'leave_behind_page_url'` sets `user_mods_lb`.
    4. `begin_user_session()`.
    5. A remember-me key and cookie if the box was ticked.
    6. Redirect.
  - A log row goes to `202_users_log` on every attempt, except throttled or token-refused ones.
  - `prosper_log('login', …)` lines.
- **Edge cases and states:**
  - Not installed: it redirects to `202-config/setup-config.php`.
  - Already signed in, or signed in by the cookie: it redirects at once.
  - The redirect target is `Prosper202\Http\SignInReturn::path($_GET['redirect'])`:
    - Accepted: a path starting with a single `/`, printable ASCII, at most 2048 characters.
    - Refused: `//`, `/\`, any backslash, a scheme, a host or user info.
    - Otherwise the target is `202-account/`.
  - Any throwable while starting the session gives the NotCompleted message.
- **v3 coverage:** **MISSING**. No v3 route signs in with a password or issues the remember-me cookie. v3 only reads a session that this page made. `GET /me` reports the session's user and its `csrf_token` afterwards. `GET /system/login-log` reads the rows this page writes (admin with `access_to_settings`).

### Reset your password (request): `202-lost-pass.php`
- **Where / who:** `/202-lost-pass.php`. No sign-in. Linked from the sign-in page.
- **Purpose:** email a password-reset link.
- **Data shown:**
  - The form card.
  - After a POST it always shows the same result: "Check your email" / "If that username and email match an account, a link to choose a new password is on its way. It works for three days.", with a "Back to sign in" button.
- **Inputs:**
  - `user_name`: "Username", required.
  - `user_email`: "Email", type email, required.
  - Hidden `token`. Refused with "Your session has expired. Please reload the page and try again."
  - The submit button is "Email me a reset link". Link "Back to sign in".
- **Actions:**
  - It POSTs to itself.
  - Lookup: `SELECT user_id FROM 202_users WHERE user_name = '…' AND user_email = '…'` (escaped).
  - A failed query shows "Your request could not be processed just now. Please try again in a moment."
- **Writes and side effects:** on a match:
  - Key: `UPDATE 202_users SET user_pass_key = <bin2hex(random_bytes(32))>, user_pass_time = <now>`. If the UPDATE fails it is logged and no mail is sent.
  - Recipient: the posted email, with CR and LF stripped, if it passes `FILTER_VALIDATE_EMAIL`.
  - Link base: `PasswordResetLink::base()`, from user 1's `202_users_pref.user_tracking_domain` through `TrackingBaseUrl::build()`. It is never taken from the request's Host.
  - With a base:
    - Subject "[Prosper202 on {host}] Password Reset".
    - An HTML body with the site link, the username and `{base}202-pass-reset.php?key=…`.
    - From and Reply-To `prosper202@{host}`.
  - With no stored domain:
    - Subject "[Prosper202] Password Reset".
    - The body names the page path but gives no link.
  - Sent with PHP `mail()`, Content-Type text/html, iso-8859-1.
- **Edge cases and states:**
  - The lookup does not check `user_active` or `user_deleted`, so a switched-off or deleted user can be sent a key.
  - The page never says whether the account exists.
- **v3 coverage:** **MISSING**. There is no v3 route that issues a reset key or sends the email.

### Choose a new password: `202-pass-reset.php`
- **Where / who:** `/202-pass-reset.php?key=…`, from the emailed link. No sign-in.
- **Purpose:** set a new password with a reset key.
- **Data shown:** the read-only username of the key's user.
- **Inputs:**
  - `key` (query string). It is looked up with `SELECT * FROM 202_users WHERE user_pass_key = '…'`. A blank key is not looked up.
    - No row: "No key was found like that".
    - Older than `PasswordChange::RESET_KEY_SECONDS` (3 days): "Sorry, this key has expired, they expire in three (3) days."
  - `user_pass` and `verify_user_pass`: password, required, `minlength="8"`, `maxlength="72"`.
  - Hidden `token`.
  - Errors:
    - Token refused: "Your session has expired. Please reload the page and try again."
    - Empty password: "You must type in your desired password".
    - Empty verify field: "You must type verify your password".
    - Length outside 8 to 72: "Passwords must be 8 to 72 characters long".
    - Mismatch: "Your passwords did not match, please try again".
- **Actions:** it POSTs to itself.
- **Writes and side effects:** `PasswordChange::store()` runs in one transaction:
  1. `UPDATE 202_users FORCE INDEX (PRIMARY) SET user_pass = ?, user_pass_key = NULL, user_pass_time = 0 WHERE user_id = ? AND user_pass_key = ? AND user_pass_time >= now - 3 days`.
  2. `RememberMeKeys::revokeAll()`.
  3. `SessionGeneration::bump()` (`user_session_generation + 1`), which ends every signed-in session of that user.
- **Edge cases and states:**
  - Success: `_die()` "Password changed" with a sign-in link.
  - The key was used or expired between the read and the write (`ResetKeyNotUsable`): `_die()` "This reset link does not work" with "This link has already been used to set a password, or it has expired." and a link to `202-lost-pass.php`.
  - Any other failure: "Could not save your new password, please try again."
  - No `user_active` or `user_deleted` check.
- **v3 coverage:** **MISSING**. `PUT /users/{id}` changes a password (`user_pass`, 8 to 72, with `current_password` for one's own), but only for an authenticated caller. Nothing accepts a reset key.

### Page not found: `202-404.php`
- **Where / who:** served for unknown paths (where the web server is set up to use it). No sign-in.
- **Purpose:** a 404 card.
- **Data shown:** answers HTTP 404 with "Page not found" / "The link may have expired, or the address may be mistyped."
- **Inputs:** none.
- **Actions:** "Go to Prosper202" (the base URL) and "Back to the previous page" (`history.back()`).
- **Writes and side effects:** none.
- **Edge cases and states:** none.
- **v3 coverage:** none needed. v3 answers its own 404 JSON.

### Access denied (redirect only): `202-access-denied.php`
- **Where / who:** not linked. `AUTH::require_user()` includes it for a signed-out browser request.
- **Purpose:** clear the stale sign-in and send the visitor to sign in.
- **Data shown:** none.
- **Inputs:** none.
- **Actions:**
  - It redirects to `202-login.php?redirect=<urlencode(REQUEST_URI)>`.
  - If `$_SESSION['toolbar'] == 'true'` it redirects to `202-Toolbar/` instead. That directory is not in the tree, and no code calls `require_user('toolbar')`.
- **Writes and side effects:** it unsets the session's `user_name`, `user_id`, `user_own_id`, `session_fingerprint`, `session_time`, `valid_key` and `account_owner_id`.
- **Edge cases and states:** script requests never reach it. They get the 401 text instead.
- **v3 coverage:** none needed. v3 answers 401.

### License key needed: `api-key-required.php`
- **Where / who:** `/api-key-required.php` and `?licence=unconfirmed`, reached from the licence gate. No sign-in needed.
- **Purpose:** enter or replace the install's Tracking202 customer licence key.
- **Data shown:**
  - Current key: `SELECT p202_customer_api_key FROM 202_users WHERE user_id = '1'` (not null or empty).
  - It renders a 1x1 image from `https://my.tracking202.com/api/v2/dni/deeplink/cookie/set/{base64(p202TrackingBaseUrl())}`.
- **Inputs:**
  - `api_key`: "License key", monospace text, required.
  - Hidden `token`.
  - Errors:
    - Token refused: "Your session has expired. Please reload the page and try again."
    - Empty: "Please enter your API key."
    - No answer from Tracking202: `P202_ACCOUNT_KEY_UNCHECKED` ("The key could not be checked with Tracking202 just now, so nothing was saved. Try again in a minute.").
    - Refused: "Invalid API key; check it and retry."
    - No user 1: "No user found. Please complete the installation process first."
    - Save failed: "The key is valid, but it could not be saved. Please try again."
- **Actions:**
  - It POSTs to itself. Validation is `api_key_validate()`: `ClickServerKeyValidator::answer()` and `interpret()`, then `p202_record_key_answer()`. A key is valid when the JSON's `msg` is exactly `Key valid`.
  - Link "Get your API key" goes to `https://my.tracking202.com/api/customers/login?redirect=get-api`.
- **Writes and side effects:**
  - `UPDATE 202_users SET p202_customer_api_key = ? WHERE user_id = '1'` (only user 1, whoever is asking).
  - `$_SESSION['valid_key'] = true`.
  - The licence answer is recorded in `202_licence_answers`.
- **Edge cases and states:**
  - Saved: "License key saved", a Sign in button, and a note that a licence confirmed in the last 30 days still counts.
  - `?licence=unconfirmed`: "Your license could not be checked", with a note to reach my.tracking202.com over HTTPS, support@tracking202.com, and a "Try again" link to sign in.
  - Default: "Your license key is missing or expired", plus a warning when a key is saved but refused.
  - Anyone who can reach the page and pass a key check can replace user 1's key. No sign-in is asked.
- **v3 coverage:** **MISSING**. No v3 route saves the customer licence key. `GET /me` reports `switches.licence` as `licensed`, `unconfirmed` or `not_licensed`, but by the CLI's rule (`CapabilitiesController::cliAccess()`), which reports access when its check errors. The pages' gate refuses after the grace period instead.

---

## First run and upgrade (pre-login)

### Setup entry (redirect only): `202-config/setup.php`
- **Where / who:** `/202-config/setup.php`, from `index.php`. No sign-in.
- **Purpose:** route to the next setup step.
- **Data shown:** none.
- **Inputs:** none.
- **Actions:**
  - No config file: `setup-config.php`.
  - Installed: `202-login.php`.
  - Otherwise: `requirements.php`.
- **Writes and side effects:** none.
- **Edge cases and states:** the steps after it run in this order: `requirements.php`, then `get_apikey.php`, then `install.php`.
- **v3 coverage:** none needed. It is routing.

### Database settings wizard: `202-config/setup-config.php`
- **Where / who:** `/202-config/setup-config.php` with `?step=0|1|2|1.1|2.2`. No sign-in.
- **Purpose:** write `202-config.php` with the database settings, or rewrite a legacy one.
- **Data shown:**
  - Existing values are read from the current file with `P202_SETUP_CONFIG_LINE_RE`.
  - Locked page: "Prosper202 is already set up", with an Update link when the file is in the legacy form.
- **Inputs:**
  - Step 1 form (POSTs to `step=2`):
    - `dbname`: "Database name", default `prosper202`, required.
    - `dbuser`: "Username", default `root`, required.
    - `dbpass`: "Password", password.
    - `dbhost`: "Database host", default `localhost`, required.
    - Under the Advanced disclosure (`data-p202-remember="setup-config-advanced"`):
      - `dbhostro`: "Reporting database host", default `localhost`.
      - `mchost`: "Memcache host", default `localhost`.
      - `prefix`: read-only, `202_`.
  - The wizard uses its own token, `$_SESSION['p202_standalone_wizard_token']`, with a flag `p202_standalone_wizard_token_ok`.
- **Actions:**
  - Step 0: the welcome card and "Let's go".
  - Step 2:
    1. It needs a POST with the token. Otherwise: "Security check failed … nothing was written".
    2. It connects without a database. On failure: "Error establishing a database connection" with MySQL's message.
    3. It selects the database, or runs `CREATE DATABASE IF NOT EXISTS … CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`. On failure: "Could not create the database".
    4. It writes the file and shows "Database connected … All right sparky!" with a link to `requirements.php`.
  - Legacy update: `step=1.1` shows a card, then a POST with the token goes to `step=2.2`.
    - It carries the old values, probes the connection first, and rewrites them unchanged.
    - Outcomes: "Nothing to update", "The settings in 202-config.php do not connect" or "202-config.php updated".
- **Writes and side effects:**
  - `202-config.php` is written atomically from `202-config-sample.php`: `p202_setup_config_render()` and `p202_setup_config_replace()` write a temp file `.202-config.*.tmp.php`, chmod it 0640, then rename it.
  - It may create the database.
- **Edge cases and states:**
  - A missing `202-config-sample.php` or a directory that cannot be written ends in `_die()`.
  - Locked (`p202_setup_config_locked()`): when the file exists and its database has users, or the database cannot be read.
- **v3 coverage:** **MISSING**. v3 cannot run before this file exists, since `api/v3/index.php` requires `202-config.php` first. This has to stay a server page, or move to a separate bootstrap.

### Requirements check: `202-config/requirements.php`
- **Where / who:** `/202-config/requirements.php`. No sign-in. Step 1 of 3.
- **Purpose:** check the server before install.
- **Data shown:**
  - A table of checks:
    - PHP 8.3.0 or newer.
    - The database floor (MariaDB 10.6, MySQL 8.0, by `database_version_check()`).
    - curl.
    - `xml_parser_create`.
    - Partitioning: `SELECT COUNT(*) FROM INFORMATION_SCHEMA.PARTITIONS WHERE TABLE_SCHEMA = DATABASE()`, read as Enabled, Disabled or "Could not be checked".
    - memcache or memcached.
    - ZipArchive.
    - sodium.
    - OpenSSL.
  - A hosting-partners list from `getData('https://my.tracking202.com/api/v2/hostings')`.
- **Inputs:** none.
- **Actions:**
  - With no errors: "Your server is ready / Step 1 of 3" and "Install Prosper202 ClickServer now" (`id="202_lb_install_btn"`, `target="_blank"`), which opens `get_apikey.php`.
  - The page's script then sets `window.location` to `https://202.redirexit.com/tracking202/redirect/dl.php?t202id=72774&t202kw=req-screen-lb` after 200 ms (a leave-behind).
- **Writes and side effects:** an outbound GET for partners. The leave-behind click goes to an external tracker.
- **Edge cases and states:**
  - Installed: `_die()` "Already Installed".
  - The partitioning query always returns one row when it runs, so the check counts as enabled whenever it runs, whatever the count.
- **v3 coverage:** **MISSING**. There is no pre-install requirements route. `GET /system/health` and `GET /system/version` exist only after install.

### Get your license key: `202-config/get_apikey.php`
- **Where / who:** `/202-config/get_apikey.php` and `?customers_api_key=…` (the my.tracking202.com portal sends the visitor back with it). No sign-in. Step 2 of 3.
- **Purpose:** carry the customer licence key from the portal into the install form.
- **Data shown:**
  - With no key: "Get your license key / Step 2 of 3" and "Get my API key", which opens `https://my.tracking202.com/api/customers/login?redirect=get-api`.
  - With a key: "We found your license key" and the key in a read-only field `user_api`.
  - The deeplink 1x1 image, as on `api-key-required.php`.
- **Inputs:** `user_api` (read-only, from `?customers_api_key`).
- **Actions:** "Save the key and continue". Its script sets the cookie `user_api=<key>; path=<base>202-config/; SameSite=Lax` (plus `Secure` over HTTPS), then goes to `install.php`.
- **Writes and side effects:** the `user_api` cookie only. Nothing on the server.
- **Edge cases and states:** when the app is installed, a request with `customers_api_key` is redirected to `/202-login.php`, and one without it gets `_die()` "Already Installed".
- **v3 coverage:** **MISSING**. There is no route; it is a client-side hand-off.

### Create your account (install): `202-config/install.php`
- **Where / who:** `/202-config/install.php`. No sign-in. Step 3 of 3.
- **Purpose:** create the schema and the first (Super user) account.
- **Data shown:** the form. After success, the panel from `render_install_success()`:
  - The username and the sign-in address.
  - Any warnings.
  - The cron line `* * * * * curl -s "{base}202-cronjobs/index.php" >/dev/null 2>&1`, with a copy button.
  - The REST API key, shown once, with a copy button.
  - The instance URL and user id.
  - A Sign in button.
- **Inputs:**
  - `user_email`: "Your email". Must be a valid email (`check_email_address()` / `filter_var`). Refused with "Please enter a valid email address".
  - `user_timezone`: a select of `DateTimeZone::listIdentifiers()` labelled "[abbr offset]".
    - Default: the posted value, else the server's `TZ`, else `America/Los_Angeles`.
    - The page's script selects the browser's zone.
  - `user_name`: "Username".
    - Empty: "You must type in your desired username".
    - Not alphanumeric (`ctype_alnum`): "Your username may only contain alphanumeric characters".
    - Outside 4 to 20 characters: "Your username must be between 4 and 20 characters long".
  - `user_pass` and `verify_user_pass`: 6 to 35 characters (`install_default_rules()`).
    - "You must type in your desired password".
    - "You must verify your password".
    - "Your password must be at least 6 characters long".
    - "Your password must be no more than 35 characters long".
    - "Your passwords did not match, please try again".
  - Hidden `token`, checked by `install_csrf_ok(session token, posted token)`. Refused with "Your session has expired or the security check failed. Please refresh the page and submit again."
  - Hidden `user_api`: from the POST or the `user_api` cookie, trimmed.
    - It must pass `install_license_key_is_valid(api_key_validate(...))` (msg `Key valid`).
    - Otherwise: "We couldn't confirm that license key with my.tracking202.com. Fetch your key again, then submit once more."
- **Actions:**
  - The page's script validates on the client, then POSTs with `fetch()`: `X-Requested-With` and `FormData`.
  - It expects JSON:
    - `{success: true, html, warnings}`, or
    - `{success: false, retryable, errors: {user_email, user_name, user_pass, general}}`.
  - It retries up to 3 times, waiting 2^n seconds, on a retryable answer or a network failure.
  - On success it replaces `#install-panel` with `html`.
  - Without script, the form posts normally.
- **Writes and side effects:**
  1. `INSTALL::install_databases()`: the schema (`SchemaInstaller`), seed data and version (`DataSeeder`), `InstallTokenKey::ensure()`, `InstallId::ensure()`, `InstallIdCache::remember()`, and publisher ids.
  2. `InstallAccount::create()` in one transaction:
     - `INSERT IGNORE 202_users` with `user_email`, `user_dash_email` (the same email), `user_name`, `user_pass`, `user_timezone`, `user_time_register`, `install_hash = md5(uniqid())`, `user_hash = ''` and `p202_customer_api_key`.
     - `INSERT IGNORE 202_users_pref`.
     - `INSERT IGNORE 202_user_role` (role 1).
     - `DefaultModel::ensureFor()` (`202_attribution_models`).
     - A default chart in `202_charts`.
     - Errors 1205, 1213, 2006 and 2013 are retryable: "The database was briefly unavailable…". Any other: "We couldn't finish creating your account…" with links.
  3. `ShellAccessCache::recordLicence()`.
  4. `callAutoCron('register')`: GET `https://my.tracking202.com/api/v2/autocron/register/{base64(base)}`. On success it writes `UPDATE 202_users_pref SET auto_cron = '1'`. On failure it adds a warning.
  5. A REST API key (`bin2hex(random_bytes(32))`) stored hashed through `ApiKeyStore::insert()`.
  6. `install_database_partitions()`. On failure it adds a warning.
- **Edge cases and states:**
  - Installed: "Already Installed" (JSON for a script request).
  - Failing version checks: redirect to `requirements.php`.
  - The remember-me check is skipped on this page.
- **v3 coverage:** **MISSING**. There is no install or first-account route. `POST /users` creates further users and needs an authenticated admin.

### Upgrade: `202-config/upgrade.php`
- **Where / who:** `/202-config/upgrade.php`. Every page redirects here while `upgrade_needed()` holds. No sign-in. The POST needs the session token.
- **Purpose:** run the schema upgrade ladder from the stored version to the code's version.
- **Data shown:**
  - "Upgrade to Prosper202 {version}".
  - "Upgrading from {stored} to {code}", with a note about time limits.
  - A downgrade wording when the stored version is newer than the code.
  - A "What's new" disclosure from the `changelog()` entries newer than the stored version (compared with `version_compare()`).
  - The backup warning (`p202_upgrade_backup_warning()`).
  - A link to the 1.9.56 notes for installs that came from the paid edition (`PaidOrigin`).
- **Inputs:**
  - Hidden `token` (`install_csrf_ok()`). Refused with the card "Security check failed".
  - `date_from`: d-m-Y, asked only when the stored version is below 1.9.3. Its default is the first click's date from `202_clicks`. Refused with "That date was not read".
  - `lp_ssl`: radios, 1 (default) or 0, shown when the stored version is older than the code. Only the 1.9.55 rung reads it: with 1 or no value it runs `UPDATE 202_landing_pages SET landing_page_url = REPLACE(landing_page_url, 'http://', 'https://')`.
- **Actions:**
  - The submit button disables itself on submit.
  - `UPGRADE::upgrade_databases($time_from)` takes the MySQL lock `GET_LOCK('prosper202_upgrade:' || SHA1(DATABASE()))`.
    - Busy: "An upgrade is already running".
    - Failure: `p202_upgrade_failure_report()`, which shows the stored version, advice and a copyable report.
- **Writes and side effects:**
  - The whole ladder: schema changes, then `202_version`.
  - Then `clear_php_caches()` and `unset($_SESSION['user_id'])`.
  - The "Success!" card's "Sign in" button is a form that posts to `202-account/signout.php`.
- **Edge cases and states:**
  - When no upgrade is needed (and `SettlementSchema::needsRepair()` is false), the page redirects to sign-in.
  - Requirement failures show the requirements table and partners, then stop.
  - Anyone who can load the page can start a pending upgrade. Only the session token is asked.
- **v3 coverage:**
  - **MISSING** for running the upgrade, its lock, the changelog and the report.
  - **PARTIAL** for detecting that an upgrade is pending:
    - `GET /me` returns `install.version` (code) and `install.schema_version` (stored).
    - `GET /system/version` returns the stored version (admin and `access_to_settings`).
    - While the schema lags, a failing v3 read answers 503 naming the upgrade (`Bootstrap::pendingUpgrade()`).

---

## Signed-in standalone pages

### Sign out: `202-account/signout.php`
- **Where / who:** the account menu's "Sign Out" (id `SignoutPage`), a form from `p202_signout_form()` (`data-p202-signout`, hidden token). Signed in.
- **Purpose:** end the session and forget this browser.
- **Data shown:** on GET while signed in: "Sign out of Prosper202?" / "This browser stops being signed in, and stops remembering you.", with the sign-out form and a "Stay signed in" link.
- **Inputs:** hidden `token`. A refused token answers 403 with the warning "That sign-out did not come from a page of yours, so you are still signed in. Press Sign out to sign out."
- **Actions:**
  - POST with a valid token: `AUTH::signOut()`, then a redirect to the base URL (or `202-Mobile/` when `$_SESSION['toolbar']` is set).
  - GET while signed out: redirect.
- **Writes and side effects:**
  - It empties the session and calls `session_destroy()`.
  - It clears the `remember_me` cookie.
  - `RememberMeKeys::revokeOne()` deletes the key the cookie names.
  - `Cache-Control: no-store`.
- **Edge cases and states:** the upgrade page's "Sign in" button posts here.
- **v3 coverage:** **MISSING**. `POST /connect/revoke` revokes the calling API key and refuses a session with 400. No route ends a session or revokes a remember-me key.

### PHP safe mode is on: `202-account/disable-safe-mode.php`
- **Where / who:** reached only by the bootstrap's safe-mode redirect. Signed in, licensed.
- **Purpose:** static notice to turn off PHP safe mode.
- **Data shown:** static text.
- **Inputs:** none.
- **Actions:** none.
- **Writes and side effects:** none.
- **Edge cases and states:** PHP 8 has no `safe_mode`, so the redirect is not expected to fire on a supported PHP.
- **v3 coverage:** none needed.

### Add your customer API key (notice): `202-account/api-key-required.php`
- **Where / who:** signed in, licensed. No page links here. The only reference found is a comment in `CapabilitiesController`.
- **Purpose:** an empty state "Add your customer API key", linking to `202-account/account.php#customer-key`.
- **Data shown:** static.
- **Inputs:** none.
- **Actions:** the link only.
- **Writes and side effects:** none.
- **Edge cases and states:** the page itself needs a licence, so a user who lacks one is redirected to `api-key-required.php` first.
- **v3 coverage:** none needed. It is static.

### App key required (notice): `202-account/app-key-required.php`
- **Where / who:** signed in, licensed. Not linked from the menus.
- **Purpose:** "App key required". It says there is no Stats202 app key field any more, and links to Help.
- **Data shown:** static.
- **Inputs:** none.
- **Actions:** link to `help.php`.
- **Writes and side effects:** none.
- **Edge cases and states:** none.
- **v3 coverage:** none needed.

### License text: `202-license.php`
- **Where / who:** `/202-license.php`. No sign-in.
- **Purpose:** the licence text.
- **Data shown:** the file has no `<?php` tag, so it is served as plain text: the BUSL-1.1 header (Blue Terra LLC, change date 2031-02-22, change licence GPL-2.0-or-later).
- **Inputs:** none.
- **Actions:** none.
- **Writes and side effects:** none.
- **Edge cases and states:** none.
- **v3 coverage:** none needed.

### TV202: `202-tv/index.php`
- **Where / who:** top navigation "Watch TV202". Signed in, licensed.
- **Purpose:** show the Tracking202 video feed.
- **Data shown:**
  - It reads `getData('https://my.tracking202.com/api/feeds/tv202?us={$_SESSION['user_hash']}&t202aid={$_SESSION['user_cirrus_link']}')`.
  - `p202_tv_modules()` pulls YouTube embeds, `h6` titles and `p` descriptions out of the feed.
- **Inputs:** none.
- **Actions:** "Try again" (reload) on the empty state.
- **Writes and side effects:** an outbound GET.
- **Edge cases and states:**
  - Empty or failed feed: "TV202 is not available right now".
  - No code sets `$_SESSION['user_hash']`, so `us=` is always empty.
  - `user_cirrus_link` is set only by a remember-me sign-in, so it is empty after a password sign-in.
- **v3 coverage:** **MISSING**. There is no route for the TV202 feed. A React page would need a server proxy or a direct call to my.tracking202.com.

### Hot deals & discounts: `202-resources/index.php`
- **Where / who:** top navigation "Hot Deals & Discounts". Signed in, licensed.
- **Purpose:** list partner deals.
- **Data shown:**
  - It fetches `https://my.tracking202.com/feed/resources/` with curl (5 s connect, 10 s total).
  - `p202_resource_deals()` reads `deals[]`: `title`, `deal-url`, `deal-img`, `deal-description` and `deal-coupon`.
- **Inputs:** none.
- **Actions:** "Get the deal" (with a coupon) or "Visit site", each to `deal-url`.
- **Writes and side effects:** an outbound GET.
- **Edge cases and states:** an empty or failed feed shows "Deals are temporarily unavailable".
- **v3 coverage:** **MISSING**. There is no route for the deals feed.

### App Store: `202-appstore/index.php`
- **Where / who:** `/202-appstore/`. Signed in, licensed. No menu link (only the release manifest names it).
- **Purpose:** list Tracking202 apps, and save the ClickServer API key.
- **Data shown:**
  - Feed `https://my.tracking202.com/feed/appstore/`, read by `p202_appstore_apps()`: `title`, `app-img`, `app-description`, `app-url`, `app-install` (installed, coming-soon or un-installed), `app-price` and `app-status` (popular).
  - The saved key shown masked: 22 stars plus `substr(key, 22)`.
- **Inputs:**
  - `clickserver_api_key`: text. An empty value removes the key.
  - Hidden `token`. Refused with `P202_ACCOUNT_TOKEN_REFUSED`.
  - A value containing `*` changes nothing: info flash "Nothing was changed: the key shown is masked…".
  - Validation is `clickserver_api_key_validate()`: GET `https://my.tracking202.com/api/v1/auth/?apiKey=…&clickserverId=<base64(HTTP_HOST)>`.
    - No answer: `P202_ACCOUNT_KEY_UNCHECKED`.
    - Refused: "This API Key appears invalid."
- **Actions:**
  - POST to itself, then a 303 redirect with the flash "ClickServer API key saved." or "ClickServer API key removed.".
  - App buttons link to `app-url`.
- **Writes and side effects:** `UPDATE 202_users SET clickserver_api_key = ? WHERE user_id = ?`. Outbound GETs.
- **Edge cases and states:** an empty or failed feed shows "The App Store is not available right now".
- **v3 coverage:** **MISSING**. There is no route for the App Store feed, and none that reads or writes `clickserver_api_key`.

### Health probe: `health/index.php`
- **Where / who:** `/health/`. No sign-in, no bootstrap. Docker and Coolify health checks use it.
- **Purpose:** a liveness probe.
- **Data shown:** an empty file, so it answers 200 with an empty body.
- **Inputs:** none.
- **Actions:** none.
- **Writes and side effects:** none.
- **Edge cases and states:** it answers before install and with the database down, so it shows only that PHP serves files.
- **v3 coverage:** **PARTIAL**. `GET /system/health` (no auth) reports `status`, `timestamp`, `api_version`, `geoip_database`, `unsaved_click_redirects` and `warnings`, but needs the config file and the bootstrap. Nothing in v3 answers before setup.

### Bundled document viewer: `202-account/docs.php`
- **Where / who:** `?doc=` one of `attribution-engine`, `attribution-troubleshooting`, `api-integrations` or `ui-standard`. Linked from Help, and from the UI kit (`ui-standard`). Signed in, licensed.
- **Purpose:** render a bundled Markdown document from `documentation/`.
- **Data shown:** `markdownToHtml()` output. The first `h1` becomes the page header. A "Help" back button.
- **Inputs:** `doc` (query string), one of the four keys.
- **Actions:** none.
- **Writes and side effects:** none.
- **Edge cases and states:**
  - Any other key answers 404 "Document not found".
  - A missing file answers 404 "Document file not found".
- **v3 coverage:** **MISSING**. No route serves the bundled documents.

### Help: `202-account/help.php`
- **Where / who:** account menu "Help" and the footer. Signed in, licensed.
- **Purpose:** links to support and documentation.
- **Data shown:**
  - Static links: support.tracking202.com, youtube.com/t202nana, tracking202.com/videos and prosper.tracking202.com/blog.
  - Three `docs.php` links.
  - The paid 1.9.56 notes link when `p202_came_from_paid_1956()`.
- **Inputs:** none.
- **Actions:** links only.
- **Writes and side effects:** none.
- **Edge cases and states:** none.
- **v3 coverage:** none needed (static). The "came from paid" flag has no v3 field, but it was not determined whether `GET /me`'s `features` carries it.

### Product Analytics Disclosure: `202-account/disclosure.php`
- **Where / who:** signed in (`AUTH::require_user('', false)`, no licence needed). Linked from the consent text.
- **Purpose:** explain the product analytics the app sends.
- **Data shown:** static text.
- **Inputs:** none.
- **Actions:** a link to `202-account/account.php#data-consent`.
- **Writes and side effects:** none.
- **Edge cases and states:** it is reachable without a licence.
- **v3 coverage:** none needed (static).

### VIP Perks profile: `202-account/vip-perks.php`
- **Where / who:** account menu "VIP Perks Profile", with a badge while `vip_perks_status` is set. Signed in, licensed, with `access_to_vip_perks`; without it the browser is redirected to `202-account/`.
- **Purpose:** answer the Tracking202 VIP Perks survey.
- **Data shown:**
  - `get_user_data_feedback()`, then `getSurveyData(install_hash)` (GET `https://my.tracking202.com/api/v1/deep/survey/{hash}`), which gives `question_groups` and `questions`.
  - Questions that are highlighted and unanswered get a "new" pill.
- **Inputs:**
  - One Yes/No answer per question id. Any other value is refused with "Answer Yes or No.".
  - Unanswered questions give the flash "One question is not answered yet." or "N questions are not answered yet."
  - Hidden token.
- **Actions:** POST to itself. `updateSurveyData()` POSTs the answers to `https://my.tracking202.com/api/v1/deep/survey/{hash}`.
- **Writes and side effects:**
  - When the service answers `updated`: `UPDATE 202_users SET modal_status = '1', vip_perks_status = '0'`, and the flash "Thank you. Your VIP Perks profile is saved."
  - If that write fails: "The VIP Perks reminder could not be switched off…".
- **Edge cases and states:**
  - Service refused: "The VIP Perks service did not accept the answers…".
  - Service unreachable: "…could not be reached…".
  - No questions: "The questions could not be loaded".
- **v3 coverage:** **MISSING**. No route reads or submits the survey. `GET /me` has `flags.can_see_vip_perks` only.

### UI kit: `202-account/ui-kit.php`
- **Where / who:** signed in, licensed, with `access_to_settings`; without it the browser is redirected to `202-account/`. Not in the menus.
- **Purpose:** an internal component gallery for developers. It renders every component of the page standard in every state.
- **Data shown:** static sections: tokens, type, buttons, forms, pills, tiles, table, reports, panel, states, code, overlays and standalone. A link to `docs.php?doc=ui-standard`.
- **Inputs:** demonstration forms only. They are inert (`onsubmit="return false"`).
- **Actions:** none that reach the server.
- **Writes and side effects:** none.
- **Edge cases and states:** none.
- **v3 coverage:** none needed. It is not a user feature.

---

## Redirect-only mobile stubs

### Mobile sign-in: `202-Mobile/202-login.php`
- **Where / who:** no sign-in.
- **Purpose:** an old mobile entry.
- **Data shown:** none.
- **Inputs:** none.
- **Actions:** 302 to `202-login.php?redirect=<rawurlencoded absolute tracking202/overview/>`.
- **Writes and side effects:** none.
- **Edge cases and states:** `SignInReturn::path()` accepts only a path, so an absolute URL in `redirect` was not checked further here, and where it lands was not run.
- **v3 coverage:** none needed.

### Mobile home: `202-Mobile/index.php`
- **Where / who:** no sign-in. Also the target of sign-out when the session has the toolbar flag.
- **Purpose:** an old mobile entry.
- **Data shown:** none.
- **Inputs:** none.
- **Actions:** 302 to the base URL.
- **Writes and side effects:** none.
- **Edge cases and states:** none.
- **v3 coverage:** none needed.

### Mobile mini stats: `202-Mobile/mini-stats/index.php`
- **Where / who:** no sign-in.
- **Purpose:** an old mobile entry.
- **Data shown:** none.
- **Inputs:** none.
- **Actions:** 302 to `tracking202/overview/`.
- **Writes and side effects:** none.
- **Edge cases and states:** none.
- **v3 coverage:** none needed.

---

## Missing v3 endpoints

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Sign in (`202-login.php`) | Password sign-in, throttle, login log, Slack failed-login alert, session start | `GET /me` (reads a session) | No route signs in. The throttle, log write and alert exist only in the page. |
| Sign in (`202-login.php`) | Issues the 14-day remember-me cookie and key | none | v3 neither issues nor accepts the remember-me cookie. |
| Sign out (`202-account/signout.php`) | Ends the session and revokes this browser's remember-me key | `POST /connect/revoke` (API keys only, refuses a session) | No route ends a session. |
| Reset your password (`202-lost-pass.php`) | Mints a reset key and emails the link | none | No password-reset request route. |
| Choose a new password (`202-pass-reset.php`) | Sets a password by reset key, revokes keys, bumps the session generation | `PUT /users/{id}` (authenticated only) | No route accepts a reset key. |
| License key needed (`api-key-required.php`) | Validates and saves the customer licence key for user 1 | `GET /me` `switches.licence` (read only) | No route saves the licence key. |
| Database wizard (`202-config/setup-config.php`) | Writes `202-config.php`, creates the database | none | v3 cannot run before the config file exists. |
| Requirements (`202-config/requirements.php`) | Pre-install server checks, partners list | none | No pre-install route. |
| Get your license key (`202-config/get_apikey.php`) | Passes the portal's key to the install form by cookie | none | No route. Client-side hand-off. |
| Install (`202-config/install.php`) | Schema install, first account, default model and chart, AutoCron registration, first REST key, partitions | `POST /users` (needs an admin) | No install or first-account route. |
| Upgrade (`202-config/upgrade.php`) | Runs the ladder under a lock, shows the changelog and the failure report | none | No route runs the upgrade or reads the changelog. |
| TV202 (`202-tv/index.php`) | Reads the remote TV202 feed | none | No feed route. |
| Hot deals (`202-resources/index.php`) | Reads the remote deals feed | none | No feed route. |
| App Store (`202-appstore/index.php`) | Reads the remote App Store feed | none | No feed route. |
| App Store (`202-appstore/index.php`) | Validates and saves or removes `202_users.clickserver_api_key` | none | No route for the ClickServer key. |
| VIP Perks (`202-account/vip-perks.php`) | Reads the survey and posts the answers, clears the reminder flags | `GET /me` `flags.can_see_vip_perks` | No survey read or submit route. |
| Bundled docs (`202-account/docs.php`) | Renders four bundled Markdown documents | none | No route serves the documents. |

## Partial v3 coverage

| Page | What the page does | Nearest v3 route (or none) | Gap |
|---|---|---|---|
| Upgrade (`202-config/upgrade.php`) and the bootstrap redirect | Detects that the stored version differs from the code's | `GET /me` (`install.version`, `install.schema_version`); `GET /system/version` (admin, `access_to_settings`); the 503 that names a pending upgrade | Detection only. Nothing runs or previews the upgrade. |
| Licence gate (every licensed page) | Refuses an unlicensed install after the grace period and redirects to `api-key-required.php` | `GET /me` `switches.licence` | v3 reports by the CLI rule (access when its check errors) and enforces it only for the Go CLI. A browser session is never refused for the licence. |
| Health probe (`health/index.php`) | Answers 200 before install, with no bootstrap | `GET /system/health` (no auth) | v3 needs `202-config.php` and the database. It cannot stand in for a pre-install probe. |
| Sign in (`202-login.php`) | Writes the login log | `GET /system/login-log` (admin, `access_to_settings`) | Read only. The write exists only in the page. |

## Not determined from code

- **Mobile sign-in redirect.** Where `202-Mobile/202-login.php` lands after sign-in. It passes an absolute URL as `redirect`, and `SignInReturn::path()` accepts only a path starting with `/`. The fallback (`202-account/`) is the likely result, but it was not run.
- **`202-Toolbar/` target.** `202-access-denied.php` and `202-account/signout.php` redirect there when `$_SESSION['toolbar']` is set. No code sets the flag (`require_user('toolbar')` is never called), and the directory is not in the tree.
- **"Came from paid 1.9.56" flag.** Whether `GET /me`'s `features` or `flags` carry what Help and Upgrade read (`p202_came_from_paid_1956()`). The flags listed in `GET /me` were checked and this one was not seen among them, but the whole `features` builder was not read.
- **Which server setups use `202-404.php`.** It depends on the web server's error-page setting, which is outside the PHP code.
