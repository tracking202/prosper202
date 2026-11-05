# Upgrading from paid Prosper202 1.9.56

This page is for installs that ran the paid 1.9.56 release and are moving to
1.9.80. Your database upgrades in place. Campaigns, landing pages, trackers,
clicks, conversions and users are kept, and so are tables 1.9.80 no longer
reads.

The tracking code you already pasted into landing pages, network postback
settings and ad accounts keeps working. The suite
`tests/Upgrade/Paid1956ContractsServedIntegrationTest.php` checks each
address below on a database shaped like a paid 1.9.56 one, after the upgrade.

## What keeps working without changes

- **Landing-page code.** Pages that load
  `tracking202/static/landing.js?lpip=…` (Get LP Code in 1.9.56) still record
  their visits. You don't need to paste the code again.
- **Pixels and postbacks with an order number.** `gpb.php`, `gpx.php` and
  `upx.php` read the order number from `t202txid`, as 1.9.56 code sends it.
  Each new order number on a click is recorded as its own sale. `gpb.php`
  also takes its values from a form `POST` body, as well as the query string.
- **Publisher links.** A link that carries `t202pubid` credits the click to
  that publisher, but only when the link's account has granted them the
  Publisher role.
- **API v1.** `api/v1/reports/` and the setup reads (`api/v1/campaigns/`
  and the others) still answer with the API keys your accounts made before
  the upgrade.
- **Redirector rules.** Rules on c1–c4, UTM values, keyword, referer and the
  other 1.9.56 types still match.
- **Update Subids.** Lines of `SUBID, PAYOUT, TRANSACTION ID, TIMESTAMP` are
  read again, each field after the subid optional.
- **Shopify order webhooks** sent to `gpb.php` are recorded again, once you
  store the webhook secret under Account › API integrations › Shopify. A
  webhook that does not verify against it is refused.
- **The Bot202 Facebook Pixel Assistant.** The settings your landing pages
  kept fire the traffic source's Meta Pixel again.
- **JVZoo's JVZIPN URL.** The URL 1.9.56 showed
  (`tracking202/static/jvzoo.php`, no `?role=`) still records an affiliate's
  sales on the click in `caffitid`, verified with the stored JVZIPN secret
  key. A vendor's product JVZIPN URL needs the URL ending `?role=vendor` from
  Account › API integrations › JVZoo.
- **Passwords.** Stored 1.9.56 passwords still sign in.
- **Privacy.** Accounts that existed before the upgrade keep tracking as they
  did. Only accounts created on 1.9.79 or later start with privacy set to
  "Enabled for European Traffic".

## Changes that are intentional

These differ from 1.9.56 on purpose and stay that way.

- **An order number sent twice counts once.** In 1.9.56, a pixel or postback
  that sent the same `t202txid` again was counted again unless it also sent
  `t202dedupe=1`. Now the same order number on the same click is one sale,
  and `t202dedupe` is ignored. Send a different order number for each sale.
- **No order number means one conversion per click.** The 1.9.56 universal
  pixel shipped with `t202txid=` empty. Fired without an order number, a pixel
  or postback now converts a click once, because a reload of the thank-you
  page can't be told apart from a second sale. Put the order number in
  `t202txid` to count repeat sales and upsells.
- **The click cookie lasts 30 days** (it was 90). The cookie is
  `tracking202subid`. Pixels that read it to find the click stop finding
  clicks older than 30 days.
- **A publisher-credited click stays in the account that owns the link.**
  In 1.9.56 the click moved to the publisher's account. Now it stays where it
  is, so the owner's reports keep its campaign and payout. The publisher is
  stored beside it. There is no report by publisher yet.
- **Reset campaign subids now clears conversions.** The Reset campaign
  subids page (Update) removes the conversions of a category, or of one campaign in it,
  through the conversion ledger. Each click's value is then recalculated from
  what is left.
- **Server postbacks to private addresses are refused.** A traffic source's
  server-to-server postback is not sent to a loopback, private, link-local or
  other non-public address. If your receiver is on your own network, list its
  network in `P202_WEBHOOK_ALLOW_NETWORKS` in `202-config.php`. Link-local and
  cloud-metadata addresses stay refused.
- **The Export tab is now Setup › Offline Conversions.** It makes Google Ads
  and Microsoft Advertising files for a rolling window of 1 to 90 days, and
  gives scheduled fetch URLs. See
  [Offline conversions](../features/offline-conversions.md). The old
  scheduled Google feed URL answers `410 Gone` and names its replacement.
- **The daily email report is retired.** Its cron address,
  `202-cronjobs/daily-email.php`, now answers `410 Gone`.
- **The `landing.js` loader ignores `defpixel` and `rsid`** in old snippets.
- **Update › CPC previews before it applies.** It shows how many clicks will
  change and refuses a confirmation that no longer matches that preview.

## Known limitations

- **ISP lookups need a GeoIP2 database.** If an install has only the legacy
  `GeoIPISP.dat` file, it needs PHP's `geoip` extension to read it. The Docker
  image doesn't include that extension, so those installs record "Unknown
  ISP/Carrier". A `GeoIP2-ISP.mmdb` file works without it.
- **Some paid-only tables are kept but not read**, among them
  `202_clicks_purlink`. The upgrade doesn't drop them.

## Not working yet after the upgrade

These 1.9.56 features don't work on 1.9.80 yet. Each one is being restored,
and its line comes off this list when the fix ships.

- PurLinks (landing-page links straight to the offer), the PurLink SubID
  format (`<click>-<campaign>`) on postbacks, and the deferred pixel
  (`defpixel=1`).
- Dynamic landing-page content, such as the visit's SubID.
- Google Ads parallel tracking templates (`tracking202/redirect/pt.php`).
