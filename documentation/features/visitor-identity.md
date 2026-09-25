# Visitor identity

Multi-touch attribution needs to know which clicks belong to one person.
Prosper202 links clicks with first-party signals only. It never uses IP
addresses, user agents or fingerprinting, because those merge strangers
behind the same office or carrier network.

## What links clicks

| Signal | Set by | Links |
|---|---|---|
| `p202vid` cookie | The tracking domain, on every redirect (`dl.php`, `rtr.php`). Random, `HttpOnly`, `SameSite=Lax`, kept up to 400 days | Clicks through your tracking links in one browser |
| `p202lpid` | `landing.php`, the script your landing pages already load. It keeps a random id in the landing page's own storage, sends it with the pageview and adds it to links into your tracker when they are followed | Clicks on your own sites, even where the tracking domain's cookie is cleared |
| Signed customer id | `cust` with `cust_sig` on a tracking link, pixel or postback, or `customer_ref` on `POST /api/v3/conversions` | One person across browsers and devices |

Only a hash of each value is stored. None of them is passed on to an offer:
the redirect drops `p202lpid`, `cust`, `customer_ref`, their `_type` fields,
`cust_sig` and `p202_consent`. The customer id, its signature and
`p202_consent` do go on to your own landing page when a redirector sends the
visitor there, so the page can personalise and honour the refusal.

## Signing customer ids

A customer id on a public pixel is something anyone can send. It joins
journeys only when your own server signs it with your account's linking key:

```
cust_sig = hex(HMAC-SHA256(linking_key, "<cust_type>:<cust>"))
```

- `cust_type` is one of `custom` (the default), `email_md5`, `email_sha256`,
  `esp_id`, `merchant_id` or `subid`.
- Sign email digests in lower case.
- Get the key with `p202 user identity-key get <user_id>` or
  `GET /api/v3/users/{id}/identity-key`. Keep it on your server.

```php
$sig = hash_hmac('sha256', 'email_sha256:' . strtolower($digest), hex2bin($linkingKey));
```

```js
const sig = require('crypto').createHmac('sha256', Buffer.from(linkingKey, 'hex'))
  .update('custom:' + accountId).digest('hex');
```

```python
sig = hmac.new(bytes.fromhex(linking_key), f"custom:{account_id}".encode(), hashlib.sha256).hexdigest()
```

Then add `cust=<id>&cust_type=<type>&cust_sig=<sig>` to the link or postback.
An id without a valid signature is still recorded for lifetime value, and
links nothing.

A REST API caller is already authenticated, so `customer_ref` on
`POST /api/v3/conversions` links without a signature.

Rotating the key (`p202 user identity-key rotate`) stops every signature made
with the old key. Clicks already linked stay linked.

## Consent

One switch turns all of it off:

- `p202_consent=0` on a tracking link;
- `p202.consent(false)` on a landing page (remembered, and sent on every
  pageview and followed link); `p202.consent(true)` turns it back on. The
  pageview is sent once the page's scripts have run, so a consent tool that
  calls it right after the snippet stops the first pageview too. A tool that
  decides before the snippet loads can set `window.p202 = {consent: false}`;
- a campaign's `identity_signals` set to `0` (API and both CLIs). Its
  landing pages then read, create and send no landing-page id either.

Then no cookie is set or read, the landing-page id is deleted, and each click
is a journey of one.

## Limits

- **Browsers clear redirect-domain cookies.** Safari and Chrome's bounce
  tracking protections reset state for domains a visitor only passes
  through. Journeys undercount there. The landing-page id helps, and Safari
  still caps script-written storage at seven days without a visit.
- **A landing page on another site gets no tracker cookie from its pageview.**
  The pageview links by `p202lpid` alone; the tracker's cookie is set when the
  visitor follows a link into the tracker.
- **Cross-device needs signed customer ids.** Without them, one person on
  two devices is two visitors.
- **Nothing before the upgrade.** Clicks recorded before this release have no
  visitor, and cannot be given one.
- **Shared ids are quarantined.** A signal that joins more than 20 visitors,
  such as `cust=test` in QA or a shared kiosk, stops linking and is still
  recorded.
