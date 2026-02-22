# Prosper202 Security Audit Report

**Date:** 2026-02-22
**Scope:** Full codebase security assessment
**Codebase:** PHP 8.3 affiliate tracking platform with REST API v3, Symfony Console CLI

---

## Executive Summary

This audit identified **47 distinct security vulnerabilities** across the Prosper202 codebase, including **8 critical**, **12 high**, **18 medium**, and **9 low** severity findings. Two critical findings — PHP object injection via session cookie deserialization and path traversal in file upload — are exploitable remotely without authentication or with minimal authentication and can lead to full server compromise or credential theft.

### Risk Rating: CRITICAL

---

## Table of Contents

1. [Critical Vulnerabilities](#1-critical-vulnerabilities)
2. [High Severity Vulnerabilities](#2-high-severity-vulnerabilities)
3. [Medium Severity Vulnerabilities](#3-medium-severity-vulnerabilities)
4. [Low Severity Vulnerabilities](#4-low-severity-vulnerabilities)
5. [Positive Security Findings](#5-positive-security-findings)
6. [Remediation Priority](#6-remediation-priority)

---

## 1. Critical Vulnerabilities

### CRIT-01: PHP Object Injection via Session Cookie Deserialization

- **File:** `api/v2/Slim/Middleware/SessionCookie.php:128`
- **CWE:** CWE-502 (Deserialization of Untrusted Data)
- **CVSS:** 9.8

```php
$_SESSION = unserialize($value); // $value comes from $_COOKIE
```

**Impact:** Remote Code Execution. An attacker can craft a malicious serialized PHP object in the session cookie to exploit gadget chains present in the codebase, achieving arbitrary code execution on the server.

**Remediation:** Replace `unserialize()` with `json_decode()` for session cookie data. Add `allowed_classes: false` parameter if `unserialize()` must be kept.

---

### CRIT-02: Command Injection in Snoopy HTTP Client

- **File:** `202-config/class-snoopy.php:934`
- **CWE:** CWE-78 (OS Command Injection)
- **CVSS:** 9.1

```php
exec($this->curl_path . " -k -D \"$headerfile\"" . $cmdline_params
     . " \"" . escapeshellcmd($URI) . "\"", $results, $return);
```

**Impact:** `escapeshellcmd()` is insufficient to prevent injection. Attacker-controlled URI or headers can break out of the command and execute arbitrary shell commands.

**Remediation:** Replace the entire Snoopy exec-based HTTP client with PHP's native `curl_*` functions.

---

### CRIT-03: Path Traversal in File Upload Handler

- **File:** `tracking202/update/upload.php:38,54,88,97`
- **CWE:** CWE-22 (Path Traversal)
- **CVSS:** 8.6

```php
$file = $upload_dir . $_GET['file'];  // No validation
$handle = fopen($file, 'rb');         // Opens traversed path
```

**Impact:** Authenticated users can read arbitrary server files by passing `file=../../202-config.php` to extract database credentials, API keys, and other secrets. Combined with the XSS on line 54 (`echo '<input ... value="'.$_GET['file'].'"/>'), this is a chained attack vector.

**Remediation:** Validate with `realpath()` and verify the resolved path is within `$upload_dir`. Sanitize filename with `basename()`.

---

### CRIT-04: Disabled CSRF Protection on Password Reset

- **File:** `202-pass-reset.php:37`
- **CWE:** CWE-352 (Cross-Site Request Forgery)
- **CVSS:** 8.1

```php
//if ($_POST['token'] != $_SESSION['token']) { $error['token'] = '...'; }
```

**Impact:** CSRF validation is commented out. An attacker can trick an authenticated user into resetting their password to an attacker-controlled value via a cross-site form submission.

**Remediation:** Uncomment the CSRF check and use `hash_equals()` for timing-safe comparison.

---

### CRIT-05: SSL Certificate Verification Disabled (40+ instances)

- **Files:** `202-config/functions-auth.php:269`, `202-config/clickserver_api_management.php:22,52,72`, `202-config/functions-tracking202.php` (20+ instances), and others
- **CWE:** CWE-295 (Improper Certificate Validation)
- **CVSS:** 7.4

```php
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
```

**Impact:** All HTTPS API calls (including API key validation against `my.tracking202.com`) are vulnerable to man-in-the-middle attacks. Attackers on the network path can intercept API keys, credentials, and tracking data.

**Remediation:** Set `CURLOPT_SSL_VERIFYPEER` to `true` and `CURLOPT_SSL_VERIFYHOST` to `2` in all cURL calls. Ensure the server has an up-to-date CA certificate bundle.

---

### CRIT-06: API Keys Exposed in URL Query Parameters

- **Files:** `202-config/clickserver_api_management.php:22,52,72`, `202-config/functions-tracking202.php:2841,2866`, `202-account/auto-upgrade-premium.php:35`
- **CWE:** CWE-598 (Use of GET Request Method With Sensitive Query Strings)
- **CVSS:** 7.5

**Impact:** API keys transmitted in URL query strings are logged in server access logs, proxy logs, browser history, and leaked via HTTP Referer headers to third-party sites.

**Remediation:** Move API keys to `Authorization` headers or POST request bodies.

---

### CRIT-07: Unencrypted API Key Storage in Database

- **Tables:** `202_api_keys.api_key`, `202_users.user_api_key`, `202_users.p202_customer_api_key`, `202_users.user_stats202_app_key`, `202_dni_networks.apiKey`
- **CWE:** CWE-312 (Cleartext Storage of Sensitive Information)
- **CVSS:** 7.5

**Impact:** Database breach (via SQL injection or backup exposure) immediately compromises all API keys and integration credentials.

**Remediation:** Store only hashed API keys (SHA-256) for lookup. For keys that need retrieval (integration keys), encrypt at rest using AES-256-GCM with an application-level key.

---

### CRIT-08: Sensitive Data Leakage via SELECT * in API Preferences

- **File:** `api/V3/Controllers/UsersController.php:294`
- **CWE:** CWE-200 (Exposure of Sensitive Information)
- **CVSS:** 7.5

```php
$stmt = $this->prepare('SELECT * FROM 202_users_pref WHERE user_id = ? LIMIT 1');
```

**Impact:** The `getPreferences()` endpoint returns ALL columns including `ipqs_api_key` (fraud detection API key) and `user_slack_incoming_webhook` (webhook tokens). Any authenticated user can read their own integration secrets via the API.

**Remediation:** Whitelist returned columns explicitly instead of using `SELECT *`.

---

## 2. High Severity Vulnerabilities

### HIGH-01: Reflected XSS via `$_GET['file']` in HTML Attribute

- **File:** `tracking202/update/upload.php:54`
- **CWE:** CWE-79 (Cross-Site Scripting)

```php
echo '<input type="hidden" name="file" value="'.$_GET['file'].'"/>';
```

### HIGH-02: Reflected XSS via `$_SERVER['REDIRECT_URL']` in Form Actions

- **Files:** `tracking202/setup/aff_campaigns.php:361`, `tracking202/setup/aff_networks.php:201`, `tracking202/setup/text_ads.php:336`, `tracking202/setup/ppc_accounts.php:405`, `tracking202/setup/landing_pages.php:327`, `202-account/user-management.php:328`
- **CWE:** CWE-79

```php
<form method="post" action="<?php echo $_SERVER['REDIRECT_URL'] ?? ''; ?>">
```

### HIGH-03: Reflected XSS via `$_GET['dl_offer_id']` in JavaScript Context

- **File:** `tracking202/setup/aff_campaigns.php:668,670`
- **CWE:** CWE-79

```php
tablesorterOptions.toggleId = <?php echo $_GET['dl_offer_id']; ?>;
```

### HIGH-04: Insecure Deserialization in Cache Functions (6 instances)

- **Files:** `202-config/functions-tracking202.php:2714,2755`, `202-config/connect2.php:2135,2203`, `tracking202/ajax/charts.php:46`, `tracking202/ajax/account_overview.php:45`
- **CWE:** CWE-502

```php
return unserialize($getCache); // Cache data unserialized without validation
```

### HIGH-05: SQL Injection via Direct Variable Interpolation (No Quotes)

- **Files:** `tracking202/static/upx.php:144`, `tracking202/static/gpb.php:145`
- **CWE:** CWE-89 (SQL Injection)

```php
WHERE 2c.`click_id` = {$mysql['click_id']}  // No quotes around value
```

### HIGH-06: SQL Injection via String Concatenation (40+ instances)

- **Files:** `202-account/api-integrations.php:52,127,142,158,298,307`, `202-account/account.php:66,362,365,368,479`, `api-key-required.php:45`, `tracking202/static/record_simple.php:585,587`, `tracking202/static/record_adv.php:509,511`, `202-config/class-dataengine.php:1695`, `tracking202/redirect/dl.php:753`, `202-config/functions-upgrade.php:1871`, `202-config/connect2.php:3547`, `202-config/functions-auth.php:293-295`
- **CWE:** CWE-89

Values are escaped with `real_escape_string()` but concatenated into queries instead of using prepared statements. While not directly exploitable under normal conditions, this pattern is fragile and breaks under charset misconfigurations or encoding edge cases.

### HIGH-07: Weak Password Reset Token Generation

- **File:** `202-lost-pass.php:29-31`
- **CWE:** CWE-330 (Use of Insufficiently Random Values)

```php
substr(str_shuffle($user_pass_key), 0, 40) . time()
```

Only 40 characters of alphabet shuffle + predictable timestamp. No rate limiting on requests.

### HIGH-08: Legacy MD5 Password Hash Support

- **Files:** `202-config/functions-auth.php:36-43`, `202-config/functions.php:101-103`
- **CWE:** CWE-328 (Use of Weak Hash)

```php
$user_pass = md5($salt . md5($user_pass . $salt)); // Hardcoded salt "202"
```

Login still accepts MD5-hashed passwords as fallback. MD5 is cryptographically broken.

### HIGH-09: SHA-1 Key Derivation for AES Encryption

- **File:** `tracking202/static/cb202.php:25`
- **CWE:** CWE-327 (Use of a Broken or Risky Cryptographic Algorithm)

```php
substr(sha1((string) $user_row['cb_key']), 0, 32) // SHA1 truncated as AES key
```

Uses SHA-1 (cryptographically broken) for key derivation without salt or iterations, with AES-CBC (no authentication).

### HIGH-10: SSRF in Sync Endpoints (No URL Validation)

- **Files:** `api/V3/Controllers/SyncController.php:473-495`, `202-config/functions-tracking202.php:3322,3469`, `202-config/Attribution/Export/WebhookDispatcher.php:166`
- **CWE:** CWE-918 (Server-Side Request Forgery)

No validation of URLs for private IP ranges (127.0.0.1, 10.x.x.x, 192.168.x.x) or dangerous protocols (file://, gopher://).

### HIGH-11: API Keys Exposed in Frontend JavaScript

- **Files:** `202-account/clickservers.php:94`, `202-account/account.php:59`
- **CWE:** CWE-200

```php
var api_key = "<?php echo base64_encode($user_row['clickserver_api_key']);?>"
```

### HIGH-12: SQL Injection via Dynamic Field Name Interpolation

- **File:** `202-account/api-integrations.php:52`
- **CWE:** CWE-89

```php
$sql = "UPDATE `202_users_pref` SET `{$field_name}` = '{$escaped_value}' ...";
```

Column names cannot be parameterized and are directly interpolated.

---

## 3. Medium Severity Vulnerabilities

### MED-01: CSRF Token Comparison Not Timing-Safe

- **Files:** `202-account/account.php:134,337,380,413,469`, `202-appstore/index.php:11`, `202-account/ajax/upgrade_submit_api_key.php:8`
- Uses `!=` instead of `hash_equals()` — vulnerable to timing attacks.

### MED-02: XSS via `$_SERVER['HTTP_HOST']` in JavaScript

- **File:** `202-account/clickservers.php:130`

### MED-03: DOM-based XSS via `.html()` jQuery Calls

- **Files:** `202-js/home.js:3,7,11,15,19`, `202-js/dni.search.offers.tablesorter.js:47-48,51,123,142`

### MED-04: Session Fingerprint Uses MD5 Without IP/UA Binding

- **File:** `202-config/functions-auth.php:59,199`

```php
$_SESSION['session_fingerprint'] = md5('session_fingerprint' . session_id());
```

### MED-05: Remember-Me Token Valid for 14 Days Without Rotation

- **File:** `202-config/functions-auth.php:314-358`

### MED-06: Weak Random via `mt_rand()` for Security-Sensitive Values

- **Files:** `tracking202/static/upx.php:200`, `tracking202/static/gpb.php:194`, `202-config/connect2.php:509,554,706`

### MED-07: Debug Output in Production (`print_r`, `var_dump`)

- **Files:** `tracking202/static/upx.php:98,153`, `tracking202/static/gpb.php:60,97,263`, `202-cronjobs/daily-email.php:130`, `api/v2/functions.php:31,35,54,85,420`

### MED-08: `error_reporting(E_ALL)` and `display_errors` in Production

- **Files:** 8+ files in `202-cronjobs/`, `tracking202/redirect/off.php:3-4`

### MED-09: Serialized Superglobals Stored in Database

- **Files:** `202-login.php:106-107`, `202-Mobile/202-login.php:39-40`

```php
$login_server_serialized = serialize($_SERVER);  // Cookies, headers, tokens
$login_session_serialized = serialize($_SESSION); // API keys, session data
```

### MED-10: Unescaped `urlencode()` in Script `src` Attribute

- **File:** `202-config/template.php:197`

### MED-11: Missing Security Headers

Only `X-Content-Type-Options: nosniff` is set. Missing: `Strict-Transport-Security`, `X-Frame-Options`, `Content-Security-Policy`, `Referrer-Policy`.

### MED-12: Error Message Information Disclosure in API

- **File:** `api/v3/index.php:438-441`
- Exception messages expose field names, database structure, validation rules.

### MED-13: Rate Limiting Bypass for Admin API Users

- **File:** `api/v3/index.php:123-150`

### MED-14: Overly Permissive Admin Scope Model

- **File:** `api/V3/Auth.php:130-134`

```php
if ($this->isAdmin()) { return true; } // Admin bypasses ALL scope checks
```

### MED-15: Missing Content-Type Validation in API

- **File:** `api/v3/index.php:66-77`

### MED-16: AES-CBC Without Authentication

- **Files:** `api/v2/Slim/Slim.php:301`, `api/v2/Slim/Http/Util.php`, `tracking202/static/cb202.php:24`
- CBC mode provides confidentiality but not integrity. Vulnerable to padding oracle attacks.

### MED-17: Password Reset Token in GET Parameter

- **File:** `202-pass-reset.php:11`
- Reset token in URL visible in server logs and Referer headers.

### MED-18: CLI Stores API Keys in Plaintext on Disk

- **File:** `cli/Config.php:29-41`
- Writes to `~/.p202/config.json` in plaintext.

---

## 4. Low Severity Vulnerabilities

### LOW-01: Tracking Cookies Without Security Flags

- **File:** `tracking202/static/ipx.php:37`

### LOW-02: Legacy mcrypt References

- **File:** `api/v2/Slim/Slim.php:36-38`

### LOW-03: CLI Accepts Secrets as Command-Line Arguments

- **Files:** `cli/Commands/ConfigSetKeyCommand.php:26`, `cli/Commands/UserPreferencesUpdateCommand.php:25,27`
- Arguments visible in shell history and process listings.

### LOW-04: No HTTPS Validation for CLI URL Configuration

- **File:** `cli/Commands/ConfigSetUrlCommand.php:26`

### LOW-05: No Authentication Failure Logging in API

- **File:** `api/V3/Auth.php:67`

### LOW-06: Case-Sensitive Admin Role Check

- **File:** `api/V3/Auth.php:146-147`

### LOW-07: CORS Configuration Without Credential Restrictions

- **File:** `api/v3/index.php:36-42`

### LOW-08: Unvalidated Attribution Model Type

- **File:** `api/V3/Controllers/AttributionController.php:87`

### LOW-09: User-Controlled Cursor TTL (Up to 86400s)

- **File:** `api/V3/Controller.php:185`

---

## 5. Positive Security Findings

The following security measures are properly implemented:

- **API v3 uses prepared statements** throughout all controllers
- **API v3 has proper user isolation** — base Controller adds `user_id` filters to all queries
- **API v3 passwords use `PASSWORD_BCRYPT`** for new user creation/updates
- **Session regeneration** on login via `session_regenerate_id(true)`
- **Remember-me cookies** use HMAC-SHA256 signatures with `HttpOnly`, `Secure`, `SameSite=Lax`
- **File upload** validates extension (only .txt/.csv allowed)
- **API rate limiting** is implemented for sync/bulk-upsert endpoints
- **CLI deletion commands** require confirmation prompts
- **API key masking** in CLI output (shows first 4 + last 4 chars)
- **Idempotency support** in bulk API operations via request hashing
- **ETag validation** on PUT operations for conflict detection

---

## 6. Remediation Priority

### Phase 1: Critical (Immediate)

| # | Finding | Fix |
|---|---------|-----|
| CRIT-01 | Object injection in session cookie | Replace `unserialize()` with `json_decode()` |
| CRIT-03 | Path traversal in upload.php | Validate with `realpath()` + `basename()` |
| CRIT-04 | Disabled CSRF on password reset | Uncomment CSRF check, use `hash_equals()` |
| HIGH-05 | SQL injection without quotes | Add quotes and use prepared statements |
| HIGH-03 | XSS in JavaScript context | Use `json_encode()` for JS output |
| HIGH-01 | XSS in HTML attribute | Use `htmlspecialchars()` |

### Phase 2: High (1-2 weeks)

| # | Finding | Fix |
|---|---------|-----|
| CRIT-05 | Disabled SSL verification | Set `CURLOPT_SSL_VERIFYPEER = true` everywhere |
| CRIT-06 | API keys in URLs | Move to Authorization headers |
| CRIT-08 | SELECT * leaks secrets | Whitelist returned columns |
| HIGH-06 | SQL concatenation (40+) | Migrate to prepared statements |
| HIGH-10 | SSRF in sync endpoints | Validate URLs against private ranges |
| HIGH-02 | XSS via REDIRECT_URL | Escape with `htmlspecialchars()` |

### Phase 3: Medium (2-4 weeks)

| # | Finding | Fix |
|---|---------|-----|
| CRIT-02 | Command injection in Snoopy | Replace with native PHP curl |
| CRIT-07 | Plaintext API key storage | Encrypt at rest |
| HIGH-04 | Cache deserialization | Replace with `json_decode()` |
| HIGH-07 | Weak reset tokens | Use `bin2hex(random_bytes(32))` |
| HIGH-08 | MD5 password fallback | Force bcrypt migration on login |
| HIGH-09 | SHA-1 key derivation | Use PBKDF2 or Argon2 |
| MED-01 | Non-timing-safe CSRF | Use `hash_equals()` |
| MED-11 | Missing security headers | Add HSTS, CSP, X-Frame-Options |

### Phase 4: Ongoing

- Remove debug output from production code
- Add authentication failure logging
- Implement Content Security Policy
- Rotate all exposed API keys after fixes are deployed
